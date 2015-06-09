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
    const cmd_system = 9;
    const cmd_login = 100;
    const cmd_register = 101;
    const cmd_error = 999;

    public $ws;
    public $redis;
    private $temp;
    private $has_sub = false;

    const NAME_GAME = 'JIANGHU';

    public function __construct() {
        $this->ws = new swoole_websocket_server("0.0.0.0", 9502);
        $this->ws->set(array(
            'worker_num' => 2,
            'task_worker_num' => 2,
            'daemonize' => false,
            'max_request' => 10000,
            'dispatch_mode' => 2,
            'debug_mode' => 1
        ));
        $this->ws->on('open', array($this, 'onOpen'));
        $this->ws->on('message', array($this, 'onMessage'));
        $this->ws->on('close', array($this, 'onClose'));
        $this->ws->on('Task', array($this, 'onTask'));
        $this->ws->on('Finish', array($this, 'onFinish'));
        $this->ws->on('Shutdown', array($this, 'onShutdown'));
        ini_set('default_socket_timeout', -1);
        $this->redis = new redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->ws->start();
    }

    public function onOpen($ws, $request) {
        echo "hello, " . $request->fd . " welcome\n";
    }

    public function onShutdown($ws) {
        $this->log('server shutdown ');
        $key_fd = $this->key_fd_id();
        $userids = $this->redis->hVals($key_fd);
        foreach ($userids as $userid) {
            $key = $this->key_id_fd_skey($userid);
            $this->redis->delete($key);
        }
    }

    public function onMessage($ws, $frame) {
        echo 'wid: ' . $ws->worker_id . " Received message: {$frame->data}\n";
        $data = json_decode($frame->data, true);
        switch ($data['cmd']) {
            case self::cmd_name:$this->set_name($frame->fd, $data);
                break;
            case self::cmd_chat:$this->chat($frame->fd, $data);
                break;
            case self::cmd_msg:
                break;
            case self::cmd_login:
                $this->login($frame->fd, $data);
                break;
            case self::cmd_register:
                $this->register($frame->fd, $data);
                break;
            default: $ws->push($frame->fd, json_encode(array('r' => 1, 'msg' => 'unknown cmd')));
        }
    }

    public function onClose($ws, $fd) {
        $key_fd = $this->key_fd_id();
        $userid = $this->redis->hGet($key_fd, $fd);
        $key_id = $this->key_id_fd_skey($userid);
        $key_user = $this->key_user($userid);
        $username = $this->redis->hGet($key_user, 'name');
        $this->redis->hDel($key_fd, $fd);
        $this->redis->delete($key_id);
        $this->log("client {$fd} closed\n");
        $this->send_system_msg("$username has gone");
    }

    public function send_system_msg($msg, $all = 1, $fd = 0) {
        $data = array(
            'r' => 0,
            'msg' => '',
            'cmd' => self::cmd_system,
            'name' => self::NAME_GAME,
            'chat' => $msg,
            'time' => date('H:i:s'),
        );

        if ($all) {
            $this->broadcast(json_encode($data));
            $this->log('send_system_msg all: ' . $msg, $fd);
        } else {
            $this->ws->push($fd, json_encode($data));
            $this->log('send_system_msg single: ' . $msg, $fd);
        }
    }

    public function onTask($ws, $task_id, $from_id, $data) {
        if ($data['type'] == 1) {
            $key_fd = $this->key_fd_id();
            $fds = $this->redis->hKeys($key_fd);
            $this->log("fromid: $from_id , send to fds: " . var_export($fds, true));
            foreach ($fds as $fd) {
                $ws->push($fd, $data['data']);
                $this->log("send to fd: $fd , " . $data['data']);
            }
        }
        echo $task_id . "|" . $from_id . "|" . var_export($data, true) . "\n";
        return "taskid = $task_id; fromid= $from_id " . ' done|' . var_export($data, true);
    }

    public function onFinish($ws, $task_id, $data) {
        $this->log("wid={$ws->worker_id} , taskid=$task_id" . $data);
    }

    public function login($fd, $data) {
        $userid = $data['userid'];
        $password = $data['password'];
        $key_user = $this->key_user($userid);
        $user_info = $this->redis->hGetAll($key_user);
        $result = array(
            'r' => 0,
            'msg' => '',
            'cmd' => self::cmd_login,
        );
        if (empty($user_info)) {
            $this->log("userid not exist/user info empty error: userid=$userid", $fd);
            $result['r'] = 4;
            $result['msg'] = 'login failed: userid not exist';
        } else {
            if ($user_info['password'] == md5($password)) {
                $key_skey = $this->key_id_fd_skey($userid);
                $login_info = $this->redis->hGetAll($key_skey);
                if (!empty($login_info)) {
                    $this->log("login failed: already login! userid=$userid", $fd);
                }
                $this->log("login success: userid=$userid", $fd);
                $result['userid'] = $user_info['userid'];
                $skey = md5($userid . rand(1, 99999));
                $result['skey'] = $skey;
                $this->redis->hMset($key_skey, array('skey' => $skey, 'fd' => $fd));
                $this->add_fd($fd, $userid);
                if (isset($user_info['name'])) {
                    $result['name'] = $user_info['name'];
                    $result['self'] = array(
                        'hp' => $user_info['hp'],
                        'max_hp' => $user_info['max_hp'],
                        'mp' => $user_info['mp'],
                        'max_mp' => $user_info['max_mp'],
                        'win' => $user_info['win'],
                        'lose' => $user_info['lose'],
                    );
                    $this->send_system_msg($user_info['name'] . ' come in');
                    $this->send_welcome($fd, $user_info['name']);
                }
            } else {
                $result['r'] = 1;
                $result['msg'] = 'login failed: password wrong!';
                $this->log("login failed: password wrong: userid=$userid", $fd);
            }
        }

        $this->ws->push($fd, json_encode($result));
    }

    public function register($fd, $data) {
        $userid = $data['userid'];
        $password = $data['password'];
        $key_user = $this->key_user($userid);
        $user_info = $this->redis->hGetAll($key_user);
        $result = array(
            'r' => 0,
            'msg' => '',
            'cmd' => self::cmd_register,
        );
        if (!empty($user_info)) {
            $this->log("regiseter failed, userid exist: userid=$userid", $fd);
            $result['r'] = 1;
            $result['msg'] = 'regiseter failed: userid exist!';
        } else {
            $key_skey = $this->key_id_fd_skey($userid);
            $password = md5($password);
            $skey = md5($userid . rand(1, 99999));
            $this->redis->hMset($key_skey, array('skey' => $skey, 'fd' => $fd));
            $this->redis->hMset($key_user, array('userid' => $userid, 'password' => $password));
            $this->add_fd($fd, $userid);
            $result['userid'] = $userid;
            $result['skey'] = $skey;
            $this->log("regiseter success: userid=$userid", $fd);
        }

        $this->ws->push($fd, json_encode($result));
    }

    protected function key_fd_id() {
        return 'key_fd_id';
    }

    protected function key_id_fd_skey($userid) {
        return 'key_id_fd_skey-' . $userid;
    }

    protected function key_user($userid) {
        return 'key_user-' . $userid;
    }

    public function check_login($fd, $data) {
        $userid = $data['userid'];
        $skey = $data['skey'];
        $key_skey = $this->key_id_fd_skey($userid);
        $login_info = $this->redis->hGetAll($key_skey);
        if (empty($login_info)) {
            $result = array(
                'r' => 1,
                'msg' => 'not login or login expired',
                'cmd' => self::cmd_error,
            );
            $this->log('userid: ' . $userid . ' | skey = ' . $skey . ' login expired', $fd);
            $this->ws->push($fd, json_encode($result));
            return false;
        } else {
            return true;
        }
    }

    public function add_fd($fd, $userid) {
        $key_fd = $this->key_fd_id();
        $result = $this->redis->hGet($key_fd, $fd, $userid);
        if ($result !== false) {
            $this->log("add_fd error, try to set fd:$fd, userid: $userid");
        } else {
            $this->redis->hSet($key_fd, $fd, $userid);
        }
    }

    public function set_name($fd, $data) {
        if (!$this->check_login($fd, $data)) {
            return;
        }
        $name = $data['name'];
        $userid = $data['userid'];
        $key_user = $this->key_user($userid);
        $user_info = $this->redis->hGetAll($key_user);
        if (!isset($user_info['name'])) {
            $this->redis->hSet($key_user, 'name', $name);
            $this->log('set name : ' . $name, $fd);
            $result = array(
                'r' => 0,
                'msg' => '',
                'cmd' => self::cmd_name,
                'name' => $name,
            );
            $attr = $this->init_attr();
            $this->log('set name and init attr: ' . var_export($attr, true), $fd);
            $this->redis->hMset($key_user, $attr);
            $result['self'] = $attr;
            $this->ws->push($fd, json_encode($result));
            $this->send_system_msg($name . ' come in');
            $this->send_welcome($fd, $name);
        } else {
            $this->log('set name twice', $fd);
        }
    }

    protected function send_welcome($fd, $name) {
        $msg = $this->add_span("Welcome $name", 'green');
        $this->send_msg($fd, $this->add_span(self::NAME_GAME, 'orange'), $msg);
    }

    protected function add_span($msg, $color = 'black') {
        return '<span class="' . $color . '">' . $msg . '</span>';
    }

    public function init_attr() {
        $max_hp = rand(8, 11);
        $max_mp = rand(8, 11);
        $win = 0;
        $lose = 0;
        return array(
            'hp' => $max_hp,
            'max_hp' => $max_hp,
            'mp' => $max_mp,
            'max_mp' => $max_mp,
            'win' => $win,
            'lose' => $lose,
        );
    }

    public function log($msg, $fd = 0) {
        echo '[' . date('Ymd H:i:s') . '] wid:' . $this->ws->worker_id . ' | fd:' . $fd . ' | ' . $msg . "\n";
    }

    public function chat($fd, $data) {
        if (!$this->check_login($fd, $data)) {
            return;
        }
        $chat = $data['chat'];
        $user_info = $this->get_user_info($data['userid']);
        $result = array(
            'r' => 0,
            'msg' => '',
            'cmd' => self::cmd_chat,
            'name' => $user_info['name'],
            'chat' => $chat,
            'time' => date('H:i:s'),
        );
        $this->broadcast(json_encode($result));
    }

    public function get_user_info($userid) {
        $key_user = $this->key_user($userid);
        return $this->redis->hGetAll($key_user);
    }

    public function broadcast($data) {
        $this->ws->task(array('type' => 1, 'data' => $data));
    }

    public function send_msg($fd, $name, $msg, $self = null, $enemy = null) {
        $result = array(
            'r' => 0,
            'cmd' => self::cmd_msg,
            'name' => $name,
            'msg' => $msg,
            'time' => date('H:i:s'),
        );
        if ($self !== null) {
            $result['self'] = $self;
        }
        if ($enemy !== null) {
            $result['enemy'] = $enemy;
        }
        $this->ws->push($fd, json_encode($result));
    }

}

$server = new GameServer();
