<?php 

declare(strict_types=1);

namespace PHPbc;

class TaskManager {
    private \SplQueue $queue;
    private array $running;
    private int $runners;
    public function __construct(int $runners = 4){
        $this->queue = new \SplQueue();
        $this->queue->setIteratorMode(\SplQueue::IT_MODE_DELETE);
        $this->running = [];
        $this->runners = $runners;
    }
    public function addTask(Task $task){
        $this->queue->enqueue($task);
    }
    private function findSlot(Task $task){
        while(true){
            for($i = 0; $i<$this->runners; $i++){
                if(isset($this->running[$i])){
                    if(!$this->running[$i]->wait(0.1)){
                        continue;
                    }
                    //printf("wait done, unsetting\n");
                    Log::i("task", $this->running[$i]->testName, "done");
                    unset($this->running[$i]);
                }
                Log::i("start task", $task->testName);
                $this->running[$i] = $task;
                $task->start();
                return;
            }
        }
    }
    private function endWait(){
        while(true){
            $living = 0;
            for($i = 0; $i<$this->runners; $i++){
                if(isset($this->running[$i])){
                    if(!$this->running[$i]->wait(0.1)){
                        $living++;
                        continue;
                    }
                    Log::i("task", $this->running[$i]->testName, "done");
                    unset($this->running[$i]);
                }
            }
            if($living <= 0){
                break;
            }
        }
    }
    public function run(){
        while($this->queue->count() > 0){
            $task = $this->queue->dequeue();
            $this->findSlot($task);
        }
        $this->endWait();
    }
}
