<?php

namespace Okay\Modules\Format\CoreSync\Core\Exceptions;

/**
 * Мажор schema_version манифеста не поддерживается модулем. Fail-closed:
 * ничего не скачивается, apply-report = failed, витрина остаётся на прошлой версии.
 */
class UnsupportedSchemaVersionException extends CoreSyncException
{
}
