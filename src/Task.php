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
    private string $testBinary;
    private array $testArgs;
    private array $testEnv;
    // list file
    private /* resource */ $list;
    private /* resource */ $stdout;
    private /* resource */ $stderr;
    private /* resource */ $resultFile;

    public string $testName;
    public string $workDir;
    public array $results;

    private const COMMON_ARGS = [
        "-q"
    ];

    public function __construct(array $tests, string $workDir = ".", string $testBinary = PHP_BINARY, array $testArgs = [], $testName = "", array $testEnv = []){
        if(count($tests) < 1){
            // bad args
            throw new TaskException("bad tests set");
        }
        $this->tests = $tests;
        $this->testBinary = $testBinary;
        $this->testArgs = $testArgs;
        //$this->resultName = Util::path_join(sys_get_temp_dir(), "result" . spl_object_hash($this) . ".txt");
        $this->workDir = $workDir;
        $this->testName = $testName;
        $this->testEnv = $testEnv;
    }
    /*
    * start tests task
    */
    private const WAIT_TICK = 100000;
    public function start(){
        // prepare tests lists
        $this->resultFile = tmpfile();
        $this->list = tmpfile();
        $this->stdout = tmpfile();
        $this->stderr = tmpfile();
        fwrite($this->list, implode(PHP_EOL, $this->tests));
        $listName = stream_get_meta_data($this->list)['uri'];
        $stdoutName = stream_get_meta_data($this->stdout)['uri'];
        $stderrName = stream_get_meta_data($this->stderr)['uri'];
        
        // create process
        $cmd = $this->testBinary . " -n run-tests.php -p " . $this->testBinary .
            " " . implode(" ", self::COMMON_ARGS) .
            " " . implode(" ", $this->testArgs) .
            sprintf(" --set-timeout %d", (Config::init())->timeout).
            " -r " . $listName .
            " -W " . stream_get_meta_data($this->resultFile)['uri'];
        //Log::d("create process with [", $cmd); 
        //var_dump($this->testEnv);
        $env = array_merge(getenv(), $this->testEnv);
        $env["TEST_PHP_EXECUTABLE"] = $this->testBinary;
        $env["NO_COLOR"] = "yes";
        $env["NO_INTERACTION"] = "1";
        $env["TRAVIS_CI"] = "1";
        //var_dump($env);
        $this->process = proc_open(
            $cmd,
            [
                0 => ["pipe", "r"],
                1 => ["file", $stdoutName, "w"],
                //1 => STDOUT,
                2 => ["file", $stderrName, "w"],
                //2 => STDERR,
            ],
            $this->pipes,
            $this->workDir,
            $env
        );
        return;
    }
    private function end(){
        //printf("ending\n");
        $resultName = stream_get_meta_data($this->resultFile)['uri'];
        $resultText = trim(file_get_contents($resultName));
        $lines = preg_split('/[\r\n]+/', $resultText);
        $results = [];
        foreach($lines as $line){
            $vk = preg_split('/\s+/', $line);
            if(count($vk)<2){
                continue;
            }
            $results[$vk[1]] = $vk[0];
        }
        $this->results = $results;
        //var_dump($results);
        unset($this->resultFile);
        fclose($this->list);
        unset($this->list);
        //$stdoutName = stream_get_meta_data($this->stdout)['uri'];
        //$stderrName = stream_get_meta_data($this->stderr)['uri'];
        //var_dump(file_get_contents($stdoutName));
        //var_dump(file_get_contents($stderrName));
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
