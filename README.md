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

## Ayar yedeği

Yönetim ekranının üstündeki **Ayarları İçe / Dışa Aktar** bölümünden JSON yedeği indirin veya bir yedek seçip **Ayarları İçe Aktar** düğmesine basın. Yedek; API bilgilerini, test ortamı ayarlarını, kategori/marka ve nitelik eşleşmelerini, kategori komisyonlarını, senkron ayarlarını, fiyat düşüş eşiğini ve özel sipariş durumlarını içerir. API anahtarları dosyada açık olarak bulunur; dosyayı güvenli saklayın.

Pazar yerleri anahtarlarına, kategori ve markalar taksonomi/slug değerlerine göre hedef siteyle eşleşir. Önce WooCommerce kategori ve markalarını aktarın; eksik eşleşme varsa hiçbir değişiklik uygulanmaz. Dosyadaki ayarlar ve aynı eşleşmeler güncellenir, diğer eşleşmeler korunur. Zamanlanmış senkronlar aktarılan ayarlara göre yeniden düzenlenir. Kayıt hatasında değişiklikler geri alınır; ilgili tabloların InnoDB olması gerekir. Dosya sınırı 10 MB'dir.

Ürünler, siparişler, işlem geçmişi ve ürün custom metaları bu yedeğe dahil değildir. KDV oranı `_multi_sync_vat_rate` ürün metasında saklanır ve ürün verileriyle taşınmalıdır.

Dışa aktarma listesinde panelde kullanılan hesaplar başlangıçta seçilidir; diğer desteklenen kayıtları da işaretleyebilirsiniz. İçe aktarmada JSON içindeki kayıtlar listelenir ve her entegrasyon için tek bir kaynak kayıt seçilir. Aynı entegrasyonun birden fazla kaydı varsa otomatik seçim yapılmaz; başka bir kaydı seçmek önceki seçimi kaldırır. Mevcut JSON dosyasını yeniden oluşturmanız gerekmez. Kayıt adı, kaynak kayıt numarası, satıcı kimliği, API bilgisi durumu ve eşleştirme sayısı seçimde gösterilir; gizli anahtarlar gösterilmez. Seçilen kaynak, hedef sitede panelin kullandığı hesabı günceller. Genel ayarlar da uygulanır. Desteklenmeyen eski/özel kayıtlar listede görünür ancak seçilemez; veritabanında korunur.

## Sürüm geçmişi

### 1.0.46

- Tüm pazaryerleri için stok/fiyat önizlemesi artık `salesPrice` (Çiçeksepeti), `sale_price` ve `price` alanlarını da okuyor.
- PTTAVM batch tracking artık JobWorker async listesinde; `cancelled`/`canceled` durumu `failed`, `waiting`/`in_progress` durumu `pending` olarak tanınıyor.
- Marka bulunamadığında tüm pazaryerleri için elle Marka ID + adı girişi eklendi.
- Kategori/marka eşleme bileşeni `TrendyolCategoryMapping` → `MarketplaceCategoryMapping` olarak yeniden adlandırıldı.

### 1.0.45

- Pazaryeri ürün gönderimi tüm adaptörler için generalize edildi.

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
