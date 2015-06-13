<?php

/**
 * Simple Description file Utils
 * 
 * Complete Description of  Utils
 * 
 * @author      cloudysong <madcloudsong@foxmail.com>
 * @version     $Id$
 * @package     module
 * @subpackage  controller/view
 * @date        2015-6-13
 */
class Utils {

    public static function fload_around($value, $bias = 0.2) {
        $min = $value * (1 - $bias);
        $max = $value * (1 + $bias);
        return round(mt_rand($min, $max));
    }
    
    public static function check_happen($rate, $all = 100) {
        $min = 0;
        $max = 10000;
        $rand = mt_rand($min, $max);
        if($rand < $rate * 10000 / $all) {
            return true;
        }else{
            return false;
        }
    }

}
