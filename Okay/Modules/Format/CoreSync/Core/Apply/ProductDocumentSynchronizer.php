<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncDocumentsEntity;
use Okay\Modules\Format\ProductDocuments\Entities\ProductDocumentsEntity;
use Okay\Modules\Format\ProductDocuments\Helpers\ProductDocumentsHelper;

class ProductDocumentSynchronizer
{
    /** @var CoreSyncDocumentsEntity */
    private $ownership;
    /** @var ProductDocumentsEntity */
    private $documents;
    /** @var ProductDocumentsHelper|null */
    private $helper;
    /** @var string */
    private $documentsDir;
    private $previewTargets = [];

    public function __construct(
        EntityFactory $entityFactory,
        ?ProductDocumentsHelper $helper,
        Settings $settings,
        Config $config
    ) {
        $this->ownership = $entityFactory->get(CoreSyncDocumentsEntity::class);
        $this->documents = $entityFactory->get(ProductDocumentsEntity::class);
        $this->helper = $helper;
        $relative = trim((string) $settings->get('documents_dir'));
        if ($relative === '') {
            $relative = 'files/documents/';
        }
        $this->documentsDir = rtrim($config->root_dir, '/') . '/' . trim($relative, '/') . '/';
    }

    /** @param array<int, array<string, mixed>> $desired */
    public function reconcile(
        string $productExternalId,
        int $productLocalId,
        array $desired,
        string $stagingDir
    ): void {
        if ($this->helper === null) {
            throw new \LogicException('Offline PDF planner cannot write');
        }
        foreach ($this->decisions($productExternalId, $desired) as $decision) {
            $document = $decision['document'];
            $owned = $decision['owned'];
            if ($decision['action'] === 'unlink') {
                $this->removeOwned($owned);
                continue;
            }

            $filename = $decision['filename'];
            $source = rtrim($stagingDir, '/') . '/' . $filename;
            $this->publishVerified($source, $filename, (string) $document['sha256'], (int) $document['bytes']);
            if ($decision['action'] === 'replace') {
                $this->removeOwned($owned);
                $owned = null;
            }
            if ($owned === null) {
                $documentId = (int) $this->documents->add([
                    'product_id' => $productLocalId,
                    'filename' => $filename,
                    'title' => (string) $document['title'],
                    'hash' => (string) $document['sha256'],
                ]);
                if ($documentId < 1) {
                    throw new ManifestException('Не удалось создать ProductDocuments row');
                }
                $ownershipId = $this->ownership->add($this->ownershipFields(
                    $productExternalId,
                    $productLocalId,
                    $documentId,
                    $document
                ));
                if (!$ownershipId) {
                    $this->helper->deleteProductDocument($documentId);
                    throw new ManifestException('Не удалось создать CoreSync document ownership');
                }
                // Okay assigns the new row ID when an inserted position is zero.
                if ((int) $document['position'] === 0) {
                    $this->ownership->update((int) $ownershipId, ['position' => 0]);
                }
                $created = $this->ownership->findOne(['id' => (int) $ownershipId]);
                if (!is_object($created) || (int) $created->position !== (int) $document['position']) {
                    throw new ManifestException('CoreSync document ownership position mismatch');
                }
            } else {
                $this->documents->update((int) $owned->document_id, [
                    'product_id' => $productLocalId,
                    'filename' => $filename,
                    'title' => (string) $document['title'],
                    'hash' => (string) $document['sha256'],
                ]);
                $this->ownership->update((int) $owned->id, $this->ownershipFields(
                    $productExternalId,
                    $productLocalId,
                    (int) $owned->document_id,
                    $document
                ));
            }
        }
    }

    /**
     * Inspect managed document links and local bytes without invoking a writer or downloader.
     * An absent staged PDF leaves the proposed action conditional, even if the target exists.
     *
     * @param array<int, array<string, mixed>> $desired
     * @return array<int, array<string, mixed>>
     */
    public function preview(
        string $productExternalId,
        int $productLocalId,
        array $desired,
        string $stagingDir,
        bool $carryAssetEffects = false
    ): array {
        $report = [];
        $priorAssets = 'verified';
        foreach ($this->decisions($productExternalId, $desired) as $decision) {
            $document = $decision['document'];
            $owned = $decision['owned'];
            $source = 'not_required';
            $target = 'not_required';
            if ($document !== null) {
                $filename = $decision['filename'];
                $sha = (string) $document['sha256'];
                $bytes = (int) $document['bytes'];
                $source = $this->pdfStatus(rtrim($stagingDir, '/') . '/' . $filename, $sha, $bytes, 'staged_verified', 'unknown');
                $target = ($carryAssetEffects ? ($this->previewTargets[$sha] ?? null) : null)
                    ?? $this->pdfStatus($this->documentsDir . $filename, $sha, $bytes, 'reuse_verified', 'publish_if_verified');
            }
            $assetCondition = $priorAssets;
            if ($assetCondition !== 'blocked') {
                if ($source === 'invalid' || $target === 'invalid') {
                    $assetCondition = 'blocked';
                } elseif ($source === 'unknown') {
                    $assetCondition = 'conditional';
                }
            }
            $priorAssets = $assetCondition;
            if ($carryAssetEffects && $assetCondition !== 'blocked') {
                if ($owned !== null && in_array($decision['action'], ['unlink', 'replace'], true)) {
                    // Native helper may delete the physical file after its last reference.
                    $this->previewTargets[(string) $owned->sha256] = 'after_prior_unlink_unknown';
                }
                if ($document !== null) {
                    $this->previewTargets[$sha] = $assetCondition === 'verified'
                        ? 'reuse_after_prior_verified_publish' : 'after_prior_publish_if_verified';
                }
            }
            $report[] = [
                'product_external_id' => $productExternalId,
                'product_local_id' => $productLocalId,
                'document_external_id' => $decision['external_id'],
                'document_local_id' => $owned === null ? null : (int) $owned->document_id,
                'ownership_local_id' => $owned === null ? null : (int) $owned->id,
                'action' => $decision['action'],
                'reason' => $decision['reason'],
                'previous_sha256' => $owned->sha256 ?? null,
                'desired_sha256' => $document['sha256'] ?? null,
                'desired_position' => $document['position'] ?? null,
                'source_bytes' => $source,
                'target_bytes' => $target,
                'asset_condition' => $assetCondition,
            ];
        }

        return $report;
    }

