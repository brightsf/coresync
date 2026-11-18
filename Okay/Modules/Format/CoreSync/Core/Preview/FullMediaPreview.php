<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use Okay\Modules\Format\CoreSync\Core\Apply\FullAbsentDecision;
use Okay\Modules\Format\CoreSync\Core\Contract;

/** Records media decisions at the native apply boundaries without invoking a fetcher. */
final class FullMediaPreview
{
    private $entries = [];
    private $absent;
    private $skips = [];
    private $reusedImages = [];
    private $phase = 'preflight';
    private $lastError;
    private $retainedVariantPrices = [];
    private $identityConflicts = [];
    private $variantInventory = [];

    public function recordVariantInventory(string $externalId, int $localVariantId, ?array $rows): void
    {
        $this->variantInventory[] = [
            'variant_external_id' => $externalId,
            'variant_local_id' => $localVariantId,
            'action' => $rows === null ? 'hold' : 'replace',
            'rows' => $rows,
        ];
    }

    public function variantInventory(): array { return $this->variantInventory; }


    public function setPhase(string $phase): void { $this->phase = $phase; }
    public function phase(): string { return $this->phase; }
    public function recordError(string $message): void { $this->lastError = $message; }
    public function lastError(): ?string { return $this->lastError; }

    public function recordIdentityConflict(array $conflict): void
    {
        $this->identityConflicts[] = $conflict;
    }

    public function identityConflicts(): array { return $this->identityConflicts; }

    public function recordPriceHold(string $externalId, object $variant): void
    {
        $this->retainedVariantPrices[] = [
            'variant_external_id' => $externalId, 'variant_local_id' => (int) $variant->id,
            'product_local_id' => (int) $variant->product_id, 'price' => $variant->price ?? null,
            'currency_id' => $variant->currency_id ?? null, 'reason' => 'explicit_price_hold',
        ];
    }

    public function retainedVariantPrices(): array { return $this->retainedVariantPrices; }

    public function recordProductImage(object $row, string $action, string $reason, ?string $oldDisposition = null): void
    {
        if ($action === 'reuse') {
            $this->reusedImages[(int) $row->id] = true;
        } elseif ($action === 'retain' && isset($this->reusedImages[(int) $row->id])) {
            return; // adoption already described the final action for this durable row
        }
        $this->entries[] = [
            'kind' => 'product_image',
            'product_external_id' => (string) $row->product_external_id,
            'product_local_id' => (int) $row->product_local_id,
            'attachment_id' => isset($row->image_id) ? (int) $row->image_id : null,
            'local_filename' => $row->filename ?? null,
            'url_sha256' => (string) $row->url_hash,
            'content_sha256' => $row->content_sha256 ?? null,
            'action' => $action,
            'reason' => $reason,
            'old_attachment_on_verified_replacement' => $oldDisposition,
            'source_bytes' => $action === 'download' ? 'unknown' : 'not_requested',
        ];
    }

    public function recordCategoryImage(object $row, string $action, string $reason): void
    {
        $this->entries[] = [
            'kind' => 'category_image',
            'category_external_id' => (string) $row->category_external_id,
            'category_local_id' => (int) $row->category_local_id,
            'source_id' => (string) $row->source_id,
            'local_filename' => $row->filename ?? null,
            'content_sha256' => (string) $row->sha256,
            'action' => $action,
            'reason' => $reason,
            'source_bytes' => $action === 'download' ? 'unknown' : 'not_requested',
        ];
    }

    public function recordDocuments(array $rows): void
    {
        foreach ($rows as $row) {
            $this->entries[] = ['kind' => 'document'] + $row;
        }
    }

    public function recordSkip(string $entity, string $sourceId, int $localId): void
    {
        $this->skips[] = [
            'entity' => $entity, 'external_id' => $sourceId,
            'local_id' => $localId, 'reason' => 'applied_hash_matches',
        ];
    }

    public function skips(): array
    {
        return $this->skips;
    }

    public function recordAbsent(FullAbsentDecision $decision): void
    {
        $this->absent = [
            'mapped_products' => $decision->mappedCount(),
            'products_by_external_id' => (object) $decision->absentProducts(),
            'policy' => $decision->policy(),
            'threshold' => Contract::ABSENT_MAX_RATIO,
            'held' => $decision->isHeld(),
            'hold_reason' => $decision->isHeld() ? 'absent_ratio' : null,
        ];
    }

    public function absent(): ?array
    {
        return $this->absent;
    }

    public function entries(): array
    {
        return $this->entries;
    }
}
