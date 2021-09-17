<?php

declare(strict_types=1);

namespace PHPbc;

class Comparation{
    private Task $ctrl;
    private Task $expr;
    public function __construct(Task $ctrl, Task $expr){
        $this->ctrl = $ctrl;
        $this->expr = $expr;
    }
    private $diffs = [];
    private $sames = [];
    private function compare(string $test){
        $testName = str_replace(DIRECTORY_SEPARATOR, "/", $test);
        $cresult = $this->ctrl->results[$test];
        if(!isset($this->expr->results[$test])){
            Log::w("no such tests in expriment", test: $test);
            $this->diffs[$testName] = [
                "type" => "$cresult:UNKNOWN"
            ];
            return;
        }

        $eresult = $this->expr->results[$test];
        $type = NULL;
        $diff = NULL;
        $reason = NULL;
        $outName = preg_replace("|\\.phpt$|", ".out", $test);
        $coutName = Util::path_join($this->ctrl->getWorkDir(), $outName);
        $eoutName = Util::path_join($this->expr->getWorkDir(), $outName);
        if($eresult === $cresult){
            $type = $cresult;
            if($cresult === "FAILED" ||
                $cresult === "XFAILED" ||
                $cresult === "LEAKED"){
                // compare outputs when both failed
                
                if(is_file($coutName) || is_file($eoutName)){
                    // at least one side outputs is present
                    $cout = is_file($coutName) ? file_get_contents($coutName) : "Control outputs is not present";
                    $eout = is_file($eoutName) ? file_get_contents($eoutName) : "Experiment outputs is not present";
                    $eout = str_replace($this->expr->getWorkDir(), $this->ctrl->getWorkDir(), $eout);
                    //printf("compare %s with %s\n", $cout, $eout);
                    $diff = Util::generate_diff($cout, NULL, $eout);
                    //printf("result %s\n", $diff);
                    // read failed reason
                    $diffName = preg_replace("|\\.phpt$|", ".diff", $test);
                    $ediffName = Util::path_join($this->expr->getWorkDir(), $diffName);
                    if(is_file($ediffName)){
                        $reason = file_get_contents($ediffName);
                        if(strlen($reason) > 4096){
                            $reason = implode("\n", [str_split($reason, 4096)[0], "..."]);
                        }
                    }
                }
            }
        }else{
            $diff = "not generated";
            $type = "$cresult:$eresult";
            if(is_file($coutName) && is_file($eoutName)){
                $diff = Util::generate_diff(file_get_contents($coutName), NULL, file_get_contents($eoutName));
            }else{
                $diffName = Util::path_join($this->expr->getWorkDir(), preg_replace("|\\.phpt$|", ".diff", $test));
                if($cresult === "PASSED" && is_file($diffName)){
                    $diff = file_get_contents($diffName);
                }
            }
        }
        if($diff){
            $this->diffs[$testName] = [
                "type" => $type,
                "diff" => $diff,
            ];
            if($reason){
                $this->diffs[$testName]["reason"] = $reason;
            }
        }else{
            if(!isset($this->sames[$type])){
                $this->sames[$type] = [];
            }
            $this->sames[$type][] = $testName;
        }
    }
    public function report(): array{
        foreach($this->ctrl->results as $ctest => $_){
            $this->compare($ctest);
        }
        return [
            "diffs" => $this->diffs,
            "sames" => $this->sames
        ];
    }
}