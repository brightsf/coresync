<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Admin\Helpers\BackendCurrenciesHelper;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Backend\Controllers\CoreSyncAdmin;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;

// RESPONSE_JSON живёт в Okay/Core/config/constants.php — bootstrap phpunit его не грузит (тот же
// приём, что в PingControllerTest).
if (!defined('RESPONSE_JSON')) {
    require_once dirname(__DIR__, 4) . '/Okay/Core/config/constants.php';
}

/**
 * Admin-страница модуля: стоп-кран на кнопке «Запустить сейчас» (SATGO-1 §A, вход 3 из 3) и
 * валидация поля «ID канала в ядре» (§C).
 *
 * IndexAdmin конструируется тривиально (`__construct($manager, $backendController, $controllerMethod)`),
 * а зависимости кладёт `onInit()` — который ходит в сеть (curl на okay-cms.com) и потому в тестах не
 * зовётся: нужные protected-свойства выставляются рефлексией.
 */
class CoreSyncAdminTest extends TestCase
{
    /** @var array<string, mixed> */
    private $assigned = [];

    /** @var array<string, mixed> тело последнего json-ответа контроллера */
    private $lastJson = [];

    /**
     * `CoreSyncAdmin::fetch()` зовёт СТАТИЧЕСКИЙ `Request::getRootUrl()` (подсказки «URL приёмника
     * пинка» / «Адрес витрины») — он читает $_SERVER напрямую, мимо мока Request. Поэтому окружение
     * запроса подставляем суперглобалами.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTP_HOST'] = 'shop.example';
        $_SERVER['SERVER_NAME'] = 'shop.example';
        $_SERVER['REQUEST_URI'] = '/backend/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
    }

    protected function tearDown(): void
    {
        unset(
            $_SERVER['SERVER_PROTOCOL'],
            $_SERVER['SERVER_PORT'],
            $_SERVER['HTTP_HOST'],
            $_SERVER['SERVER_NAME'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['SCRIPT_NAME']
        );
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $settingsValue
     * @return array{0:CoreSyncAdmin,1:Settings,2:Response,3:Request,4:Design}
     */
    private function harness(array $settingsValue = [], array $post = []): array
    {
        $admin = new CoreSyncAdmin(null, null, null);

        // setContent стабится РОВНО ЗДЕСЬ и один раз: второй any-matcher не переопределяет первый
        // (репо-грабли, см. комментарий в PingControllerTest). Тело ответа — в $this->lastJson.
        $this->lastJson = [];
        $response = $this->createMock(Response::class);
        $response->expects($this->any())->method('setContent')
            ->willReturnCallback(function ($content) use ($response) {
                $this->lastJson = json_decode((string) $content, true) ?: [];

                return $response;
            });

        $request = $this->createMock(Request::class);
        $request->expects($this->any())->method('method')->willReturnCallback(static function ($m = null) use ($post) {
            return $post !== [] && $m === 'POST';
        });
        $request->expects($this->any())->method('post')->willReturnCallback(static function ($name = null) use ($post) {
            if ($name === 'settings') {
                return $post === [] ? null : $post;
            }

            return null;
        });

        $design = $this->createMock(Design::class);
        $this->assigned = [];
        $design->expects($this->any())->method('assign')->willReturnCallback(function ($key, $value) {
            $this->assigned[(string) $key] = $value;
        });
        $design->expects($this->any())->method('fetch')->willReturn('');

        $settings = $this->createMock(Settings::class);
        $settings->expects($this->any())->method('get')->willReturnCallback(static function (string $key) use ($settingsValue) {
            return $key === Contract::SETTINGS_KEY ? $settingsValue : null;
        });

        $this->setProp($admin, 'response', $response);
        $this->setProp($admin, 'request', $request);
        $this->setProp($admin, 'design', $design);

        return [$admin, $settings, $response, $request, $design];
    }

    private function setProp(object $obj, string $name, $value): void
    {
        $ref = new \ReflectionProperty(get_class($obj), $name);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }

    /** EntityFactory, отдающий сущность прогонов (fetch()/runNow() зовут findLatest()). */
    private function factoryWithJobs(): EntityFactory
    {
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->expects($this->any())->method('findLatest')->willReturn(null);

        $factory = $this->createMock(EntityFactory::class);
        $factory->expects($this->any())->method('get')->willReturn($jobs);

        return $factory;
    }

    /**
     * @return array{0:SyncRunner,1:EntityFactory}
     */
    private function runnerAndFactory(): array
    {
        return [$this->createMock(SyncRunner::class), $this->factoryWithJobs()];
    }

