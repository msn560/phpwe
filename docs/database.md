# PHPWE Veritabanı Sınıfı Dokümantasyonu

Bu doküman, PHPWE kütüphanesinin veritabanı işlemlerini yöneten DB sınıfını ve ilgili bileşenleri açıklamaktadır.

## İçindekiler

1. [DB Sınıfı](#db-sınıfı)
2. [Sorgu Sınıfları](#sorgu-sınıfları)
3. [Model Sınıfı](#model-sınıfı)
4. [Unique Sınıfı](#unique-sınıfı)
5. [Önbellek Sistemi](#önbellek-sistemi)

## DB Sınıfı

`App\Database\DB` sınıfı, veritabanı bağlantılarını ve işlemlerini yönetir.

### Bağlantı

```php
// DB bağlantısını başlatma
DB::start();

// Konfigürasyon ile başlatma
DB::start([
    'type' => 'mysql',
    'mysql' => [
        'host' => 'localhost',
        'name' => 'database_name',
        'user' => 'username',
        'password' => 'password',
        'charset' => 'utf8mb4'
    ]
]);
```

### Sorgu İşlemleri

```php
// Sorgu oluşturma
$query = DB::query('user');

// Ekleme işlemi
DB::insert('user')->values([
    'username' => 'admin',
    'email' => 'admin@example.com',
    'status' => 1
])->execute();

// Güncelleme işlemi
DB::update('user')->set([
    'status' => 2
])->where('id', 1)->execute();

// Silme işlemi
DB::delete('user')->where('id', 1)->execute();
```

### Hata Yönetimi

```php
// Hata ekleme
DB::start()->addErrors([
    'msg' => 'Hata mesajı',
    'source' => 'Hata kaynağı',
    'debug' => $exception // İsteğe bağlı
]);

// Hata gösterme
DB::showDebugStatic();
```

## Sorgu Sınıfları

PHPWE, farklı sorgu türleri için özel sınıflar sunar:

### Query Sınıfı

```php
$users = DB::query('user')
    ->select('id, username, mail')
    ->where('status', 1)
    ->orderBy('id', 'DESC')
    ->limit(10)
    ->get();

// Tekil sonuç alma
$user = DB::query('user')
    ->where('id', 1)
    ->first();

// Sayım yapma
$count = DB::query('user')
    ->where('status', 1)
    ->count();
```

### Insert Sınıfı

```php
$id = DB::insert('user')
    ->values([
        'username' => 'admin',
        'mail' => 'admin@example.com',
        'password' => 'hashed_password',
        'created_time' => time()
    ])
    ->execute();
```

### Update Sınıfı

```php
$affected = DB::update('user')
    ->set([
        'status' => 2,
        'updated_time' => time()
    ])
    ->where('id', 1)
    ->execute();
```

### Delete Sınıfı

```php
$affected = DB::delete('user')
    ->where('status', 0)
    ->execute();
```

## Model Sınıfı

`App\Database\Model` sınıfı, ORM yapısının temelini oluşturur.

### Model Özellikleri

Model sınıflarında tanımlanan özellikler, veritabanı tablosunun sütunlarına karşılık gelir. Model oluşturulduğunda, bu özellikler otomatik olarak veritabanı şemasına yansıtılır.

```php
class User extends Model
{
    public int $id;                   // PRIMARY KEY
    public Unique $username;          // UNIQUE
    public Unique $mail;              // UNIQUE
    public ?string $password = null;  // VARCHAR, NULL
    public int $createdTime = 0;      // INT, DEFAULT 0
    public int $status = 0;           // INT, DEFAULT 0
}
```

### Model Metodları

```php
// Veri kaydetme
$model->save();

// Belirli ID'ye sahip kaydı yükleme
$model->load(1);

// Koşula göre tekil kayıt bulma
$model->findOneBy(['status' => 1]);

// Kaydı silme
$model->delete();

// Tüm ilişkileri yükleme
$model->loadAllRelations();
```

### Statik Model Metodları

```php
// ID ile bulma
User::find(1);

// Tümünü getirme
User::findAll('id', 'DESC');

// Koşula göre bulma
User::findBy(['status' => 1]);

// Koşula göre silme
User::deleteWhere(['status' => 0]);

// Sayım
User::count(['status' => 1]);

// Sorgu oluşturma
User::query()->where('status', 1)->get();
```

## Unique Sınıfı

`App\Database\Unique` sınıfı, benzersiz değer kontrolü sağlar.

```php
// Yeni Unique değer oluşturma
$username = new Unique('admin');

// Değer ayarlama
$username->setValue('new_admin');

// Değer alma
$value = $username->getValue();

// Benzersizlik kontrolü
$isUnique = $username->isUnique('user', 'username', $excludeId = 1);
```

## Önbellek Sistemi

PHPWE, sorgu sonuçlarını önbelleklemek için bir sistem sunar.

```php
// Önbellek nesnesini alma
$cache = DB::cache();

// Önbellek süresini ayarlama (saniye)
$cache->setCacheTime(300); // 5 dakika

// Önbellekli sorgu yapma
$results = DB::query('user')
    ->useCache()
    ->get();

// Belirli bir tablonun önbelleğini temizleme
$cache->clearCache('user');

// Tüm önbelleği temizleme
$cache->clearAllCache();

// Önbellek istatistiklerini alma
$stats = $cache->getStats();
```

Önbellek sistemi, performansı artırmak için sorgu sonuçlarını geçici olarak saklar. Bu, özellikle sık kullanılan ve nadiren değişen veriler için faydalıdır. 