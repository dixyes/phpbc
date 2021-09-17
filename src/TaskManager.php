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
    private function findSlot(Task $task):bool{
        // scan all slots to avoid conflict
        for($i = 0; $i<$this->runners; $i++){
            if(!isset($this->running[$i])){
                continue;
            }
            // wait a quantum at first
            if($this->running[$i]->wait(0.1)){
                Log::i("task", (string)$this->running[$i], "done");
                unset($this->running[$i]);
                continue;
            }
            if($this->running[$i]->getTestDir() === $task->getTestDir()){
                // same dir, conflict
                return false;
            }
        }
        // no conflict
        while(true){
            for($i = 0; $i<$this->runners; $i++){
                if(isset($this->running[$i])){
                    // wait a quantum per round
                    if(!$this->running[$i]->wait(0.1)){
                        continue;
                    }
                    Log::i("task", (string)$this->running[$i], "done");
                    unset($this->running[$i]);
                }
                Log::i("start task", (string)$task);
                $this->running[$i] = $task;
                $task->start();
                return true;
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
                    Log::i("task", (string)$this->running[$i], "done");
                    unset($this->running[$i]);
                }
            }
            if($living <= 0){
                break;
            }
        }
    }
    public function run():void{
        while($this->queue->count() > 0){
            $task = $this->queue->dequeue();
            if(!$this->findSlot($task)){
                //Log::i((string)$task, "is rejected due to conflict");
                $this->queue->enqueue($task);
            }
        }
        $this->endWait();
    }
}
