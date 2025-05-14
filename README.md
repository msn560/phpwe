# PHPWE - Basit PHP ORM Framework

PHPWE, PHP dilinde yazılmış, veritabanı işlemlerini kolaylaştıran ve ORM yapısı sunan hafif bir frameworktür. Model tabanlı bir yapı kullanarak veritabanı işlemlerini hızlı ve güvenli bir şekilde gerçekleştirmenizi sağlar.

## Özellikler

- Otomatik tablo oluşturma ve güncelleme
- Model tabanlı veritabanı işlemleri
- İlişkisel veritabanı desteği (hasOne, hasMany, belongsTo)
- Benzersiz değer kontrolü
- Önbellekleme sistemi
- Hata ayıklama araçları

## Kurulum

1. Depoyu klonlayın:
```bash
git clone https://github.com/msn560/phpwe.git
```

2. Config dosyasını düzenleyin:
```php
// config/config.php
$config = [
    "db"=>[
        'mysql' => [
            'host' => 'localhost',
            'name' => 'veritabani_adi',
            'user' => 'kullanici_adi',
            'password' => 'parola',
            'charset' => 'utf8mb4'
        ],
        'type' => 'mysql', // mysql veya sqlite
    ]
];
```

3. Projeyi çalıştırın:
```php
require_once "index.php";
```

## Kullanım Örnekleri

### Model Oluşturma

```php
<?php 
namespace App\Models;
use \App\Database\Model;
use \App\Database\Unique;

class User extends Model
{
    public int $id;
    public Unique $username; // Benzersiz değer
    public Unique $mail;     // Benzersiz değer
    public ?string $password = null;  
    public int $createdTime = 0;
    public int $status = 0; 
}
```

### Veri Ekleme

```php
use App\Models\User;
use App\Database\Unique;

$user = new User();
$user->username(new Unique("kullanici_adi"));
$user->mail(new Unique("mail@ornek.com"));
$user->password("guvenli_parola");
$user->createdTime = time();
$user->status = 1;
$user->save();
```

### Veri Sorgulama

```php
// ID ile sorgulama
$user = User::find(1);

// Koşula göre sorgulama
$users = User::findBy(['status' => 1]);

// Tüm kayıtları getirme
$allUsers = User::findAll();
```

### İlişkisel Veriler

```php
// Sessions ile User arasında ilişki
$session = new Sessions();
$session->user = $user; // Kullanıcıya bağlantı kurma
$session->session_key(new Unique("benzersiz_anahtar"));
$session->save();

// İlişkili verileri yükleme
$userWithSessions = $user->loadRelation('sessions');
```

## Lisans

MIT 