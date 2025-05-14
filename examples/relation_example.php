<?php
/**
 * PHPWE - İlişkisel Veritabanı Örneği
 * 
 * Bu örnek, PHPWE kütüphanesinin ilişkisel modeller ile kullanımını göstermektedir.
 */

// Ana dizine göre yolu ayarlayın
require_once __DIR__ . "/../index.php";

use App\Database\DB;
use App\Database\Unique;
use App\Models\User;
use App\Models\Sessions;
use App\Models\Profile;

// Debug modunu aktif et
define("DEBUG", true);

echo "İlişkisel veri örneği çalıştırılıyor...\n";

// 1. Kullanıcı oluştur
$user = new User();
$user->username(new Unique("test_user"));
$user->mail(new Unique("test@example.com"));
$user->password("test123");
$user->createdTime = time();
$user->status = 1;

if ($user->save()) {
    echo "1. Kullanıcı oluşturuldu. ID: {$user->id}\n";
} else {
    echo "Kullanıcı oluşturulurken hata oluştu!\n";
    DB::showDebugStatic();
    exit;
}

// 2. Kullanıcı için oturum oluştur
$session = new Sessions();
$session->user = $user; // İlişki kuruldu
$session->session_key(new Unique(md5(uniqid())));
$session->session_type = 1;
$session->device_id = 1;
$session->createdIp = "127.0.0.1";
$session->createdTime = time();
$session->finishTime = time() + (60 * 60); // 1 saat
$session->last_activity = time();
$session->current_page = "home";
$session->status = 1;

if ($session->save()) {
    echo "Oturum oluşturuldu. ID: {$session->id}\n";
} else {
    echo "Oturum oluşturulurken hata oluştu!\n";
    DB::showDebugStatic();
    exit;
}

// 3. Kullanıcı için profil oluştur
$profile = new Profile();
$profile->user = $user; // İlişki kuruldu
$profile->data_key = array_search("name_surname", Profile::PROFILE_DATA_TYPE);
$profile->data_val = [
    "first_name" => "Test",
    "last_name" => "User",
    "display_name" => "Test User"
];
$profile->created_time = time();

if ($profile->save()) {
    echo "Profil oluşturuldu. ID: {$profile->id}\n";
} else {
    echo "Profil oluşturulurken hata oluştu!\n";
    DB::showDebugStatic();
    exit;
}

// Kullanıcının ilişkili verilerini yükleme
echo "\nKullanıcı ve ilişkili verilerini yükleme:\n";
$loadedUser = User::find($user->id);

// Oturum bilgilerini yükle
$sessions = $loadedUser->loadRelation('sessions');
echo "Kullanıcı oturumları: " . count($sessions) . "\n";
echo "Oturum anahtarı: " . $sessions[0]->session_key . "\n";

// Profil bilgilerini yükle
$userProfile = $loadedUser->loadRelation('profile');
echo "Profil verisi: " . json_encode($userProfile->data_val) . "\n";

// Tüm ilişkileri tek seferde yükleme
echo "\nTüm ilişkileri tek seferde yükleme:\n";
$userWithAllRelations = User::find($user->id)->loadAllRelations();
echo "Kullanıcı: " . $userWithAllRelations->username . "\n";
echo "Oturum: " . $userWithAllRelations->sessions[0]->session_key . "\n";
echo "Profil: " . $userWithAllRelations->profile->data_val['display_name'] . "\n";

// İlişki üzerinden erişim
echo "\nİlişki üzerinden erişim:\n";
$sessionFromDB = Sessions::find($session->id);
$sessionUser = $sessionFromDB->loadRelation('user');
echo "Oturum sahibi kullanıcı: " . $sessionUser->username . "\n";

// Sorgu ile ilişkili veri
echo "\nSorgu ile ilişkili veri:\n";
$activeSessionUser = DB::query('sessions')
    ->where('status', 1)
    ->first();

if ($activeSessionUser) {
    $userId = $activeSessionUser['user'];
    $sessionOwner = User::find($userId);
    echo "Aktif oturuma sahip kullanıcı: " . $sessionOwner->username . "\n";
}

// Debug bilgilerini göster
DB::showDebugStatic(); 