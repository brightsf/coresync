<?php

namespace Tests\Modules\Format\CoreSync\Support;

use PHPUnit\Framework\TestCase;

class Php74SuiteRunnerContractTest extends TestCase
{
    public function testRunnerSelectsTheRequestedCompatibilityRuntimeAndPropagatesSafeRunExit(): void
    {
        $suiteDir = $this->suiteDirectoryOrSkip();
        $probeRoot = sys_get_temp_dir() . '/coresync-php74-runner-probe-' . getmypid();
        $okayRoot = $probeRoot . '/okay';
        $contractsRoot = $probeRoot . '/contracts';
        $safeRun = $probeRoot . '/safe-run.sh';

        mkdir($okayRoot . '/vendor/composer', 0777, true);
        mkdir($okayRoot . '/Okay', 0777, true);
        mkdir($contractsRoot, 0777, true);
        file_put_contents($okayRoot . '/vendor/composer/autoload_classmap.php', "<?php return [];\n");
        file_put_contents($contractsRoot . '/satellite-product-i18n-v3.json', "{}\n");
        file_put_contents(
            $safeRun,
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$@\" > \"\$CORESYNC_RUNNER_CAPTURE\"\nexit \"\$CORESYNC_RUNNER_EXIT\"\n"
        );

        $cases = [
            ['argument' => '', 'image' => 'artazru-web:latest', 'skip' => 'tools/php74-suite/skip-list.txt'],
            ['argument' => '--runtime=7.4-cli', 'image' => 'php:7.4-cli', 'skip' => 'tools/php74-suite/skip-list.php74-cli.txt'],
            ['argument' => '--runtime=8.0', 'image' => 'coresatellites-web:latest', 'skip' => 'tools/php74-suite/skip-list.txt'],
        ];

        try {
            foreach ($cases as $index => $case) {
                $capture = $probeRoot . '/args-' . $index . '.txt';
                $command = sprintf(
                    'CORESYNC_OKAY_ROOT=%s CORESYNC_CONTRACTS_ROOT=%s CORESYNC_SAFE_RUN=%s CORESYNC_RUNNER_CAPTURE=%s CORESYNC_RUNNER_EXIT=23 bash %s %s 2>&1',
                    escapeshellarg($okayRoot),
                    escapeshellarg($contractsRoot),
                    escapeshellarg($safeRun),
                    escapeshellarg($capture),
                    escapeshellarg($suiteDir . '/run.sh'),
                    escapeshellarg($case['argument'])
                );
                $output = [];
                exec($command, $output, $status);

                $this->assertSame(23, $status, implode("\n", $output));
                $arguments = file($capture, FILE_IGNORE_NEW_LINES);
                $this->assertIsArray($arguments);
                $this->assertSame(
                    ['--class', 'targeted', '--oneoff', '--', 'docker', 'run', '--rm'],
                    array_slice($arguments, 0, 7)
                );
                $this->assertContains('PHPRC=/workspace/tools/php74-suite/php.ini', $arguments);
                $this->assertContains('CORESYNC_SKIP_LIST=' . $case['skip'], $arguments);
                $this->assertContains('SATELLITE_I18N_CONTRACT=/contracts/satellite-product-i18n-v3.json', $arguments);
                $this->assertContains($contractsRoot . ':/contracts:ro', $arguments);
                $this->assertContains($case['image'], $arguments);
            }
        } finally {
            foreach (glob($probeRoot . '/args-*.txt') ?: [] as $capture) {
                @unlink($capture);
            }
            @unlink($safeRun);
            @unlink($contractsRoot . '/satellite-product-i18n-v3.json');
            @rmdir($contractsRoot);
            @unlink($okayRoot . '/vendor/composer/autoload_classmap.php');
            @rmdir($okayRoot . '/vendor/composer');
            @rmdir($okayRoot . '/vendor');
            @rmdir($okayRoot . '/Okay');
            @rmdir($okayRoot);
            @rmdir($probeRoot);
        }
    }

