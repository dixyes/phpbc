<?php

declare(strict_types=1);

include_once __DIR__ . "/vendor/autoload.php";

use PHPbc\Task;
use PHPbc\TaskManager;
use PHPbc\Util;
use PHPbc\Log;
use PHPbc\Comparation;
use PHPbc\Config;
use PHPbc\Report;

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

if("Windows" === PHP_OS_FAMILY){
    $result["env"]=[
        "wmic os get Caption,CSDVersion,OSArchitecture,OSLanguage,TotalVisibleMemorySize,Version /value" =>
        `wmic os get Caption,CSDVersion,OSArchitecture,OSLanguage,TotalVisibleMemorySize,Version /value`,
        "wmic cpu get Caption,Name,NumberOfCores,NumberOfLogicalProcessors,Architecture /value" =>
        `wmic cpu get Caption,Name,NumberOfCores,NumberOfLogicalProcessors,Architecture /value`
    ];
}else{
    $result["env"]=[
        "uname -a" => `uname -a`
    ];
    if(is_file("/proc/cpuinfo")){
        $result["env"]["cat /proc/cpuinfo"] = @file_get_contents("/proc/cpuinfo");
    }
    if(is_file("/proc/meminfo")){
        $result["env"]["cat /proc/meminfo"] = @file_get_contents("/proc/meminfo");
    }
    if(is_file("/etc/os-release")){
        $result["env"]["cat /etc/os-release"] = @file_get_contents("/etc/os-release");
    }
}

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

$result["summary"] = [
    "overall_rate" => $diffNum/($sameNum+$diffNum),
    "real_rate" => $diffNum/($realSameNum+$diffNum),
    "all" => $sameNum+$diffNum,
    "tested" => $realSameNum+$diffNum,
    "same" => $sameNum
];

// output

foreach($config->outputs as $output){
    $report = new Report($result, $output);
    $report->generate();
}
