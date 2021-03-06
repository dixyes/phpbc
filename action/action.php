<?php /** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

include_once __DIR__ . '/../vendor/autoload.php';

use PHPbc\Config;
use PHPbc\Log;
use PHPbc\PHPbc;
use PHPbc\Util;

// make all warnings into exceptions
Util::enable_error_handler();

// read actions inputs
$context = json_decode(file_get_contents($argv[1]), true);

// cd to workspace
chdir($context['github']['workspace']);

$inputs = $context['inputs'];

$needsClone = false;
$needsCopy = false;
if ($inputs['ctrl_workdir']) {
    $ctrl_workdir = $inputs['ctrl_workdir'];
    $expr_workdir = $inputs['expr_workdir'] ? $inputs['expr_workdir'] : "{$ctrl_workdir}_expr";
} else {
    $rand = bin2hex(random_bytes(8));
    $ctrl_workdir = "phpbc_ctrl_phpsrc_{$rand}";
    $expr_workdir = "phpbc_expr_phpsrc_{$rand}";
}
if (!is_dir($ctrl_workdir)) {
    // needs prepare source dir
    $needsClone = true;
}
if (!is_dir($expr_workdir)) {
    // needs prepare experiment source dir
    $needsCopy = true;
}

if ('Windows' === PHP_OS_FAMILY) {
    $phpbins = shell_exec('where php');
    $phpbin = preg_split("|[\r\n]+|", trim($phpbins))[0];
} else {
    $phpbin = trim(shell_exec('which php'));
}

$ctrl_binary = $inputs['ctrl_binary'] ?: $phpbin;
$expr_binary = $inputs['expr_binary'] ?: $phpbin;

$skipStr = trim($inputs['skip']);
$skip = [
    // these tests may leak private info
    'ext/standard/tests/general_functions/phpinfo.phpt',
    'ext/openssl/tests/check_default_conf_path.phpt',
    // this tests will always fail when soap is compiled as dynamic library
    'ext/soap/tests/bug73037.phpt',
];

$ver = trim(shell_exec($ctrl_binary . ' -r "printf(\'%d.%d.%d\', PHP_MAJOR_VERSION,PHP_MINOR_VERSION,PHP_RELEASE_VERSION);"'));
//$shortVer = trim(shell_exec($ctrl_binary . ' -r "printf("%d.%d", PHP_MAJOR_VERSION,PHP_MINOR_VERSION)"'));
if (version_compare($ver, '7.4.0', '<')) {
    // phpbdg tests stuck on php 7.3
    Log::i('skipping phpdbg tests');
    $skip[] = 'sapi/phpdbg.*';
}
if (version_compare($ver, '8.1.0', '<')) {
    // these tests are too slow on some platform,
    // see https://github.com/php/php-src/commit/77201d70132b284c30a6b6fd5f5ac5db2f58f638
    Log::i('skipping soap slow tests');
    $skip[] = 'sapi/phpdbg.*';
    $skip = array_merge($skip, [
        'ext/soap/tests/bug70388.phpt',
        'ext/soap/tests/bugs/bug31755.phpt',
        'ext/soap/tests/bugs/bug69085.phpt',
        'ext/soap/tests/bugs/bug77141.phpt',
    ]);
}
if ('Windows' == PHP_OS_FAMILY) {
    // see https://bugs.php.net/bug.php?id=80905
    Log::i('skipping opcache jit tests');
    $skip[] = 'ext/opcache/tests/jit.*';
    if (version_compare($ver, '7.4.0', '<')) {
        // openssl tests stuck on windows php 7.3
        Log::i('skipping openssl tests');
        $skip[] = 'ext/openssl.*';
    }
}
if ($skipStr) {
    $skip = array_filter(array_merge($skip, preg_split('|,|', $skipStr)));
}

// prepare envs
function gen_env($inputName): array
{
    global $inputs;
    $ret = [];
    foreach (explode(',', $inputs[$inputName]) as $e) {
        $kv = explode('=', $e, 2);
        if (!$kv[1]) {
            $v = null;
        } else {
            $v = $kv[1];
        }
        $ret[$kv[0]] = $v;
    }

    return $ret;
}

// generate config
$configData = [
    'tests' => array_filter(array_map('trim', explode(',', $inputs['tests']))),
    'ctrl' => [
        'binary' => $ctrl_binary,
        'workdir' => $ctrl_workdir,
        'args' => [$inputs['ctrl_args']],
        'env' => gen_env('ctrl_env'),
    ],
    'expr' => [
        'binary' => $expr_binary,
        'workdir' => $expr_workdir,
        'args' => [$inputs['expr_args']],
        'env' => gen_env('expr_env'),
    ],
    'outputs' => [
        [
            'name' => 'phpbc_results.json',
            'type' => 'json',
            'pretty' => true,
        ],
        [
            'name' => 'phpbc_results.md',
            'type' => 'markdown',
            'sames' => false,
        ],
    ],
];

if (count($skip) > 0) {
    $configData['skip'] = $skip;
}

$config = Config::init($configData);

if ($needsClone) {
    Log::i('cloning php sources');
    passthru("git clone --bare --single-branch --depth 1 --branch php-{$ver} https://github.com/php/php-src {$ctrl_workdir}.git");
    if ('Windows' === PHP_OS_FAMILY) {
        Log::i('disable autocrlf for windows');
        passthru("git --git-dir={$ctrl_workdir}.git config core.autocrlf false");
    }
    Log::i('checking out php sources for control');
    mkdir($ctrl_workdir);
    passthru("git --git-dir={$ctrl_workdir}.git --work-tree={$ctrl_workdir} reset --hard php-{$ver}");
}
if ($needsCopy) {
    if ($needsClone) {
        Log::i('checking out php sources for experiment');
        mkdir($expr_workdir);
        passthru("git --git-dir={$ctrl_workdir}.git --work-tree={$expr_workdir} reset --hard php-{$ver}");
    } else {
        Log::i('copying php sources for experiment');
        if ('Windows' === PHP_OS_FAMILY) {
            passthru("ROBOCOPY {$ctrl_workdir} {$expr_workdir} /E /MT /COMPRESS /UNICODE");
        } else {
            passthru("cp -r {$ctrl_workdir} {$expr_workdir}");
        }
    }
}

PHPbc::run();

Log::i('show md report');

Log::i(file_get_contents('phpbc_results.md'));

// comment the commit

if ((int) $context['inputs']['comment'] == 1) {
    Log::i('comment about this commit');
}
