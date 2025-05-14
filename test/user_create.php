<?php
function random_string(int $length = 16): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
}

require __DIR__."/../index.php";
    $start = microtime(true);
use App\Database\DB;
use App\Database\Unique;
use \App\Models\User ; 
use \App\Models\Sessions;
use \App\Models\Profile;

    
    $user = new User();

    $user->mail (new Unique( "mms@example.com"));
    $user->username ( new Unique("admin"));
    $user->password ("password"); 
    $user->save();

    $random = random_string(12);
    $session = new Sessions();
    $session->user = $user;
    $session->session_key(new Unique($random));
    $session->session_type = 0;
    $session->device_id = 0;
    $session->createdIp = "127.0.0.1";
    $session->createdTime =  time();
    $session->finishTime = time() + 24*60;
    $session->last_activity =  time();
    $session->current_page = "home";
    $session->status = 1;
    $session->save();

     
    var_dump( (new Sessions())->session_key(new Unique($random))->createdIp());

    $profile = new Profile();
    $profile->user = $user;
    print_r("bitti > " . (microtime(true) - $start));
    DB::showDebugStatic();

