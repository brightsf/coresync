<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

/** V5 validates stable option references, then reuses the frozen v4 document/product boundary. */
class ProductV5Validator
{
    /** @var ProductV4Validator */
    private $v4;

    public function __construct(?ProductV4Validator $v4 = null)
    {
        $this->v4 = $v4 ?? new ProductV4Validator();
    }

    /** @param array<string, mixed> $line @param array<int, string> $languages @param array<string, int> $localLanguageIds @param array<string, int> $documents */
    public function validate(array $line, array $languages, array $localLanguageIds, array $documents, ?string $rawJson = null): array
    {
        $pairs = $line['data']['feature_values'] ?? null;
        if (!is_array($pairs) || !Contract::isList($pairs)) {
            throw new ManifestException('product v5 feature_values invalid');
        }
        $normalized = $line;
        foreach ($pairs as $index => $pair) {
            if (!is_array($pair) || !is_string($pair['feature_external_id'] ?? null)
                || !is_string($pair['value'] ?? null)) {
                throw new ManifestException('product v5 feature pair invalid');
            }
            $featureId = $pair['feature_external_id'];
            $synthetic = strpos($featureId, 'attr-') === 0;
            $required = $synthetic ? ['feature_external_id', 'value']
                : ['feature_external_id', 'option_external_id', 'value'];
            $actual = array_keys($pair);
            sort($actual, SORT_STRING);
            sort($required, SORT_STRING);
            if ($actual !== $required || ($synthetic && strlen($featureId) < 6)
                || (!$synthetic && (preg_match('/\A[1-9][0-9]*\z/', $featureId) !== 1
                    || !is_string($pair['option_external_id'])
                    || preg_match('/\A[1-9][0-9]*\z/', $pair['option_external_id']) !== 1))) {
                throw new ManifestException('product v5 feature identity invalid');
            }
            if (!$synthetic) { unset($normalized['data']['feature_values'][$index]['option_external_id']); }
        }
        $normalizedRaw = null;
        if ($rawJson !== null) {
            $native = json_decode($rawJson);
            if (!is_object($native) || !isset($native->data) || !is_object($native->data)
                || !isset($native->data->feature_values) || !is_array($native->data->feature_values)) {
                throw new ManifestException('product v5 JSON list/object shape invalid');
            }
            foreach ($native->data->feature_values as $pair) {
                if (!is_object($pair)) { throw new ManifestException('product v5 feature pair must be object'); }
                unset($pair->option_external_id);
            }
            $normalizedRaw = json_encode($native);
        }
        $this->v4->validate($normalized, $languages, $localLanguageIds, $documents, $normalizedRaw);
        return $line;
    }
}
