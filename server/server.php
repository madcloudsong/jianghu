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
    public $redis;
    public $user_array = array();
    const SUB_PUB_KEY = 'sub_pub_key';
    
    private $temp;
    private $has_sub = false;

    public function __construct() {
        $this->ws = new swoole_websocket_server("0.0.0.0", 9502);
        $this->ws->set(array(
            'worker_num' => 8,
            'task_worker_num' => 8,
            'daemonize' => false,
            'max_request' => 10000,
            'dispatch_mode' => 2,
            'debug_mode'=> 1
        ));
        $this->ws->on('open', array($this, 'onOpen'));
        $this->ws->on('message', array($this, 'onMessage'));
        $this->ws->on('close', array($this, 'onClose'));
        $this->ws->on('Task', array($this, 'onTask'));
        $this->ws->on('Finish', array($this, 'onFinish'));
        $this->redis = new redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->ws->start();
    }
    
    public function onOpen($ws, $request) {
        echo "hello, " . $request->fd . " welcome\n";
        if(!$this->has_sub && !$ws->taskworker) {
            $this->sub();
        }
    }

    public function onMessage($ws, $frame) {
        echo "Received message: {$frame->data}\n";
        $data = json_decode($frame->data, true);
        switch ($data['cmd']) {
            case self::cmd_name:$this->set_name($frame->fd, $data);
                break;
            case self::cmd_chat:$this->chat($frame->fd, $data);
                break;
            case self::cmd_msg:
                break;
            default: $ws->push($frame->fd, json_encode(array('r' => 1, 'msg' => 'unknown cmd')));
        }
    }

    public function onClose($ws, $fd) {
        echo "client {$fd} closed\n";
    }
    
    public function onTask($ws, $task_id, $from_id, $data){
        echo $task_id."|".$from_id."|".var_export($data,true) . "\n";
        $this->redis->subscribe(array(self::SUB_PUB_KEY), array($this, 'onSub'));
        return $task_id . '|'.$from_id . "|" . $this->temp;
    }
    
    public function onSub($redis, $chan, $msg){
        $redis->unsubscribe();
        $this->temp = $msg;
        echo 'onsub' . $chan . '|' . $msg . "\n";
    }

    public function onFinish($ws, $task_id, $data){
        echo 'wid : '. $ws->worker_id . $task_id."|".var_export($data,true) . "\n";
        $this->redis->subscribe(array(self::SUB_PUB_KEY), array($this, 'onSub'));
    }

    public function sub() {
        $wid = $this->ws->worker_id;
        $this->ws->task($wid);
    }

    protected function user_key($name) {
        return 'user-' . $name;
    }

    public function set_name($fd, $data) {
        $name = $data['name'];
        $key = $this->user_key($name);
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
            'name' => 'test',
            'chat' => $chat,
            'time' => date('H:i:s'),
        );
        $this->ws->push($fd, json_encode($result));
    }

    public function send_msg($fd, $msg, $self, $enemy) {
        $result = array(
            'r' => 0,
            'msg' => '',
            'cmd' => self::cmd_msg,
            'name' => $msg,
            'self' => $self,
            'enemy' => $enemy,
            'time' => date('H:i:s'),
        );
        $this->ws->push($fd, json_encode($result));
    }

}

$server = new GameServer();
