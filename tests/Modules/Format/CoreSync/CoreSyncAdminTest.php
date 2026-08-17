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
use Okay\Modules\Format\CoreSync\Core\Apply\GalleryAdoptionPlanReader;
use Okay\Modules\Format\CoreSync\Core\Apply\LegacyGalleryAdopter;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Core\Update\Updater;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
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
     * @param array<string, mixed> $extraSettings прочие ключи Settings::get() (напр.
     *                                             Updater::SETTINGS_UPDATE_STATUS_KEY) → значение;
     *                                             пусто — как раньше, любой прочий ключ отдаёт null.
     * @return array{0:CoreSyncAdmin,1:Settings,2:Response,3:Request,4:Design}
     */
    private function harness(array $settingsValue = [], array $post = [], array $extraSettings = []): array
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
                return $post === [] ? null : ($post['settings'] ?? $post);
            }

            return $post[$name] ?? null;
        });
        $request->expects($this->any())->method('files')->willReturnCallback(static function ($name = null) use ($post) {
            return $post['__files'][$name] ?? null;
        });

        $design = $this->createMock(Design::class);
        $this->assigned = [];
        $design->expects($this->any())->method('assign')->willReturnCallback(function ($key, $value) {
            $this->assigned[(string) $key] = $value;
        });
        $design->expects($this->any())->method('fetch')->willReturn('');

        $settings = $this->createMock(Settings::class);
        $settings->expects($this->any())->method('get')->willReturnCallback(static function (string $key) use ($settingsValue, $extraSettings) {
            if ($key === Contract::SETTINGS_KEY) {
                return $settingsValue;
            }

            return array_key_exists($key, $extraSettings) ? $extraSettings[$key] : null;
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

    /**
     * EntityFactory, отдающий все четыре сущности, из которых собирается панель (B): jobs, map,
     * товарные и категорийные картинки. Дефолт — «прогонов не было, владения/картинок нет» (нулевые
     * counts, findLatest null); overrides позволяют точечным тестам панели переопределить нужную
     * сущность целиком.
     *
     * @param array{jobs?:object,map?:object,images?:object,categoryImages?:object} $overrides
     */
    private function factoryWithJobs(array $overrides = []): EntityFactory
    {
        $jobs = $overrides['jobs'] ?? $this->createMock(CoreSyncJobsEntity::class);
        if (!isset($overrides['jobs'])) {
            $jobs->expects($this->any())->method('findLatest')->willReturn(null);
        }

        $map = $overrides['map'] ?? $this->createMock(CoreSyncMapEntity::class);
        if (!isset($overrides['map'])) {
            $map->expects($this->any())->method('countByType')->willReturn(array_fill_keys(Contract::ENTITY_TYPES, 0));
        }

        $images = $overrides['images'] ?? $this->createMock(CoreSyncImagesEntity::class);
        if (!isset($overrides['images'])) {
            $images->expects($this->any())->method('countByState')->willReturn(array_fill_keys(Contract::IMAGE_STATES, 0));
        }

        $categoryImages = $overrides['categoryImages'] ?? $this->createMock(CoreSyncCategoryImagesEntity::class);
        if (!isset($overrides['categoryImages'])) {
            $categoryImages->expects($this->any())->method('countByState')->willReturn(array_fill_keys(Contract::IMAGE_STATES, 0));
        }

        $factory = $this->createMock(EntityFactory::class);
        $factory->expects($this->any())->method('get')->willReturnCallback(
            static function (string $class) use ($jobs, $map, $images, $categoryImages) {
                if ($class === CoreSyncJobsEntity::class) {
                    return $jobs;
                }
                if ($class === CoreSyncMapEntity::class) {
                    return $map;
                }
                if ($class === CoreSyncImagesEntity::class) {
                    return $images;
                }
                if ($class === CoreSyncCategoryImagesEntity::class) {
                    return $categoryImages;
                }

                throw new \InvalidArgumentException('Unexpected entity: ' . $class);
            }
        );

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

    public function testGalleryAdoptionPreviewRequiresPostCsrfDisabledModuleAndSharedLock(): void
    {
        $upload = ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/private/upload', 'size' => 10];
        [$admin, $settings] = $this->harness(['enabled' => 0, 'source_instance' => 'artaz'], [
            'session_id' => 'csrf',
            '__files' => ['gallery_adoption_plan' => $upload],
        ]);
        $reader = $this->createMock(GalleryAdoptionPlanReader::class);
        $adopter = $this->createMock(LegacyGalleryAdopter::class);
        $lock = $this->createMock(LockHelper::class);
        $plan = ['sha256' => str_repeat('a', 64), 'header' => ['rows_count' => 1], 'rows' => [[]]];

        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');
        $reader->expects($this->once())->method('read')->with($upload)->willReturn($plan);
        $adopter->expects($this->once())->method('preview')->with($plan)->willReturn([
            'plan_sha256' => str_repeat('a', 64),
            'rows' => 1,
            'writes' => ['durable_adds' => 1, 'durable_updates' => 0, 'map_updates' => 1],
        ]);

        $admin->previewGalleryAdoption($settings, $reader, $adopter, $lock);

        self::assertTrue($this->lastJson['success'] ?? false);
        self::assertSame(str_repeat('a', 64), $this->lastJson['plan_sha256'] ?? null);
    }

    public function testGalleryAdoptionApplyUsesExactPreviewIdentityAndConfirmation(): void
    {
        $sha = str_repeat('b', 64);
        $upload = ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/private/upload', 'size' => 10];
        [$admin, $settings] = $this->harness(['enabled' => 0, 'source_instance' => 'artaz'], [
            'session_id' => 'csrf',
            'expected_plan_sha256' => $sha,
            'confirm' => 'ADOPT_EXISTING_GALLERY',
            '__files' => ['gallery_adoption_plan' => $upload],
        ]);
        $reader = $this->createMock(GalleryAdoptionPlanReader::class);
        $adopter = $this->createMock(LegacyGalleryAdopter::class);
        $lock = $this->createMock(LockHelper::class);
        $plan = ['sha256' => $sha, 'header' => ['rows_count' => 1], 'rows' => [[]]];

        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');
        $reader->expects($this->once())->method('read')->with($upload)->willReturn($plan);
        $adopter->expects($this->once())->method('apply')->with($plan, $sha, 'ADOPT_EXISTING_GALLERY')->willReturn([
            'plan_sha256' => $sha, 'rows' => 1, 'writes' => 2,
        ]);

        $admin->applyGalleryAdoption($settings, $reader, $adopter, $lock);

        self::assertTrue($this->lastJson['success'] ?? false);
        self::assertSame(2, $this->lastJson['writes'] ?? null);
    }

    public function testGalleryAdoptionRefusesWhenModuleIsEnabledBeforeLockOrRead(): void
    {
        [$admin, $settings] = $this->harness(['enabled' => 1], ['session_id' => 'csrf']);
        $reader = $this->createMock(GalleryAdoptionPlanReader::class);
        $adopter = $this->createMock(LegacyGalleryAdopter::class);
        $lock = $this->createMock(LockHelper::class);
        $lock->expects($this->never())->method('acquire');
        $reader->expects($this->never())->method('read');
        $adopter->expects($this->never())->method('preview');

        $admin->previewGalleryAdoption($settings, $reader, $adopter, $lock);

        self::assertFalse($this->lastJson['success'] ?? true);
        self::assertStringContainsStringIgnoringCase('выключ', (string) ($this->lastJson['error'] ?? ''));
    }

    public function testGalleryAdoptionRefusesWrongStoredSourceBeforeLockOrRead(): void
    {
        [$admin, $settings] = $this->harness(['enabled' => 0, 'source_instance' => 'other'], [
            'session_id' => 'csrf',
        ]);
        $reader = $this->createMock(GalleryAdoptionPlanReader::class);
        $adopter = $this->createMock(LegacyGalleryAdopter::class);
        $lock = $this->createMock(LockHelper::class);
        $lock->expects($this->never())->method('acquire');
        $reader->expects($this->never())->method('read');
        $adopter->expects($this->never())->method('preview');

        $admin->previewGalleryAdoption($settings, $reader, $adopter, $lock);

        self::assertFalse($this->lastJson['success'] ?? true);
        self::assertStringContainsStringIgnoringCase('source', (string) ($this->lastJson['error'] ?? ''));
    }

    public function testGalleryAdoptionRefusesMissingSessionAndBusySharedLock(): void
    {
        [$withoutSession, $settings] = $this->harness(['enabled' => 0]);
        $reader = $this->createMock(GalleryAdoptionPlanReader::class);
        $adopter = $this->createMock(LegacyGalleryAdopter::class);
        $lock = $this->createMock(LockHelper::class);
        $lock->expects($this->never())->method('acquire');
        $reader->expects($this->never())->method('read');
        $withoutSession->previewGalleryAdoption($settings, $reader, $adopter, $lock);
        self::assertFalse($this->lastJson['success'] ?? true);

        [$busy, $busySettings] = $this->harness(['enabled' => 0, 'source_instance' => 'artaz'], ['session_id' => 'csrf']);
        $busyLock = $this->createMock(LockHelper::class);
        $busyLock->expects($this->once())->method('acquire')->willReturn(false);
        $busyLock->expects($this->never())->method('release');
        $busy->previewGalleryAdoption($busySettings, $reader, $adopter, $busyLock);
        self::assertFalse($this->lastJson['success'] ?? true);
        self::assertStringContainsStringIgnoringCase('замок', (string) ($this->lastJson['error'] ?? ''));
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
            ['source_instance' => 'grundfos'],
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

    // ------------------------------------------------------------------
    // D. Успешное сохранение — симметрия с message_error
    // ------------------------------------------------------------------

    /**
     * До этого пункта успешное сохранение молчало: в шаблоне была ветка только для message_error, у
     * message_success не было ни отправителя, ни приёмника — оператор нажимал «Сохранить» и не видел
     * подтверждения (симметрия с уже существующей веткой ошибки — брифа §D).
     *
     * KILL-ПРОБА: убрать assign('message_success', …) из saveSettings-ветки fetch() → тест красный.
     */
    public function testSuccessfulSaveAssignsMessageSuccess(): void
    {
        [$admin, $settings] = $this->harness(
            [],
            ['core_url' => 'https://core.example', 'channel_code' => '42', 'token' => 'secret']
        );

        $this->fetchPage($admin, $settings);

        $this->assertNotEmpty($this->assigned['message_success'] ?? '', 'оператору подтверждается сохранение');
        $this->assertArrayNotHasKey('message_error', $this->assigned);
    }

    /** Отклонённое сохранение (невалидный канал) не должно одновременно рисовать «успех». */
    public function testRejectedSaveDoesNotAssignMessageSuccess(): void
    {
        [$admin, $settings] = $this->harness(
            ['core_url' => 'https://core.example', 'channel_code' => '42', 'token' => 't'],
            ['core_url' => 'https://core.example', 'channel_code' => 'site-a', 'token' => '']
        );

        $this->fetchPage($admin, $settings);

        $this->assertArrayNotHasKey('message_success', $this->assigned);
        $this->assertNotEmpty($this->assigned['message_error'] ?? '');
    }

    /** GET (страница без сохранения) не рисует ни успех, ни ошибку. */
    public function testPageLoadWithoutSaveAssignsNeitherMessage(): void
    {
        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example']);

        $this->fetchPage($admin, $settings);

        $this->assertArrayNotHasKey('message_success', $this->assigned);
        $this->assertArrayNotHasKey('message_error', $this->assigned);
    }

    /** @dataProvider invalidSourceInstances */
    public function testUnsafeSourceInstanceIsRejectedWithoutPartialSave(string $sourceInstance): void
    {
        [$admin, $settings] = $this->harness(
            ['core_url' => 'https://core.example', 'channel_code' => '42', 'token' => 't'],
            ['core_url' => 'https://core.example', 'channel_code' => '42', 'source_instance' => $sourceInstance, 'token' => '']
        );
        $settings->expects($this->never())->method('set');

        $this->fetchPage($admin, $settings);

        $this->assertStringContainsString('source_instance', (string) ($this->assigned['message_error'] ?? ''));
    }

    /** @return array<string, array{string}> */
    public function invalidSourceInstances(): array
    {
        return [
            'path' => ['../grundfos'],
            'space' => ['grund fos'],
            'uppercase' => ['Grundfos'],
            'slash' => ['sat/grundfos'],
        ];
    }

    public function testSafeSourceInstanceIsSavedAndRendered(): void
    {
        [$admin, $settings] = $this->harness(
            ['source_instance' => 'grundfos'],
            ['core_url' => 'https://core.example', 'channel_code' => '42', 'source_instance' => 'grundfos', 'token' => 'secret']
        );
        $saved = [];
        $settings->expects($this->once())->method('set')->willReturnCallback(static function ($key, $value) use (&$saved): void {
            $saved = (array) $value;
        });

        $this->fetchPage($admin, $settings);

        $this->assertSame('grundfos', $saved['source_instance'] ?? null);
        $this->assertSame('grundfos', $this->assigned['coresync']['source_instance'] ?? null);
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

    // ------------------------------------------------------------------
    // C. Чек-лист готовности — прокладка страницы к Contract::readiness()
    // ------------------------------------------------------------------

    /**
     * fetch() обязан прокинуть ТЕ ЖЕ настройки, что читает раннер/кнопки, в Contract::readiness() —
     * страница не пересобирает собственную копию проверок.
     *
     * KILL-ПРОБА: убрать assign('readiness', …) из fetch() → тест красный.
     */
    public function testReadinessChecklistIsAssignedFromCurrentSettings(): void
    {
        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example']); // остальное пусто

        $this->fetchPage($admin, $settings);

        $this->assertSame(
            Contract::readiness(['core_url' => 'https://core.example']),
            $this->assigned['readiness'] ?? null
        );
        $this->assertNotEmpty($this->assigned['readiness'], 'неполные настройки → есть незакрытые пункты');
    }

    /** Полностью заполненные (валидные) настройки → пустой чек-лист. */
    public function testReadinessChecklistIsEmptyForCompleteSettings(): void
    {
        $complete = [
            'core_url' => 'https://core.example',
            'channel_code' => '42',
            'token' => 'secret',
            'source_instance' => 'grundfos',
            'storefront_base_url' => 'https://shop.example',
            'currency_map' => ['UAH' => 1],
            'enabled' => 1,
        ];
        [$admin, $settings] = $this->harness($complete);

        $this->fetchPage($admin, $settings);

        $this->assertSame([], $this->assigned['readiness'] ?? null);
    }

    // ------------------------------------------------------------------
    // F. Версия модуля с диска + исход последнего самообновления
    // ------------------------------------------------------------------

    /**
     * Версия читается С ДИСКА (Init/module.json), НЕ из channel_satellites.module_version ядра —
     * та колонка пишется только церемонией «Подключить» и не обновляется (D-CORESYNC-VERSION-
     * INVENTORY-STALE, вне scope этого этапа). Источник тот же файл, что читают Describer и Updater.
     *
     * KILL-ПРОБА: убрать assign('module_version', …) из fetch() → тест красный.
     */
    public function testModuleVersionIsReadFromDiskManifest(): void
    {
        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example']);

        $this->fetchPage($admin, $settings);

        $moduleJson = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/Init/module.json'),
            true
        );
        $this->assertSame($moduleJson['version'], $this->assigned['module_version'] ?? null);
    }

    /**
     * Исход самообновления — Updater::SETTINGS_UPDATE_STATUS_KEY, пишет Updater::recordOutcome().
     * Поля status/from/to/at/error передаются странице как есть (шаблон решает, как их показать).
     */
    public function testUpdateStatusIsAssignedWhenPresent(): void
    {
        $outcome = ['status' => 'updated', 'from' => '1.5.0', 'to' => '1.5.1', 'at' => '2026-08-08 12:00:00', 'error' => null];
        [$admin, $settings] = $this->harness(
            ['core_url' => 'https://core.example'],
            [],
            [Updater::SETTINGS_UPDATE_STATUS_KEY => $outcome]
        );

        $this->fetchPage($admin, $settings);

        $this->assertSame($outcome, $this->assigned['update_status'] ?? null);
    }

    /**
     * Ключа настроек может не быть вовсе (самообновление ещё не срабатывало) — нормальное состояние,
     * НЕ ошибка. Контроллер передаёт null как есть; текст «обновлений не применялось» рисует шаблон.
     *
     * KILL-ПРОБА: если бы контроллер подставлял пустой массив/строку вместо null, этот тест поймал бы
     * подмену (assertNull различает null и []/'').
     */
    public function testUpdateStatusIsNullWhenNeverAttempted(): void
    {
        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example']);

        $this->fetchPage($admin, $settings);

        $this->assertArrayHasKey('update_status', $this->assigned, 'ключ обязан присутствовать даже когда обновлений не было');
        $this->assertNull($this->assigned['update_status']);
    }

    // ------------------------------------------------------------------
    // B. Панель состояния — ОДНА функция сборки данных для fetch()/status()/runNow()/cancel()
    // ------------------------------------------------------------------

    /**
     * Первый показ (fetch → tpl) обязан нести те же поля, что и AJAX-обновление (status →
     * поллер): job, ownership (владение по типам), images (картинки, товарные+категорийные
     * суммарно), ping_url, channel_link. Иначе первый показ и обновление поллером разъедутся
     * (брифа формулировка §B) — гейт этого пункта: ОДНА сборка данных на оба пути (см. следующий тест).
     *
     * KILL-ПРОБА: убрать assign('panel', …) из fetch() → тест красный.
     */
    public function testFetchAssignsPanelWithOwnershipAndImageCounts(): void
    {
        $map = $this->createMock(CoreSyncMapEntity::class);
        $map->expects($this->any())->method('countByType')->willReturn(
            array_merge(array_fill_keys(Contract::ENTITY_TYPES, 0), ['product' => 7, 'variant' => 12])
        );
        $images = $this->createMock(CoreSyncImagesEntity::class);
        $images->expects($this->any())->method('countByState')->willReturn(['pending' => 1, 'done' => 4, 'failed' => 0]);
        $categoryImages = $this->createMock(CoreSyncCategoryImagesEntity::class);
        $categoryImages->expects($this->any())->method('countByState')->willReturn(['pending' => 0, 'done' => 2, 'failed' => 1]);

        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example', 'channel_code' => '42']);
        $admin->fetch(
            $settings,
            $this->factoryWithJobs(['map' => $map, 'images' => $images, 'categoryImages' => $categoryImages]),
            $this->createMock(BackendCurrenciesHelper::class),
            $this->createMock(Languages::class)
        );

        $panel = $this->assigned['panel'] ?? null;
        $this->assertIsArray($panel);
        $this->assertSame(7, $panel['ownership']['product'] ?? null);
        $this->assertSame(12, $panel['ownership']['variant'] ?? null);
        $this->assertSame(0, $panel['ownership']['category'] ?? null, 'типы без строк карты — нулём, не отсутствуют');
        // товарные + категорийные СУММАРНО (брифа §B: «суммарно по товарным и категорийным»).
        $this->assertSame(1, $panel['images']['pending'] ?? null);
        $this->assertSame(6, $panel['images']['done'] ?? null);
        $this->assertSame(1, $panel['images']['failed'] ?? null);
        $this->assertArrayHasKey('job', $panel);
        $this->assertArrayHasKey('ping_url', $panel);
        $this->assertArrayHasKey('channel_link', $panel);
    }

    /**
     * Начальные данные панели вставляются внутрь `<script>`. error_message приходит из внешнего
     * контура (HTTP/manifest/apply), поэтому обычный json_encode оставляет `</script>` живым и
     * позволяет преждевременно закрыть тег. Контроллер обязан подготовить script-safe JSON через
     * JSON_HEX_*; шаблон не должен сам угадывать флаги сериализации.
     *
     * KILL-ПРОБА: заменить JSON_HEX_* на обычный json_encode() → в panel_json появится буквальный
     * `</script>` и первый assert покраснеет; соседняя проверка payload остаётся зелёной.
     */
    public function testInitialPanelJsonCannotCloseScriptTag(): void
    {
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->expects($this->any())->method('findLatest')->willReturn((object) [
            'id' => 7,
            'status' => Contract::STATUS_FAILED,
            'phase' => Contract::PHASE_DOWNLOAD,
            'snapshot_version' => 3,
            'files_total' => 1,
            'files_done' => 0,
            'cancel_requested' => 0,
            'error_message' => '</script><script>alert("xss")</script>',
            'started_at' => '2026-08-08 12:00:00',
            'finished_at' => '2026-08-08 12:01:00',
        ]);

        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example']);
        $admin->fetch(
            $settings,
            $this->factoryWithJobs(['jobs' => $jobs]),
            $this->createMock(BackendCurrenciesHelper::class),
            $this->createMock(Languages::class)
        );

        $json = (string) ($this->assigned['panel_json'] ?? '');
        $this->assertStringNotContainsStringIgnoringCase('</script>', $json);
        $this->assertStringContainsString('\\u003C\/script\\u003E', $json);
        $this->assertSame(
            '</script><script>alert("xss")</script>',
            $this->assigned['panel']['job']['error_message'] ?? null,
            'данные панели не теряются: экранируется только script-транспорт'
        );
    }

    /** core_url/channel_code оба заданы → ссылка на карточку канала собирается. */
    public function testPanelChannelLinkBuiltWhenBothFieldsPresent(): void
    {
        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example/', 'channel_code' => '42']);
        $this->fetchPage($admin, $settings);

        $this->assertSame('https://core.example/channels/42', $this->assigned['panel']['channel_link'] ?? null);
    }

    /** Один из двух (или оба) не заданы → ссылки нет (не собираем кривой урл наполовину). */
    public function testPanelChannelLinkNullWhenChannelCodeMissing(): void
    {
        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example']);
        $this->fetchPage($admin, $settings);

        $this->assertArrayHasKey('channel_link', $this->assigned['panel'] ?? []);
        $this->assertNull($this->assigned['panel']['channel_link']);
    }

    /**
     * status() (AJAX-поллер) обязан отвечать ТОЙ ЖЕ формой, что и fetch()→panel — job/ownership/
     * images/ping_url/channel_link, а не только job (как до этой правки). Ровно то расхождение
     * первого показа и обновления, которое брифа §B требует исключить.
     *
     * KILL-ПРОБА: status() продолжает отдавать только job → assertArrayHasKey('ownership', …) красный.
     */
    public function testStatusReturnsSamePanelShapeAsFetch(): void
    {
        $map = $this->createMock(CoreSyncMapEntity::class);
        $map->expects($this->any())->method('countByType')->willReturn(
            array_merge(array_fill_keys(Contract::ENTITY_TYPES, 0), ['product' => 3])
        );

        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example', 'channel_code' => '7']);
        $admin->status($this->factoryWithJobs(['map' => $map]), $settings);

        $this->assertTrue($this->lastJson['success'] ?? null);
        $this->assertArrayHasKey('job', $this->lastJson);
        $this->assertSame(3, $this->lastJson['ownership']['product'] ?? null);
        $this->assertArrayHasKey('images', $this->lastJson);
        $this->assertSame('https://core.example/channels/7', $this->lastJson['channel_link'] ?? null);
    }

    /** runNow() отвечает той же формой (одна функция рендера JS кормится одинаковым payload). */
    public function testRunNowReturnsSamePanelShapeAsStatus(): void
    {
        [$admin, $settings] = $this->harness(['enabled' => 1]);
        $runner = $this->createMock(SyncRunner::class);
        $runner->expects($this->once())->method('run');

        $admin->runNow($runner, $this->factoryWithJobs(), $settings);

        $this->assertTrue($this->lastJson['success'] ?? null);
        $this->assertArrayHasKey('job', $this->lastJson);
        $this->assertArrayHasKey('ownership', $this->lastJson);
        $this->assertArrayHasKey('images', $this->lastJson);
    }

    /** cancel() — тот же контракт ответа (панель перерисовывается той же функцией после отмены). */
    public function testCancelReturnsSamePanelShapeAsStatus(): void
    {
        [$admin, $settings] = $this->harness(['core_url' => 'https://core.example']);

        $admin->cancel($this->factoryWithJobs(), $settings);

        $this->assertTrue($this->lastJson['success'] ?? null);
        $this->assertArrayHasKey('job', $this->lastJson);
        $this->assertArrayHasKey('ownership', $this->lastJson);
        $this->assertArrayHasKey('images', $this->lastJson);
    }
}
