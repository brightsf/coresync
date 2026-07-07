<?php

namespace Okay\Modules\Format\CoreSync\Core\Exceptions;

/**
 * Некорректный/недоступный манифест снапшота (битый JSON, отсутствуют обязательные поля,
 * HTTP 404 по токену/каналу и т.п.). Витрина при этом не затрагивается.
 */
class ManifestException extends CoreSyncException
{
}
