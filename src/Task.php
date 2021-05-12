<?php 

declare(strict_types=1);

namespace PHPbc;

/*
* Task - a run-tests.php task
* a task contains a run-tests.php process and its subprocess,
*/
class Task {
    // tests will be executed in this task
    private array $tests;
    // list file
    private /* resource */ $list;
    private /* resource */ $stdout;
    private /* resource */ $stderr;
    private string $resultName;

    public string $testName;
    public string $workdir;
    public array $results;

    private const COMMON_ARGS = [
        "--no-color",
        "-q"
    ];

    public function __construct(array $tests, string $workDir = ".", string $testBinary = PHP_BINARY, array $testArgs = [], $testName = ""){
        if(count($tests) < 1){
            // bad args
            throw new TaskException("bad tests set");
        }
        $this->tests = $tests;
        $this->testBinary = $testBinary;
        $this->testArgs = $testArgs;
        $this->resultName = Util::path_join(sys_get_temp_dir(), "result" . spl_object_hash($this) . ".txt");
        $this->workDir = $workDir;
        $this->testName = $testName;
    }
    /*
    * start tests task
    */
    private const WAIT_TICK = 100000;
    public function start(){
        // prepare tests lists
        $this->list = tmpfile();
        $this->stdout = tmpfile();
        $this->stderr = tmpfile();
        fwrite($this->list, implode(PHP_EOL, $this->tests));
        $listName = stream_get_meta_data($this->list)['uri'];
        $stdoutName = stream_get_meta_data($this->stdout)['uri'];
        $stderrName = stream_get_meta_data($this->stderr)['uri'];
        @unlink($this->resultName);
        
        // create process
        $cmd = PHP_BINARY . " -n run-tests.php -p " . $this->testBinary .
            " " . implode(" ", self::COMMON_ARGS) .
            " " . implode(" ", $this->testArgs) .
            sprintf(" --set-timeout %d", (Config::init())->timeout).
            " -r " . $listName .
            " -W " . $this->resultName;
        //printf("create process with %s\n", $cmd);
        $this->process = proc_open(
            $cmd,
            [
                0 => ["pipe", "r"],
                1 => ["file", $stdoutName, "w"],
                2 => ["file", $stderrName, "w"],
            ],
            $this->pipes,
            $this->workDir
        );
        return;
    }
    private function end(){
        //printf("ending\n");
        $resultText = trim(file_get_contents($this->resultName));
        $lines = preg_split('/[\r\n]+/', $resultText);
        $results = [];
        foreach($lines as $line){
            $vk = preg_split('/\s+/', $line);
            $results[$vk[1]] = $vk[0];
        }
        $this->results = $results;
        //var_dump($results);
        unlink($this->resultName);
        unset($this->resultName);
        fclose($this->list);
        unset($this->list);
        fclose($this->stdout);
        unset($this->stdout);
        fclose($this->stderr);
        unset($this->stderr);
        unset($this->process);
        //printf("end\n");
    }
    /*
    * wait tests task done
    * timeout is in seconds, a negative timeout will make it wait infinite
    */
    public function wait(float $timeout = -1):bool{
        if(!isset($this->process)){
            throw new TaskException("task is not started");
        }
        $remaining = (int)($timeout * 1000000);
        //printf("wait start\n");
        while(true){
            $status = proc_get_status($this->process);
            //var_dump($status["running"]);
            if(!$status["running"]){
                $this->end();
                return true;
            }
            if($timeout < 0){
                usleep(self::WAIT_TICK);
                continue;
            }
            usleep($remaining > self::WAIT_TICK ? self::WAIT_TICK : $remaining);
            $remaining -= self::WAIT_TICK;
            if($remaining <= 0){
                return false;
            }
        }
    }
}
