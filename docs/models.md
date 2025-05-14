# PHPWE Model Yapısı ve İlişkiler

Bu doküman, PHPWE kütüphanesinin model yapısını ve modeller arasındaki ilişkileri açıklar.

## İçindekiler

1. [Model Sınıfları](#model-sınıfları)
2. [Model Özellik Tipleri](#model-özellik-tipleri)
3. [Model Oluşturma](#model-oluşturma)
4. [İlişkisel Yapı](#i̇lişkisel-yapı)
5. [Örnekler](#örnekler)

## Model Sınıfları

PHPWE, PHP sınıflarını veritabanı tablolarına eşleştiren bir ORM (Object-Relational Mapping) sistemi kullanır. Her model, bir veritabanı tablosunu temsil eder.

### Temel Model Yapısı

```php
<?php 
namespace App\Models;
use \App\Database\Model;
use \App\Database\Unique;

class User extends Model
{
    public int $id;                   // Her modelde bulunmalıdır (PRIMARY KEY)
    public Unique $username;          // Benzersiz alan
    public string $fullName;          // Normal string alan
    public ?string $email = null;     // Null olabilen string alan
    public int $createdTime = 0;      // Varsayılan değere sahip integer
    public float $balance = 0.0;      // Float alan
    public bool $isActive = true;     // Boolean alan
    public array $settings = [];      // Dizi alan (JSON olarak saklanır)
}
```

## Model Özellik Tipleri

PHPWE modelleri, PHP 7.4+ tip tanımlamalarını kullanarak tablo şemasını otomatik olarak oluşturur. Desteklenen tipler:

| PHP Tipi | Veritabanı Tipi | Açıklama |
|----------|-----------------|----------|
| `int` | `INT` | Tam sayı değerler |
| `float` | `FLOAT` | Ondalıklı sayılar |
| `string` | `VARCHAR(255)` | Kısa metin |
| `bool` | `TINYINT(1)` | Boolean değerler |
| `array` | `JSON` | Dizi/Nesne (JSON olarak saklanır) |
| `Unique` | İlgili tip + `UNIQUE` | Benzersiz değerler için |
| Model Sınıfları | `INT` (Foreign Key) | İlişkisel bağlantılar için |

### Özel Tipler

#### Unique Sınıfı

`Unique` sınıfı, benzersiz değerler için kullanılır ve veritabanında `UNIQUE` kısıtlaması ekler.

```php
public Unique $username;
public Unique $email;

// Kullanım
$user->username(new Unique("admin"));
$value = $user->username->getValue();
```

## Model Oluşturma

### Veritabanı Tablosu Otomatik Oluşturma

PHPWE, model sınıfı ilk kez örneklendiğinde ilgili tabloyu otomatik olarak oluşturur veya günceller. Bu işlev, geliştirme sürecinde şema değişikliklerini otomatik olarak yansıtır.

```php
// User sınıfı örneklendiğinde, user tablosu yoksa oluşturulur
$user = new User();

// Tablo oluşturma sürecini manuel tetiklemek için
User::ensureTableSync();
```

### Model Kaydetme/Güncelleme

```php
// Yeni kayıt oluşturma
$user = new User();
$user->username(new Unique("admin"));
$user->fullName = "Admin User";
$user->email = "admin@example.com";
$user->createdTime = time();
$user->save(); // INSERT işlemi

// Var olan kaydı güncelleme
$user = User::find(1);
$user->fullName = "Super Admin";
$user->save(); // UPDATE işlemi
```

## İlişkisel Yapı

PHPWE, üç temel ilişki tipini destekler:

1. **hasOne**: Bire-bir ilişki
2. **hasMany**: Bire-çok ilişki
3. **belongsTo**: Çoka-bir ilişki

### İlişki Tanımlama

İlişkiler, `initializeRelations()` metodunda tanımlanabilir:

```php
class User extends Model
{
    public int $id;
    public Unique $username;
    
    protected function initializeRelations()
    {
        parent::initializeRelations();
        
        // Bir kullanıcının bir profili var
        $this->hasOne('App\\Models\\Profile', 'profile');
        
        // Bir kullanıcının birden çok postu var
        $this->hasMany('App\\Models\\Post', 'posts');
    }
}

class Profile extends Model
{
    public int $id;
    public User $user;
    public string $bio;
    
    protected function initializeRelations()
    {
        parent::initializeRelations();
        
        // Profil bir kullanıcıya aittir
        $this->belongsTo('App\\Models\\User', 'user');
    }
}

class Post extends Model
{
    public int $id;
    public User $user;
    public string $title;
    public string $content;
    
    protected function initializeRelations()
    {
        parent::initializeRelations();
        
        // Post bir kullanıcıya aittir
        $this->belongsTo('App\\Models\\User', 'user');
    }
}
```

### İlişki Kullanımı

```php
// Kullanıcı ve ilişkili verileri ekleme
$user = new User();
$user->username(new Unique("admin"));
$user->save();

// Profil ekleme
$profile = new Profile();
$profile->user = $user;
$profile->bio = "Admin kullanıcısının biyografisi";
$profile->save();

// Post ekleme
$post = new Post();
$post->user = $user;
$post->title = "İlk Gönderi";
$post->content = "Merhaba Dünya!";
$post->save();

// İlişkili verileri yükleme
$userProfile = $user->loadRelation('profile');
echo $userProfile->bio;

$userPosts = $user->loadRelation('posts');
foreach ($userPosts as $post) {
    echo $post->title;
}

// Tüm ilişkileri yükleme
$user = User::find(1)->loadAllRelations();
```

## Örnekler

### Gerçek Model Örnekleri

PHPWE ile kullanılan örnek model sınıfları:

#### User.php
```php
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
    
    protected function initializeRelations()
    {
        parent::initializeRelations();
        
        $this->hasMany('App\\Models\\Sessions', 'sessions');
        $this->hasOne('App\\Models\\Profile', 'profile');
    }
}
```

#### Sessions.php
```php
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
    public int $createdTime = 0;
    public int $finishTime = 0;
    public int $last_activity = 0;
    public string $current_page = "home";
    public int $status = 0;  
    
    protected function initializeRelations()
    {
        parent::initializeRelations();
        $this->belongsTo('App\\Models\\User', 'user');
    }
}
```

#### Profile.php
```php
<?php 
namespace App\Models;
use \App\Database\Model;
use \App\Database\Unique;
use \App\Database\DB;

class Profile extends Model
{
    const PROFILE_DATA_TYPE = [
        "profile_picture", "name_surname", "preferences", "wallet_balance",
        "is_premium", "premium_start", "premium_end", "regdate"
    ];
    
    public int $id;
    public User $user; 
    public int $data_key; 
    public array $data_val;
    public int $up_id = 0; 
    public int $created_time = 0;
    public int $up_time = 0;
    
    protected function initializeRelations()
    {
        parent::initializeRelations();
        $this->belongsTo('App\\Models\\User', 'user');
    }
}
```

### Tam Kullanım Örneği

```php
<?php
require_once "index.php";

use App\Database\DB;
use App\Database\Unique;
use App\Models\User;
use App\Models\Sessions;
use App\Models\Profile;

// Yeni kullanıcı oluşturma
$user = new User();
$user->mail(new Unique("user@example.com"));
$user->username(new Unique("testuser"));
$user->password("secure_password");
$user->createdTime = time();
$user->status = 1;
$user->save();

// Kullanıcı için oturum oluşturma
$session = new Sessions();
$session->user = $user;
$session->session_key(new Unique(md5(uniqid())));
$session->session_type = 1;
$session->createdIp = $_SERVER['REMOTE_ADDR'] ?? "127.0.0.1";
$session->createdTime = time();
$session->finishTime = time() + (24 * 60 * 60); // 1 gün
$session->last_activity = time();
$session->status = 1;
$session->save();

// Kullanıcı için profil oluşturma
$profile = new Profile();
$profile->user = $user;
$profile->data_key = array_search("name_surname", Profile::PROFILE_DATA_TYPE);
$profile->data_val = ["first_name" => "Test", "last_name" => "User"];
$profile->created_time = time();
$profile->save();

// İlişkili verileri yükleme
$userWithRelations = $user->loadAllRelations();
echo "Kullanıcı Adı: " . $userWithRelations->username . "\n";
echo "Oturum Anahtarı: " . $userWithRelations->sessions[0]->session_key . "\n";
echo "Tam Ad: " . $userWithRelations->profile->data_val['first_name'] . " " . 
     $userWithRelations->profile->data_val['last_name'] . "\n";

// Hata ayıklama bilgilerini göster
DB::showDebugStatic();
``` 