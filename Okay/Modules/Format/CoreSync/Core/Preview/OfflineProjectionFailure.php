<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use RuntimeException;

/** A known native stop discovered without attempting its write. */
final class OfflineProjectionFailure extends RuntimeException
{
}
