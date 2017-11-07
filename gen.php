<?php

//yield作为接受者,如何同时进行接收和发送的例子：

function gen() {
	echo "sss\n";
    $ret = (yield 'yield1'); //同时返回当前断点处yield1的值以及接受发送过来的值，保存进入ret进入下一步操作
    var_dump($ret);
    $ret = (yield 'yield2');
    var_dump($ret);
}
 
$gen = gen();
var_dump($gen->current());    
$gen->send('ret1'); 
$gen->send('ret2');