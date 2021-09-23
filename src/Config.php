<?php

declare(strict_types=1);

namespace PHPbc;

use LogicException;

/**
 * @property int workers
 * @property string[] tests
 * @property string[] skip
 * @property string[] patches
 * @property float timeout
 * @property array ctrl
 * @property array expr
 * @property array outputs
 */
class Config implements \ArrayAccess
{
    private static ?Config $config = null;

    private array $_common = [
        'tests' => [],
        'skip' => [],
        'patches' => [],
        'timeout' => 30,
        'workers' => 4,
        'outputs' => [
            [
                'type' => 'json',
                'name' => 'phpbc_result.json',
                'pretty' => true,
            ],
            [
                'type' => 'markdown',
                'name' => 'phpbc_result.md',
                'sames' => false,
            ],
        ],
    ];

    private array $_ctrl = [
        'binary' => PHP_BINARY,
        'args' => [],
        'workdir' => 'php-src',
        'env' => [],
    ];

    private array $_expr = [
        'binary' => PHP_BINARY,
        'args' => [],
        'workdir' => 'php-src-expr',
        'env' => [],
    ];

    private function __construct(array $data)
    {
        foreach ($data as $k => $v) {
            switch ($k) {
                case 'tests':
                case 'skip':
                case 'patches':
                case 'timeout':
                case 'workers':
                case 'outputs':
                    $this->_common[$k] = $v;
                    break;
                case 'ctrl':
                case 'expr':
                    foreach ($v as $kk => $vv) {
                        $_k = "_{$k}";
                        switch ($kk) {
                            case 'binary':
                            case 'args':
                            case 'workdir':
                            case 'env':
                                $this->{$_k}[$kk] = $vv;
                                break;
                            default:
                                // TODO: warning here
                                break;
                        }
                    }
                    break;
                default:
                    // TODO: warning here
                    break;
            }
        }
    }

    public static function init(mixed $path_or_data = null): Config
    {
        if (self::$config) {
            return self::$config;
        }
        if (is_array($path_or_data)) {
            self::$config = new static($path_or_data);

            return self::$config;
        }
        if (is_string($path_or_data)) {
            $conffile = $path_or_data;
            if (!$conffile) {
                $conffile = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.json';
            }
            if (!file_exists($conffile)) {
                // TODO: warning here
                self::$config = new static([]);

                return self::$config;
            }
            $data = json_decode(file_get_contents($conffile), true);
            if (!$data) {
                // TODO: warning here
                self::$config = new static([]);

                return self::$config;
            }
            self::$config = new static($data);

            return self::$config;
        }
        throw new LogicException('bad path or data');
    }

    public function __get($k)
    {
        return match ($k) {
            'tests', 'skip', 'patches', 'timeout', 'workers', 'outputs' => $this->_common[$k],
            'ctrl', 'expr' => $this->{"_{$k}"},
            default => throw new LogicException('no such thing'),
        };
    }

    public function __set($property, $value)
    {
        throw new LogicException('not settable');
    }

    public function offsetExists(mixed $offset): bool
    {
        return match ($offset) {
            'tests', 'skip', 'patches', 'timeout', 'workers', 'outputs', 'ctrl', 'expr' => true,
            default => false,
        };
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value)
    {
        throw new LogicException('not settable');
    }

    public function offsetUnset(mixed $offset)
    {
        throw new LogicException('not unsettable');
    }
}
