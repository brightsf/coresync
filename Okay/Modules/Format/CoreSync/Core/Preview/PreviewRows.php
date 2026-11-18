<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use InvalidArgumentException;
use Okay\Modules\Format\CoreSync\Core\Contract;
use LogicException;
use OutOfBoundsException;

/**
 * Copy-on-write rows for an offline apply. Entity adapters must project their
 * native filters and values into this table; there is no database fallback.
 */
class PreviewRows
{
    private const FIRST_PLANNED_ID = 4_503_599_627_370_496;

    private $entity;
    private $rows = [];
    private $readOrder = [];
    private $fields = [];
    private $indexes = [];
    private $plannedIds = [];
    private $changes = [];
    private $baselineDigest;

    /** @param iterable<object|array> $rows */
    public function __construct(string $entity, iterable $rows, array $fields = [])
    {
        if (PHP_INT_SIZE < 8 || !preg_match('/^[a-z][a-z0-9_]*$/D', $entity)) {
            throw new InvalidArgumentException('Invalid CoreSync preview entity or integer width');
        }

        $this->entity = $entity;
        $this->fields = array_fill_keys(array_merge(['id'], $fields), true);
        foreach ($rows as $row) {
            $data = (array) $row;
            $rawId = $data['id'] ?? null;
            if (!is_int($rawId) && (!is_string($rawId) || !ctype_digit($rawId))) {
                throw new InvalidArgumentException('Preview baseline row has no physical ID');
            }
            $id = (int) $rawId;
            if ($id < 1 || $id >= self::FIRST_PLANNED_ID || isset($this->rows[$id])) {
                throw new InvalidArgumentException('Preview baseline has invalid or duplicate physical IDs');
            }

            $data['id'] = $id;
            $this->validateFields($data);
            $this->readOrder[$id] = count($this->readOrder);
            $this->rows[$id] = $data;
        }

        $sorted = $this->rows;
        foreach ($sorted as &$row) {
            ksort($row, SORT_STRING);
        }
        unset($row);
        $this->baselineDigest = hash('sha256', serialize($sorted));
    }

    public function baselineDigest(): string
    {
        return $this->baselineDigest;
    }

    /** @return object|false */
    public function get(int $id)
    {
        return isset($this->rows[$id]) ? (object) $this->rows[$id] : false;
    }

    /** @return object|false */
    public function findOne(array $filters)
    {
        return $this->find($filters)[0] ?? false;
    }

    /** @return object[] */
    public function find(array $filters = []): array
    {
        $matches = null;
        foreach ($filters as $field => $value) {
            $idList = $field === 'id' && is_array($value) && Contract::isList($value)
                && count(array_filter($value, 'is_int')) === count($value);
            if (!isset($this->fields[$field]) || (!$idList && !is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException("Unsupported CoreSync preview filter for {$this->entity}");
            }
            if (!isset($this->indexes[$field])) {
                $this->indexes[$field] = [];
                foreach ($this->rows as $id => $row) {
                    $key = serialize($row[$field] ?? null);
                    $this->indexes[$field][$key][$id] = true;
                }
            }
            $ids = [];
            foreach ($idList ? $value : [$value] as $candidate) {
                $ids += $this->indexes[$field][serialize($candidate)] ?? [];
            }
            $matches = $matches === null ? $ids : array_intersect_key($matches, $ids);
        }
        if ($matches === null) {
            $selected = $this->rows;
        } else {
            $ids = array_keys($matches);
            usort($ids, function (int $left, int $right): int {
                return $this->readOrder[$left] <=> $this->readOrder[$right];
            });
            $selected = array_map(function (int $id): array {
                return $this->rows[$id];
            }, $ids);
        }

        return array_values(array_map(static function (array $row): object {
            return (object) $row;
        }, $selected));
    }

    public function count(array $filters = []): int
    {
        return count($this->find($filters));
    }

    public function add(array $fields): int
    {
        if (array_key_exists('id', $fields)) {
            throw new LogicException('Preview insert cannot override its planned ID');
        }
        $this->validateFields($fields);
        $ordinal = count($this->plannedIds) + 1;
        $id = self::FIRST_PLANNED_ID + $ordinal;
        $this->plannedIds[$id] = "new:{$this->entity}:$ordinal";
        $row = ['id' => $id] + $fields;
        $this->readOrder[$id] = count($this->readOrder);
        $this->rows[$id] = $row;
        $this->indexRow($id, $row, true);
        $this->record('create', $id, [], $fields);

        return $id;
    }

    public function update(int $id, array $fields): int
    {
        if (array_key_exists('id', $fields)) {
            throw new LogicException('Preview update cannot override row identity');
        }
        $old = $this->requireRow($id);
        $this->validateFields($fields);
        $before = array_intersect_key($old, $fields);
        $this->record('update', $id, $before, $fields);
        $this->indexRow($id, $old, false);
        $this->rows[$id] = $fields + $old;
        $this->indexRow($id, $this->rows[$id], true);

        return $id;
    }

    public function delete(int $id): void
    {
        $row = $this->requireRow($id);
        $this->indexRow($id, $row, false);
        unset($this->rows[$id], $row['id']);
        $this->record('delete', $id, $row, []);
    }

    /** @return int|string */
    public function reportId(int $id)
    {
        return $this->plannedIds[$id] ?? $id;
    }

    public function changes(): array
    {
        return $this->changes;
    }

    public function transaction(callable $apply): void
    {
        $before = clone $this;
        try {
            $apply();
        } catch (\Throwable $error) {
            $this->rows = $before->rows;
            $this->fields = $before->fields;
            $this->readOrder = $before->readOrder;
            $this->indexes = $before->indexes;
            $this->plannedIds = $before->plannedIds;
            $this->changes = $before->changes;
            throw $error;
        }
    }

    private function validateFields(array $data): void
    {
        foreach ($data as $field => $value) {
            if (!is_string($field) || (!is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException("Unsupported field value for {$this->entity} preview");
            }
            $this->fields[$field] = true;
        }
    }

    private function requireRow(int $id): array
    {
        if (!isset($this->rows[$id])) {
            throw new OutOfBoundsException("Preview row {$this->entity}:$id does not exist");
        }

        return $this->rows[$id];
    }

    private function indexRow(int $id, array $row, bool $add): void
    {
        foreach ($this->indexes as $field => &$index) {
            $key = serialize($row[$field] ?? null);
            if ($add) {
                $index[$key][$id] = true;
            } else {
                unset($index[$key][$id]);
            }
        }
    }

    private function record(string $action, int $id, array $before, array $after): void
    {
        $fields = [];
        foreach ($after + $before as $field => $unused) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;
            if ($action !== 'update' || $from !== $to) {
                $fields[$field] = [$from, $to];
            }
        }
        if ($fields !== [] || $action !== 'update') {
            $this->changes[] = ['entity' => $this->entity, 'action' => $action, 'id' => $this->reportId($id), 'fields' => $fields];
        }
    }
}
