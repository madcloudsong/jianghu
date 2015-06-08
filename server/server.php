<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

$ws = new swoole_websocket_server("0.0.0.0", 9502);
$ws->on('open', function ($ws, $request) {
    echo "hello, welcome\n";
});
$ws->on('message', function ($ws, $frame) {
    echo "Received message: {$frame->data}\n";
    $ws->push($frame->fd, "server: {$frame->data}");
});
$ws->start();