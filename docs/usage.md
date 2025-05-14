# PHPWE Kullanım Kılavuzu

Bu doküman, PHPWE kütüphanesinin temel kullanım örneklerini ve fonksiyonlarını açıklar.

## İçindekiler

1. [Model Yapısı](#model-yapısı)
2. [Veritabanı İşlemleri](#veritabanı-işlemleri)
3. [Unique Sınıfı](#unique-sınıfı)
4. [İlişkisel Yapı](#i̇lişkisel-yapı)
5. [Önbellek Kullanımı](#önbellek-kullanımı)
6. [Hata Ayıklama](#hata-ayıklama)

## Model Yapısı

PHPWE, model-tabanlı bir ORM yapısı sunar. Her veritabanı tablosu için bir model sınıfı oluşturmalısınız.

### Temel Model Oluşturma

```php
<?php 
namespace App\Models;
use \App\Database\Model;
use \App\Database\Unique;

class User extends Model
{
    public int $id;                   // Her modelde bulunması gereken id alanı
    public Unique $username;          // Benzersiz olması gereken alan
    public Unique $mail;              // Benzersiz olması gereken alan
    public ?string $password = null;  // Normal string alan
    public int $createdTime = 0;      // Varsayılan değerli int alan
    public int $status = 0;           // Durum alanı
}
```

Model sınıfı oluşturulduğunda, PHPWE otomatik olarak ilgili veritabanı tablosunu oluşturur. Tablo adı, model sınıfının adının küçük harfli versiyonu olarak belirlenir (örneğin `User` sınıfı için `user` tablosu).

### Model Özelliklerini Belirleme

Her model özelliği, veritabanında bir sütuna karşılık gelir. Özellik türleri şunlar olabilir:

- `int`: Tam sayı değerler için
- `string`: Metin değerleri için
- `float`: Ondalıklı sayılar için
- `bool`: Boolean değerler için
- `array`: Dizi değerleri için (JSON olarak saklanır)
- `Unique`: Benzersiz değer kontrolü için özel sınıf
- Diğer Model sınıfları: İlişkisel veriler için

## Veritabanı İşlemleri

### Veri Ekleme

```php
$user = new User();
$user->username(new Unique("admin"));
$user->mail(new Unique("admin@example.com"));
$user->password("guvenli_parola");
$user->createdTime = time();
$user->status = 1;
$user->save();
```

### Veri Okuma

```php
// ID ile okuma
$user = User::find(1);
echo $user->username; // "admin"

// Koşula göre tekil veri okuma
$user = (new User())->findOneBy(['status' => 1]);
if ($user) {
    echo $user->mail; // "admin@example.com"
}

// Koşula göre çoklu veri okuma
$users = User::findBy(['status' => 1]);
foreach ($users as $user) {
    echo $user->username;
}

// Tüm verileri alma
$allUsers = User::findAll('createdTime', 'DESC'); // createdTime'a göre azalan sırada
```

### Veri Güncelleme

```php
$user = User::find(1);
$user->username(new Unique("yeni_admin"));
$user->status = 2;
$user->save();
```

### Veri Silme

```php
// Belirli bir kaydı silme
$user = User::find(1);
$user->delete();

// Koşula göre silme
User::deleteWhere(['status' => 0]);
```

## Unique Sınıfı

`Unique` sınıfı, veritabanında benzersiz değerler için kullanılır.

```php
use App\Database\Unique;

// Unique nesne oluşturma
$username = new Unique("kullanici_adi");

// Model ile kullanma
$user->username($username);

// Doğrudan değer atama
$user->username(new Unique("kullanici_adi"));

// Değer alma
$username = $user->username->getValue();
```

## İlişkisel Yapı

PHPWE, ORM modelleri arasında ilişkisel bağlantılar kurmanıza olanak tanır.

### İlişki Tanımlama

```php
// Profile modeli
class Profile extends Model
{
    public int $id;
    public User $user;
    public string $fullName;
    
    protected function initializeRelations()
    {
        parent::initializeRelations();
        $this->belongsTo('App\\Models\\User', 'user');
    }
}

// User sınıfında profil ilişkisini tanımlama
class User extends Model
{
    // ... diğer özellikler
    
    protected function initializeRelations()
    {
        parent::initializeRelations();
        $this->hasOne('App\\Models\\Profile', 'profile');
    }
}
```

### İlişkili Verileri Kullanma

```php
// Kullanıcıya profil ekleme
$user = User::find(1);
$profile = new Profile();
$profile->user = $user;
$profile->fullName = "John Doe";
$profile->save();

// Profil üzerinden kullanıcıya erişme
$profile = Profile::find(1);
$username = $profile->user->username;

// Kullanıcı üzerinden profile erişme
$user = User::find(1);
$profile = $user->loadRelation('profile');
echo $profile->fullName; // "John Doe"
```

## Önbellek Kullanımı

PHPWE, sorgu sonuçlarını önbellekleme yeteneğine sahiptir.

```php
use App\Database\DB;

// Önbellek ayarlarını yapılandırma
DB::cache()->setCacheTime(300); // 5 dakika önbellekleme

// Önbelleklenmiş sorgu
$user = DB::query('user')->where('id', 1)->useCache()->first();

// Önbelleği temizleme
DB::cache()->clearCache('user');
```

## Hata Ayıklama

PHPWE, kapsamlı hata ayıklama araçları sağlar.

```php
// Debug modunu etkinleştir (index.php'de)
define("DEBUG", true);

// Veritabanı hatalarını göster
DB::showDebugStatic();
```

Debug modu etkinleştirildiğinde, işlenen SQL sorguları, hatalar ve performans ölçümleri ekranda gösterilir. 