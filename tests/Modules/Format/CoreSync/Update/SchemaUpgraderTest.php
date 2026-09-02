<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMarker;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigrationCatalog;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaUpgrader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Догоняющий апгрейд схемы на фейках (эталон — BindTest/UpdaterTest: анонимные наследники
 * коллабораторов, БД не нужна). Проверяем: отставание догоняется РОВНО недостающими по порядку;
 * провал шага НЕ поднимает маркер + кричит в статус + повтор догоняет; идемпотентность/no-op;
 * маркер поднимается ТОЛЬКО при успехе всех. Kill-проба порядка/сравнения — testLagging… (строгий
 * порядок + исключение ≤applied): сломай сравнение версий → красный.
 */
class SchemaUpgraderTest extends TestCase
{
    /** @var array<int, array{0:string,1:mixed}> */
    private $settingsSets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsSets = [];
    }

    /**
     * Фейк-маркер (modules.version) в памяти. $applied — применённая версия; markApplied
     * пишет её и (опционально) может «провалиться» ($canMark=false).
     *
     * @param array<int,string> $marked out-параметр: версии, зафиксированные markApplied
     */
    private function marker(?string $applied, array &$marked, bool $canMark = true): SchemaMarker
    {
        return new class($applied, $marked, $canMark) extends SchemaMarker {
            /** @var string|null */
            private $applied;
            /** @var array<int,string> */
            private $marked;
            /** @var bool */
            private $canMark;

            public function __construct(?string $applied, array &$marked, bool $canMark)
            {
                $this->applied = $applied;
                $this->marked = &$marked;
                $this->canMark = $canMark;
            }

            public function appliedVersion(): ?string
            {
                return $this->applied;
            }

            public function markApplied(string $version): bool
            {
                if (!$this->canMark) {
                    return false;
                }
                $this->marked[] = $version;
                $this->applied = $version;

                return true;
            }
        };
    }

    /**
     * Фейк-каталог: целевая версия + карта [версия => callable]. Карту принимаем НАМЕРЕННО в
     * произвольном порядке — апгрейдер обязан отсортировать сам.
     *
     * @param array<string, callable():void> $migrations
     */
    private function catalog(?string $target, array $migrations): SchemaMigrationCatalog
    {
        return new class($target, $migrations) extends SchemaMigrationCatalog {
            /** @var string|null */
            private $target;
            /** @var array<string, callable():void> */
            private $migrations;

            public function __construct(?string $target, array $migrations)
            {
                $this->target = $target;
                $this->migrations = $migrations;
            }

            public function targetVersion(): ?string
            {
                return $this->target;
            }

            public function migrations(): array
            {
                return $this->migrations;
            }
        };
    }

    private function settingsMock(): Settings
    {
        $settings = $this->createMock(Settings::class);
        $sets = &$this->settingsSets;
        $settings->method('set')->willReturnCallback(static function (string $key, $value) use (&$sets): void {
            $sets[] = [$key, $value];
        });
        // Settings durable: get отдаёт последнее записанное значение того же ключа — как в бою
        // (vars[$param] сразу после set, unserialize при инициализации следующего процесса).
        $settings->method('get')->willReturnCallback(static function ($param) use (&$sets) {
            $found = null;
            foreach ($sets as [$key, $value]) {
                if ($key === $param) {
                    $found = $value;
                }
            }

            return $found;
        });

        return $settings;
    }

    /** @return array<string,mixed>|null последний записанный durable-исход схемы */
    private function outcome(): ?array
    {
        $found = null;
        foreach ($this->settingsSets as [$key, $value]) {
            if ($key === SchemaUpgrader::SETTINGS_SCHEMA_STATUS_KEY) {
                $found = $value;
            }
        }

        return $found;
    }

    /** @return int сколько раз durable-исход схемы был переписан */
    private function outcomeWrites(): int
    {
        $writes = 0;
        foreach ($this->settingsSets as [$key, $value]) {
            if ($key === SchemaUpgrader::SETTINGS_SCHEMA_STATUS_KEY) {
                $writes++;
            }
        }

        return $writes;
    }

    /** Положить durable-исход предыдущего тика — то, что прочитает следующий процесс. */
    private function seedOutcome(string $status, string $from, string $to): void
    {
        $this->settingsSets[] = [SchemaUpgrader::SETTINGS_SCHEMA_STATUS_KEY, [
            'status' => $status,
            'from'   => $from,
            'to'     => $to,
            'at'     => '2026-09-01 21:00:00',
            'error'  => null,
        ]];
    }

    /**
     * Логгер-шпион: собирает info-строки, которые видит оператор.
     *
     * @param array<int,string> $infos out-параметр
     */
    private function loggerSpy(array &$infos): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function ($message, array $context = []) use (&$infos): void {
            $infos[] = (string) $message;
        });

        return $logger;
    }

    /** @param array<int,string> $ran куда миграция дописывает свою версию при запуске */
    private function migration(string $tag, array &$ran, ?\Throwable $throw = null): callable
    {
        return static function () use ($tag, &$ran, $throw): void {
            $ran[] = $tag;
            if ($throw !== null) {
                throw $throw;
            }
        };
    }

    // ── отставание догоняется ровно недостающими по порядку (kill-проба порядка/сравнения) ──

    public function testLaggingMarkerRunsExactlyMissingMigrationsInOrder(): void
    {
        $ran = [];
        $marked = [];
        // Карта намеренно НЕ по порядку и с версиями по обе стороны от applied.
        $migrations = [
            '1.4.0' => $this->migration('1.4.0', $ran),
            '1.1.0' => $this->migration('1.1.0', $ran), // ≤ applied — НЕ должна гоняться
            '1.3.0' => $this->migration('1.3.0', $ran),
            '1.2.0' => $this->migration('1.2.0', $ran), // == applied — НЕ должна гоняться
        ];

        $upgrader = new SchemaUpgrader(
            $this->marker('1.2.0', $marked),
            $this->catalog('1.4.0', $migrations),
            $this->settingsMock()
        );

        $this->assertTrue($upgrader->upgrade());
        $this->assertSame(['1.3.0', '1.4.0'], $ran, 'гоняются РОВНО недостающие (applied, target] и ПО ВОЗРАСТАНИЮ');
        $this->assertSame(['1.4.0'], $marked, 'маркер поднят до целевой ровно один раз');
        $this->assertSame('upgraded', $this->outcome()['status'] ?? null);
        $this->assertSame('1.2.0', $this->outcome()['from'] ?? null);
        $this->assertSame('1.4.0', $this->outcome()['to'] ?? null);
    }

    // ── провал шага: маркер не поднят, статус кричит, повтор догоняет ──

    public function testFailedMigrationDoesNotBumpMarkerAndScreams(): void
    {
        $ran = [];
        $marked = [];
        $migrations = [
            '1.3.0' => $this->migration('1.3.0', $ran),
            '1.4.0' => $this->migration('1.4.0', $ran, new \RuntimeException('DDL провалено (Database::query→false)')),
        ];

        $upgrader = new SchemaUpgrader(
            $this->marker('1.2.0', $marked),
            $this->catalog('1.4.0', $migrations),
            $this->settingsMock()
        );

        $this->assertFalse($upgrader->upgrade(), 'провал миграции → upgrade() false (тик не идёт дальше)');
        $this->assertSame(['1.3.0', '1.4.0'], $ran, 'дошли до провалившегося шага');
        $this->assertSame([], $marked, 'маркер НЕ поднят при провале');
        $this->assertSame('failed', $this->outcome()['status'] ?? null, 'исход кричит failed в статус оператора');
        $this->assertStringContainsString('1.4.0', (string) ($this->outcome()['error'] ?? ''), 'в ошибке — версия провалившейся миграции');
    }

    public function testReRunAfterFailureCatchesUp(): void
    {
        // Тот же разрыв, но теперь 1.4.0 успешна (следующий тик). 1.3.0 идемпотентна — повторный
        // прогон безопасен. Ожидаем: обе применены, маркер поднят, upgrade() true.
        $ran = [];
        $marked = [];
        $migrations = [
            '1.3.0' => $this->migration('1.3.0', $ran),
            '1.4.0' => $this->migration('1.4.0', $ran),
        ];

        $upgrader = new SchemaUpgrader(
            $this->marker('1.2.0', $marked),
            $this->catalog('1.4.0', $migrations),
            $this->settingsMock()
        );

        $this->assertTrue($upgrader->upgrade());
        $this->assertSame(['1.3.0', '1.4.0'], $ran);
        $this->assertSame(['1.4.0'], $marked, 'после успешного повтора маркер догнал целевую');
    }

    // ── no-op / идемпотентность ──

    public function testExactTargetMigrationRetriesPartialFreshInstallAndHealsWithoutVersionBump(): void
    {
        $ran = [];
        $marked = [];
        $attempt = 0;
        $migrations = [
            '1.3.0' => $this->migration('1.3.0', $ran),
            '1.4.0' => static function () use (&$ran, &$attempt): void {
                $ran[] = '1.4.0';
                $attempt++;
                if ($attempt === 1) {
                    throw new \RuntimeException('fresh install partial DDL failure');
                }
            },
            '1.5.0' => $this->migration('1.5.0', $ran),
        ];

        $failedTick = new SchemaUpgrader(
            $this->marker('1.4.0', $marked), // Installer уже сохранил target до завершения install()
            $this->catalog('1.4.0', $migrations),
            $this->settingsMock()
        );

        $this->assertFalse($failedTick->upgrade(), 'exact-target DDL fail блокирует runtime tick');
        $this->assertSame(['1.4.0'], $ran, 'при applied == target гоняется только exact-target migration');
        $this->assertSame([], $marked, 'провал не делает бессмысленный version bump');
        $this->assertSame('failed', $this->outcome()['status'] ?? null);

        $healingTick = new SchemaUpgrader(
            $this->marker('1.4.0', $marked),
            $this->catalog('1.4.0', $migrations),
            $this->settingsMock()
        );

        $this->assertTrue($healingTick->upgrade(), 'следующий тик повторяет идемпотентную migration и лечит partial install');
        $this->assertSame(['1.4.0', '1.4.0'], $ran);
        $this->assertSame([], $marked, 'успешный self-heal не bump-ает уже target version');
        $this->assertSame('upgraded', $this->outcome()['status'] ?? null, 'failed outcome заменён успешным');
        $this->assertSame('1.4.0', $this->outcome()['from'] ?? null);
        $this->assertSame('1.4.0', $this->outcome()['to'] ?? null);
    }

    // ── self-heal делается ОДИН раз: признак сделанного — durable-исход upgraded с to == target ──

    public function testSuccessfulSelfHealRunsOnceAndLaterTicksAreSilentNoop(): void
    {
        $ran = [];
        $marked = [];
        $migrations = ['1.4.0' => $this->migration('1.4.0', $ran)];

        $healingTick = new SchemaUpgrader(
            $this->marker('1.4.0', $marked), // Installer сохранил target до завершения install()
            $this->catalog('1.4.0', $migrations),
            $this->settingsMock()
        );

        $this->assertTrue($healingTick->upgrade());
        $this->assertSame(['1.4.0'], $ran, 'первый тик лечит частичную установку');
        $this->assertSame(1, $this->outcomeWrites(), 'успешный self-heal пишет исход ровно один раз');
        $healedAt = $this->outcome()['at'] ?? null;

        $infos = [];
        foreach ([2, 3] as $tick) {
            $laterTick = new SchemaUpgrader(
                $this->marker('1.4.0', $marked),
                $this->catalog('1.4.0', $migrations),
                $this->settingsMock(),
                $this->loggerSpy($infos)
            );
            $this->assertTrue($laterTick->upgrade(), 'тик ' . $tick . ' не блокирует витрину');
        }

        $this->assertSame(['1.4.0'], $ran, 'после зафиксированного self-heal миграция больше не гоняется');
        $this->assertSame(1, $this->outcomeWrites(), 'холостой тик не переписывает durable-исход');
        $this->assertSame($healedAt, $this->outcome()['at'] ?? null, 'штамп исхода остался от реального прогона');
        $this->assertSame([], $infos, 'холостой тик молчит в логе оператора');
        $this->assertSame([], $marked, 'self-heal не трогает marker ни в одном тике');
    }

    public function testRealCatchUpToTargetSuppressesLaterExactTargetSelfHeal(): void
    {
        // Настоящий догон уже прогнал миграцию ровно target-версии в составе pending — повторять её
        // следующим тиком незачем. Маркер общий: догон поднимает applied до target.
        $ran = [];
        $marked = [];
        $migrations = [
            '1.3.0' => $this->migration('1.3.0', $ran),
            '1.4.0' => $this->migration('1.4.0', $ran),
        ];
        $marker = $this->marker('1.2.0', $marked);

        $catchUpTick = new SchemaUpgrader($marker, $this->catalog('1.4.0', $migrations), $this->settingsMock());
        $this->assertTrue($catchUpTick->upgrade());
        $this->assertSame(['1.3.0', '1.4.0'], $ran);
        $this->assertSame(['1.4.0'], $marked);

        $infos = [];
        $nextTick = new SchemaUpgrader(
            $marker,
            $this->catalog('1.4.0', $migrations),
            $this->settingsMock(),
            $this->loggerSpy($infos)
        );

        $this->assertTrue($nextTick->upgrade());
        $this->assertSame(['1.3.0', '1.4.0'], $ran, 'после настоящего догона до target self-heal не гоняется');
        $this->assertSame(1, $this->outcomeWrites(), 'исход догона не перезаписывается холостым self-heal');
        $this->assertSame([], $infos, 'холостой тик молчит в логе оператора');
    }

    public function testOutcomeOfAnotherVersionStillRunsSelfHealExactlyOnce(): void
    {
        // Маркер подняли до 1.4.0 мимо апгрейдера (ручная кнопка ядра), durable-исход остался от
        // догона до 1.3.0 — это НЕ признак сделанного self-heal целевой версии.
        $ran = [];
        $marked = [];
        $migrations = [
            '1.3.0' => $this->migration('1.3.0', $ran),
            '1.4.0' => $this->migration('1.4.0', $ran),
        ];
        $this->seedOutcome('upgraded', '1.2.0', '1.3.0');

        $healingTick = new SchemaUpgrader(
            $this->marker('1.4.0', $marked),
            $this->catalog('1.4.0', $migrations),
            $this->settingsMock()
        );

        $this->assertTrue($healingTick->upgrade());
        $this->assertSame(['1.4.0'], $ran, 'исход ДРУГОЙ версии не считается сделанным self-heal');
        $this->assertSame('1.4.0', $this->outcome()['to'] ?? null, 'после self-heal исход указывает на target');

        $nextTick = new SchemaUpgrader(
            $this->marker('1.4.0', $marked),
            $this->catalog('1.4.0', $migrations),
            $this->settingsMock()
        );

        $this->assertTrue($nextTick->upgrade());
        $this->assertSame(['1.4.0'], $ran, 'и ровно один раз: следующий тик уже no-op');
        $this->assertSame([], $marked, 'self-heal не трогает marker');
    }

    // ── честный лог: self-heal не выдаёт себя за догон, текст догона прежний ──

    public function testSelfHealLogsHonestLineInsteadOfCatchUpLine(): void
    {
        $ran = [];
        $marked = [];
        $infos = [];

        $upgrader = new SchemaUpgrader(
            $this->marker('1.4.0', $marked),
            $this->catalog('1.4.0', ['1.4.0' => $this->migration('1.4.0', $ran)]),
            $this->settingsMock(),
            $this->loggerSpy($infos)
        );

        $this->assertTrue($upgrader->upgrade());
        $this->assertSame(['1.4.0'], $ran);
        $this->assertContains('CoreSync schema: self-heal миграции 1.4.0 выполнен', $infos, 'self-heal логируется своей формой');
        foreach ($infos as $line) {
            $this->assertStringNotContainsString('схема догнана', $line, 'self-heal не выдаёт себя за догон');
        }
    }

    public function testRealCatchUpKeepsItsLogLine(): void
    {
        $ran = [];
        $marked = [];
        $infos = [];

        $upgrader = new SchemaUpgrader(
            $this->marker('1.2.0', $marked),
            $this->catalog('1.4.0', [
                '1.3.0' => $this->migration('1.3.0', $ran),
                '1.4.0' => $this->migration('1.4.0', $ran),
            ]),
            $this->settingsMock(),
            $this->loggerSpy($infos)
        );

        $this->assertTrue($upgrader->upgrade());
        $this->assertContains(
            'CoreSync schema: схема догнана 1.2.0 → 1.4.0 (миграций применено: 2)',
            $infos,
            'строка настоящего догона не изменилась'
        );
        foreach ($infos as $line) {
            $this->assertStringNotContainsString('self-heal', $line, 'догон не подписывается self-heal-ом');
        }
    }

    public function testExactTargetWithoutMigrationRemainsNoop(): void
    {
        $ran = [];
        $marked = [];
        $migrations = [
            '1.3.0' => $this->migration('1.3.0', $ran),
            '1.5.0' => $this->migration('1.5.0', $ran),
        ];

        $upgrader = new SchemaUpgrader(
            $this->marker('1.4.0', $marked),
            $this->catalog('1.4.0', $migrations),
            $this->settingsMock()
        );

        $this->assertTrue($upgrader->upgrade());
        $this->assertSame([], $ran, 'release без exact-target migration остаётся no-op');
        $this->assertSame([], $marked, 'no-op не трогает marker');
        $this->assertNull($this->outcome(), 'no-op не пишет outcome');
    }

    public function testDowngradeTargetIsNoop(): void
    {
        $ran = [];
        $marked = [];
        // module.json «младше» применённой (не должно случаться — updater только вверх) — не понижаем.
        $upgrader = new SchemaUpgrader(
            $this->marker('1.4.0', $marked),
            $this->catalog('1.2.0', ['1.3.0' => $this->migration('1.3.0', $ran)]),
            $this->settingsMock()
        );

        $this->assertTrue($upgrader->upgrade());
        $this->assertSame([], $ran);
        $this->assertSame([], $marked, 'target < applied — маркер не понижаем');
    }

    public function testNoSchemaChangeStillAdvancesMarker(): void
    {
        // Версия кода выросла, но схемных методов в диапазоне НЕТ (типовой релиз без DDL): маркер
        // всё равно догоняем до целевой, чтобы следующий тик не перепроверял разрыв вечно.
        $marked = [];
        $upgrader = new SchemaUpgrader(
            $this->marker('1.2.0', $marked),
            $this->catalog('1.3.0', []),
            $this->settingsMock()
        );

        $this->assertTrue($upgrader->upgrade());
        $this->assertSame(['1.3.0'], $marked, 'нет миграций в диапазоне → маркер всё равно догнан до целевой');
        $this->assertSame('upgraded', $this->outcome()['status'] ?? null);
    }

    // ── провал фиксации маркера ──

    public function testMarkerBumpFailureReturnsFalseAndScreams(): void
    {
        $ran = [];
        $marked = [];
        $upgrader = new SchemaUpgrader(
            $this->marker('1.2.0', $marked, false), // markApplied всегда false
            $this->catalog('1.3.0', ['1.3.0' => $this->migration('1.3.0', $ran)]),
            $this->settingsMock()
        );

        $this->assertFalse($upgrader->upgrade(), 'не удалось зафиксировать маркер → false');
        $this->assertSame(['1.3.0'], $ran, 'миграция отработала');
        $this->assertSame('failed', $this->outcome()['status'] ?? null);
    }

    // ── деградации чтения версий не блокируют тик ──

    public function testUnknownTargetSkipsGracefully(): void
    {
        $ran = [];
        $marked = [];
        $logger = $this->createMock(LoggerInterface::class);

        $upgrader = new SchemaUpgrader(
            $this->marker('1.2.0', $marked),
            $this->catalog(null, ['1.3.0' => $this->migration('1.3.0', $ran)]),
            $this->settingsMock(),
            $logger
        );

        $this->assertTrue($upgrader->upgrade(), 'нет целевой версии → тик не блокируем');
        $this->assertSame([], $ran);
        $this->assertSame([], $marked);
    }

    public function testUnknownAppliedSkipsGracefully(): void
    {
        $ran = [];
        $marked = [];
        $upgrader = new SchemaUpgrader(
            $this->marker(null, $marked),
            $this->catalog('1.3.0', ['1.3.0' => $this->migration('1.3.0', $ran)]),
            $this->settingsMock()
        );

        $this->assertTrue($upgrader->upgrade(), 'нет применённой версии → тик не блокируем');
        $this->assertSame([], $ran);
        $this->assertSame([], $marked);
    }
}
