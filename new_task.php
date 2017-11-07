<?php
class SystemCall {
    protected $callback;
 
    public function __construct(callable $callback) {
        $this->callback = $callback;
    }
 
    public function __invoke(Task $task, Scheduler $scheduler) {
        $callback = $this->callback;
        return $callback($task, $scheduler);
    }
}

class Task {
    protected $taskId;
    protected $coroutine;
    protected $sendValue = null;
    protected $beforeFirstYield = true; //第一个yield会被隐式调用，以此可以确定第一个yield的值能被正确返回.
 
    public function __construct($taskId, Generator $coroutine) {
        $this->taskId = $taskId;
        $this->coroutine = $coroutine;
    }
 
    public function getTaskId() {
        return $this->taskId;
    }
 
    public function setSendValue($sendValue) {
        $this->sendValue = $sendValue;
    }
 
    public function run() {
        if ($this->beforeFirstYield) {
            $this->beforeFirstYield = false;
            return $this->coroutine->current(); //获取第一个yield断点处的值
        } else {
            $retval = $this->coroutine->send($this->sendValue); //此处返回的是执行处yield断点的值
            $this->sendValue = null;
            return $retval;
        }
    }
 
    public function isFinished() {
        return !$this->coroutine->valid();
    }
}

class Scheduler {
    protected $maxTaskId = 0;
    protected $taskMap = []; // taskId => task
    protected $taskQueue;
 
    public function __construct() {
        $this->taskQueue = new SplQueue();
    }
 
    public function newTask(Generator $coroutine) {
        $tid = ++$this->maxTaskId;
        $task = new Task($tid, $coroutine);
        $this->taskMap[$tid] = $task;
        $this->schedule($task);
        return $tid;
    }
 
    public function schedule(Task $task) {
        $this->taskQueue->enqueue($task);
    }
 
    public function run() {
        while (!$this->taskQueue->isEmpty()) {
	        $task = $this->taskQueue->dequeue();
	        $retval = $task->run();
	 
	        if ($retval instanceof SystemCall) {
	            $retval($task, $this);
	            continue;
	        }
	 
	        if ($task->isFinished()) {
	            unset($this->taskMap[$task->getTaskId()]);
	        } else {
	            $this->schedule($task);
	        }
	    }
    }

    public function killTask($tid) {
	    if (!isset($this->taskMap[$tid])) {
	        return false;
	    }
	 
	    unset($this->taskMap[$tid]);
	 
	    // This is a bit ugly and could be optimized so it does not have to walk the queue,
	    // but assuming that killing tasks is rather rare I won't bother with it now
	    foreach ($this->taskQueue as $i => $task) {
	        if ($task->getTaskId() === $tid) {
	            unset($this->taskQueue[$i]);
	            break;
	        }
	    }
	 
	    return true;
	}
}

//重新生成一个任务的的系统调用
function newTask(Generator $coroutine) {
    return new SystemCall(
        function(Task $task, Scheduler $scheduler) use ($coroutine) {
            $task->setSendValue($scheduler->newTask($coroutine));
            $scheduler->schedule($task);
        }
    );
}

//杀死协程的系统调用
function killTask($tid) {
    return new SystemCall(
        function(Task $task, Scheduler $scheduler) use ($tid) {
            $task->setSendValue($scheduler->killTask($tid));
            $scheduler->schedule($task);
        }
    );
}

//获取当前任务ID的系统调用
function getTaskId() {
    return new SystemCall(function(Task $task, Scheduler $scheduler) {
        $task->setSendValue($task->getTaskId());
        $scheduler->schedule($task);
    });
}


/*-----------------------------
//旧的demo
function task($max) {
    $tid = (yield getTaskId()); // <-- here's the syscall!
    for ($i = 1; $i <= $max; ++$i) {
        echo "This is task $tid iteration $i.\n";
        yield;
    }
}
 
$scheduler = new Scheduler;
 
$scheduler->newTask(task(10));
$scheduler->newTask(task(5));
 
$scheduler->run();
-----------------------------*/

//新的调用
function childTask() {
    $tid = (yield getTaskId());
    while (true) {
        echo "Child task $tid still alive!\n";
        yield;
    }
}
 
function task() {
    $tid = (yield getTaskId());
    $childTid = (yield newTask(childTask()));
 
    for ($i = 1; $i <= 6; ++$i) {
        echo "Parent task $tid iteration $i.\n";
        yield;
 
        if ($i == 3) yield killTask($childTid);
    }
}
 
$scheduler = new Scheduler;
$scheduler->newTask(task());
$scheduler->run();