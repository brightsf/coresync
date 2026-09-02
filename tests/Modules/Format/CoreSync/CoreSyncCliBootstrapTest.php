<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Console\ReapplyCommand;
use Okay\Modules\Format\CoreSync\Console\RebindCommand;
use Okay\Modules\Format\CoreSync\Console\StatusCommand;
use PHPUnit\Framework\TestCase;

class CoreSyncCliBootstrapTest extends TestCase
{
    public function testBinScriptIsPhp74ValidAndRegistersCommandsFromTheModuleDepth(): void
    {
        $script = dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/bin/coresync';

        self::assertFileExists($script);
        self::assertSame(dirname(__DIR__, 4), dirname(dirname($script), 5));

        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));

        $source = (string) file_get_contents($script);
        self::assertStringContainsString('$projectRoot = dirname(__DIR__, 5);', $source);
        self::assertStringContainsString('$app->registerCommand(RebindCommand::class);', $source);
        self::assertStringContainsString('$app->registerCommand(ReapplyCommand::class);', $source);
        self::assertStringContainsString('$app->registerCommand(StatusCommand::class);', $source);
        self::assertStringNotContainsString('error_reporting(false)', $source);
        self::assertStringNotContainsString("ini_set('display_errors', false)", $source);
    }

    public function testLiveOkayApplicationRegistersAllThreeModuleCommands(): void
    {
        $hostRoot = (string) getenv('CORESYNC_OKAY_ROOT');
        $suiteBootstrap = dirname(__DIR__, 4) . '/tools/php74-suite/bootstrap.php';
        $probe = 'require ' . var_export($suiteBootstrap, true) . ';'
            . 'chdir(' . var_export($hostRoot, true) . ');'
            . 'require ' . var_export($hostRoot . '/vendor/snowplow/referer-parser/php/src/Snowplow/RefererParser/Config/ConfigReaderInterface.php', true) . ';'
            . 'require ' . var_export($hostRoot . '/vendor/snowplow/referer-parser/php/src/Snowplow/RefererParser/Config/ConfigFileReaderTrait.php', true) . ';'
            . 'require ' . var_export($hostRoot . '/vendor/snowplow/referer-parser/php/src/Snowplow/RefererParser/Config/JsonConfigReader.php', true) . ';'
            . '$app=new Okay\\Core\\Console\\Application();'
            . '$app->registerCommand(' . RebindCommand::class . '::class);'
            . '$app->registerCommand(' . ReapplyCommand::class . '::class);'
            . '$app->registerCommand(' . StatusCommand::class . '::class);'
            . 'foreach (["coresync:rebind","coresync:reapply","coresync:status"] as $name) {'
            . 'if (!$app->has($name)) { fwrite(STDERR,"missing ".$name); exit(3); }'
            . '}'
            . 'echo "registered=coresync:rebind,coresync:reapply,coresync:status";';

        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertSame('registered=coresync:rebind,coresync:reapply,coresync:status', implode("\n", $output));
    }
}