    public function testRunnerRejectsMissingSharedContractBeforeCallingSafeRun(): void
    {
        $suiteDir = $this->suiteDirectoryOrSkip();
        $probeRoot = sys_get_temp_dir() . '/coresync-php74-runner-missing-contract-' . getmypid();
        $okayRoot = $probeRoot . '/okay';
        $contractsRoot = $probeRoot . '/contracts';
        $safeRun = $probeRoot . '/safe-run.sh';
        $capture = $probeRoot . '/safe-run-called.txt';

        mkdir($okayRoot . '/vendor/composer', 0777, true);
        mkdir($okayRoot . '/Okay', 0777, true);
        mkdir($contractsRoot, 0777, true);
        file_put_contents($okayRoot . '/vendor/composer/autoload_classmap.php', "<?php return [];\n");
        file_put_contents(
            $safeRun,
            "#!/usr/bin/env bash\nprintf 'called\\n' > \"\$CORESYNC_RUNNER_CAPTURE\"\nexit 0\n"
        );

        try {
            $command = sprintf(
                'CORESYNC_OKAY_ROOT=%s CORESYNC_CONTRACTS_ROOT=%s CORESYNC_SAFE_RUN=%s CORESYNC_RUNNER_CAPTURE=%s bash %s 2>&1',
                escapeshellarg($okayRoot),
                escapeshellarg($contractsRoot),
                escapeshellarg($safeRun),
                escapeshellarg($capture),
                escapeshellarg($suiteDir . '/run.sh')
            );
            $output = [];
            exec($command, $output, $status);

            $this->assertSame(2, $status, implode("\n", $output));
            $this->assertStringContainsString('shared contract is unavailable', implode("\n", $output));
            $this->assertFileDoesNotExist($capture, 'safe-run must not be called without the shared contract');
        } finally {
            @unlink($capture);
            @unlink($safeRun);
            @unlink($okayRoot . '/vendor/composer/autoload_classmap.php');
            @rmdir($okayRoot . '/vendor/composer');
            @rmdir($okayRoot . '/vendor');
            @rmdir($okayRoot . '/Okay');
            @rmdir($okayRoot);
            @rmdir($contractsRoot);
            @rmdir($probeRoot);
        }
    }

    public function testRunnerNeverFallsBackToBareDockerAfterSafeRunExitSix(): void
    {
        $suiteDir = $this->suiteDirectoryOrSkip();
        $probeRoot = sys_get_temp_dir() . '/coresync-php74-runner-no-bare-' . getmypid();
        $okayRoot = $probeRoot . '/okay';
        $contractsRoot = $probeRoot . '/contracts';
        $binRoot = $probeRoot . '/bin';
        $safeRun = $probeRoot . '/safe-run.sh';
        $bareCapture = $probeRoot . '/bare-docker-called.txt';

        mkdir($okayRoot . '/vendor/composer', 0777, true);
        mkdir($okayRoot . '/Okay', 0777, true);
        mkdir($contractsRoot, 0777, true);
        mkdir($binRoot, 0777, true);
        file_put_contents($okayRoot . '/vendor/composer/autoload_classmap.php', "<?php return [];\n");
        file_put_contents($contractsRoot . '/satellite-product-i18n-v3.json', "{}\n");
        file_put_contents($safeRun, "#!/usr/bin/env bash\nexit 6\n");
        file_put_contents(
            $binRoot . '/docker',
            "#!/usr/bin/env bash\nprintf 'called\\n' > \"\$CORESYNC_BARE_CAPTURE\"\nexit 41\n"
        );
        chmod($binRoot . '/docker', 0777);

        try {
            $command = sprintf(
                'PATH=%s CORESYNC_OKAY_ROOT=%s CORESYNC_CONTRACTS_ROOT=%s CORESYNC_SAFE_RUN=%s CORESYNC_BARE_CAPTURE=%s SAFE_RUN_ALLOW_BARE=forbidden bash %s 2>&1',
                escapeshellarg($binRoot . ':' . getenv('PATH')),
                escapeshellarg($okayRoot),
                escapeshellarg($contractsRoot),
                escapeshellarg($safeRun),
                escapeshellarg($bareCapture),
                escapeshellarg($suiteDir . '/run.sh')
            );
            $output = [];
            exec($command, $output, $status);

            $this->assertSame(6, $status, implode("\n", $output));
            $this->assertFileDoesNotExist($bareCapture, 'EXIT=6 must propagate without a direct docker invocation');
        } finally {
            @unlink($bareCapture);
            @unlink($binRoot . '/docker');
            @unlink($safeRun);
            @unlink($contractsRoot . '/satellite-product-i18n-v3.json');
            @unlink($okayRoot . '/vendor/composer/autoload_classmap.php');
            @rmdir($binRoot);
            @rmdir($contractsRoot);
            @rmdir($okayRoot . '/vendor/composer');
            @rmdir($okayRoot . '/vendor');
            @rmdir($okayRoot . '/Okay');
            @rmdir($okayRoot);
            @rmdir($probeRoot);
        }
    }

    public function testSuiteConfigBuilderMaterializesEverySelectedClassAndHonorsExplicitSkips(): void
    {
        $suiteDir = $this->suiteDirectoryOrSkip();
        $root = dirname($suiteDir, 2);
        $builderFile = $suiteDir . '/SuiteConfigBuilder.php';
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

    private function suiteDirectoryOrSkip(): string
    {
        $suiteDir = dirname(__DIR__, 5) . '/tools/php74-suite';
        if (!is_dir($suiteDir)) {
            $this->markTestSkipped('tools/php74-suite is not synchronized into the Okay host repository');
        }

        return $suiteDir;
    }
}
