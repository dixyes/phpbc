<?php 

declare(strict_types=1);

namespace PHPbc;

class Log{
    static private ?Log $logger = NULL;

    private Config $config;
    private $ffi;
    private $stdoutHandle;
    private $stderrHandle;
    private $csbi;
    private int $stdoutAttr;
    private int $stderrAttr;
    const INTENSITY = 0x8;
    const RED = 0x4;
    const GREEN = 0x2;
    const BLUE = 0x1;
    private function __construct(){
        $this->config = Config::init();
        $this->ffi = NULL;
        if("Windows" == PHP_OS_FAMILY && extension_loaded("FFI")){
            $this->ffi = \FFI::cdef("
                typedef uint64_t HANDLE;
                typedef int32_t BOOL;
                typedef struct _SMALL_RECT {
                    int16_t Left;
                    int16_t Top;
                    int16_t Right;
                    int16_t Bottom;
                } SMALL_RECT;
                typedef struct _COORD {
                    int16_t X;
                    int16_t Y;
                } COORD, *PCOORD;
                typedef struct _CONSOLE_SCREEN_BUFFER_INFO {
                    COORD      dwSize;
                    COORD      dwCursorPosition;
                    uint16_t    wAttributes;
                    SMALL_RECT srWindow;
                    COORD      dwMaximumWindowSize;
                } CONSOLE_SCREEN_BUFFER_INFO, *PCONSOLE_SCREEN_BUFFER_INFO;
                BOOL GetConsoleScreenBufferInfo(HANDLE, PCONSOLE_SCREEN_BUFFER_INFO);
                HANDLE GetStdHandle(int32_t);
                BOOL SetConsoleTextAttribute(HANDLE, uint16_t);
            ", "kernel32.dll");
            $this->csbi = $this->ffi->new("CONSOLE_SCREEN_BUFFER_INFO");
            $ret = $this->ffi->GetStdHandle(-11/* STD_OUTPUT_HANDLE */);
            if(0 == $ret || -1 == $ret){
                // we cannot get std handle, nothing can be outputted;
                throw new \RuntimeException("cannot get stdout handle");
            }
            $this->stdoutHandle = $ret;
            $ret = $this->ffi->GetStdHandle(-12/* STD_ERROR_HANDLE */);
            if(0 == $ret || -1 == $ret){
                // we cannot get std handle, nothing can be outputted;
                throw new \RuntimeException("cannot get stdout handle");
            }
            $this->stderrHandle = $ret;
            $this->ffi->GetConsoleScreenBufferInfo($this->stdoutHandle, \FFI::addr($this->csbi));
            $this->stdoutAttr = $this->csbi->wAttributes;
            $this->ffi->GetConsoleScreenBufferInfo($this->stderrHandle, \FFI::addr($this->csbi));
            $this->stderrAttr = $this->csbi->wAttributes;
            // clean bg
            $this->ffi->SetConsoleTextAttribute($this->stdoutHandle, 0x07);
            $this->ffi->SetConsoleTextAttribute($this->stderrHandle, 0x07);
        }
    }
    function __destruct(){
        if("Windows" == PHP_OS_FAMILY && extension_loaded("FFI")){
            // restore bg
            $this->ffi->SetConsoleTextAttribute($this->stdoutHandle, $this->stdoutAttr);
            $this->ffi->SetConsoleTextAttribute($this->stderrHandle, $this->stderrAttr);
        }
    }
    private function changeFgColor(int $color, int $fd){
        $r = ($color & self::RED) === self::RED;
        $g = ($color & self::GREEN) === self::GREEN;
        $b = ($color & self::BLUE) === self::BLUE;
        $i = ($color & self::INTENSITY) === self::INTENSITY;
        
        $unixColor = [];
        if($r || $g || $b){
            $num = 30 + ($r ? 1 : 0) + ($g ? 2 : 0) + ($b ? 4 : 0);
            $unixColor[] = "$num";
        }
        if($i){
            $unixColor[] = "1";
        }
        $unixColorStr = "\x1b[" . implode(";", $unixColor). "m";
        
        if(getenv("CI") === "true" || "Windows" !== PHP_OS_FAMILY){
            // in gh ci
            switch($fd){
                case 1:
                    fprintf(STDOUT, $unixColorStr);
                    break;
                case 2:
                    fprintf(STDERR, $unixColorStr);
                    break;
                default:
                    throw new \LogicException("bad fd num");
            }
        }
        if("Windows" === PHP_OS_FAMILY && extension_loaded("FFI")){
            switch($fd){
                case 1:
                    $this->ffi->SetConsoleTextAttribute($this->stdoutHandle, ($this->stdoutAttr & 0xff00) | $color);
                    break;
                case 2:
                    $this->ffi->SetConsoleTextAttribute($this->stderrHandle, ($this->stderrAttr & 0xff00) | $color);
                    break;
                default:
                    throw new \LogicException("bad fd num");
            }
        }
    }
    private function printThings(array $things){
        $items = [];
        $idx = 0;
        foreach($things as $k => $v){
            if($idx++ === $k){
                if(is_string($v)){
                    echo " $v";
                }else{
                    echo " ";
                    var_export($v);
                }
            }else{
                echo " $k: ";
                var_export($v);
            }
        }
    }
    private function log(int $color, int $fd, string $tag, array $things){
        if(!("Windows" === PHP_OS_FAMILY && extension_loaded("FFI"))){
            ob_start();
        }
        $this->changeFgColor($color, $fd);
        switch($fd){
            case 1:
                fprintf(STDOUT, $tag);
                break;
            case 2:
                fprintf(STDERR, $tag);
                break;
            default:
                throw new \LogicException("bad fd num");
        }
        $this->changeFgColor(self::RED|self::BLUE|self::GREEN, $fd);
        if("Windows" === PHP_OS_FAMILY && extension_loaded("FFI")){
            ob_start();
        }
        $this->printThings($things);
        echo PHP_EOL;
        $s = ob_get_clean();
        switch($fd){
            case 1:
                fprintf(STDOUT, $s);
                break;
            case 2:
                fprintf(STDERR, $s);
                break;
            default:
                throw new \LogicException("bad fd num");
        }

    }
    private function _d(...$args){
        $this->log(color: self::RED|self::BLUE, fd: 1, tag: "[DBG]", things: $args);
    }
    private function _v(...$args){
        $this->log(color: self::RED|self::BLUE|self::GREEN, fd: 1, tag: "[VER]", things: $args);
    }
    private function _i(...$args){
        $this->log(color: self::RED|self::BLUE|self::GREEN|self::INTENSITY, fd: 1, tag: "[IFO]", things: $args);
    }
    private function _w(...$args){
        $this->log(color: self::RED|self::BLUE, fd: 2, tag: "[WRN]", things: $args);
    }
    private function _e(...$args){
        $this->log(color: self::RED, fd: 2, tag: "[ERR]", things: $args);
    }

    
    static public function init(){
        if(!self::$logger){
            self::$logger = new static();
        }
    }
    static public function __callStatic(string $name, array $args){
        switch($name){
            case "d":
            case "v":
            case "i":
            case "w":
            case "e":
                self::init();
                $realName = "_$name";
                return self::$logger->$realName(...$args);
            default:
                throw new \LogicException(sprintf("no such static function on Log: %d", $name));
        };
    }
}