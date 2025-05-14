<?php 
namespace App\Models;
use \App\Database\Model;
use \App\Database\Unique;
use \App\Database\DB;

class Sessions extends Model
{
    public int $id;
    public User $user;
    public Unique $session_key;
    public int $session_type = 0;
    public int $device_id = 0; 
    public string $createdIp = ""; 
    public int $createdTime = 0 ;
    public int $finishTime = 0;
    public int $last_activity = 0;
    public string  $current_page = "home";
    public int $status = 0;  
    protected function initializeRelations()
    {
        parent::initializeRelations();

        // Device ile ilişki kurma
        $this->belongsTo('App\\Models\\User', 'user');
    }
}


