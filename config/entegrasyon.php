<?php

declare(strict_types=1);

/**
 * Entegrasyon uygulama ayarları.
 *
 * Mimari Karar Dokümanı v2.2 referans alınarak yazılmıştır.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Kimlik bilgisi şifreleme
    |--------------------------------------------------------------------------
    |
    | key_version yalnızca hangi kayıtların henüz yeniden şifrelenmediğini
    | görmek için tutulur. Çözme yönlendirmesi Laravel'in APP_PREVIOUS_KEYS
    | mekanizmasına bırakılır (§11).
    |
    */
    'credentials' => [
        'key_version' => (int) env('APP_KEY_VERSION', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kuyruk adları
    |--------------------------------------------------------------------------
    |
    | Mimari Karar Dokümanı v2.2 · §12. Kritik kuyruklar toplu işlerden ayrı
    | worker havuzlarında çalışır; reconciliation kendi havuzundadır.
    |
    */
    'queues' => [
        'orders' => 'orders:high',
        'inventory' => 'inventory:high',
        'inbox' => 'inbox:process',
        'price' => 'price:high',
        'listing' => 'listing:default',
        'listing_bulk' => 'listing:bulk',
        'reconciliation' => 'reconciliation',
        'maintenance' => 'maintenance',
    ],

    /*
    |--------------------------------------------------------------------------
    | Uyarı bildirimleri
    |--------------------------------------------------------------------------
    |
    | Mimari Karar Dokümanı v2.2 · §11 ("eşik aşımında e-posta") ve §12
    | ("sistem geneli: eşik aşarsa yönetici uyarısı").
    |
    | ADRES KODA GÖMÜLMEZ. Sistem ve bağlantı kapsamlı uyarıların kiracısı
    | yoktur; alıcıları buradan okunur. TANIMSIZSA o uyarılar GÖNDERİLMEZ
    | ve bu bilinçli bir kapıdır — uydurma bir adrese göndermek ya da
    | sessizce ilk kullanıcıya düşmek, uyarının yanlış kişiye gitmesi
    | demektir. Gönderilmeyen uyarı uygulama günlüğüne yazılır.
    |
    | Kiracı kapsamlı uyarılar bu ayardan BAĞIMSIZDIR: onlar kiracının
    | sahiplerine gider ve adresleri `tenant_users` üzerinden bulunur.
    |
    */
    'alerts' => [
        'admin_email' => env('ALERT_ADMIN_EMAIL'),
    ],

    /*
    | Beta kanal bildirimleri (kullanıcı kararı 21 Eki 2026) — 34Devs'e.
    | Beta kanala yeni bağlantı kurulunca ve o bağlantıda kalıcı/kimlik
    | hatası olunca: ntfy konusu (boşsa atlanır) + posta. Hata bildirimi
    | bağlantı başına saatte en çok bir kez.
    */
    'beta' => [
        'ntfy_topic' => env('BETA_NTFY_TOPIC'),
        'ntfy_base_url' => env('BETA_NTFY_BASE_URL', 'https://ntfy.sh'),
        'notify_email' => env('BETA_NOTIFY_EMAIL', 'info@34devs.com'),
    ],

    /*
    | Stok fan-out · yankı bastırma penceresi (dk). Varyantta bu süre içinde
    | kaynak dışı hareket varsa siparişin geldiği kanala da stok yazılır
    | (S7 yük testi yarışı). 0 = kaynak kanal hep atlanır (eski davranış).
    */
    'sync' => [
        'origin_echo_window_minutes' => (int) env('ORIGIN_ECHO_WINDOW_MINUTES', 30),
    ],

    /*
    | S7 · işletme izleme turu (`ops:watch`, 5 dk) — Black Friday hazırlığı.
    | Alarm 34Devs'e gider (yukarıdaki ntfy + posta), aynı alarm saatte 1.
    */
    'ops' => [
        // Horizon kuyruğunda en eski işin bekleme süresi (sn).
        'queue_wait_seconds' => (int) env('OPS_QUEUE_WAIT_SECONDS', 120),
        // Son 15 dakikada kanal başına 429 sayısı.
        'rate_limit_hits_15m' => (int) env('OPS_RATE_LIMIT_HITS_15M', 30),
        // Son 1 saatte ölen (dead) senkron işlemi, tüm sistem.
        'dead_operations_1h' => (int) env('OPS_DEAD_OPERATIONS_1H', 20),
        'throttle_seconds' => (int) env('OPS_ALERT_THROTTLE_SECONDS', 3600),
        // Son 1 saatte AI öneri çağrısı hata oranı (yüzde) ve en az çağrı sayısı.
        'ai_error_rate_percent' => (int) env('OPS_AI_ERROR_RATE_PERCENT', 30),
        'ai_error_min_calls' => (int) env('OPS_AI_ERROR_MIN_CALLS', 5),
        // Canlı ilanı olan bağlantı bu kadar saattir sağlıksızsa (günde bir alarm).
        'unhealthy_connection_hours' => (int) env('OPS_UNHEALTHY_CONNECTION_HOURS', 24),
    ],

    /*
    | S9 · otomatik kur çevirisi (TCMB).
    |
    | `max_age_days`: en son bülten bundan eskiyse kur KULLANILMAZ ve çevrilmiş
    | fiyat gönderilmez (hafta sonu 2–3 gün normaldir; uzun bayramda TCMB
    | bülten yayımlamaz — o zaman değeri büyüt ya da kanal fiyatı gir).
    | `anchor_threshold_percent`: yeni bülten fiyatlama kurundan bu kadar
    | saparsa kur güncellenir ve fiyatlar yeniden gönderilir; altında kalırsa
    | kanala istek gitmez.
    */
    'fx' => [
        'source_url' => env('FX_TCMB_URL', 'https://www.tcmb.gov.tr/kurlar/today.xml'),
        'max_age_days' => (int) env('FX_MAX_AGE_DAYS', 4),
        'anchor_threshold_percent' => (float) env('FX_ANCHOR_THRESHOLD_PERCENT', 0.5),
        'timeout_seconds' => (int) env('FX_TIMEOUT_SECONDS', 15),
    ],

    /*
    | S8 · AI ile listeleme (Claude).
    |
    | ANAHTAR YOKSA ÖZELLİK YOKTUR: panelde düğme çizilmez, rota 404 döner.
    | Yarım bir ekran (düğme var, her tıklamada hata) satıcıya bozuk ürün
    | gibi görünürdü. `AI_LISTING_ENABLED=false` anahtar varken de kapatır.
    |
    | Model seçimi: başlık/açıklama hızlı ve ucuz modelle; öznitelik ve
    | kategori (görsel + uzun değer listesi) kaliteli modelle.
    */
    /*
    | S12 · herkese açık demo (`/demo`, `demo:install`). Kapalıyken rota 404
    | ve kurulum yapılmaz. Ayrıntı: App\Domain\Identity\Support\DemoAccount.
    */
    'demo' => [
        'enabled' => (bool) env('DEMO_ENABLED', true),
    ],

    'ai' => [
        'enabled' => (bool) env('AI_LISTING_ENABLED', true),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model_fast' => env('AI_MODEL_FAST', 'claude-haiku-5-5'),
        'model_quality' => env('AI_MODEL_QUALITY', 'claude-sonnet-5-5'),
        'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 60),
        // Kategori önerisinde modele giden aday sayısı (bizim ağacımızdan).
        'category_candidates' => (int) env('AI_CATEGORY_CANDIDATES', 40),
        // Bu güvenin altındaki kategori önerisi panelde işaretlenir.
        'low_confidence' => (int) env('AI_LOW_CONFIDENCE', 60),
        // Öznitelik başına modele gösterilen en çok değer.
        'max_values_per_attribute' => (int) env('AI_MAX_VALUES_PER_ATTRIBUTE', 300),
    ],

    /*
    | Ödeme sağlayıcısı — §13 · Faz 4.
    |
    | KULLANICI KARARI: Stripe (doküman "iyzico" diyor; sapma onaylı).
    |
    | ANAHTARLAR KODA GÖMÜLMEZ ve `.env`'den okunur. Gömülü anahtar
    | depoya sızar ve iptal edilene kadar herkes hesabı kullanabilir.
    |
    | `webhook_secret` AYRI bir sırdır ve gizli anahtarla AYNI DEĞİLDİR:
    | imza doğrulaması onunla yapılır. Karıştırılırsa doğrulama HER
    | ZAMAN başarısız olur ve hiçbir ödeme işlenmez.
    */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Her 34Pazar faturasının altbilgisi (müşteri `invoice_settings.footer`).
        // Stripe hesabı 34Devs'in (DE); Kleinunternehmer notu zorunlu.
        'invoice_footer' => env('STRIPE_INVOICE_FOOTER', '34Devs Shopify Agency · Inh. Ahmet Hamdi Kilic · Hedwigstraße 27, 41352 Korschenbroich, Deutschland · USt-IdNr. DE450498414 · info@34devs.com · Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.'),
    ],

    /*
    | Kuyruk paneli (/horizon) erişimi — virgülle ayrılmış e-postalar.
    | Boşsa üretimde kimse göremez (HorizonServiceProvider).
    */
    /*
    | ADR-010 · Canlı doğrulama kapısından GEÇMEMİŞ kanallar. Bu türlerin
    | bağlantılarında yazma izinleri (ürün yayını, fiyat, stok) panelden
    | AÇILAMAZ. Bir kanal listeden yalnız canlı doğrulama tamamlanıp açık
    | onay verildiğinde çıkarılır.
    |
    | 21 Eki 2026 kullanıcı kararı: varsayılan liste BOŞ — doğrulanmamış
    | kanallar "beta" olarak açık; yazma izinleri yine kapalı doğar ama
    | satıcı açabilir. Acil durumda env ile kanal yeniden kilitlenebilir.
    */
    'outbound_write_locked_channels' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OUTBOUND_WRITE_LOCKED_CHANNELS', '')),
    ))),

    /*
    | S5 · Tedarikçi XML akışı en büyük boyutu (bayt). Okuma bu sınırda
    | KESİLİR; tur FETCH_FAILED (SUPPLIER_FEED_TOO_LARGE) olur.
    */
    'supplier_feed_max_bytes' => (int) env('SUPPLIER_FEED_MAX_BYTES', 64_000_000),

    'horizon_admin_emails' => env('HORIZON_ADMIN_EMAILS', ''),

    /*
    | Süper admin (/admin) — virgülle ayrılmış e-postalar. Boşsa Horizon
    | listesine düşer: iki ayrı yönetici listesi tutulup ayrışmasın diye.
    | E-postası DOĞRULANMAMIŞ hesap listede olsa bile giremez.
    */
    'super_admin_emails' => env('SUPER_ADMIN_EMAILS', env('HORIZON_ADMIN_EMAILS', '')),
];
