# PHPWE Katkıda Bulunma Rehberi

PHPWE projesine katkıda bulunmak istediğiniz için teşekkür ederiz! Bu belge, projeye nasıl katkıda bulunabileceğinizi açıklar.

## İçindekiler

1. [Kod Geliştirme Süreci](#kod-geliştirme-süreci)
2. [Hata Raporlama](#hata-raporlama)
3. [Özellik İstekleri](#özellik-istekleri)
4. [Pull Request Gönderme](#pull-request-gönderme)
5. [Kodlama Standartları](#kodlama-standartları)

## Kod Geliştirme Süreci

Projeye katkıda bulunmak için aşağıdaki adımları izleyin:

1. Bu depoyu kendi GitHub hesabınıza fork edin.
2. Yerel bir kopya oluşturun: `git clone https://github.com/kullaniciadi/phpwe.git`
3. Yeni bir dal (branch) oluşturun: `git checkout -b ozellik/yeni-ozellik` veya `hata/hata-aciklamasi`
4. Değişikliklerinizi yapın ve bunları test edin.
5. Değişikliklerinizi commit edin: `git commit -m "Özellik: Yeni özellik eklendi"`
6. Dalınızı GitHub'daki fork'unuza push edin: `git push origin ozellik/yeni-ozellik`
7. Bu depoya bir Pull Request açın.

## Hata Raporlama

Bir hata bulduğunuzda, aşağıdaki bilgileri içeren bir GitHub issue açın:

- Hatanın kısa bir başlığı
- Hatanın detaylı açıklaması
- Hatanın nasıl tekrarlanabileceğini gösteren adımlar
- Beklenen davranış ve gerçekleşen davranış
- Kullandığınız PHP sürümü ve veritabanı bilgileri
- Varsa ekran görüntüleri veya hata mesajları

## Özellik İstekleri

Yeni bir özellik önerisi için, aşağıdaki bilgileri içeren bir GitHub issue açın:

- Özelliğin kısa bir başlığı
- Özelliğin detaylı açıklaması
- Özelliğin nasıl kullanılacağına dair örnek kod
- Özelliğin projeye nasıl fayda sağlayacağı
- Varsa, benzer projelerde bu özelliğin nasıl çalıştığına dair örnekler

## Pull Request Gönderme

Pull Request göndermeden önce şunlara dikkat edin:

- Kodunuzun test edildiğinden emin olun.
- Kodunuzun kodlama standartlarına uygun olduğundan emin olun.
- PR açıklamasında değişikliklerin amaçlarını detaylı açıklayın.
- İlgili issue numarasını PR açıklamasına ekleyin (örneğin, "Fixes #123").

## Kodlama Standartları

Proje, PSR-12 kodlama standartlarını takip eder. Katkıda bulunurken:

- Girintiler için 4 boşluk kullanın.
- Sınıf, metod ve değişken isimleri anlamlı ve tutarlı olmalıdır.
- PHP-DOC stil dokümantasyon yorumları ekleyin.
- Karmaşık kod bloklarını yorumlarla açıklayın.
- Kodunuzun PHP 7.4 ve üzeri sürümlerle uyumlu olduğundan emin olun.

### Örnek Sınıf Yapısı

```php
<?php

namespace App\Models;

use App\Database\Model;
use App\Database\Unique;

/**
 * ExampleModel sınıfı
 * 
 * Bu sınıf örnek bir model sınıfıdır.
 */
class ExampleModel extends Model
{
    public int $id;
    public Unique $uniqueField;
    public string $normalField;
    
    /**
     * Özel bir işlem yapar
     * 
     * @param string $param Parametre açıklaması
     * @return bool İşlem sonucu
     */
    public function doSomething(string $param): bool
    {
        // İşlem kodları
        return true;
    }
}
```

Katkılarınız için şimdiden teşekkür ederiz! 