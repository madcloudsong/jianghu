<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


$ws = new swoole_websocket_server("0.0.0.0", 9502);
$ws->on('open', function ($ws, $request) {
    echo "hello, " .$request->fd." welcome\n";
});
$ws->on('message', function ($ws, $frame) {
    echo "Received message: {$frame->data}\n";
    $data = json_decode($frame->data, true);
    switch($data['cmd']) {
        case 1:break;
        case 2:break;
        case 3:break;
        default: $ws->push($frame->fd, json_encode(array('r' => 1, 'msg' => 'unknown cmd')));
    }
    $ws->push($frame->fd, "server: {$frame->data}");
});

$server->on('close', function ($ws, $fd) {
    echo "client {$fd} closed\n";
});



$ws->start();