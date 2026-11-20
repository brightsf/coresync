<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

/**
 * Strict v4 product boundary. Adds documents and permits the explicit price hold in a full snapshot.
 * Documents follow the legacy shape, or product_documents_store 1.0.0 when the manifest declares a mode.
 */
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
        ?string $rawJson = null,
        bool $allowVariantPriceHold = false,
        ?string $documentsMode = null
    ): array {
        $data = $line['data'] ?? null;
        if (!is_array($data) || !array_key_exists('documents', $data) || !is_array($data['documents'])
            || !Contract::isList($data['documents'])) {
            throw new ManifestException('product v4 documents должен быть списком');
        }
        if (count($data['documents']) > Contract::PRODUCT_DOCUMENTS_MAX_PER_PRODUCT) {
            throw new ManifestException('product v4 содержит более 8 документов');
        }

        $documents = $data['documents'];
        if ($documentsMode === Contract::PRODUCT_DOCUMENTS_MODE_STORE) {
            $this->validateStoreDocuments($documents, $manifestDocuments);
        } elseif ($documentsMode === Contract::PRODUCT_DOCUMENTS_MODE_DISABLED) {
            // disabled: модуль игнорирует списки строк и удаляет всё пришедшее из ядра.
            $documents = [];
        } elseif ($documentsMode === null) {
            $this->validateLegacyDocuments($documents, $manifestDocuments);
        } else {
            throw new ManifestException('product v4 documents: неизвестный режим манифеста');
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
        $validated = $this->v3->validate($v3Line, $languages, $localLanguageIds, $v3Raw, $allowVariantPriceHold);
        $validated['data']['documents'] = $documents;

        return $validated;
    }

    /**
     * Прежняя форма V4 (без ключа product_documents в манифесте).
     *
     * @param array<int, mixed> $documents
     * @param array<string, int> $manifestDocuments
     */
    private function validateLegacyDocuments(array $documents, array $manifestDocuments): void
    {
        $seen = [];
        foreach ($documents as $index => $document) {
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
    }

    /**
     * Повторная проверка списка документов строки store вне полного preflight (догон отложенных
     * документов по уже применённой версии).
     *
     * @param mixed $documents
     * @param array<string, int> $manifestDocuments sha256 => bytes
     */
    public function validateStoreDocumentList($documents, array $manifestDocuments): void
    {
        if (!is_array($documents) || !Contract::isList($documents)
            || count($documents) > Contract::PRODUCT_DOCUMENTS_MAX_PER_PRODUCT) {
            throw new ManifestException('product store documents должен быть списком до 8 элементов');
        }
        $this->validateStoreDocuments($documents, $manifestDocuments);
    }

    /**
     * Форма product_documents_store 1.0.0: ready описывает брендированную копию из пакета (все
     * четыре поля файла заполнены, байты сходятся с manifest.files), pending не несёт ни одного.
     *
     * @param array<int, mixed> $documents
     * @param array<string, int> $manifestDocuments sha256 => bytes
     */
    private function validateStoreDocuments(array $documents, array $manifestDocuments): void
    {
        $required = ['external_id', 'title', 'position', 'language', 'type', 'state', 'file', 'sha256', 'bytes', 'original_filename'];
        sort($required, SORT_STRING);
        $seen = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                throw new ManifestException('product store document должен быть объектом');
            }
            $actualKeys = array_keys($document);
            sort($actualKeys, SORT_STRING);
            if ($actualKeys !== $required) {
                throw new ManifestException('product store document содержит неверный набор полей');
            }
            $externalId = $document['external_id'];
            if (!is_string($externalId) || $externalId === '' || strlen($externalId) > 255 || isset($seen[$externalId])
                || !is_string($document['title']) || $document['title'] === '' || strlen($document['title']) > 255
                || !is_int($document['position']) || $document['position'] < 0
                || !$this->validLanguage($document['language'])
                || !$this->validType($document['type'])) {
                throw new ManifestException('product store document не соответствует контракту');
            }
            $seen[$externalId] = true;

            if ($document['state'] === Contract::PRODUCT_DOCUMENT_STATE_PENDING) {
                if ($document['file'] !== null || $document['sha256'] !== null || $document['bytes'] !== null
                    || $document['original_filename'] !== null) {
                    throw new ManifestException('product store document pending не должен нести файл');
                }
                continue;
            }
            if ($document['state'] !== Contract::PRODUCT_DOCUMENT_STATE_READY) {
                throw new ManifestException('product store document: неизвестный state');
            }
            $sha = $document['sha256'];
            if (!is_string($sha) || preg_match('/\A[0-9a-f]{64}\z/', $sha) !== 1
                || $document['file'] !== 'document-' . $sha . '.pdf'
                || !is_int($document['bytes']) || $document['bytes'] < 1
                || $document['bytes'] > Contract::PRODUCT_DOCUMENT_MAX_BYTES
                || ($manifestDocuments[$sha] ?? null) !== $document['bytes']
                || !$this->validOriginalFilename($document['original_filename'])) {
                throw new ManifestException('product store document ready не соответствует пакету');
            }
        }
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