    /** EntityFactory, отдающий заданную карту (rebind зовёт resetForRebind на CoreSyncMapEntity). */
    private function factoryWithMap(CoreSyncMapEntity $map): EntityFactory
    {
        $factory = $this->createMock(EntityFactory::class);
        $factory->expects($this->any())->method('get')->willReturn($map);

        return $factory;
    }

    /** Прогон fetch() со стандартным окружением админки. */
    private function fetchPage(CoreSyncAdmin $admin, Settings $settings): void
    {
        $admin->fetch(
            $settings,
            $this->factoryWithJobs(),
            $this->createMock(BackendCurrenciesHelper::class),
            $this->createMock(Languages::class)
        );
    }

    // ------------------------------------------------------------------
    // §A. Стоп-кран — вход 3 из 3 («Запустить сейчас»)
    // ------------------------------------------------------------------

    /**
     * ВХОД 3 из 3. Оператор аутентифицирован — ему врать незачем: отказ ВНЯТНЫЙ (в отличие от
     * неразличимой 404 публичного пинка), прогон не стартует.
     *
     * KILL-ПРОБА (мутация 3 из 3, независимая): убрать гейт из runNow() → ответ станет
     * `success:true` → тест красный. Гейт в SyncRunner этот тест НЕ прикрывает: прогон бы не пошёл,
     * но кнопка отрапортовала бы «запущено» и оператор искал бы причину в другом месте.
     */
    public function testRunNowOnDisabledModuleRefusesWithExplicitReason(): void
    {
        [$admin, $settings] = $this->harness(['enabled' => 0]);
        [$runner, $factory] = $this->runnerAndFactory();

        $runner->expects($this->never())->method('run');

        $admin->runNow($runner, $factory, $settings);

        $this->assertFalse($this->lastJson['success'] ?? null, 'выключенный модуль → кнопка честно отвечает отказом');
        $this->assertNotEmpty($this->lastJson['error'] ?? '', 'оператору называется причина, а не тишина');
        $this->assertStringContainsStringIgnoringCase('выключен', (string) ($this->lastJson['error'] ?? ''));
    }

    /** enabled=1 → кнопка работает как прежде. */
    public function testRunNowOnEnabledModuleRuns(): void
    {
        [$admin, $settings] = $this->harness(['enabled' => 1]);
        [$runner, $factory] = $this->runnerAndFactory();

        $runner->expects($this->once())->method('run');

        $admin->runNow($runner, $factory, $settings);

        $this->assertTrue($this->lastJson['success'] ?? null);
    }

    /** Дефолт (приёмка §2): ключа нет → включено → кнопка работает как до этой ветки. */
    public function testRunNowWithMissingEnabledKeyRuns(): void
    {
        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example']);
        [$runner, $factory] = $this->runnerAndFactory();

        $runner->expects($this->once())->method('run');

        $admin->runNow($runner, $factory, $settings);

        $this->assertTrue($this->lastJson['success'] ?? null);
    }

    // ------------------------------------------------------------------
    // Церемония «Связать заново» (D-SAT-BIND-REBIND-CEREMONY) — стоп-кран + сброс
    // ------------------------------------------------------------------

    /**
     * Стоп-кран на кнопке «Связать заново» (как в runNow): выключенный модуль отвечает ВНЯТНЫМ
     * отказом и связывание НЕ сбрасывает (иначе сброс перед прогоном, который всё равно не пойдёт).
     *
     * KILL-ПРОБА: убрать гейт → resetForRebind вызовется и ответ станет success:true → тест красный.
     */
    public function testRebindOnDisabledModuleRefusesAndDoesNotReset(): void
    {
        [$admin, $settings] = $this->harness(['enabled' => 0]);
        $map = $this->createMock(CoreSyncMapEntity::class);
        $map->expects($this->never())->method('resetForRebind');

        $admin->rebind($settings, $this->factoryWithMap($map));

        $this->assertFalse($this->lastJson['success'] ?? null, 'выключенный модуль → отказ');
        $this->assertNotEmpty($this->lastJson['error'] ?? '', 'оператору называется причина');
        $this->assertStringContainsStringIgnoringCase('выключен', (string) ($this->lastJson['error'] ?? ''));
    }

    /** enabled=1 → карта сбрасывается (resetForRebind), ответ success. */
    public function testRebindOnEnabledModuleResetsMap(): void
    {
        [$admin, $settings] = $this->harness(['enabled' => 1]);
        $map = $this->createMock(CoreSyncMapEntity::class);
        $map->expects($this->once())->method('resetForRebind');

        $admin->rebind($settings, $this->factoryWithMap($map));

        $this->assertTrue($this->lastJson['success'] ?? null);
    }

