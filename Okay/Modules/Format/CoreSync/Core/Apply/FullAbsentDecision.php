<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Modules\Format\CoreSync\Core\Contract;

/** The full apply decides absence against the map as it exists after the row phases. */
final class FullAbsentDecision
{
    /** @var array<string, int> */
    private $absentProducts;
    /** @var int */
    private $mappedCount;
    /** @var string */
    private $policy;

    private function __construct(array $absentProducts, int $mappedCount, string $policy)
    {
        $this->absentProducts = $absentProducts;
        $this->mappedCount = $mappedCount;
        $this->policy = $policy;
    }

    /**
     * @param array<string, int> $mappedProducts
     * @param string[] $seenProducts
     */
    public static function fromProducts(array $mappedProducts, array $seenProducts, ?string $policy): self
    {
        $seen = array_fill_keys($seenProducts, true);

        return new self(array_diff_key($mappedProducts, $seen), count($mappedProducts), $policy ?? 'out_of_stock');
    }

    /** @return array<string, int> */
    public function absentProducts(): array
    {
        return $this->absentProducts;
    }

    public function mappedCount(): int
    {
        return $this->mappedCount;
    }

    public function isHeld(): bool
    {
        return $this->mappedCount > 0
            && (count($this->absentProducts) / $this->mappedCount) > Contract::ABSENT_MAX_RATIO;
    }

    public function policy(): string
    {
        return $this->policy;
    }
}
