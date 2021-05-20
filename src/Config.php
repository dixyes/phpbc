<?php 

declare(strict_types=1);

namespace PHPbc;

class Config implements \ArrayAccess{
    static private $config = NULL;
    private array $_common = [
        "tests" => [],
        "skip" => [],
        "patches" => [],
        "timeout" => 30,
        "workers" => 4,
        "outputs" => [
            [
                "type" => "json",
                "name" => "phpbc_result.json",
                "pretty" => true,
            ],
            [
                "type" => "markdown",
                "name" => "phpbc_result.md",
                "sames" => false,
            ],
        ],
    ];
    private array $_ctrl = [
        "binary" => PHP_BINARY,
        "args" => [],
        "workdir" => "php-src",
    ];
    private array $_expr = [
        "binary" => PHP_BINARY,
        "args" => [],
        "workdir" => "php-src-expr",
    ];
    private function __construct(array $data){
        foreach($data as $k => $v){
            switch($k){
                case "tests":
                case "skip":
                case "patches":
                case "timeout":
                case "workers":
                case "outputs":
                    $this->_common[$k] = $v;
                    break;
                case "ctrl":
                case "expr":
                    foreach($v as $kk => $vv){
                        $_k = "_$k";
                        switch($kk){
                            case "binary":
                            case "args":
                            case "workdir":
                                $this->$_k[$kk] = $vv;
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
    static public function init(mixed $path_or_data=NULL): Config{
        if(self::$config){
            return self::$config;
        }
        if(is_array($path_or_data)){
            self::$config = new static($path_or_data);
            return self::$config;
        }else if(is_string($path_or_data)){
            $conffile=$path_or_data;
            if(!$conffile){
                $conffile=__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR ."config.json";
            }
            if(!file_exists($conffile)){
                // TODO: warning here
                self::$config = new static([]);
                return self::$config;
            }
            $data = json_decode(file_get_contents($conffile), true);
            if(!$data){
                // TODO: warning here
                self::$config = new static([]);
                return self::$config;
            }
            self::$config = new static($data);
            return self::$config;
        }
        throw new \LogicException("bad path or data");
    }

    public function __get($k) {
        switch($k){
            case "tests":
            case "skip":
            case "patches":
            case "timeout":
            case "workers":
            case "outputs":
                return $this->_common[$k];
            case "ctrl":
            case "expr":
                $_k = "_$k";
                return $this->$_k;
            default:
                throw new \LogicException("no such thing");
        }
    }
    public function __set($property, $value) {
        throw new \LogicException("not settable");
    }
    public function offsetExists(mixed $offset): bool{
        switch($property){
            case "tests":
            case "skip":
            case "patches":
            case "timeout":
            case "workers":
            case "outputs":
            case "ctrl":
            case "expr":
                return true;
            default:
                return false;
        }
    }
    public function offsetGet(mixed $offset ): mixed{
        return $this->__get($offset);
    }
    public function offsetSet(mixed $offset, mixed $value){
        throw new \LogicException("not settable");
    }
    public function offsetUnset(mixed $offset){
        throw new \LogicException("not unsettable");
    }
}
