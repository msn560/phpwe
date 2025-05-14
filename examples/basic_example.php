<?php
/**
 * PHPWE - Temel Kullanım Örneği
 * 
 * Bu örnek, PHPWE kütüphanesinin temel kullanımını göstermektedir.
 */

// Ana dizine göre yolu ayarlayın
require_once __DIR__ . "/../index.php";

use App\Database\DB;
use App\Database\Unique;
use App\Models\User;

// Debug modunu aktif et
define("DEBUG", true);

// Kullanıcı oluşturma örneği
$user = new User();
$user->username(new Unique("ornek_kullanici"));
$user->mail(new Unique("ornek@example.com"));
$user->password("gizli_parola123");
$user->createdTime = time();
$user->status = 1;

// Kullanıcıyı kaydet
if ($user->save()) {
    echo "Kullanıcı başarıyla kaydedildi. ID: " . $user->id . "\n";
} else {
    echo "Kullanıcı kaydedilirken bir hata oluştu.\n";
}

// Kullanıcı bulma örneği
$found_user = User::find(1);
if ($found_user) {
    echo "Kullanıcı bulundu: " . $found_user->username . "\n";
} else {
    echo "Kullanıcı bulunamadı.\n";
}

// Koşula göre kullanıcı bulma
$active_users = User::findBy(['status' => 1]);
echo "Aktif kullanıcı sayısı: " . count($active_users) . "\n";

// Tüm kullanıcıları listeleme
$all_users = User::findAll();
echo "Toplam kullanıcı sayısı: " . count($all_users) . "\n";

echo "Tüm kullanıcılar:\n";
foreach ($all_users as $u) {
    echo "- " . $u->username . " (" . $u->mail . ")\n";
}

// Kullanıcı güncelleme
if (isset($found_user) && $found_user) {
    $found_user->status = 2;
    if ($found_user->save()) {
        echo "Kullanıcı durumu güncellendi.\n";
    }
}

// Önbellekli sorgu örneği
$cached_user = DB::query('user')
    ->where('id', 1) 
    ->first();

if ($cached_user) {
    echo "Önbellekten kullanıcı: " . $cached_user['username'] . "\n";
}

// Debug bilgisini göster
DB::showDebugStatic(); 