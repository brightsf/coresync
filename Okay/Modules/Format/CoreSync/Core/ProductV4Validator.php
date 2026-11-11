<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

/** Strict v4 product boundary. The structural v3 payload stays frozen; v4 only adds documents. */
class ProductV4Validator
{
    /** @var ProductV3Validator */
    private $v3;

    public function __construct(?ProductV3Validator $v3 = null)
    {
        $this->v3 = $v3 ?? new ProductV3Validator();
    }

    /**
     * @param array<string, mixed> $line
     * @param array<int, string> $languages
     * @param array<string, int> $localLanguageIds
     * @param array<string, int> $manifestDocuments sha256 => bytes
     * @return array<string, mixed>
     */
    public function validate(
        array $line,
        array $languages,
        array $localLanguageIds,
        array $manifestDocuments,
        ?string $rawJson = null
    ): array {
        $data = $line['data'] ?? null;
        if (!is_array($data) || !array_key_exists('documents', $data) || !is_array($data['documents'])
            || !Contract::isList($data['documents'])) {
            throw new ManifestException('product v4 documents должен быть списком');
        }
        if (count($data['documents']) > 8) {
            throw new ManifestException('product v4 содержит более 8 документов');
        }

        $seen = [];
        foreach ($data['documents'] as $index => $document) {
            if (!is_array($document)) {
                throw new ManifestException('product v4 document должен быть объектом');
            }
            $required = ['external_id', 'title', 'position', 'file', 'sha256', 'bytes', 'original_filename', 'language', 'type'];
            $actualKeys = array_keys($document);
            sort($actualKeys, SORT_STRING);
            $requiredKeys = $required;
            sort($requiredKeys, SORT_STRING);
            if ($actualKeys !== $requiredKeys) {
                throw new ManifestException('product v4 document содержит неверный набор или порядок полей');
            }
            $externalId = $document['external_id'];
            $sha = $document['sha256'];
            if (!is_string($externalId) || $externalId === '' || strlen($externalId) > 255 || isset($seen[$externalId])
                || !is_string($document['title']) || $document['title'] === '' || strlen($document['title']) > 255
                || !is_int($document['position']) || $document['position'] < 0
                || !is_string($sha) || preg_match('/\A[0-9a-f]{64}\z/', $sha) !== 1
                || $document['file'] !== 'document-' . $sha . '.pdf'
                || !is_int($document['bytes']) || $document['bytes'] < 1
                || $document['bytes'] > Contract::PRODUCT_DOCUMENT_MAX_BYTES
                || ($manifestDocuments[$sha] ?? null) !== $document['bytes']
                || !$this->validOriginalFilename($document['original_filename'])
                || !$this->validLanguage($document['language'])
                || !$this->validType($document['type'])) {
                throw new ManifestException('product v4 document не соответствует контракту');
            }
            $seen[$externalId] = true;
        }

        $v3Line = $line;
        unset($v3Line['data']['documents']);
        $v3Raw = null;
        if ($rawJson !== null) {
            $native = json_decode($rawJson);
            if (!is_object($native) || !isset($native->data) || !is_object($native->data)) {
                throw new ManifestException('product v4 row/data должны быть JSON-объектами');
            }
            unset($native->data->documents);
            $v3Raw = json_encode($native);
        }
        $validated = $this->v3->validate($v3Line, $languages, $localLanguageIds, $v3Raw);
        $validated['data']['documents'] = $data['documents'];

        return $validated;
    }

    /** @param mixed $value */
    private function validOriginalFilename($value): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= 255
            && preg_match('/[\/\\\\\x00]/', $value) !== 1;
    }

    /** @param mixed $value */
    private function validLanguage($value): bool
    {
        return $value === null
            || (is_string($value) && preg_match('/\A(?:und|[a-z]{2,3}(?:-[A-Z]{2})?)\z/', $value) === 1);
    }

    /** @param mixed $value */
    private function validType($value): bool
    {
        return $value === null
            || (is_string($value) && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $value) === 1);
    }
}