    /** Дефолт (ключа нет → включено): «Связать заново» работает как при явном enabled=1. */
    public function testRebindWithMissingEnabledKeyResets(): void
    {
        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example']);
        $map = $this->createMock(CoreSyncMapEntity::class);
        $map->expects($this->once())->method('resetForRebind');

        $admin->rebind($settings, $this->factoryWithMap($map));

        $this->assertTrue($this->lastJson['success'] ?? null);
    }

    // ------------------------------------------------------------------
    // §C. Поле «ID канала в ядре» — валидация на сохранении
    // ------------------------------------------------------------------

    /**
     * Поле подставляется сегментом пути `/api/satellite/{channel}/manifest.json`, где роут ядра —
     * `[0-9]+`. Не-число = гарантированный 404 через полчаса, поэтому оно не сохраняется, а
     * отбивается внятной ошибкой сразу.
     *
     * KILL-ПРОБА §C: снять валидацию → настройки сохранятся → тест красный.
     *
     * @dataProvider nonNumericChannels
     */
    public function testNonNumericChannelIsRejectedWithExplicitError(string $channel): void
    {
        [$admin, $settings] = $this->harness(
            ['core_url' => 'https://core.example', 'channel_code' => '42', 'token' => 't'],
            ['core_url' => 'https://core.example', 'channel_code' => $channel, 'token' => '']
        );

        $settings->expects($this->never())->method('set'); // ничего не записали

        $this->fetchPage($admin, $settings);

        $this->assertNotEmpty($this->assigned['message_error'] ?? '', 'оператору названа причина');
        $this->assertStringContainsStringIgnoringCase('канал', (string) ($this->assigned['message_error'] ?? ''));
    }

    /**
     * @return array<string, array{0:string}>
     */
    public function nonNumericChannels(): array
    {
        return [
            'словесный код'   => ['site-a'],
            'смешанный'       => ['12abc'],
            'с пробелом'      => ['4 2'],
            'отрицательный'   => ['-1'],
            'дробный'         => ['1.5'],
            'путь'            => ['/api/satellite/42/'],
        ];
    }

    /** Числовой ID сохраняется. */
    public function testNumericChannelIsSaved(): void
    {
        [$admin, $settings] = $this->harness(
            [],
            ['core_url' => 'https://core.example', 'channel_code' => '42', 'token' => 'secret']
        );

        $saved = [];
        $settings->expects($this->once())->method('set')
            ->willReturnCallback(static function ($key, $value) use (&$saved) {
                $saved = ['key' => $key, 'value' => $value];
            });

        $this->fetchPage($admin, $settings);

        $this->assertSame(Contract::SETTINGS_KEY, $saved['key'] ?? null);
        $this->assertSame('42', $saved['value']['channel_code'] ?? null);
        $this->assertArrayNotHasKey('message_error', $this->assigned);
    }

    /**
     * Пустое поле — это «ещё не настроено», а не ошибка: оператор заполняет форму постепенно, а
     * неполные настройки уже отрабатывает SyncRunner (failed-job «не заданы адрес/канал/токен»).
     * Валидация ловит именно ЛОЖЬ (не-число), а не незаполненность.
     */
    public function testEmptyChannelIsSavedAsBefore(): void
    {
        [$admin, $settings] = $this->harness(
            [],
            ['core_url' => 'https://core.example', 'channel_code' => '', 'token' => 'secret']
        );

        $settings->expects($this->once())->method('set');

        $this->fetchPage($admin, $settings);

        $this->assertArrayNotHasKey('message_error', $this->assigned);
    }

    // ------------------------------------------------------------------
    // §A. Галка в форме не врёт
    // ------------------------------------------------------------------

    /**
     * Форма обязана показывать ТО ЖЕ, что читает раннер. Иначе на установке без ключа галка
     * рисуется снятой при фактически включённом модуле — и первое же «Сохранить» (оператор не
     * трогал галку) молча выключает обмен. Ровно тот молчаливый отказ, который чинит этот этап.
     */
    public function testCheckboxDefaultsToCheckedWhenKeyIsAbsent(): void
    {
        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example']);

        $this->fetchPage($admin, $settings);

        $this->assertTrue($this->assigned['coresync']['enabled'] ?? null, 'нет ключа → галка стоит (модуль включён)');
    }

    /** Снятая галка рисуется снятой. */
    public function testCheckboxUncheckedWhenDisabled(): void
    {
        [$admin, $settings] = $this->harness(['enabled' => 0]);

        $this->fetchPage($admin, $settings);

        $this->assertFalse($this->assigned['coresync']['enabled'] ?? null);
    }
}
