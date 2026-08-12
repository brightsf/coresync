<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Modules\Format\CoreSync\Core\Contract;

/**
 * Strict boundary for product/variant legacy Okay identifiers supplied by snapshot v2.
 * Validation is deliberately pure so an entire row can be checked before the first map write.
 */
class ProductSourceIdentityValidator
{
    /** @var string[] */
    private $identityKeys = ['namespace', 'instance', 'entity', 'id'];

    /**
     * @param array<string, mixed> $data
     */
    public function hasIdentifiedProduct(array $data): bool
    {
        return array_key_exists('source_identity', $data) && $data['source_identity'] !== null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{product_id:int,variants:array<string,int>}
     * @throws \InvalidArgumentException
     */
    public function validate(array $data, string $expectedInstance): array
    {
        if (!Contract::isValidSourceInstance($expectedInstance)) {
            throw new \InvalidArgumentException('configured source_instance is invalid');
        }

        $productId = $this->validateIdentity(
            $data['source_identity'] ?? null,
            $expectedInstance,
            Contract::ENTITY_PRODUCT
        );
        $variants = $data['variants'] ?? null;
        if (!is_array($variants) || !Contract::isList($variants)) {
            throw new \InvalidArgumentException('identified product variants must be a list');
        }

        $resolved = [];
        $localIds = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                throw new \InvalidArgumentException('identified product variant must be an object');
            }
            $externalId = $variant['external_id'] ?? null;
            if (!is_string($externalId) || $externalId === '' || isset($resolved[$externalId])) {
                throw new \InvalidArgumentException('identified variant external_id is missing or duplicated');
            }
            if (!array_key_exists('source_identity', $variant) || $variant['source_identity'] === null) {
                throw new \InvalidArgumentException('identified variant source_identity is required');
            }
            $localId = $this->validateIdentity(
                $variant['source_identity'],
                $expectedInstance,
                Contract::ENTITY_VARIANT
            );
            if (isset($localIds[$localId])) {
                throw new \InvalidArgumentException('identified variants resolve to duplicate local id');
            }
            $resolved[$externalId] = $localId;
            $localIds[$localId] = true;
        }

        return ['product_id' => $productId, 'variants' => $resolved];
    }

    /** @param mixed $value */
    private function validateIdentity($value, string $expectedInstance, string $expectedEntity): int
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('source_identity must be an object');
        }
        foreach ($this->identityKeys as $key) {
            if (!array_key_exists($key, $value) || !is_string($value[$key])) {
                throw new \InvalidArgumentException('source_identity fields must be strings');
            }
        }
        if (count($value) !== count($this->identityKeys)) {
            throw new \InvalidArgumentException('source_identity has missing or extra keys');
        }
        if ($value['namespace'] !== Contract::SOURCE_IDENTITY_NAMESPACE
            || $value['instance'] !== $expectedInstance
            || $value['entity'] !== $expectedEntity
            || preg_match('/\A[1-9][0-9]*\z/', $value['id']) !== 1) {
            throw new \InvalidArgumentException('source_identity does not match configured Okay source');
        }

        $id = (int) $value['id'];
        if ($id <= 0 || (string) $id !== $value['id']) {
            throw new \InvalidArgumentException('source_identity id is outside local integer range');
        }

        return $id;
    }
}
