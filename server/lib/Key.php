<?php

/**
 * Simple Description file Key
 * 
 * Complete Description of  Key
 * 
 * @author      cloudysong <madcloudsong@foxmail.com>
 * @version     $Id$
 * @package     module
 * @subpackage  controller/view
 * @date        2015-6-9
 */
class Key {
    public static function key_fd_id() {
        return 'key_fd_id';
    }

    public static function key_id_fd_skey($userid) {
        return 'key_id_fd_skey-' . $userid;
    }

    public static function key_user($userid) {
        return 'key_user-' . $userid;
    }
    
    public static function key_room($roomid) {
        return 'key_room-' . $roomid;
    }

    public static function key_room_list() {
        return 'key_room_list';
    }

    public static function key_room_id($userid) {
        return 'key_room_id' . $userid;
    }
}
