<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use LogicException;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;

final class PreviewSettings extends Settings
{
    private $scope;

    public function __construct(array $scope)
    {
        if (array_diff(array_keys($scope), ['currency_map', 'channel_code', 'source_instance', 'lang_id', Contract::SETTINGS_SYNC_PRICES_FIELD,
            'v5_adoption_plan_path', 'v5_adoption_plan_sha256']) !== []) {
            throw new LogicException('Unsupported settings in the offline receiver state');
        }
        $path = $scope['v5_adoption_plan_path'] ?? '';
        if (!is_string($path) || ($path !== '' && ($path[0] !== '/' || strpos($path, "\0") !== false
            || strpos($path, '://') !== false || is_link($path)))) {
            throw new LogicException('Offline V5 adoption plan must be a local non-symlink file');
        }
        $this->scope = $scope;
    }

    public function get($param)
    {
        if ($param === 'documents_dir') {
            return 'documents/';
        }

        return $param === Contract::SETTINGS_KEY ? $this->scope : null;
    }

    public function set($param, $value)
    {
        throw new LogicException('Offline preview settings are immutable');
    }
}
