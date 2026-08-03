# Open Entegre WP

WooCommerce ürün, stok, fiyat ve sipariş verilerini birden fazla pazar yeriyle yönetmek için geliştirilmiş WordPress eklentisi.

Bu proje özgür ve açık kaynaklıdır. Kullanabilir, inceleyebilir, değiştirebilir ve GPL-2.0-or-later koşullarıyla dağıtabilirsiniz.

## Desteklenen pazar yerleri

- Trendyol
- n11
- Pazarama
- Çiçeksepeti
- Amazon
- PTTAVM
- Hepsiburada

Her entegrasyonun desteklediği işlem ve gerekli API yetkileri pazar yerine göre değişebilir.

## Özellikler

- Pazar yeri hesaplarını tek yönetim ekranından yapılandırma
- Ürünleri önizleyerek WooCommerce'e aktarma
- Basit ve varyasyonlu ürün desteği
- Stok ve fiyat değişikliklerini manuel, zamanlanmış veya olay tabanlı işlerle gönderme
- Siparişleri önizleme ve WooCommerce'e aktarma
- Kategori, marka ve alan eşleştirmeleri
- İş kuyruğu, onay/red akışı ve değişiklik geçmişi
- Pazar yeri sorularını görüntüleme ve desteklenen kanallarda yanıtlama
- WordPress yönetim paneli için React tabanlı arayüz

## Gereksinimler

- WordPress
- WooCommerce
- Pazar yerlerinden alınmış geçerli API bilgileri ve gerekli hesap yetkileri

Belirli bir minimum WordPress, WooCommerce veya PHP sürümü henüz belgelenmemiştir. Üretim kurulumu öncesinde kendi ortamınızda test edin.

## Kurulum

1. Repoyu `wp-content/plugins/sync_plugin` dizinine indirin veya kopyalayın.
2. WooCommerce'in kurulu ve etkin olduğundan emin olun.
3. WordPress yönetim panelinde **Eklentiler** sayfasından **Open Entegre WP** eklentisini etkinleştirin.
4. Sol menüdeki **Çoklu Senkron** sayfasını açın.
5. Kullanacağınız pazar yerinin API bilgilerini girin, ardından senkron ayarlarını kaydedin.

Eklenti etkinleştirildiğinde gerekli veritabanı tablolarını oluşturur ve zamanlanmış işleri kaydeder.

## Yönetim ekranları

- **Yetkilendirme:** Pazar yeri hesabı ve API bilgileri
- **Senkron Ayarları:** Ürün, stok, fiyat ve sipariş akışlarının ayarları
- **Senkron Merkezi:** Önizleme, manuel çalıştırma ve kuyruk işlemleri
- **Sorular:** Pazar yerlerinden gelen müşteri soruları

## Geliştirme

Yönetim arayüzünün kaynak kodu `admin-ui/src` dizinindedir. Arayüzü değiştirdikten sonra:

```bash
cd admin-ui
npm install
npm run build
```

WordPress'in yüklediği derlenmiş dosyalar `admin-ui/build` dizinindedir.

## Destek olun

Projeyi faydalı bulduysanız GitHub'da yıldız vererek daha fazla kişiye ulaşmasına yardımcı olabilirsiniz. Geliştiriciyseniz hata düzeltmeleri ve yeni özellikler için pull request oluşturabilirsiniz.

## Katkıda bulunma

Hata bildirimleri, geliştirme önerileri ve pull request'ler kabul edilir. Değişiklik yapmadan önce ilgili bir issue açarak kapsamı konuşmanız önerilir.

1. Repoyu klonlayın.
2. Değişikliğiniz için ayrı bir branch oluşturun.
3. Yönetim arayüzünü değiştirdiyseniz `npm run build` çalıştırın.
4. Branch'inizi gönderin ve değişikliğin ne yaptığını açıklayan bir pull request açın.

## Desert Tycoon

Geliştiricinin oyunu **Desert Tycoon: Build & Explore**:

- [Google Play](https://play.google.com/store/apps/details?id=com.semilon.deserttycoon)
- [CrazyGames](https://www.crazygames.com/game/desert-tycoon-zov)

## Güvenlik

API anahtarlarını, erişim tokenlarını veya müşteri verilerini repoya commit etmeyin. Entegrasyon bilgilerini yalnızca WordPress yönetim ekranından girin ve canlı ortamda hata ayıklama kayıtlarını gerektiğinde etkinleştirin.

## Lisans

Bu proje [GNU General Public License v2.0 veya sonrası](LICENSE) ile lisanslanmıştır.

Repodaki üçüncü taraf bileşenler kendi lisanslarına tabidir.
