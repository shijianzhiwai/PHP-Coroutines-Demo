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
 	protected $waitingForRead = [];
	protected $waitingForWrite = [];

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
    	$this->newTask($this->ioPollTask()); //添加io阻塞等待协程
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

	public function waitForRead($socket, Task $task) {
	    if (isset($this->waitingForRead[(int) $socket])) {
	        $this->waitingForRead[(int) $socket][1][] = $task;
	    } else {
	        $this->waitingForRead[(int) $socket] = [$socket, [$task]];
	    }
	}
	 
	public function waitForWrite($socket, Task $task) {
	    if (isset($this->waitingForWrite[(int) $socket])) {
	        $this->waitingForWrite[(int) $socket][1][] = $task;
	    } else {
	        $this->waitingForWrite[(int) $socket] = [$socket, [$task]];
	    }
	}

	protected function ioPoll($timeout) {
	    $rSocks = [];
	    foreach ($this->waitingForRead as list($socket)) {
	        $rSocks[] = $socket;
	    }
	 
	    $wSocks = [];
	    foreach ($this->waitingForWrite as list($socket)) {
	        $wSocks[] = $socket;
	    }
	 
	    $eSocks = []; // dummy
	 
	    if (!@stream_select($rSocks, $wSocks, $eSocks, $timeout)) {
	        return;
	    }
	 
	    foreach ($rSocks as $socket) {
	        list(, $tasks) = $this->waitingForRead[(int) $socket];
	        unset($this->waitingForRead[(int) $socket]);
	 
	        foreach ($tasks as $task) {
	            $this->schedule($task);
	        }
	    }
	 
	    foreach ($wSocks as $socket) {
	        list(, $tasks) = $this->waitingForWrite[(int) $socket];
	        unset($this->waitingForWrite[(int) $socket]);
	 
	        foreach ($tasks as $task) {
	            $this->schedule($task);
	        }
	    }
	}

	protected function ioPollTask() {
	    while (true) {
	        if ($this->taskQueue->isEmpty()) {
	            $this->ioPoll(null);
	        } else {
	            $this->ioPoll(0);
	        }
	        yield;
	    }
	}
}

function waitForRead($socket) {
    return new SystemCall(
        function(Task $task, Scheduler $scheduler) use ($socket) {
            $scheduler->waitForRead($socket, $task);
        }
    );
}
 
function waitForWrite($socket) {
    return new SystemCall(
        function(Task $task, Scheduler $scheduler) use ($socket) {
            $scheduler->waitForWrite($socket, $task);
        }
    );
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

function server($port) {
    echo "Starting server at port $port...\n";
 
    $socket = @stream_socket_server("tcp://localhost:$port", $errNo, $errStr);
    if (!$socket) throw new Exception($errStr, $errNo);

    stream_set_blocking($socket, 0);
 
    while (true) {
        yield waitForRead($socket);
        $clientSocket = stream_socket_accept($socket, 0);
        yield newTask(handleClient($clientSocket));
    }
}
 
function handleClient($socket) {
    yield waitForRead($socket);
    $data = fread($socket, 8192);
    $msg = "Received following request:\n\n$data";
    $msgLength = strlen($msg);
 
    $response = <<<RES
HTTP/1.1 200 OK\r
Content-Type: text/plain\r
Content-Length: $msgLength\r
Connection: close\r
\r
$msg
RES;
 
    yield waitForWrite($socket);
    fwrite($socket, $response);
 
    fclose($socket);
}
 
$scheduler = new Scheduler;
$scheduler->newTask(server(8020));
$scheduler->run();