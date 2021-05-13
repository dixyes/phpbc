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

Log::i("end");
