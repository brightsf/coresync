<?php

namespace Okay\Modules\Format\CoreSync\Core\Exceptions;

/**
 * Файл NDJSON.gz непригоден к применению: не открывается либо доля битых строк выше порога
 * (Contract::NDJSON_MAX_BROKEN_RATIO) — fail файла, но не всего прогона.
 */
class NdjsonReadException extends CoreSyncException
{
}
