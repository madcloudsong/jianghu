<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
require __DIR__ . '/lib/Key.php';

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
    const cmd_pvp_reject = 19;
    const cmd_pvp_cancel = 20;
    const cmd_system_msg = 21;
    const cmd_war_end = 22;
    const cmd_war_cd = 23;
    const cmd_login = 100;
    const cmd_register = 101;
    const cmd_error = 999;
    const AI_INTERVAL = 3000;

    public $ws;
    public $redis;

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
            $serv->tick(self::AI_INTERVAL, array($this, 'onTimer'));
            $key = Key::key_fd_id();
            $this->redis->delete($key);
        }
    }

    public function onOpen($ws, $request) {
        echo "hello, " . $request->fd . " welcome\n";
    }

    public function onShutdown($ws) {
        $this->log('server shutdown ');
        $key_fd = Key::key_fd_id();
        $userids = $this->redis->hVals($key_fd);
        foreach ($userids as $userid) {
            $key = Key::key_id_fd_skey($userid);
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
            case self::cmd_pvp_list:
                $this->cmd_pvp_list($frame->fd, $data);
                break;
            case self::cmd_pvp:
                $this->cmd_pvp($frame->fd, $data);
                break;
            case self::cmd_pvp_ready:
                $this->cmd_pvp_ready($frame->fd, $data);
                break;
            case self::cmd_pvp_reject:
                $this->cmd_pvp_reject($frame->fd, $data);
                break;
            case self::cmd_pvp_cancel:
                $this->cmd_pvp_cancel($frame->fd, $data);
                break;
            case self::cmd_attack:
                $this->cmd_attack($frame->fd, $data);
                break;
            case self::cmd_defence:
                $this->cmd_defence($frame->fd, $data);
                break;
            case self::cmd_rest:
                $this->cmd_rest($frame->fd, $data);
                break;
            default: $ws->push($frame->fd, json_encode(array('r' => 1, 'msg' => 'unknown cmd')));
        }
    }

    public function onClose($ws, $fd) {
        $key_fd = Key::key_fd_id();
        $userid = $this->redis->hGet($key_fd, $fd);
        if ($userid !== false) {
            $key_id = Key::key_id_fd_skey($userid);
            $key_user = Key::key_user($userid);
            $username = $this->redis->hGet($key_user, 'name');
            $this->redis->hDel($key_fd, $fd);
            $this->redis->delete($key_id);
            $this->log("client {$fd} closed\n");
            $this->send_system_chat("$username has gone");
        } else {
            $this->log('a no fd user has been removed');
        }
    }

    public function send_system_chat($msg, $all = 1, $fd = 0) {
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
            $this->log('send_system_chat all: ' . $msg, $fd);
        } else {
            $this->ws->push($fd, json_encode($data));
            $this->log('send_system_chat single: ' . $msg, $fd);
        }
    }

    public function onTask($ws, $task_id, $from_id, $data) {
        if ($data['type'] == 1) {
            $key_fd = Key::key_fd_id();
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
        $key_user = Key::key_user($userid);
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
                $key_skey = Key::key_id_fd_skey($userid);
                $login_info = $this->redis->hGetAll($key_skey);
                if (!empty($login_info)) {
                    $this->log("login failed: already login! userid=$userid", $fd);
                    $old_fd = $login_info['fd'];
                    $key_fd = Key::key_fd_id();
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
                    $this->send_system_chat($user_info['name'] . ' come in');
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
        $key_user = Key::key_user($userid);
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
            $key_skey = Key::key_id_fd_skey($userid);
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

    public function check_login($fd, $data) {
        $userid = $data['userid'];
        $skey = $data['skey'];
        $key_skey = Key::key_id_fd_skey($userid);
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
        $key_fd = Key::key_fd_id();
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
        $key_user = Key::key_user($userid);
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
            $this->pushto($fd, $result);
            $this->send_system_chat($name . ' come in');
            $this->send_welcome($fd, $name);
        } else {
            $this->log('set name twice', $fd);
        }
    }

    protected function pushto($fd, $data) {
        $this->ws->push($fd, json_encode($data));
    }

    protected function send_welcome($fd, $name) {
        $msg = $this->add_span("Welcome $name", 'green');
        $this->send_msg($fd, $this->add_span(self::NAME_GAME, 'orange'), $msg);
    }

    protected function add_span($msg, $color = 'black') {
        return '<span class="' . $color . '">' . $msg . '</span>';
    }

    public function init_attr() {
        $max_hp = rand(90, 110);
        $max_mp = 200 - $max_hp;
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
        $key_user = Key::key_user($userid);
        return $this->redis->hGetAll($key_user);
    }

    public function get_user_war_info($userid) {
        $key_user = Key::key_user($userid);
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

    public function send_system_msg($fd, $msg, $self = null, $enemy = null) {
        $result = array(
            'r' => 0,
            'cmd' => self::cmd_system_msg,
            'name' => self::NAME_GAME,
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
    const WAR_TIME_LIMIT = 60;
    const FAILER_A = 1;
    const FAILER_D = 2;
    const CD_ATTACK = 1;
    const CD_DEFENCE = 2;
    const CD_REST = 3;
    const CD_BIAS = 0.2;

    protected $cd_map = array(
        self::cmd_attack => self::CD_ATTACK,
        self::cmd_defence => self::CD_DEFENCE,
        self::cmd_rest => self::CD_REST,
    );

    public function cmd_pvp_list($fd, $data) {
        if (!$this->check_login($fd, $data)) {
            return;
        }
        $userid = $data['userid'];
        $list = $this->get_pvp_list($userid);
        $result = array(
            'r' => 0,
            'msg' => '',
            'cmd' => self::cmd_pvp_list,
            'list' => $list,
        );
        $this->pushto($fd, $result);
    }

    public function cmd_pvp($fd, $data) {
        if (!$this->check_login($fd, $data)) {
            return;
        }
        $userid = $data['userid'];
        $did = $data['did'];
        $this->pvp($userid, $did);
    }

    public function cmd_pvp_ready($fd, $data) {
        if (!$this->check_login($fd, $data)) {
            return;
        }
        $userid = $data['userid'];
        $aid = $data['aid'];
        $this->pvp_ready($aid, $userid);
    }

    public function cmd_pvp_reject($fd, $data) {
        if (!$this->check_login($fd, $data)) {
            return;
        }
        $userid = $data['userid'];
        $aid = $data['aid'];
        $this->pvp_reject($aid, $userid);
    }

    //battle
    public function cmd_attack($fd, $data) {
        if (!$this->check_login($fd, $data)) {
            return;
        }
        $userid = $data['userid'];
        $this->battle(self::cmd_attack, $userid);
    }

    public function cmd_defence($fd, $data) {
        if (!$this->check_login($fd, $data)) {
            return;
        }
        $userid = $data['userid'];
        $this->battle(self::cmd_defence, $userid);
    }

    public function cmd_rest($fd, $data) {
        if (!$this->check_login($fd, $data)) {
            return;
        }
        $userid = $data['userid'];
        $this->battle(self::cmd_rest, $userid);
    }

    public function battle($cmd, $userid) {
        //check roomid if in battle
        $roomid = $this->get_roomid($userid);
        if ($roomid === false) {
            $this->log("battle error no room id userid: $userid");
            return;
        }

        //check room state if running
        $state = $this->get_room_state($roomid);
        if ($state != self::WAR_STATE_RUN) {
            $this->log("battle error state error userid: $userid, roomid : $roomid, state : $state");
            return;
        }
        //cal enemyid
        $roominfo = $this->get_room_info($roomid);
        $aid = $roominfo['aid'];
        $did = $roominfo['did'];
        if ($aid == $userid) {
            $enemyid = $did;
        } else if ($did == $userid) {
            $enemyid = $aid;
        } else {
            $this->log("battle error no room id userid: $userid, aid: $aid, did: $did");
            return;
        }

        $key_timeout = Key::key_timeout($userid, $cmd);
        $skill_time = isset($roominfo[$key_timeout]) ? $roominfo[$key_timeout] : 0;
        $cd = $this->cd_map[$cmd];
        $current_time = microtime(true);
        if ($skill_time + $cd - self::CD_BIAS > $current_time) {
            $this->log("battle error cd userid: $userid, aid: $aid, did: $did, cmd: $cmd, skilltime: $skill_time, cd: $cd, ctime: $current_time");
            return;
        }
        //battle logic maybe need lock
        $self_info = $this->get_user_war_info($userid);
        $enemy_info = $this->get_user_war_info($enemyid);
        if ($cmd == self::cmd_attack) {
            $this->notice_battle_msg($userid, $self_info['name'], 'attack', $self_info, $enemy_info);
            $this->notice_battle_msg($enemyid, $self_info['name'], 'attack', $enemy_info, $self_info);
        } else if ($cmd == self::cmd_defence) {
            $this->notice_battle_msg($userid, $self_info['name'], 'cmd_defence', $self_info, $enemy_info);
            $this->notice_battle_msg($enemyid, $self_info['name'], 'cmd_defence', $enemy_info, $self_info);
        } else if ($cmd == self::cmd_rest) {
            $this->notice_battle_msg($userid, $self_info['name'], 'cmd_rest', $self_info, $enemy_info);
            $this->notice_battle_msg($enemyid, $self_info['name'], 'cmd_rest', $enemy_info, $self_info);
        } else {
            $this->log("battle error no cmd: $cmd, userid: $userid, aid: $aid, did: $did");
        }
        //update skill cd
        $this->notice_skill_cd($userid, $cmd, $cd);
        $key_room = Key::key_room($roomid);
        $this->redis->hSet($key_room, $key_timeout, $current_time);
    }

    public function notice_battle_msg($userid, $fromname, $msg, $selfinfo, $enemyinfo) {
        $fd = $this->get_fd_by_id($userid);
        if ($fd !== false) {
            $this->send_msg($fd, $fromname, $msg, $selfinfo, $enemyinfo);
        } else {
            $this->log("notice_battle_msg fd not exist userid: $userid");
        }
    }

    public function notice_skill_cd($userid, $cmd, $cd) {
        $data = array(
            'cmd' => self::cmd_war_cd,
            'r' => 0,
            'msg' => '',
            'skill' => $cmd,
            'cd' => $cd,
        );
        $fd = $this->get_fd_by_id($userid);
        if ($fd !== false) {
            $this->pushto($fd, $data);
        } else {
            $this->log("notice_skill_cd fd not exist userid: $userid");
        }
    }

    protected function get_enemy_id($userid) {
        $roomid = $this->get_roomid($userid);
        if ($roomid !== false) {
            $roominfo = $this->get_room_info($roomid);
            $aid = $roominfo['aid'];
            $did = $roominfo['did'];
            if ($aid == $userid) {
                return $did;
            } else if ($did == $userid) {
                return $aid;
            } else {
                $this->log('get_enemy_id error userid:' . $userid . ' | aid:' . $aid . ' | did:' . $did);
            }
        }
        return false;
    }

    protected function get_room_info($roomid) {
        $key = Key::key_room($roomid);
        return $this->redis->hGetAll($key);
    }

    ///////////

    public function cmd_pvp_cancel($fd, $data) {
        if (!$this->check_login($fd, $data)) {
            return;
        }
        $userid = $data['userid'];
        $did = $data['did'];
        $this->pvp_cancel($userid, $did);
    }

    protected function get_fd_by_id($userid) {
        $key = Key::key_id_fd_skey($userid);
        $fd = $this->redis->hGet($key, 'fd');
        return $fd;
    }

    protected function get_pvp_list($current_id) {
        $key_fd = Key::key_fd_id();
        $userids = $this->redis->hVals($key_fd);
        shuffle($userids);
        $list = array();
        $count = 0;
        foreach ($userids as $userid) {
            if ($userid == $current_id) {
                continue;
            }
            $key_roomid = Key::key_room_id($userid);
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
        $this->log('userid:' . $current_id . ' | get_pvp_list: ' . var_export($list, true));
        return $list;
    }

    protected function gen_roomid() {
        return md5(time() . rand(1, 9999));
    }

    protected function set_roomid($userid, $roomid) {
        $key = Key::key_room_id($userid);
        $this->redis->set($key, $roomid);
    }

    protected function get_roomid($userid) {
        $key = Key::key_room_id($userid);
        return $this->redis->get($key);
    }

    protected function in_war($userid) {
        $key = Key::key_room_id($userid);
        $r = $this->redis->get($key);
        return $r !== false;
    }

    protected function is_online($userid) {
        $key = Key::key_id_fd_skey($userid);
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
            $this->log("userid: $aid is in war");
            return;
        }
        if ($this->in_war($did)) {
            $this->log("userid: $did is in war");
            return;
        }
        ///////
        $roomid = $this->gen_roomid();

        //set room info
        $key_room = Key::key_room($roomid);
        $this->redis->hMset($key_room, array(
            'aid' => $aid,
            'did' => $did,
            'time' => time(),
        ));

        //set room list
        $key_roomlist = Key::key_room_list();
        $this->redis->hSet($key_roomlist, $roomid, self::WAR_STATE_READY);

        //set user roomid
        $this->set_roomid($aid, $roomid);
        $this->set_roomid($did, $roomid);

        $this->notice_war_wait($aid, $did);
        $this->notice_war_defence($aid, $did);
    }

    public function pvp_reject($aid, $did) {
        $a_roomid = $this->get_roomid($aid);
        $d_roomid = $this->get_roomid($did);
        if ($a_roomid === false || $d_roomid === false || $a_roomid != $d_roomid) {
            $this->log("error, pvp_reject: aroomid: $a_roomid, droomid: $d_roomid");
            return;
        }

        $roomid = $a_roomid;
        $key_roomlist = Key::key_room_list();
        $state = $this->redis->hGet($key_roomlist, $roomid);
        if ($state === false) {
            $this->log("error, pvp_reject: state: false");
            return;
        }
        if ($state == self::WAR_STATE_READY) {
            $this->clear_user_war_state($aid);
            $this->clear_user_war_state($did);
            $this->clear_room($roomid);
            $this->notice_war_cancel($aid, $did, $did . ' reject your fight request');
            $this->notice_war_cancel($did, $aid, $aid . ' pvp cancel');
        } else {
            $this->log("error, pvp_reject: state: $state");
            return;
        }
    }

    public function pvp_cancel($aid, $did) {
        $a_roomid = $this->get_roomid($aid);
        $d_roomid = $this->get_roomid($did);
        if ($a_roomid === false || $d_roomid === false || $a_roomid != $d_roomid) {
            $this->log("error, pvp_cancel: aroomid: $a_roomid, droomid: $d_roomid");
            return;
        }

        $roomid = $a_roomid;
        $key_roomlist = Key::key_room_list();
        $state = $this->redis->hGet($key_roomlist, $roomid);
        if ($state === false) {
            $this->log("error, pvp_cancel: state: false");
            return;
        }
        if ($state == self::WAR_STATE_READY) {
            $this->clear_user_war_state($aid);
            $this->clear_user_war_state($did);
            $this->clear_room($roomid);
            $this->notice_war_cancel($aid, $did, $did . ' pvp cancel');
            $this->notice_war_cancel($did, $aid, $aid . ' cancel pvp request');
        } else {
            $this->log("error, pvp_cancel: state: $state");
            return;
        }
    }

    public function notice_war_cancel($userid, $enemyid, $msg) {
        $data = array(
            'cmd' => self::cmd_pvp_cancel,
            'r' => 0,
            'msg' => '',
            'enemyid' => $enemyid,
        );
        $fd = $this->get_fd_by_id($userid);
        if ($fd !== false) {
            $this->pushto($fd, $data);
            $this->send_system_msg($fd, $msg);
        } else {
            $this->log("notice_war_cancel fd not exist userid: $userid");
        }
    }

    public function notice_war_end($userid, $enemyid, $msg) {
        $self = $this->get_user_war_info($userid);
        $enemy = $this->get_user_war_info($enemyid);
        $data = array(
            'cmd' => self::cmd_war_end,
            'r' => 0,
            'msg' => '',
            'self' => $self,
            'enemy' => $enemy,
        );
        $fd = $this->get_fd_by_id($userid);
        if ($fd !== false) {
            $this->pushto($fd, $data);
            $this->send_system_msg($fd, $msg);
        } else {
            $this->log("notice_war_end fd not exist userid: $userid");
        }
    }

    public function notice_war_defence($aid, $did) {
        $ainfo = $this->get_user_war_info($aid);
        $data = array(
            'cmd' => self::cmd_war_defence,
            'r' => 0,
            'msg' => '',
            'aid' => $aid,
            'enemy' => $ainfo,
        );
        $dfd = $this->get_fd_by_id($did);
        if ($dfd !== false) {
            $this->pushto($dfd, $data);
            $this->send_system_msg($dfd, $ainfo['name'] . ' want to fight with you');
        } else {
            $this->log("notice_war_defence fd not exist aid: $aid, did: $did");
        }
    }

    public function notice_war_wait($aid, $did) {
        $dinfo = $this->get_user_war_info($did);
        $data = array(
            'cmd' => self::cmd_war_wait,
            'r' => 0,
            'msg' => '',
            'did' => $did,
            'enemy' => $dinfo,
        );
        $afd = $this->get_fd_by_id($aid);
        if ($afd !== false) {
            $this->pushto($afd, $data);
            $this->send_system_msg($afd, 'waiting for ' . $dinfo['name'] . ' accept');
        } else {
            $this->log("notice_war_wait fd not exist aid: $aid, did: $did");
        }
    }

    public function notice_ready_timeout($aid, $did) {
        $ainfo = $this->get_user_war_info($aid);
        $dinfo = $this->get_user_war_info($did);
        $data = array(
            'cmd' => self::cmd_ready_timeout,
            'r' => 0,
            'msg' => '',
        );
        $afd = $this->get_fd_by_id($aid);
        if ($afd !== false) {
            $this->pushto($afd, $data);
            $this->send_system_msg($afd, 'waiting timeout for ' . $dinfo['name'] . ' accept, cancel');
        }
        $dfd = $this->get_fd_by_id($did);
        if ($dfd !== false) {
            $this->pushto($dfd, $data);
            $this->send_system_msg($dfd, 'timeout for you accept ' . $ainfo['name'] . '\'s request, cancel');
        } else {
            $this->log("notice_ready_timeout fd not exist aid: $aid, did: $did");
        }
    }

    public function notice_war_start($userid, $self_info, $enemy_info) {
        $data = array(
            'cmd' => self::cmd_war_start,
            'r' => 0,
            'msg' => '',
            'self' => $self_info,
            'enemy' => $enemy_info,
        );
        $fd = $this->get_fd_by_id($userid);
        if ($fd !== false) {
            $this->pushto($fd, $data);
            $this->send_system_msg($fd, $self_info['name'] . ' battle with ' . $enemy_info['name'] . ' start');
        } else {
            $this->log("notice_war_start fd not exist userid: $userid");
        }
    }

    public function get_war_enemyid($userid) {
        $roomid = $this->get_roomid($userid);
        if ($roomid !== false) {
            $key = Key::key_room($roomid);
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
            $key = Key::key_room($roomid);
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
        $key_roomlist = Key::key_room_list();
        $state = $this->redis->hGet($key_roomlist, $roomid);
        if ($state === false) {
            $this->log("error, pvp_ready: state: false");
            return;
        }
        if ($state == self::WAR_STATE_READY) {
            $this->redis->hSet($key_roomlist, $roomid, self::WAR_STATE_RUN);
            $key_room = Key::key_room($roomid);
            $this->redis->hSet($key_room, 'time', time());
            $ainfo = $this->get_user_war_info($aid);
            $dinfo = $this->get_user_war_info($did);
            $this->notice_war_start($aid, $ainfo, $dinfo);
            $this->notice_war_start($did, $dinfo, $ainfo);
            $this->log("pvp_ready: war_start aid: $aid, bid: $did");
        } else {
            $this->log("error, pvp_ready: state: $state");
            return;
        }
    }

    /**
     * check whether the room of user is in running state
     * @param string $userid
     * @return boolean
     */
    public function check_war_running($userid) {
        $roomid = $this->get_roomid($userid);
        if ($roomid !== false) {
            $state = $this->get_room_state($roomid);
            return $state === self::WAR_STATE_RUN;
        }
        return false;
    }

    public function get_room_state($roomid) {
        $key = Key::key_room_list();
        $state = $this->redis->hGet($key, $roomid);
        return $state;
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

    protected function clear_room($roomid) {
        $key_list = Key::key_room_list();
        $this->redis->hDel($key_list, $roomid);
        $key_room = Key::key_room($roomid);
        $this->redis->delete($key_room);
    }

    protected function check_failer($ainfo, $dinfo) {
        $failer = self::FAILER_A;
        if ($ainfo['hp'] > $dinfo['hp']) {
            $failer = self::FAILER_D;
        } else if ($ainfo['hp'] < $dinfo['hp']) {
            $failer = self::FAILER_A;
        } else if ($ainfo['mp'] > $dinfo['mp']) {
            $failer = self::FAILER_D;
        } else if ($ainfo['mp'] < $dinfo['mp']) {
            $failer = self::FAILER_A;
        } else if ($ainfo['max_hp'] > $dinfo['max_hp']) {
            $failer = self::FAILER_A;
        } else if ($ainfo['max_hp'] < $dinfo['max_hp']) {
            $failer = self::FAILER_D;
        } else if ($ainfo['max_mp'] > $dinfo['max_mp']) {
            $failer = self::FAILER_A;
        } else if ($ainfo['max_mp'] < $dinfo['max_mp']) {
            $failer = self::FAILER_D;
        } else {
            $failer = self::FAILER_A;
        }
        return $failer;
    }

    protected function reset_user_info($userid) {
        $info = $this->get_user_war_info($userid);
        $key = Key::key_user($userid);
        $this->redis->hMset($key, array('hp' => $info['max_hp'], 'mp' => $info['max_mp']));
        $this->log("reset_user_info | userid: $userid");
    }

    protected function pvp_reward($winnerid, $winner_info, $loserid, $loser_info) {
        $key_winner = key::key_user($winnerid);
        $key_loser = Key::key_user($loserid);
        $winner_change = array();
        $loser_change = array();
        if ($winner_info['max_hp'] >= $loser_info['max_hp']) {
            $winner_change['max_hp'] += mt_rand(3, 6);
            $loser_info['max_hp'] -= mt_rand(1, 2);
        } else {
            $winner_change['max_hp'] += mt_rand(5, 10);
            $loser_info['max_hp'] -= mt_rand(3, 6);
        }
        if ($winner_info['max_mp'] >= $loser_info['max_mp']) {
            $winner_change['max_mp'] += mt_rand(3, 6);
            $loser_info['max_mp'] -= mt_rand(1, 2);
        } else {
            $winner_change['max_mp'] += mt_rand(5, 10);
            $loser_info['max_mp'] -= mt_rand(3, 6);
        }
        $this->redis->hMset($key_winner, $winner_change);
        $this->redis->hMset($key_loser, $loser_change);
        $this->log("pvp_reward | winnerid: $winnerid, loserid: $loserid");
    }

    /**
     * ai
     */
    public function onTimer() {
        $key_list = Key::key_room_list();
        $room_list = $this->redis->hGetAll($key_list);
        if (!empty($room_list)) {
            $this->log('room_list | ' . var_export($room_list, true));
        }
        $current_time = time();
        foreach ($room_list as $roomid => $state) {
            $key_room = Key::key_room($roomid);
            $room_info = $this->redis->hGetAll($key_room);
            $aid = $room_info['aid'];
            $did = $room_info['did'];
            $time = $room_info['time'];
            if ($state == self::WAR_STATE_READY) {
                if ($time + self::TIMEOUT < $current_time) {//ready timeout
                    $this->ready_timeout($aid);
                    $this->ready_timeout($did);

                    $this->clear_room($roomid);
                    $this->clear_user_war_state($aid);
                    $this->clear_user_war_state($did);
                    $this->log("remove timeout roomid: $roomid, aid: $aid, did: $did");
                    //timeout handle
                    $this->notice_ready_timeout($aid, $did);
                }
            } else if ($state == self::WAR_STATE_RUN) {
                if ($time + self::WAR_TIME_LIMIT < $current_time) {//war timeout
                    //check winner
                    $ainfo = $this->get_user_war_info($aid);
                    $dinfo = $this->get_user_war_info($did);
                    $failer = $this->check_failer($ainfo, $dinfo);
                    if ($failer == self::FAILER_A) {
                        $msg = 'war timeout, ' . $dinfo['name'] . ' win the war';
                        $toamsg = $msg . ', you lose';
                        $todmsg = $msg . ', you win';
                        $this->pvp_reward($did, $dinfo, $aid, $ainfo);
                    } else {
                        $msg = 'war timeout, ' . $ainfo['name'] . ' win the war';
                        $todmsg = $msg . ', you lose';
                        $toamsg = $msg . ', you win';
                        $this->pvp_reward($aid, $ainfo, $did, $dinfo);
                    }

                    $this->reset_user_info($aid);
                    $this->reset_user_info($did);
                    $this->clear_user_war_state($aid);
                    $this->clear_user_war_state($did);
                    $this->clear_room($roomid);
                    $this->log("WAR_TIME_LIMIT timeout roomid: $roomid, aid: $aid, did: $did");
                    $toamsg = '';
                    $todmsg = '';
                    $this->notice_war_end($aid, $did, $toamsg);
                    $this->notice_war_end($did, $aid, $todmsg);
                    $this->log("notice_war_end roomid: $roomid, aid: $aid, did: $did, toamsg: $toamsg");
                } else if ($did == 0) { // pve
                    $npcid = $room_info['npcid'];
                    $npc_info = array(
                        'name' => 'npc',
                        'max_hp' => 110,
                        'hp' => 110,
                        'max_mp' => 110,
                        'mp' => 110,
                        'win' => 110,
                        'lose' => 110,
                    );
                    //ai
                } else { // pvp
                }
            } else {//end
            }
        }
    }

    protected function clear_user_war_state($userid) {
        $key = Key::key_room_id($userid);
        $this->redis->delete($key);
    }

    protected function ready_timeout($userid) {
        
    }

}

$server = new GameServer();
