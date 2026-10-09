<?php

declare(strict_types=1);

use App\Domain\Catalog\Console\TickPriceCampaignsCommand;
use App\Domain\Channels\Console\CheckChannelsHealthCommand;
use App\Domain\Channels\Console\PruneApiCallsCommand;
use App\Domain\Channels\Console\RefreshExpiringTokensCommand;
use App\Domain\Channels\Console\RegisterWebhooksCommand;
use App\Domain\Channels\Console\SyncTaxonomyCommand;
use App\Domain\Messaging\Console\DetectUnconsumedEventsCommand;
use App\Domain\Messaging\Console\OutboxRelayCommand;
use App\Domain\Messaging\Console\RecoverPendingInbox;
use App\Domain\Orders\Console\PollChannelOrdersCommand;
use App\Domain\Orders\Console\ResolveUnmatchedOrderLinesCommand;
use App\Domain\Reconciliation\Console\ReconcileColdCommand;
use App\Domain\Reconciliation\Console\ReconcileHotCommand;
use App\Domain\Reconciliation\Console\ReconcilePricesCommand;
use App\Domain\Reconciliation\Console\ReconcileWarmCommand;
use App\Domain\Sync\Console\DetectStuckSyncOperationsCommand;
use App\Domain\Sync\Console\PollChannelBatchesCommand;
use App\Domain\Sync\Console\TrackApprovalStatusCommand;
use App\Http\Middleware\EstablishTenantContext;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Support\LoadTest\SyncLoadTestCommand;
use App\Support\Observability\CaptureMetricsCommand;
use App\Support\Observability\DispatchAlertsCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Webhook rotaları web grubunda DEĞİL: CSRF muaf ve oturumsuz.
        // Muafiyetin bedeli HMAC doğrulamasıyla ödenir (§11).
        then: function (): void {
            Route::middleware('api')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    // Domain klasörlerindeki komutlar otomatik keşfedilmez; Laravel yalnızca
    // app/Console/Commands altını tarar. Modüler yapıda açık kayıt gerekir.
    ->withCommands([
        OutboxRelayCommand::class,
        RecoverPendingInbox::class,
        // §6 · iki bütünlük taraması. Zamanlaması routes/console.php içinde;
        // kayıt burada olmadan zamanlayıcı komutu bulamaz ve tarama sessizce
        // hiç çalışmaz (ScheduledScansTest bu ikisini ayrı ayrı doğrular).
        DetectUnconsumedEventsCommand::class,
        DetectStuckSyncOperationsCommand::class,
        // §10 · mutabakatın ÜÇ KATMANI — ayrı komutlar, ayrı frekanslar.
        // Zamanlamaları routes/console.php içinde.
        ReconcileHotCommand::class,
        ReconcileWarmCommand::class,
        ReconcileColdCommand::class,
        // §9 · fiyat çakışması turu — katman değil DOMAIN ayrımı, gerekçe
        // komutun sınıf başlığında. Zamanlaması routes/console.php içinde.
        ReconcilePricesCommand::class,
        // §13 · Faz 2 · taksonomi. Zamanlaması routes/console.php içinde.
        SyncTaxonomyCommand::class,
        RegisterWebhooksCommand::class,
        // §13 · Faz 2 · onay durumu takibi. Zamanlaması routes/console.php.
        TrackApprovalStatusCommand::class,
        // Asenkron kanal toplu işlerinin satır sonucu. Zamanlaması routes/console.php.
        PollChannelBatchesCommand::class,
        // §13 · Faz 2 · sipariş yoklaması. Zamanlaması routes/console.php.
        PollChannelOrdersCommand::class,
        // A12 · eşleşmemiş sipariş satırları. Zamanlaması routes/console.php.
        ResolveUnmatchedOrderLinesCommand::class,
        TickPriceCampaignsCommand::class,
        // §13 · Faz 3 · api_calls saklama. Zamanlaması routes/console.php.
        PruneApiCallsCommand::class,
        // V3.0 · §03 · Delta 3 · token yenileme. Zamanlaması routes/console.php.
        RefreshExpiringTokensCommand::class,
        // Kanal sağlık taraması (8 Eki canlı olayı). Zamanlaması routes/console.php.
        CheckChannelsHealthCommand::class,
        // §11 · metrik anlık görüntüleri. Zamanlaması routes/console.php.
        CaptureMetricsCommand::class,
        // §11 · §12 · eşik aşımı uyarıları. Zamanlaması routes/console.php.
        DispatchAlertsCommand::class,
        // §11 · yük testi. ZAMANLANMAZ ve bu bilinçlidir: ölçüm aracıdır,
        // bakım turu değil — zamanlansaydı her gece kendiliğinden veri
        // üretir ve kuyruğu meşgul ederdi. Elle çalıştırılır.
        SyncLoadTestCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // Dil Inertia paylaşımından ÖNCE kurulur: `translations` prop'u
            // hangi dilin dosyasını göndereceğini buradan okur.
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Kiracı bağlamı AYRI bir ara katmandır, web grubuna eklenmez:
        // giriş ve kayıt rotaları henüz kiracısızdır ve bağlam kurmaya
        // çalışmak onları kendi üzerine yönlendirirdi.
        $middleware->alias([
            'tenant' => EstablishTenantContext::class,
        ]);

        // VEKİL SUNUCUYA GÜVEN. Üretimde istek Plesk (nginx) → Caddy →
        // php-fpm zinciriyle gelir; php-fpm'e yalnız aynı Docker ağındaki
        // Caddy ulaşabilir. Güvenilmeseydi Laravel isteği "http" sanır,
        // https yerine http bağlantı üretir ve imzalı bağlantılar (e-posta
        // doğrulama) "geçersiz imza" verirdi.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Giriş yapmamış ziyaretçi panel yerine giriş ekranına gider.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // DOĞRULAMA HATASINDA SIR OTURUMA FLASH EDİLMEZ — §11.
        //
        // Laravel doğrulama hatasında TÜM istek girdisini oturuma flash
        // eder ki form yeniden doldurulabilsin. Varsayılan `dontFlash`
        // listesi yalnızca `password` ailesini kapsar — kanal anahtarları
        // DEĞİL.
        //
        // Bedeli: kullanıcı kanal formunu eksik doldurup gönderdiğinde
        // anahtar oturum deposuna DÜZ METİN yazılır ve bu projede oturum
        // deposu VERİTABANIDIR (`SESSION_DRIVER=database`). Kasada
        // şifrelenen değer, kasaya hiç ulaşmadan şifresiz bir tabloya
        // düşer ve oturum süresi boyunca orada durur.
        //
        // LİSTE BUGÜN KULLANILANLARDAN GENİŞTİR ve bu bilinçlidir: yeni
        // bir kanal eklendiğinde alan adı burada YOKSA sızıntı sessizce
        // geri gelir ve hiçbir test onu göstermez. `PayloadRedactor`'ün
        // anahtar listesiyle aynı gerekçe.
        $exceptions->dontFlash([
            'consumer_key', 'consumer_secret',
            'api_key', 'api_secret',
            'access_token', 'refresh_token', 'token', 'secret',
            // V3.0 · §19 — Etsy OAuth. `code_verifier` PKCE'nin tek
            // kullanımlık sırrıdır ve `etsy_keystring` uygulamanın
            // kimliğidir; ikisi de bir doğrulama hatasında oturuma flash
            // edilirse `SESSION_DRIVER=database` altında ŞİFRESİZ bir
            // tabloya düşer.
            'code_verifier', 'etsy_keystring', 'keystring',
        ]);
    })->create();
