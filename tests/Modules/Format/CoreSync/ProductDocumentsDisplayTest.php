<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\Design;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStoreFiles;
use Okay\Modules\Format\CoreSync\Extenders\ProductDocumentsDesignExtender;
use Okay\Modules\Format\CoreSync\Init\Init;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\InMemoryDocumentStoreRepository;

require_once __DIR__ . '/Support/InMemoryDocumentStoreRepository.php';

/**
 * Показ документов из ядра: шаблон модуля по умолчанию (рендер настоящим Smarty витрины), путь
 * переопределения в теме по коду Okay, вызов в стандартной теме модификацией module.json, блок
 * админки без форм записи.
 */
class ProductDocumentsDisplayTest extends TestCase
{
    private const SHA = '8e504a494dea96d9428d320880bde5acabe77353ff81da1f9615c732e36b4360';

    /** @var list<string> */
    private $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        parent::tearDown();
    }

    public function testDefaultTemplateRendersDownloadLinks(): void
    {
        $html = $this->render($this->moduleDir() . '/design/html/product_documents.tpl', [
            $this->document('Паспорт насоса', 72),
            $this->document('Схема <b>"А"</b>', 3 * 1048576),
        ]);

        $this->assertStringContainsString(
            '<a class="coresync-documents__link" href="https://shop.example/files/coresync/documents/' . self::SHA
            . '.pdf" download="Паспорт насоса.pdf">Паспорт насоса</a>',
            $html
        );
        $this->assertStringContainsString('1 КБ', $html);
        $this->assertStringContainsString('3,0 МБ', $html);
        $this->assertStringContainsString('Схема &lt;b&gt;&quot;А&quot;&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<style', $html, 'шаблон по умолчанию без своих стилей');
        $this->assertStringNotContainsString('style=', $html);
    }

    public function testDefaultTemplateRendersNothingForEmptyList(): void
    {
        $html = $this->render($this->moduleDir() . '/design/html/product_documents.tpl', []);

        $this->assertSame('', trim($html));
    }

    /** Acceptance 5: админ-блок только показывает, форм записи нет. */
    public function testAdminBlockIsReadOnly(): void
    {
        $html = $this->render($this->moduleDir() . '/Backend/design/html/product_documents_admin.tpl', [
            $this->document('Паспорт насоса', 72),
        ]);

        $this->assertStringContainsString('Документы из ядра', $html);
        $this->assertStringContainsString('управляется ядром', $html);
        $this->assertStringContainsString('/files/coresync/documents/' . self::SHA . '.pdf', $html);
        foreach (['<form', '<input', '<button', '<select', '<textarea', 'fn_delete', 'type="file"'] as $writeControl) {
            $this->assertStringNotContainsString($writeControl, $html);
        }
        $this->assertSame('', trim($this->render($this->moduleDir() . '/Backend/design/html/product_documents_admin.tpl', [])));
    }

    /**
     * Acceptance 5, путь переопределения (замер Okay 4.2.1, Okay/Core/Modules/AbstractInit::addFrontBlock):
     * сначала design/<активная тема>/modules/<Vendor>/<Module>/html/<файл>, иначе design/html модуля.
     * Тест фиксирует это правило по коду ядра хоста и проверяет, что модуль регистрирует блок именно
     * через addFrontBlock с именем файла, а не абсолютным путём.
     */
    public function testThemeTemplateOverridesModuleTemplateThroughFrontBlock(): void
    {
        $method = new \ReflectionMethod(\Okay\Core\Modules\AbstractInit::class, 'addFrontBlock');
        $lines = file((string) $method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        $compact = (string) preg_replace('/\s+/', '', $body);
        $this->assertStringContainsString(
            "/design/'.\$this->frontTemplateConfig->getTheme().'/modules/'.\$this->vendor.'/'.\$this->moduleName.'/html/'",
            $compact
        );
        $this->assertMatchesRegularExpression('/if\(file_exists\(\$themeModuleHtmlDir\.\$blockTplFile\)\)\{\$blockTplFile=\$themeModuleHtmlDir/', $compact);
        $this->assertStringContainsString("'design/html/'", $compact);

        $init = $this->recordingInit('1.9.0');
        $init->registerForTest();

        $this->assertSame([['coresync_product_documents', 'product_documents.tpl']], $init->front);
        $this->assertSame([['product_custom_block', 'product_documents_admin.tpl']], $init->backend);
        $this->assertFileExists($this->moduleDir() . '/design/html/product_documents.tpl');
        $this->assertFileExists($this->moduleDir() . '/Backend/design/html/product_documents_admin.tpl');

        // Своя разметка темы вместо шаблона модуля: тот же список, другой вывод.
        $theme = $this->tmpDir() . '/design/mytheme/modules/Format/CoreSync/html/product_documents.tpl';
        mkdir(dirname($theme), 0775, true);
        file_put_contents($theme, '{foreach $coresync_product_documents as $d}<p class="theme">{$d->title|escape}</p>{/foreach}');
        $this->assertSame('<p class="theme">Паспорт</p>', $this->render($theme, [$this->document('Паспорт', 10)]));
    }

    public function testBlocksWaitForDocumentStoreSchema(): void
    {
        $init = $this->recordingInit('1.8.4');
        $init->registerForTest();

        $this->assertSame([], $init->front);
        $this->assertSame([], $init->backend);
    }

    /** Вызов блока в стандартную тему ставится модификацией module.json перед вкладками карточки. */
    public function testModuleJsonInsertsBlockCallIntoStandardTheme(): void
    {
        $module = json_decode((string) file_get_contents($this->moduleDir() . '/Init/module.json'), true);
        $change = $module['modifications']['front'][0]['changes'][0];

        $this->assertSame('product.tpl', $module['modifications']['front'][0]['file']);
        $snippet = (string) file_get_contents($this->moduleDir() . '/design/html/' . $change['appendBefore']);
        $this->assertSame('{get_design_block block="coresync_product_documents"}', trim($snippet));
        $standard = dirname(__DIR__, 4) . '/design/okay_shop/html/product.tpl';
        $this->assertStringContainsString($change['find'], (string) file_get_contents($standard));
    }

    public function testExtenderAssignsDocumentsOfCurrentProductOnly(): void
    {
        $repo = new InMemoryDocumentStoreRepository('/nonexistent');
        $repo->files[self::SHA] = ['bytes' => 72, 'path' => 'ignored/../../path'];
        $repo->documents = [
            1 => ['id' => 1, 'product_external_id' => '101', 'product_local_id' => 11, 'document_external_id' => '9001',
                'title' => 'Паспорт', 'position' => 1, 'language' => null, 'document_type' => null, 'sha256' => self::SHA],
            2 => ['id' => 2, 'product_external_id' => '101', 'product_local_id' => 11, 'document_external_id' => '9002',
                'title' => 'Сертификат', 'position' => 0, 'language' => null, 'document_type' => null, 'sha256' => self::SHA],
            3 => ['id' => 3, 'product_external_id' => '102', 'product_local_id' => 12, 'document_external_id' => '9003',
                'title' => 'Чужой', 'position' => 0, 'language' => null, 'document_type' => null, 'sha256' => self::SHA],
        ];
        $config = $this->getMockBuilder(Config::class)->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
        $extender = new ProductDocumentsDesignExtender($repo, new ProductDocumentStoreFiles($config));
        $assigned = [];
        $design = $this->createMock(Design::class);
        $design->method('getVar')->willReturnCallback(static function (string $name) {
            return $name === 'product' ? (object) ['id' => 11] : null;
        });
        $design->method('assign')->willReturnCallback(static function ($name, $value) use (&$assigned) {
            $assigned[$name] = $value;
        });

        $extender->assignProductDocuments($design);

        $documents = $assigned['coresync_product_documents'];
        $this->assertSame(['Сертификат', 'Паспорт'], array_column(array_map('get_object_vars', $documents), 'title'));
        $this->assertSame('files/coresync/documents/' . self::SHA . '.pdf', $documents[0]->path, 'путь из sha256, не из строки');
        $this->assertSame('Сертификат.pdf', $documents[0]->download_name);
    }

    /** @dataProvider downloadNames */
    public function testDownloadNameIsSafeFileName(string $title, string $expected): void
    {
        $this->assertSame($expected, ProductDocumentsDesignExtender::downloadName($title));
    }

    /** @return array<string, array{0:string,1:string}> */
    public function downloadNames(): array
    {
        return [
            'plain' => ['Паспорт насоса', 'Паспорт насоса.pdf'],
            'already pdf' => ['manual.PDF', 'manual.PDF'],
            'path chars' => ['../etc/passwd', '.. etc passwd.pdf'],
            'only bad chars' => ['///', 'document.pdf'],
        ];
    }

    /** @param list<object> $documents */
    private function render(string $template, array $documents): string
    {
        $smarty = new \Smarty();
        $smarty->setCompileDir($this->tmpDir());
        $smarty->assign('rootUrl', 'https://shop.example');
        $smarty->assign('coresync_product_documents', $documents);

        return $smarty->fetch('file:' . $template);
    }

    private function document(string $title, int $bytes): object
    {
        return (object) ([
            'title' => $title,
            'path' => 'files/coresync/documents/' . self::SHA . '.pdf',
            'download_name' => ProductDocumentsDesignExtender::downloadName($title),
            'bytes' => $bytes,
        ] + ProductDocumentsDesignExtender::size($bytes));
    }

    private function recordingInit(string $appliedVersion): Init
    {
        return new class($appliedVersion) extends Init {
            /** @var list<array{0:string,1:string}> */
            public $front = [];
            /** @var list<array{0:string,1:string}> */
            public $backend = [];
            /** @var string */
            private $version;

            public function __construct(string $version)
            {
                $this->version = $version;
            }

            public function registerForTest(): void
            {
                $this->registerProductDocumentBlocks();
            }

            protected function dictionaryIdentitySchemaVersion(): ?string
            {
                return $this->version;
            }

            protected function addFrontBlock($blockName, $blockTplFile, $callback = null)
            {
                $this->front[] = [$blockName, $blockTplFile];
            }

            protected function addBackendBlock($blockName, $blockTplFile, $callback = null)
            {
                $this->backend[] = [$blockName, $blockTplFile];
            }
        };
    }

    private function moduleDir(): string
    {
        return dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync';
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/coresync_display_' . uniqid('', true);
        mkdir($dir, 0775, true);
        $this->dirs[] = $dir;

        return $dir;
    }
}
