<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

class GameServer {

    const cmd_name = 1;
    const cmd_chat = 2;
    const cmd_attack = 3;
    const cmd_defence = 4;
    const cmd_rest = 5;
    const cmd_reborn = 6;
    const cmd_msg = 7;
    const cmd_list = 8;

    public $ws;

    public function __construct() {
        $this->ws = new swoole_websocket_server("0.0.0.0", 9502);
        $this->ws->on('open', function ($ws, $request) {
            echo "hello, " . $request->fd . " welcome\n";
        });
        $this->ws->on('message', array($this, 'onMessage'));
        $this->ws->on('close', array($this, 'onClose'));
        $this->ws->start();
    }

    public function onMessage($ws, $frame) {
        echo "Received message: {$frame->data}\n";
        $data = json_decode($frame->data, true);
        switch ($data['cmd']) {
            case self::cmd_name:$this->set_name($frame->fd, $data);
                break;
            case self::cmd_chat:$this->chat($frame->fd, $data);
                break;
            case self::cmd_msg:break;
            default: $ws->push($frame->fd, json_encode(array('r' => 1, 'msg' => 'unknown cmd')));
        }
        //$ws->push($frame->fd, "server: {$frame->data}");
    }

    public function onClose($ws, $fd) {
        echo "client {$fd} closed\n";
    }

    public function set_name($fd, $data) {
        $name = $data['name'];
        $result = array(
            'r' => 0,
            'msg' => '',
            'cmd' => self::cmd_name,
            'name' => $name,
        );
        $this->ws->push($fd, json_encode($result));
    }

    public function chat($fd, $data) {
        $chat = $data['chat'];
        $result = array(
            'r' => 0,
            'msg' => '',
            'cmd' => self::cmd_chat,
            'chat' => $chat,
        );
        $this->ws->push($fd, json_encode($result));
    }

}


$server = new GameServer();
