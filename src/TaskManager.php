<?php 

declare(strict_types=1);

namespace PHPbc;

class TaskManager {
    private \SplQueue $queue;
    private array $runnning;
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
                    unset($this->running[$i]);
                }
                printf("start %s\n", $task->testName);
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
                    unset($this->running[$i]);
                }
            }
            if($living <= 0){
                break;
            }
        }
    }
    public function run(){
        print("start running\n");
        while($this->queue->count() > 0){
            $task = $this->queue->dequeue();
            $this->findSlot($task);
        }
        $this->endWait();
    }
}
