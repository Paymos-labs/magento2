# Magento 2 için Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

Magento Open Source ve Adobe Commerce için resmî Paymos ödeme modülü. Ödeme adımına tek bir yöntem
ekler: müşteri siparişi verir, Paymos’un barındırılan ödeme sayfasına yönlendirilir, bir token ile
ağ seçer ve transferi kendi cüzdanından gönderir. Magento siparişi, müşteri mağazaya geri döndüğünde
değil, imzalı bir webhook ödemenin zincir üzerinde onaylandığını bildirdiğinde faturalandırır.

[![İzleyin: 12 Resmî CMS Kripto Ödeme Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video anlatım (1:12, 6 dilde altyazılı):** [YouTube’da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

- Magento Open Source veya Adobe Commerce 2.4 — modül `magento/framework` 103.0 veya üzerini gerektirir
- PHP 8.3, 8.4 veya 8.5 (Adobe Commerce 2.4.9 ile 8.1/8.2 desteği kaldırıldı)
- `bin/magento` için kabuk (shell) erişimi
- HTTPS üzerinden sunulan bir vitrin; bağlantı akışı düz HTTP temel URL’yi reddeder
- Bağlamak istediğiniz projenin panelde açık olduğu bir Paymos hesabı

Bu sürümler `app/code/Paymos/Payment/composer.json` dosyasında tanımlıdır. Sürüm arşivi Paymos PHP
SDK’yı modülün kendi `vendor/` dizini altında paketlediği için manuel kurulumda Composer adımı
gerekmez.

## Kurulum

[Releases](https://github.com/Paymos-labs/magento2/releases/latest) sayfasından veya Paymos
panelindeki **CMS integration** bölümünden `paymos-magento2-<version>.zip` dosyasını alın ve
Magento kök dizinine çıkarın. Dosyalar `app/code/Paymos/Payment/…` yapısıyla hazır gelir; sonrasında
hiçbir şeyi taşımanız gerekmez.

```bash
bin/magento module:enable Paymos_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode only
bin/magento cache:flush
```

`setup:upgrade` iki tablo oluşturur: her sipariş için tek bir anlık görüntü tutan
`paymos_payment_invoice` ve webhook tekilleştirme defteri olan `paymos_payment_event`.

Her sürüm için tek bir arşiv yayımlanır ve her işyeri aynı dosyayı indirir. İçinde API anahtarı,
API secret’ı, proje ID’si, webhook secret’ı, OAuth token’ı veya cihaz kodu bulunmaz; kurulum yapmak
tek başına herhangi bir bağlantı kurmaz.

## Bağlantı

1. Paymos panelinde bu mağazanın faturalandırma yapacağı projeyi açın. Mağazaya bağlanacak proje
   budur — modülün içinde ayrı bir proje seçici yoktur.
2. Magento’da **Stores → Configuration → Sales → Payment Methods → Paymos** ekranını açın ve
   **Connect Paymos** düğmesine basın.
3. Açılan Paymos sekmesinde gösterilen mağaza URL’sini ve projeyi onaylayın. Tarayıcı sekmeyi
   engellerse ilgili satır bunun yerine onay bağlantısını ve kullanıcı kodunu yazdırır.
4. **Connection status** değerinin bağlı (connected) görünmesini bekleyin, ardından **Enabled**
   seçeneğini Yes yapın.

Tek bir onay hem Sandbox hem de Live ortamını birlikte hazırlar. Tek etkin Payment anahtarınız
yeniden kullanılır (yoksa yeni bir tane oluşturulur) ve aşağıdaki adrese bir Invoice webhook’u
kaydedilir:

```text
https://your-store.example/paymos/payment/callback
```

Mevcut bir webhook yalnızca callback URL’si, kategori ve proje tamamen eşleştiğinde yeniden
kullanılır; aynı adresteki çakışan bir webhook asla üzerine yazılmaz. Cihaz yetkilendirmesi tek
seferlik bir teslimat kanalıdır — kısa ömürlü token atılır ve sonraki tüm Merchant API çağrıları
HMAC ile imzalanır.

Kimlik bilgileri Magento’nun `EncryptorInterface` arayüzüyle şifrelenerek `core_config_data`
tablosuna yazılır ve yönetici sayfasına hiçbir zaman geri yazdırılmaz. Secret yapıştırılacak bir
alan yoktur; değiştirmenin tek yolu yeniden bağlanmaktır.

## Yapılandırma kapsamı

Modül alanları **Default Config** ve **website** kapsamında sunulur. Store view’lar Magento’nun
normal devralma (fallback) mekanizmasıyla ayarları devralır; bir store view’un çözümlediği değer
kendi ödeme akışı ve siparişleri için geçerlidir.

| Ayar | Varsayılan | Amaç |
|---|---|---|
| Enabled | No | Yöntemin ödeme adımında sunulup sunulmayacağı |
| Title | `Pay with crypto (Paymos)` | Müşterinin gördüğü etiket |
| Mode | `sandbox` | Web sitesinin kullandığı kimlik bilgisi seti |
| New order status | `pending_payment` | Müşteri ödeme yaparken uygulanan durum |
| Paid order status | `processing` | Ödeme onaylandığında uygulanan durum |
| Debug logging | No | `var/log/paymos.log` dosyasına tanılama kaydı |
| Sort order | `1` | Diğer yöntemler arasındaki sıralama |

Bağlanan kimlik bilgileri web sitesi başına değil kurulum başına tek bir settir; bu nedenle ikinci
bir web sitesi için ayrı onay gerekmez. Bir web sitesini ortamlar arasında yalnızca **Mode** ile
taşıyın. Yeniden bağlanma yalnızca temel URL değiştiğinde, bir Payment anahtarı iptal edildiğinde
veya webhook secret’ı rotasyona alındığında gerekir.

## Siparişte neler olur

| Paymos olayı | Magento’daki sonuç |
|---|---|
| Fatura oluşturuldu, müşteri yönlendirildi | Yapılandırılan **New order status** ve faturayı belirten bir geçmiş yorumu |
| `invoice.confirming` | Durum değişmez; ödemenin onaylanmakta olduğunu belirten yorum eklenir |
| `invoice.underpaid_waiting` | Durum değişmez; sipariş kalan tutarı bekler |
| `invoice.awaiting_payment` | Durum değişmez; sayılmış bir ödeme zincir yeniden düzenlemesinde (reorg) kayboldu |
| `invoice.paid`, `invoice.paid_over` | Çevrimdışı fatura belgesi oluşturulur, sipariş Processing durumuna ve yapılandırılan **Paid order status** değerine geçer, fatura e-postası gönderilir |
| `invoice.underpaid` | Sipariş iptal edilir |
| `invoice.expired`, `invoice.cancelled` | Sipariş iptal edilir |

Fatura belgesi, son onaylanan transferin zincir üstü işlem hash’ini transaction id olarak kaydeder;
yükte transfer yoksa Paymos fatura ID’sine geri döner.

Bu tablonun önünde iki koruma bulunur. Fatura oluşturulduktan sonra genel toplamı veya para birimi
değişen bir sipariş asla otomatik tamamlanmaz — manuel inceleme isteyen bir yorum alır ve yeniden
deneme zincirinin durması için teslimat yine de onaylanır. Ayrıca sipariş ödendikten sonra geç
gelen bir `confirming`, `underpaid`, `expired` veya `cancelled` olayı günlüğe yazılır ve
uygulanmadan yoksayılır.

## Canlıya geçmeden önce test edin

1. **Mode** ayarını Sandbox yapın ve vitrinden bir sipariş verin.
2. Paymos paneli Sandbox konumundayken ilgili faturayı açın. **Pay Full**, **Pay 50%**, **Pay 150%**
   ve **Cancel** düğmeleri gerçek bir ödemenin ürettiği yaşam döngüsü olaylarının aynısını tetikler.
   Gerçek kripto hareketi olmaz.
3. Magento siparişini kontrol edin — durum, geçmiş yorumları ve fatura belgesinin oluşup oluşmadığı.
4. **Mode** ayarını Live yapın. Live kimlik bilgileri aynı onayla geldiği için yeniden bağlanmanız
   veya ikinci kez yapılandırma yapmanız gerekmez.

Sandbox yükleri transfer taşımaz; bu nedenle canlı bir sipariş işlem hash’i kaydederken Sandbox
siparişi fatura ID’sini kaydeder.

## Webhook’lar

Teslimatlar `/paymos/payment/callback` adresine `POST` olarak gelir. Güven sınırı
`X-Webhook-Signature` başlığı olduğu için rota Magento’nun form key kontrolünden muaftır; imzasız
veya yanlış imzalı bir gövde `401` alır ve hiçbir siparişe ulaşmaz. `X-Webhook-Id` yeniden
denemeler boyunca sabit kalır ve `paymos_payment_event` tablosunda tekilleştirme anahtarı olarak
saklanır; böylece tekrar teslimat `200` yanıtı verir ve hiçbir şeyi değiştirmez.

Nihai olaylar ters doğrulamadan geçer. Bir sipariş tamamlanmadan veya iptal edilmeden önce fatura
Merchant API’den geri okunur ve saklanan anlık görüntüyle karşılaştırılır; uyuşmayan yük
reddedilir.

Bir teslimat döngüsü yaklaşık 16 saate yayılan 11 denemeden oluşur; böylece kısa süreli erişilemeyen
bir mağaza yine de olayı alır. Bunun arkasında `paymos_reconcile_invoices` cron görevi son açık
faturaları API’den çekmek için 15 dakikada bir çalışırken, `paymos_reconcile_refunds` bekleyen
iadeleri kontrol etmek için her dakika çalışır; her ikisi de Magento cron’unun aktif olmasını
gerektirir.

## İadeler

Modül, Paymos Merchant API (`/v1/refunds`) üzerinden iki modda yerel iade desteği sunar:

- `full` — ödenmiş fatura tutarının tamamını iade eder;
- `overpayment` — `invoice.paid_over` siparişinde yalnızca fazladan gelen tutarı iade eder.

Fonlar yalnızca Paymos’un fatura ödenirken kaydettiği doğrulanmış `first_sender` cüzdan adresine
döner. Elle alıcı cüzdan adresi girilmez ve kısmi ürün veya düzeltme iadeleri reddedilir.

- **Tam iade (`full`):** Magento Admin’de ödenmiş Paymos siparişinin faturasını açın ve faturalanan
  tüm kalemlerin tam iade edildiği, kargo veya tutar düzeltmesi içermeyen yerel bir **Credit Memo**
  gönderin. `Paymos\Payment\Plugin\RefundEntry` gönderimi yakalar, zamansız bir yerel Credit Memo
  kaydetmeden Paymos nezdinde `full` iadesini başlatır ve **Paymos refunds** paneline yönlendirir.
- **Fazla ödeme iadesi (`overpayment`) ve durum kontrolleri:** **Stores → Configuration → Sales →
  Payment Methods → Paymos** ekranına gidin, **Paymos refunds** alanına sipariş ID’sini girip
  **Load order** düğmesine tıklayın ve **Refund overpayment** veya **Check full refund** /
  **Check overpayment refund** seçeneklerini kullanın.

Magento, Credit Memo belgesini ve sipariş geçmiş yorumunu yalnızca Paymos `/paymos/payment/callback`,
manuel durum kontrolü veya 1 dakikalık `paymos_reconcile_refunds` cron görevi aracılığıyla
`completed` durumunu onayladıktan sonra oluşturur. Sandbox modunda kripto hareketi olmadan nihai
iade sonuçları (`completed`, `failed`, `cancelled`) simüle edilebilir.

## Sorun giderme

**Ödeme adımında Paymos görünmüyor.** Yöntem kurulumda devre dışı gelir. Vitrinin bağlı olduğu web
sitesinde **Enabled** ayarını Yes yapın, **Connection status** bilgisinin bağlı olduğunu doğrulayın
ve yapılandırma önbelleğini temizleyin — Magento diğer yapılandırma yolları gibi `payment/paymos/*`
yolunu da önbelleğe alır.

**Siparişler ödeme bekliyor durumunda kalıyor.** Webhook ulaşmıyordur. Callback adresi onaylanan
tam temel URL üzerinden genel internetten erişilebilir olmalıdır: loopback, özel ve link-local
hedefler engellenir ve yönlendirmeler takip edilmez; dolayısıyla sunucu taşıyan veya yönlendirme
ekleyen bir mağazanın yeniden bağlanması gerekir. Bu arada `bin/magento cron:run` çalıştırmak
mutabakat görevinin açığı kapatıp kapatamadığını gösterir.

**Bağlantı "Magento base URL must use HTTPS" hatasıyla başarısız oluyor.** Cihaz akışı varsayılan
store view’un temel URL’sini okur; onay ekranında gösterilen ve webhook’un kaydedildiği adres budur.
Adresi HTTPS üzerinden sunup bağlantıyı yeniden başlatın.

## Bağlantılar

- Dokümantasyon: [paymos.io/tr/docs/cms-magento2](https://paymos.io/tr/docs/cms-magento2)
- Kaynak kodu ve sürümler: [Paymos-labs/magento2](https://github.com/Paymos-labs/magento2)
- Destek: [support@paymos.io](mailto:support@paymos.io)
