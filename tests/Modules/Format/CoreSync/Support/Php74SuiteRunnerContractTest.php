<?php

namespace Tests\Modules\Format\CoreSync\Support;

use PHPUnit\Framework\TestCase;

class Php74SuiteRunnerContractTest extends TestCase
{
    public function testRunnerFilesDeclareThePhp74CompatibilityGate(): void
    {
        $root = dirname(__DIR__, 5);
        $suiteDir = $root . '/tools/php74-suite';

        $this->assertFileExists($suiteDir . '/run.sh');
        $this->assertFileExists($suiteDir . '/bootstrap.php');
        $this->assertFileExists($suiteDir . '/phpunit.php');
        $this->assertFileExists($suiteDir . '/phpunit.xml');
        $this->assertFileExists($suiteDir . '/php.ini');
        $this->assertFileExists($suiteDir . '/skip-list.txt');
        $this->assertFileExists($suiteDir . '/skip-list.php74-cli.txt');

        $runner = (string) file_get_contents($suiteDir . '/run.sh');
        $this->assertStringContainsString('php:7.4-cli', $runner);
        $this->assertStringContainsString('artazru-web:latest', $runner);
        $this->assertStringContainsString('--class targeted', $runner);
        $this->assertStringContainsString('--oneoff', $runner);
        $this->assertStringContainsString('PHPRC=/workspace/tools/php74-suite/php.ini', $runner);

        $skipList = (string) file_get_contents($suiteDir . '/skip-list.txt');
        $this->assertStringContainsString('# One relative test-class path per line', $skipList);
    }

    public function testSuiteConfigBuilderMaterializesEverySelectedClassAndHonorsExplicitSkips(): void
    {
        $root = dirname(__DIR__, 5);
        $builderFile = $root . '/tools/php74-suite/SuiteConfigBuilder.php';
        $skipFile = sys_get_temp_dir() . '/coresync-php74-suite-skip-' . getmypid() . '.txt';
        $configFile = sys_get_temp_dir() . '/coresync-php74-suite-config-' . getmypid() . '.xml';

        $this->assertFileExists($builderFile);
        require_once $builderFile;

        file_put_contents(
            $skipFile,
            "tests/Modules/Format/CoreSync/Support/Php74SuiteRunnerContractTest.php\tcontract probe\n"
        );

        try {
            $builder = new \CoreSyncPhp74Suite\SuiteConfigBuilder($root);
            $plan = $builder->build($skipFile, $configFile);
            $xml = (string) file_get_contents($configFile);

            $this->assertCount(1, $plan['skipped']);
            $this->assertSame('contract probe', $plan['skipped'][0]['reason']);
            $this->assertStringNotContainsString('Php74SuiteRunnerContractTest.php', $xml);
            $this->assertStringContainsString('tests/Modules/Format/CoreSync/AckServiceTest.php', $xml);
            $this->assertSame(
                count($plan['selected']),
                substr_count($xml, '<file>'),
                'each selected class must become an unambiguous PHPUnit suite entry'
            );
        } finally {
            @unlink($skipFile);
            @unlink($configFile);
        }
    }
}
