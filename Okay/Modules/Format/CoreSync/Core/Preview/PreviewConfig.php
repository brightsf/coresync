<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use InvalidArgumentException;
use LogicException;
use Okay\Core\Config;

final class PreviewConfig extends Config
{
    private $root;

    public function __construct(string $root)
    {
        if ($root === '' || $root[0] !== '/' || strpos($root, "\0") !== false || !is_dir($root)) {
            throw new InvalidArgumentException('Offline asset root must be a local directory');
        }
        $this->root = rtrim($root, '/') . '/';
    }

    public function get($name)
    {
        if ($name === 'root_dir') {
            return $this->root;
        }
        if ($name === 'original_images_dir') {
            return 'images/';
        }

        if ($name === 'original_categories_dir') {
            return 'category-images/';
        }

        return null;
    }

    public function set($name, $value)
    {
        throw new LogicException('Offline preview configuration is immutable');
    }
}
