<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\Languages;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\BackgroundRun;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Describer;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use Okay\Modules\Format\CoreSync\Core\ManifestValidator;
use Okay\Modules\Format\CoreSync\Core\ProductContentLanguageCatalog;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\RecordingBackgroundRun;

/**
 * Describe для режима "только по запросу ядра" (coresync-trigger-module). Ядро (coresync-trigger-core)
 * читает способность `capabilities.core_triggered_runs` = ровно {"version":"1.0.0"} и поле верхнего
 * уровня `run_trigger` ("cron" | "core"). Способность объявляется, только если витрина может
 * запустить фоновый процесс; поле режима есть всегда.
 */
class DescriberCoreTriggerTest extends TestCase
{
    const PHP = '/opt/php74/bin/php';

    public function testCapableStorefrontAdvertisesCoreTriggeredRunsAndCurrentMode(): void
    {
        $out = $this->describer(['run_trigger' => 'core', 'php_cli_path' => self::PHP], $this->capable())->describe();

        $this->assertSame(['version' => '1.0.0'], $out['capabilities']['core_triggered_runs']);
        $this->assertSame('core', $out['run_trigger']);
    }

    /** Режим по умолчанию и явный cron: поле "cron", способность всё равно объявлена (она про "может"). */
    public function testCronModeIsReportedAndCapabilityStaysIndependentOfMode(): void
    {
        foreach ([[], ['run_trigger' => 'cron'], ['run_trigger' => 'unknown']] as $override) {
            $out = $this->describer($override + ['php_cli_path' => self::PHP], $this->capable())->describe();

            $this->assertSame('cron', $out['run_trigger']);
            $this->assertSame(['version' => '1.0.0'], $out['capabilities']['core_triggered_runs']);
        }
    }

    /**
     * Acceptance 3: нет возможности фонового запуска -> способность не объявляется.
     * Kill-проба: объявлять способность без проверки capability() -> красный.
     */
    public function testIncapableStorefrontDoesNotAdvertiseTheCapability(): void
    {
        $incapable = $this->capable();
        $incapable->functions = ['proc_open' => false, 'exec' => false];

        $out = $this->describer(['run_trigger' => 'core', 'php_cli_path' => self::PHP], $incapable)->describe();
        $this->assertArrayNotHasKey('core_triggered_runs', $out['capabilities']);
        $this->assertSame('core', $out['run_trigger']);

        $out = $this->describer(['run_trigger' => 'core'], null)->describe();
        $this->assertArrayNotHasKey('core_triggered_runs', $out['capabilities']);
    }

    /** Новое поле не ломает форму describe для старого ядра: обязательные ключи и схема прежние. */
    public function testDescribeStillSatisfiesTheVendoredSchemaWithTopLevelRunTrigger(): void
    {
        $schema = json_decode((string) file_get_contents(
            dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/schema/v1/describe.schema.json'
        ), true);
        $out = $this->describer(['run_trigger' => 'core', 'php_cli_path' => self::PHP], $this->capable())->describe();

        $this->assertTrue($schema['additionalProperties'], 'схема describe допускает новые поля верхнего уровня');
        foreach ($schema['required'] as $key) {
            $this->assertArrayHasKey($key, $out);
        }
        $this->assertContains($out['run_trigger'], Contract::RUN_TRIGGERS);
    }

    private function capable(): RecordingBackgroundRun
    {
        $lock = $this->createMock(LockHelper::class);
        $run = new RecordingBackgroundRun($this->createMock(Config::class), $lock);
        $run->versions = [self::PHP => RecordingBackgroundRun::cliBanner()];

        return $run;
    }

    /**
     * @param array<string, mixed> $override
     */
    private function describer(array $override, ?BackgroundRun $backgroundRun): Describer
    {
        $cfg = array_merge([
            'core_url' => 'https://core.example', 'channel_code' => '42', 'token' => 'secret',
            'storefront_base_url' => 'https://shop.example',
        ], $override);
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) use ($cfg) {
            return $key === Contract::SETTINGS_KEY ? $cfg : null;
        });
        $languages = $this->createMock(Languages::class);
        $languages->method('getAllLanguages')->willReturn([(object) ['href_lang' => 'ru', 'id' => 1]]);

        return new class($settings, new ManifestValidator(), new ProductContentLanguageCatalog($languages), $backgroundRun) extends Describer {
            protected function routePath(string $routeName, string $slugToken): string
            {
                return '/' . $routeName . '/' . $slugToken . '/';
            }
        };
    }
}
