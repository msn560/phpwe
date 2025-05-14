<?php 
namespace App\Models;
use \App\Database\Model;
use \App\Database\Unique;
use \App\Database\DB;

class User extends Model
{
    public int $id;
    public Unique $username;
    public Unique $mail;
    public ?string $password = null;  
    public int $createdTime = 0;
    public int $status = 0; 
    
}


