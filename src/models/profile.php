<?php 
namespace App\Models;
use \App\Database\Model;
use \App\Database\Unique;
use \App\Database\DB;

class Profile extends Model
{
    const PROFILE_DATA_TYPE = [
        "profile_picture","password","name_surname", "preferences","wallet_balance","is_premium","premium_start","premium_end","regdate","username","mail","rank","roles"
    ];
    public int $id;
    public User $user; 
    public int $data_key; 
    public array $data_val;
    public int $up_id = 0 ; 
    public int $created_time = 0 ;
    public int $up_time = 0 ;
    protected function initializeRelations()
    {
        parent::initializeRelations();

        // Device ile ilişki kurma
        $this->belongsTo('App\\Models\\User', 'user');
    }
}