    /**
     * @param array<int, array<string, mixed>> $desired
     * @return \Generator<int, array<string, mixed>>
     */
    private function decisions(string $productExternalId, array $desired): \Generator
    {
        $existing = [];
        foreach ($this->ownership->find(['product_external_id' => $productExternalId]) as $row) {
            $existing[(string) $row->document_external_id] = $row;
        }
        $seen = [];
        foreach ($desired as $document) {
            $externalId = (string) $document['external_id'];
            $seen[$externalId] = true;
            $owned = $existing[$externalId] ?? null;
            if ($owned === null) {
                $action = 'attach';
                $reason = 'no_ownership';
            } elseif ((string) $owned->sha256 !== (string) $document['sha256']) {
                $action = 'replace';
                $reason = 'digest_changed';
            } else {
                $action = 'retain';
                $reason = 'matching_digest';
            }
            yield [
                'external_id' => $externalId,
                'document' => $document,
                'owned' => $owned,
                'filename' => 'document-' . $document['sha256'] . '.pdf',
                'action' => $action,
                'reason' => $reason,
            ];
        }
        foreach ($existing as $externalId => $owned) {
            if (!isset($seen[$externalId])) {
                yield [
                    'external_id' => (string) $externalId,
                    'document' => null,
                    'owned' => $owned,
                    'filename' => null,
                    'action' => 'unlink',
                    'reason' => 'omitted_from_desired',
                ];
            }
        }
    }

    private function pdfStatus(string $path, string $sha256, int $bytes, string $verified, string $missing): string
    {
        if (!is_file($path)) {
            return $missing;
        }

        try {
            $this->assertPdf($path, $sha256, $bytes);
        } catch (ManifestException $failure) {
            return 'invalid';
        }

        return $verified;
    }

    /** @param object $owned */
    private function removeOwned($owned): void
    {
        $this->ownership->delete((int) $owned->id);
        $this->helper->deleteProductDocument((int) $owned->document_id);
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    private function ownershipFields(string $productExternalId, int $productLocalId, int $documentId, array $document): array
    {
        return [
            'product_external_id' => $productExternalId,
            'product_local_id' => $productLocalId,
            'document_external_id' => (string) $document['external_id'],
            'document_id' => $documentId,
            'sha256' => (string) $document['sha256'],
            'bytes' => (int) $document['bytes'],
            'title' => (string) $document['title'],
            'position' => (int) $document['position'],
            'original_filename' => (string) $document['original_filename'],
            'language' => $document['language'],
            'document_type' => $document['type'],
        ];
    }

    private function publishVerified(string $source, string $filename, string $sha256, int $bytes): void
    {
        $this->assertPdf($source, $sha256, $bytes);
        if (!is_dir($this->documentsDir) && !@mkdir($this->documentsDir, 0775, true) && !is_dir($this->documentsDir)) {
            throw new ManifestException('Не удалось создать documents directory');
        }
        $target = $this->documentsDir . $filename;
        if (is_file($target)) {
            $this->assertPdf($target, $sha256, $bytes);
            @chmod($target, 0644);
            return;
        }
        $temp = tempnam($this->documentsDir, '.coresync-document-');
        if ($temp === false || !copy($source, $temp)) {
            if (is_string($temp)) { @unlink($temp); }
            throw new ManifestException('Не удалось подготовить document temp file');
        }
        try {
            $this->assertPdf($temp, $sha256, $bytes);
            if (!@chmod($temp, 0644)) {
                throw new ManifestException('Не удалось выставить безопасные права document');
            }
            if (!@rename($temp, $target)) {
                throw new ManifestException('Не удалось атомарно опубликовать document');
            }
        } finally {
            if (is_file($temp)) { @unlink($temp); }
        }
    }

    private function assertPdf(string $path, string $sha256, int $bytes): void
    {
        if (!is_file($path) || filesize($path) !== $bytes || $bytes < 1
            || $bytes > Contract::PRODUCT_DOCUMENT_MAX_BYTES
            || hash_file('sha256', $path) !== $sha256) {
            throw new ManifestException('PDF size/digest verification failed');
        }
        $handle = @fopen($path, 'rb');
        $signature = is_resource($handle) ? fread($handle, 5) : false;
        if (is_resource($handle)) { fclose($handle); }
        if ($signature !== '%PDF-') {
            throw new ManifestException('Document is not a PDF by content');
        }
    }
}
