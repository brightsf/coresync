<?php

namespace Okay\Modules\Format\CoreSync\Extenders;

use Okay\Core\Design;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStoreFiles;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStoreRepository;

/**
 * Документы из ядра для карточки товара (сайт и админка): название, ссылка на файл хранилища,
 * имя для сохранения и размер. Только чтение; ссылка строится из sha256, а не из сохранённого пути.
 */
class ProductDocumentsDesignExtender
{
    const DESIGN_VAR = 'coresync_product_documents';

    /** @var ProductDocumentStoreRepository */
    private $repository;
    /** @var ProductDocumentStoreFiles */
    private $files;

    public function __construct(ProductDocumentStoreRepository $repository, ProductDocumentStoreFiles $files)
    {
        $this->repository = $repository;
        $this->files = $files;
    }

    /** Колбэк блока карточки: кладёт в шаблон список документов текущего товара (или пустой). */
    public function assignProductDocuments(Design $design): void
    {
        $product = $design->getVar('product');
        $productId = is_object($product) ? (int) ($product->id ?? 0) : 0;
        $design->assign(self::DESIGN_VAR, $productId > 0 ? $this->documents($productId) : []);
    }

    /** @return list<object> title, path, download_name, bytes, size_value, size_unit (kb|mb) */
    public function documents(int $productLocalId): array
    {
        $documents = [];
        foreach ($this->repository->displayDocuments($productLocalId) as $row) {
            $title = (string) $row['title'];
            $documents[] = (object) ([
                'title' => $title,
                'path' => $this->files->relativePath((string) $row['sha256']),
                'download_name' => self::downloadName($title),
                'bytes' => (int) $row['bytes'],
            ] + self::size((int) $row['bytes']));
        }

        return $documents;
    }

    /** "<название>.pdf" без символов, которые ломают имя файла. */
    public static function downloadName(string $title): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $title));
        if ($name === '') {
            $name = 'document';
        }

        return preg_match('/\.pdf\z/i', $name) === 1 ? $name : $name . '.pdf';
    }

    /** Размер для подписи; единицу подписывает шаблон. @return array{size_value:string,size_unit:string} */
    public static function size(int $bytes): array
    {
        if ($bytes >= 1048576) {
            return ['size_value' => number_format($bytes / 1048576, 1, ',', ''), 'size_unit' => 'mb'];
        }

        return ['size_value' => (string) max(1, (int) ceil($bytes / 1024)), 'size_unit' => 'kb'];
    }
}
