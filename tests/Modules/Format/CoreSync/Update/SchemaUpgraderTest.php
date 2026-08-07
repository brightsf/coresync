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
