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
    const cmd_war_defence = 10;
    const cmd_war_wait = 11;
    const cmd_war_start = 12;
    const cmd_pvp_ready = 13;
    const cmd_pvp = 14;
    const cmd_pvp_list = 15;
    const cmd_pve = 16;
    const cmd_pve_list = 17;
    const cmd_ready_timeout = 18;
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
        $this->ws->on('WorkerStart', array($this, 'onWorkerStart'));
        ini_set('default_socket_timeout', -1);
        $this->redis = new redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->log('server running');
        $this->ws->start();
    }

    public function onWorkerStart($serv, $worker_id) {
        if ($worker_id == 0) {
            $serv->tick(1000, array($this, 'onTimer'));
            $key = $this->key_fd_id();
            $this->redis->delete($key);
        }
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
        if ($userid !== false) {
            $key_id = $this->key_id_fd_skey($userid);
            $key_user = $this->key_user($userid);
            $username = $this->redis->hGet($key_user, 'name');
            $this->redis->hDel($key_fd, $fd);
            $this->redis->delete($key_id);
            $this->log("client {$fd} closed\n");
            $this->send_system_msg("$username has gone");
        } else {
            $this->log('a no fd user has been removed');
        }
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
                    $old_fd = $login_info['fd'];
                    $key_fd = $this->key_fd_id();
                    $old_userid = $this->redis->hGet($key_fd, $old_fd);
                    if ($old_fd !== false && $old_userid == $userid) {
                        $this->redis->hDel($key_fd, $old_fd);
                        $this->ws->close($old_fd);
                    }
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
        } else if ($login_info['skey'] != $skey) {
            $result = array(
                'r' => 1,
                'msg' => 'login in other place or login expired',
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

    public function get_user_war_info($userid) {
        $key_user = $this->key_user($userid);
        $info = $this->redis->hGetAll($key_user);
        return array(
            'userid' => $info['userid'],
            'name' => $info['name'],
            'hp' => $info['hp'],
            'max_hp' => $info['max_hp'],
            'mp' => $info['mp'],
            'max_mp' => $info['max_mp'],
            'win' => $info['win'],
            'lose' => $info['lose'],
        );
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

    ////////////
    const WAR_STATE_READY = 1;
    const WAR_STATE_RUN = 2;
    const WAR_STATE_END = 3;
    const TIMEOUT = 10;
    const WAR_TIME_LIMIT = 300;

    protected function key_room($roomid) {
        return 'key_room-' . $roomid;
    }

    protected function key_room_list() {
        return 'key_room_list';
    }

    protected function key_room_id($userid) {
        return 'key_room_id' . $userid;
    }

    protected function get_fd_by_id($userid) {
        $key = $this->key_id_fd_skey($userid);
        $fd = $this->redis->hGet($key, 'fd');
        return $fd;
    }

    protected function get_pvp_list() {
        $key_fd = $this->key_fd_id();
        $userids = $this->redis->hVals($key_fd);
        $userids = shuffle($userids);
        $list = array();
        $count = 0;
        foreach ($userids as $userid) {
            $key_roomid = $this->key_room_id($userid);
            $roomid = $this->redis->get($key_roomid);
            if ($roomid === false) { // not in war
                $war_info = $this->get_user_war_info($userid);
                $list[$userid] = $war_info;
                $count++;
                if ($count > 10) {
                    break;
                }
            }
        }
        return $list;
    }

    protected function gen_roomid() {
        return md5(time() . rand(1, 9999));
    }

    protected function set_roomid($userid, $roomid) {
        $key = $this->key_room_id($userid);
        $this->redis->set($key, $roomid);
    }

    protected function get_roomid($userid) {
        $key = $this->key_room_id($userid);
        return $this->redis->get($key);
    }

    protected function in_war($userid) {
        $key = $this->key_room_id($userid);
        $r = $this->redis->get($key);
        return $r !== false;
    }

    protected function is_online($userid) {
        $key = $this->key_id_fd_skey($userid);
        $r = $this->redis->hGet($key, 'fd');
        return $r !== false;
    }

    public function pvp($aid, $did) {
        //error check
        if (!$this->is_online($did)) {
            $this->log("userid: $did is not online");
            return;
        }
        if ($this->in_war($aid)) {
            $this->log("userid: $did is in war");
            return;
        }
        if ($this->in_war($did)) {
            $this->log("userid: $did is in war");
            return;
        }
        ///////
        $roomid = $this->gen_roomid();

        //set room info
        $key_room = $this->key_room($roomid);
        $this->redis->hMset($key_room, array(
            'aid' => $aid,
            'did' => $did,
            'time' => time(),
        ));

        //set room list
        $key_roomlist = $this->key_room_list();
        $this->redis->hSet($key_roomlist, $roomid, self::WAR_STATE_READY);

        //set user roomid
        $this->set_roomid($aid, $roomid);
        $this->set_roomid($did, $roomid);

        $this->notice_war_wait($aid, $did);
        $this->notice_defence($aid, $did);
    }

    public function notice_defence($aid, $did) {
        
    }

    public function notice_war_wait($aid, $did) {
        
    }

    public function notice_war_start($userid, $enemy_info) {
        
    }

    public function get_war_enemyid($userid) {
        $roomid = $this->get_roomid($userid);
        if ($roomid !== false) {
            $key = $this->key_room($roomid);
            $did = $this->redis->hGet($key, 'did');
            if ($did !== false) {
                return $did;
            }
        }
        return false;
    }

    public function get_war_npcid($userid) {
        $roomid = $this->get_roomid($userid);
        if ($roomid !== false) {
            $key = $this->key_room($roomid);
            $did = $this->redis->hGet($key, 'did');
            if ($did !== false && $did == 0) {
                return $this->redis->hGet($key, 'npcid');
            }
        }
        return false;
    }

    public function pvp_ready($aid, $did) {
        $a_roomid = $this->get_roomid($aid);
        $d_roomid = $this->get_roomid($did);
        if ($a_roomid === false || $d_roomid === false || $a_roomid != $d_roomid) {
            $this->log("error, pvp_ready: aroomid: $a_roomid, droomid: $d_roomid");
            return;
        }

        $roomid = $a_roomid;
        $key_roomlist = $this->key_room_list();
        $state = $this->redis->hGet($key_roomlist, $roomid);
        if ($state === false) {
            $this->log("error, pvp_ready: state: false");
            return;
        }
        if ($state == self::WAR_STATE_READY) {
            $this->redis->hSet($key_roomlist, self::WAR_STATE_RUN);
            $ainfo = $this->get_user_war_info($aid);
            $dinfo = $this->get_user_war_info($did);
            $this->notice_war_start($aid, $dinfo);
            $this->notice_war_start($did, $ainfo);
        } else {
            $this->log("error, pvp_ready: state: $state");
            return;
        }
    }

    public function pve($aid, $npcid) {
        $key_a = $k;
    }

    /*
     * room info
     * 
     * time
     * 
     * aid
     * 
     * did
     * npcid
     * 
     * 
     */

    /**
     * ai
     */
    public function onTimer() {
        $key_list = $this->key_room_list();
        $room_list = $this->redis->hGetAll($key_list);
        if (!empty($room_list)) {
            $this->log('room_list | ' . var_export($room_list, true));
        }
        $current_time = time();
        foreach ($room_list as $roomid => $state) {
            $key_room = $this->key_room($roomid);
            $room_info = $this->redis->hGetAll($key_room);
            $aid = $room_info['aid'];
            $did = $room_info['did'];
            if ($state == self::WAR_STATE_READY) {
                $time = $room_info['time'];
                if ($time + self::TIMEOUT < $current_time) {//ready timeout
                    $this->ready_timeout($aid);
                    $this->ready_timeout($did);

                    $this->redis->hDel($key_list, $roomid);
                    $this->clear_user_war_state($aid);
                    $this->clear_user_war_state($did);
                    $this->redis->delete($key_room);
                    $this->log("remove timeout roomid: $roomid, aid: $aid, did: $did");
                } else if ($time + self::WAR_TIME_LIMIT < $current_time) {
                    //todo check winner
                    $this->redis->hDel($key_list, $roomid);

                    $this->clear_user_war_state($aid);
                    $this->clear_user_war_state($did);
                    $this->redis->delete($key_room);
                    $this->log("WAR_TIME_LIMIT timeout roomid: $roomid, aid: $aid, did: $did");
                }
            } else if ($state == self::WAR_STATE_RUN) {
                if ($did == 0) { // pve
                    $npcid = $room_info['npcid'];
                    $npc_info = array(
                        'name' => 'npc',
                        'max_hp' => 11,
                        'hp' => 11,
                        'max_mp' => 11,
                        'mp' => 11,
                        'win' => 11,
                        'lose' => 11,
                    );
                    //ai
                } else { // pvp
                }
            } else {//end
            }
        }
    }

    protected function clear_user_war_state($userid) {
        $key = $this->key_room_id($userid);
        $this->redis->delete($key);
    }

    protected function ready_timeout($userid) {
        
    }

}

$server = new GameServer();
