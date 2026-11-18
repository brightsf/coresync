<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use InvalidArgumentException;
use Okay\Core\Languages;

final class PreviewLanguages extends Languages
{
    private $catalog = [];
    private $mainId;
    private $selectedId;

    public function __construct(array $catalog, int $mainId, int $selectedId)
    {
        foreach ($catalog as $row) {
            if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] < 1
                || isset($this->catalog[$row['id']])) {
                throw new InvalidArgumentException('Invalid offline language catalogue');
            }
            $this->catalog[$row['id']] = $row;
        }
        if (!isset($this->catalog[$mainId], $this->catalog[$selectedId])) {
            throw new InvalidArgumentException('Offline language context is incomplete');
        }
        $this->mainId = $mainId;
        $this->selectedId = $selectedId;
    }

    public function getAllLanguages()
    {
        return array_map(static function (array $row): object { return (object) $row; }, $this->catalog);
    }

    public function getMainLanguage()
    {
        return (object) $this->catalog[$this->mainId];
    }

    public function getLangId()
    {
        return $this->selectedId;
    }

    public function setLangId($id)
    {
        $id = (int) $id;
        $this->selectedId = isset($this->catalog[$id]) ? $id : $this->mainId;
    }

}
