<?php

/**
 * @noinspection PhpMissingFieldTypeInspection
 */

declare(strict_types=1);

// php installer for phpbc running in actions
// why shivammathur/setup-php overrides system-side php?

// lightweight logger for actions environment
class Log
{
    public static $prefix;

    public static function i(...$args)
    {
        if (static::$prefix === null) {
            if (getenv('CI') === 'true') {
                static::$prefix = "\033[1m[phpinstaller:IFO]\033[0m ";
            }
            static::$prefix = '[phpinstaller:IFO] ';
        }

        echo static::$prefix . implode(' ', array_map(function ($x) {return "{$x}"; }, $args));
    }
}

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        // This error code is not included in error_reporting
        return;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

function setup_composer()
{
    if ('Windows' === PHP_OS_FAMILY) {
        Log::i('finding composer from path by where');
        $cmdResult = shell_exec('where composer.phar');
        if (!$cmdResult) {
            Log::i('not found composer.phar in path');
            goto no_composer;
        }
        $phars = preg_split("|[\r\n]+|", trim($cmdResult));
        copy($phars[0], 'php/composer.phar');

        return;
    }
    Log::i('finding composer from path by which');
    $cmdResult = shell_exec('which composer');
    if (!$cmdResult) {
        Log::i('not found composer in path');
        goto no_composer;
    }
    $phar = trim($cmdResult);
    symlink($phar, 'php/composer.phar');

    return;
    no_composer:
    Log::i('downloading composer');
    $opts = [
        'http' => [
            'header' => 'User-Agent: myphpinstaller/1.0',
        ],
    ];
    $context = stream_context_create($opts);
    file_put_contents('php/composer.phar', file_get_contents('https://getcomposer.org/download/latest-stable/composer.phar', false, $context));
}

function setup_win()
{
    $opts = [
        'http' => [
            'header' => 'User-Agent: myphpinstaller/1.0',
        ],
    ];
    $context = stream_context_create($opts);
    Log::i('downloading latest 8.0 release');
    file_put_contents('php/php.zip', file_get_contents('https://windows.php.net/downloads/releases/latest/php-8.0-nts-Win32-vs16-x64-latest.zip', false, $context));
    Log::i('unzipping php');
    passthru('unzip php/php.zip -d php');
    Log::i('setting ini for composer');
    $extdir = realpath('php/ext');
    file_put_contents('php/php.ini', "extension_dir={$extdir}\r\nextension=openssl\r\nextension=ffi\r\n");
}

function setup_mac()
{
    $phps = array_filter(scandir('/usr/local/Cellar/php'), function ($dir) {
        if ($dir === '..' || $dir === '.') {
            return false;
        }

        return preg_match('|^8\\..+|', $dir);
    });
    if (count($phps) < 1) {
        Log::i('install latest PHP8 by brew');
        passthru('brew install php@8.0');
    }
    usort($phps, 'version_compare');
    $used = end($phps);
    symlink("/usr/local/Cellar/php/{$used}/bin/php", 'php/php');
}

function setup_linux()
{
    Log::i('pacstrapping');
    @mkdir('php/archroot');
    $archroot = realpath('php/archroot');
    passthru("docker run --privileged --rm -v {$archroot}:/inst:rw archlinux:base sh -c 'pacman -Sy && pacman -S --noconfirm arch-install-scripts && pacstrap /inst php'");
    Log::i('generating php shell');
    $cmd = <<<EOF
    #!/bin/sh
    exec {$archroot}/lib/ld-linux-x86-64.so.2 --library-path {$archroot}/lib {$archroot}/bin/php $*
EOF;
    file_put_contents('php/php', $cmd);
    chmod('php/php', 0755);
}

function mian()
{
    Log::i('start install php 8.x for phpbc');
    @mkdir('php');
    setup_composer();
    switch (PHP_OS_FAMILY) {
        case 'Windows':
            return setup_win();
        case 'Darwin':
            return setup_mac();
        case 'Linux':
            return setup_linux();
        default:
            fprintf(STDERR, 'not supported os');

            return 1;
    }
}

exit(mian());
