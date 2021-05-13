<?php

declare(strict_types=1);

include_once __DIR__ . "/vendor/autoload.php";

use PHPbc\Task;
use PHPbc\TaskManager;
use PHPbc\Util;
use PHPbc\Log;
use PHPbc\Comparation;
use PHPbc\Config;

// make all warnings into exceptions
Util::enable_error_handler();

// initialize config
$config = Config::init();

// find out all tests
Log::i("start walk all tests");
$tests = Util::walk_tests($config->ctrl["workdir"], filter: $config->tests, skip: $config->skip);

// create task manager
$manager = new TaskManager($config->workers);

$ctrlTasks = [];
$exprTasks = [];

// generate tasks
foreach($tests as $testdir => $test){
    $ctrlTasks[$testdir] = new Task(
        $test,
        testName: "control tests at $testdir",
        testBinary: $config->ctrl["binary"],
        workDir: $config->ctrl["workdir"],
        testArgs: $config->ctrl["args"]
    );
    $exprTasks[$testdir] = new Task(
        $test,
        testName: "expr tests at $testdir",
        testBinary: $config->expr["binary"],
        workDir: $config->expr["workdir"],
        testArgs: $config->expr["args"]
    );
}
foreach($ctrlTasks as $task){
    $manager->addTask($task);
}
foreach($exprTasks as $task){
    $manager->addTask($task);
}

// start tasks
Log::i("start run tests");
$manager->run();

// compare results
$cmps = [];
foreach($ctrlTasks as $ctest => $ctask){
    $cmps[] = (new Comparation($ctask, $exprTasks[$ctest]))->report();
}

// merge results
$result = array_merge_recursive(...$cmps);

// output
file_put_contents($config->output, json_encode($result, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

Log::i("output wrote to", $config->output);

// note about result
$diffNum = count($result["diffs"]);
$sameNum = array_sum(array_map("count", $result["sames"]));

$realSameNum = 0;
foreach($result["sames"] as $k => $v){
    switch($k){
        case 'SKIPPED':
        case 'BORKED':
            break;
        case 'PASSED':
        case 'WARNED':
        case 'FAILED':
        case 'LEAKED':
        case 'XFAILED':
        case 'XLEAKED':
            $realSameNum += count($v);
            break;
        default:
            $realSameNum += count($v);
            Log::w("strange result", $k);
            break;
    }
};

Log::i(sprintf("overall (including tests that were skipped) behavior change: %0.2f%% (%d changes/%d tests)",
    ($diffNum/($sameNum+$diffNum))*100,
    $diffNum,
    $sameNum+$diffNum));
Log::i(sprintf("tested behavior change: %0.2f%% (%d changes/%d tested/%d skipped)",
    ($diffNum/($realSameNum+$diffNum))*100,
    $diffNum,
    $realSameNum+$diffNum,
    $sameNum-$realSameNum));
