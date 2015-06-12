#!/usr/bin/php
<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

require __DIR__.'../config/redisconfig.php';
require __DIR__.'../lib/Key.php';

$userid = $argv['1'];
$redis = new redis();
$redis->connect($redisconfig['host'], $redisconfig['port']);
$key = Key::key_user($userid);
var_dump($redis->hGetAll($key));

$key = Key::key_room_id($userid);
Var_dump($redis->get($key));

$key = Key::key_id_fd_skey($userid);
Var_dump($redis->hGetAll($key));

