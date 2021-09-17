<?php

declare(strict_types=1);

namespace PHPbc;

use RuntimeException;

class PHPbc
{
    public static function run(?string $conffile = null)
    {
        // initialize config
        $config = Config::init($conffile);

        // find out all tests
        Log::i('start walk all tests');
        $tests = Util::walk_tests($config->ctrl['workdir'], filter: $config->tests, skip: $config->skip);

        if (count($tests) < 1) {
            Log::w('no tests found, exiting');
            exit();
        }

        // create task manager
        $manager = new TaskManager($config->workers);

        $ctrlTasks = [];
        $exprTasks = [];

        // generate tasks
        foreach ($tests as $testdir => $test) {
            $ctrlTasks[$testdir] = new Task(
                $test,
                workDir: $config->ctrl['workdir'],
                testBinary: $config->ctrl['binary'],
                testArgs: $config->ctrl['args'],
                testEnv: $config->ctrl['env'],
                testType: 'control tests',
                testDir: $testdir
            );
            $exprTasks[$testdir] = new Task(
                $test,
                workDir: $config->expr['workdir'],
                testBinary: $config->expr['binary'],
                testArgs: $config->expr['args'],
                testEnv: $config->expr['env'],
                testType: 'experiment tests',
                testDir: $testdir
            );
        }
        foreach ($ctrlTasks as $task) {
            $manager->addTask($task);
        }
        foreach ($exprTasks as $task) {
            $manager->addTask($task);
        }

        // start tasks
        Log::i('start run tests');
        $manager->run();

        // compare results
        $cmps = [];
        foreach ($ctrlTasks as $ctest => $ctask) {
            $cmps[] = (new Comparation($ctask, $exprTasks[$ctest]))->report();
        }

        // merge results
        $result = array_merge_recursive(...$cmps);

        // generate env infomations
        $result['env'] = [];

        $result['env']['control php -v'] = shell_exec(sprintf('%s %s -v', $config->ctrl['binary'], implode(' ', $config->ctrl['args'])));
        $result['env']['control php -m'] = shell_exec(sprintf('%s %s -m', $config->ctrl['binary'], implode(' ', $config->ctrl['args'])));

        $result['env']['experiment php -v'] = shell_exec(sprintf('%s %s -v', $config->expr['binary'], implode(' ', $config->expr['args'])));
        $result['env']['experiment php -m'] = shell_exec(sprintf('%s %s -m', $config->expr['binary'], implode(' ', $config->expr['args'])));

        if ('Windows' === PHP_OS_FAMILY) {
            $cp = (int) shell_exec('wmic os get CodeSet');
            $result['env']['wmic os get Caption,CSDVersion,OSArchitecture,OSLanguage,TotalVisibleMemorySize,Version /value'] =
                sapi_windows_cp_conv($cp, 65001, shell_exec('wmic os get Caption,CSDVersion,OSArchitecture,OSLanguage,TotalVisibleMemorySize,Version /value'));
            $result['env']['wmic cpu get Caption,Name,NumberOfCores,NumberOfLogicalProcessors,Architecture /value'] =
                sapi_windows_cp_conv($cp, 65001, shell_exec('wmic cpu get Caption,Name,NumberOfCores,NumberOfLogicalProcessors,Architecture /value'));
        } else {
            $result['env']['uname -a'] = shell_exec('uname -a');
            if (is_file('/proc/cpuinfo')) {
                $result['env']['cat /proc/cpuinfo'] = @file_get_contents('/proc/cpuinfo');
            }
            if (is_file('/proc/meminfo')) {
                $result['env']['cat /proc/meminfo'] = @file_get_contents('/proc/meminfo');
            }
            if (is_file('/etc/os-release')) {
                $result['env']['cat /etc/os-release'] = @file_get_contents('/etc/os-release');
            }
        }

        // note about result
        $diffNum = count($result['diffs']);
        $sameNum = array_sum(array_map('count', $result['sames']));

        $realSameNum = 0;
        foreach ($result['sames'] as $k => $v) {
            switch ($k) {
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
                    Log::w('strange result', $k);
                    break;
            }
        }

        if ($realSameNum === 0 && $diffNum === 0) {
            // all tests skipped
            Log::i('tested behavior change: 0 (all tests skipped)');
            $result['summary'] = [
                'overall_rate' => 0,
                'real_rate' => 0,
                'all' => $sameNum + $diffNum,
                'tested' => 0,
                'same' => 0,
            ];
        } else {
            Log::i(sprintf(
                'overall (including tests that were skipped) behavior change: %0.2f%% (%d changes/%d tests)',
                ($diffNum / ($sameNum + $diffNum)) * 100,
                $diffNum,
                $sameNum + $diffNum
            ));
            Log::i(sprintf(
                'tested behavior change: %0.2f%% (%d changes/%d tested/%d skipped)',
                ($diffNum / ($realSameNum + $diffNum)) * 100,
                $diffNum,
                $realSameNum + $diffNum,
                $sameNum - $realSameNum
            ));
            $result['summary'] = [
                'overall_rate' => $diffNum / ($sameNum + $diffNum),
                'real_rate' => $diffNum / ($realSameNum + $diffNum),
                'all' => $sameNum + $diffNum,
                'tested' => $realSameNum + $diffNum,
                'same' => $sameNum,
            ];
        }

        // output

        foreach ($config->outputs as $output) {
            try {
                $report = new Report($result, $output);
                $report->generate();
            } catch (RuntimeException $e) {
                Log::e("failed to generate report: {$e}");
            }
        }
        Log::i('done');
    }
}
