<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Entity\Entity;
use Okay\Core\OkayContainer\Reference\ServiceReference;
use Okay\Modules\Format\CoreSync\Controllers\PingController;
use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use PHPUnit\Framework\TestCase;

/**
 * Модуль ProductDocuments снимают с витрин. Контейнер модуля (Init/services.php) обязан собирать
 * Applier, SyncRunner и аргументы PingController::ping без классов
 * Okay\Modules\Format\ProductDocuments\*: автозагрузчик теста их не находит, а сервис, которого нет
 * ни в модуле, ни в ядре Okay, - ServiceNotFoundException у OkayContainer::get. Граф разрешается по
 * правилам OkayContainer (SR на определение модуля или сервис ядра) и MethodDI (сервис, Entity,
 * класс). Конфиг ядра здесь не исполняется (он вызывает внешние библиотеки), поэтому сервисом ядра
 * считается существующий класс или интерфейс вне Okay\Modules: сервисы других модулей в контейнере
 * есть, только пока модуль установлен.
 * Kill-проба: вернуть SR(ProductDocumentsHelper::class) в services.php -> красный.
 */
class ServicesWithoutProductDocumentsTest extends TestCase
{
    private const FORBIDDEN = 'Okay\\Modules\\Format\\ProductDocuments\\';

    /** @var callable|null */
    private $guard;
    /** @var array<string, array<string, mixed>> */
    private $module = [];
    /** @var array<string, bool> */
    private $resolved = [];

    protected function setUp(): void
    {
        $this->guard = static function (string $class): void {
            if (strpos(ltrim($class, '\\'), self::FORBIDDEN) === 0) {
                throw new \LogicException('ProductDocuments недоступен: ' . $class);
            }
        };
        spl_autoload_register($this->guard, true, true);
        $this->module = require dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/Init/services.php';
    }

    protected function tearDown(): void
    {
        if ($this->guard !== null) {
            spl_autoload_unregister($this->guard);
        }
        parent::tearDown();
    }

    public function testApplierSyncRunnerAndPingControllerResolveWithoutProductDocuments(): void
    {
        $this->resolveService(Applier::class, 'Applier');
        $this->resolveService(SyncRunner::class, 'SyncRunner');
        $ping = new \ReflectionMethod(PingController::class, 'ping');
        foreach ($ping->getParameters() as $parameter) {
            $this->resolveMethodArgument($parameter, 'PingController::ping');
        }
        $this->assertArrayHasKey(Applier::class, $this->resolved);
    }

    public function testNoModuleServiceMentionsProductDocuments(): void
    {
        foreach ($this->module as $id => $entry) {
            $this->assertStringNotContainsString(self::FORBIDDEN, (string) $id);
            $this->assertStringNotContainsString(self::FORBIDDEN, (string) ($entry['class'] ?? ''));
            foreach ((array) ($entry['arguments'] ?? []) as $argument) {
                if ($argument instanceof ServiceReference) {
                    $this->assertStringNotContainsString(self::FORBIDDEN, $this->referenceId($argument), (string) $id);
                }
            }
        }
    }

    private function resolveService(string $id, string $path): void
    {
        $this->assertStringNotContainsString(self::FORBIDDEN, $id, $path);
        if (isset($this->resolved[$id])) {
            return;
        }
        if (!isset($this->module[$id])) {
            $this->assertTrue($this->isCoreService($id), 'Service not found: ' . $id . ' (' . $path . ')');
            $this->resolved[$id] = true;

            return;
        }
        $this->resolved[$id] = true;
        $class = (string) $this->module[$id]['class'];
        $this->assertTrue(class_exists($class), $id . ' service class does not exist: ' . $class);
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $this->assertStringNotContainsString(self::FORBIDDEN, $type->getName(), $class);
                }
            }
        }
        foreach ((array) ($this->module[$id]['arguments'] ?? []) as $argument) {
            if ($argument instanceof ServiceReference) {
                $this->resolveService($this->referenceId($argument), $path . ' > ' . $id);
            }
        }
    }

    private function resolveMethodArgument(\ReflectionParameter $parameter, string $path): void
    {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return;
        }
        $name = $type->getName();
        $this->assertStringNotContainsString(self::FORBIDDEN, $name, $path);
        if (isset($this->module[$name]) || $this->isCoreService($name)) {
            $this->resolveService($name, $path);

            return;
        }
        $this->assertTrue(
            is_subclass_of($name, Entity::class) || class_exists($name) || $parameter->isDefaultValueAvailable(),
            'Missing argument $' . $parameter->getName() . ' in ' . $path
        );
    }

    private function isCoreService(string $id): bool
    {
        return strpos(ltrim($id, '\\'), 'Okay\\Modules\\') !== 0 && (class_exists($id) || interface_exists($id));
    }

    private function referenceId(ServiceReference $reference): string
    {
        return (string) $reference->getName();
    }
}
