<?php

namespace Okay\Modules\Format\CoreSync\Core\Exceptions;

/** A fail-fast feature reconciliation conflict with the affected identities. */
final class FeatureLifecycleConflict extends ManifestException
{
    private $context;

    public function __construct(string $message, array $context, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    public function reportFields(): array
    {
        return [
            'feature_conflicts'=>1,
            'feature_conflict_context'=>$this->context,
        ];
    }

    public function jobMessage(): string
    {
        $sample = $this->context;
        foreach ($sample as $key => $value) {
            if (is_array($value)) {
                if (count($value) > 5) {
                    $sample[$key . '_total'] = count($value);
                }
                $sample[$key] = array_map(static function ($item) {
                    return is_string($item) ? mb_substr($item, 0, 128, 'UTF-8') : $item;
                }, array_slice($value, 0, 5));
            } elseif (is_string($value)) {
                $sample[$key] = mb_substr($value, 0, 128, 'UTF-8');
            }
        }
        $details = json_encode(['feature_conflicts'=>1, 'feature_conflict_context'=>$sample],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (mb_strlen($details, 'UTF-8') > 1499) {
            $details = '{"feature_conflicts":1,"feature_conflict_context":"see report stats"}';
        }
        return mb_substr($this->getMessage(), 0, 500, 'UTF-8') . ' ' . $details;
    }
}
