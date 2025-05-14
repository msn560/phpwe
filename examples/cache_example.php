<?php
/**
 * PHPWE - Önbellek Kullanım Örneği
 * 
 * Bu örnek, PHPWE kütüphanesinin önbellek (cache) sisteminin kullanımını göstermektedir.
 */

// Ana dizine göre yolu ayarlayın
require_once __DIR__ . "/../index.php";

use App\Database\DB;
use App\Database\Unique;
use App\Models\User;

// Debug modunu aktif et
define("DEBUG", true);

echo "Önbellek örneği çalıştırılıyor...\n";

// Önce veri ekleyelim
$totalUsers = User::count();
if ($totalUsers < 5) {
    echo "Test için yeni kullanıcılar ekleniyor...\n";
    
    for ($i = 1; $i <= 5; $i++) {
        $user = new User();
        $user->username(new Unique("cache_user_" . $i));
        $user->mail(new Unique("cache_user_" . $i . "@example.com"));
        $user->password("password" . $i);
        $user->createdTime = time();
        $user->status = 1;
        $user->save();
    }
    
    echo "Test kullanıcıları eklendi.\n\n";
}

// Önbellek nesnesini al
$cache = DB::cache();

// Önbellek süresini ayarla (5 dakika)
$cache->setCacheTime(300);

echo "Önbellek süresi 300 saniye olarak ayarlandı.\n";

// İlk sorgu - Önbellekte olmayan (soğuk) sorgu
echo "\n1. Sorgu (Önbelleksiz):\n";
$startTime = microtime(true);

$users = DB::query('user')
    ->where('status', 1)
    ->orderBy('id', 'ASC')
    ->get();

$endTime = microtime(true);
$executionTime = ($endTime - $startTime) * 1000; // milisaniye

echo "Sorgu süresi: " . number_format($executionTime, 2) . " ms\n";
echo "Bulunan kullanıcı sayısı: " . count($users) . "\n";

// İkinci sorgu - Önbellekli sorgu
echo "\n2. Sorgu (Önbellekli):\n";
$startTime = microtime(true);

$cachedUsers = DB::query('user')
    ->where('status', 1)
    ->orderBy('id', 'ASC') 
    ->get();

$endTime = microtime(true);
$executionTime = ($endTime - $startTime) * 1000; // milisaniye

echo "Sorgu süresi: " . number_format($executionTime, 2) . " ms\n";
echo "Bulunan kullanıcı sayısı: " . count($cachedUsers) . "\n";

// Üçüncü sorgu - Hala önbellekten
echo "\n3. Sorgu (Önbellekli, tekrar):\n";
$startTime = microtime(true);

$cachedUsersAgain = DB::query('user')
    ->where('status', 1)
    ->orderBy('id', 'ASC') 
    ->get();

$endTime = microtime(true);
$executionTime = ($endTime - $startTime) * 1000; // milisaniye

echo "Sorgu süresi: " . number_format($executionTime, 2) . " ms\n";
echo "Bulunan kullanıcı sayısı: " . count($cachedUsersAgain) . "\n";

// Şimdi önbelleği temizleyelim
echo "\nÖnbellek temizleniyor...\n";
$cache->clearCache('user');

// Dördüncü sorgu - Önbellek temizlendikten sonra
echo "\n4. Sorgu (Önbellek temizlendikten sonra):\n";
$startTime = microtime(true);

$usersAfterClear = DB::query('user')
    ->where('status', 1)
    ->orderBy('id', 'ASC') 
    ->get();

$endTime = microtime(true);
$executionTime = ($endTime - $startTime) * 1000; // milisaniye

echo "Sorgu süresi: " . number_format($executionTime, 2) . " ms\n";
echo "Bulunan kullanıcı sayısı: " . count($usersAfterClear) . "\n";

// Beşinci sorgu - Yeniden önbelleklenmiş sorgu
echo "\n5. Sorgu (Yeniden önbelleklenmiş):\n";
$startTime = microtime(true);

$cachedUsersNew = DB::query('user')
    ->where('status', 1)
    ->orderBy('id', 'ASC') 
    ->get();

$endTime = microtime(true);
$executionTime = ($endTime - $startTime) * 1000; // milisaniye

echo "Sorgu süresi: " . number_format($executionTime, 2) . " ms\n";
echo "Bulunan kullanıcı sayısı: " . count($cachedUsersNew) . "\n";

// Önbellek istatistiklerini göster
echo "\nÖnbellek istatistikleri:\n";
$stats = $cache->getStats();
echo "Toplam önbelleklenmiş tablo: " . $stats['tables'] . "\n";
echo "Toplam önbellek öğesi: " . $stats['total_items'] . "\n";

if (!empty($stats['items_by_table'])) {
    echo "Tablo bazında önbellek:\n";
    foreach ($stats['items_by_table'] as $table => $count) {
        echo "- " . $table . ": " . $count . " öğe\n";
    }
}

// Debug bilgilerini göster
DB::showDebugStatic(); 