<?php

namespace Okay\Modules\Format\CoreSync\Core\Exceptions;

/**
 * sha256 скачанного файла не совпал с манифестом после исчерпания перекачек.
 * Набор снапшота НИКОГДА не считается готовым при таком сбое.
 */
class Sha256MismatchException extends CoreSyncException
{
}
