<?php

declare(strict_types=1);

use App\Domain\Channels\Console\PruneApiCallsCommand;
use App\Domain\Channels\Console\SyncTaxonomyCommand;
use App\Domain\Messaging\Console\DetectUnconsumedEventsCommand;
use App\Domain\Messaging\Console\OutboxRelayCommand;
use App\Domain\Messaging\Console\RecoverPendingInbox;
use App\Domain\Orders\Console\PollChannelOrdersCommand;
use App\Domain\Reconciliation\Console\ReconcileColdCommand;
use App\Domain\Reconciliation\Console\ReconcileHotCommand;
use App\Domain\Reconciliation\Console\ReconcileWarmCommand;
use App\Domain\Sync\Console\DetectStuckSyncOperationsCommand;
use App\Domain\Sync\Console\TrackApprovalStatusCommand;
use App\Http\Middleware\EstablishTenantContext;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Observability\CaptureMetricsCommand;
use App\Support\Observability\DispatchAlertsCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
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
        // §13 · Faz 2 · taksonomi. Zamanlaması routes/console.php içinde.
        SyncTaxonomyCommand::class,
        // §13 · Faz 2 · onay durumu takibi. Zamanlaması routes/console.php.
        TrackApprovalStatusCommand::class,
        // §13 · Faz 2 · sipariş yoklaması. Zamanlaması routes/console.php.
        PollChannelOrdersCommand::class,
        // §13 · Faz 3 · api_calls saklama. Zamanlaması routes/console.php.
        PruneApiCallsCommand::class,
        // §11 · metrik anlık görüntüleri. Zamanlaması routes/console.php.
        CaptureMetricsCommand::class,
        // §11 · §12 · eşik aşımı uyarıları. Zamanlaması routes/console.php.
        DispatchAlertsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Kiracı bağlamı AYRI bir ara katmandır, web grubuna eklenmez:
        // giriş ve kayıt rotaları henüz kiracısızdır ve bağlam kurmaya
        // çalışmak onları kendi üzerine yönlendirirdi.
        $middleware->alias([
            'tenant' => EstablishTenantContext::class,
        ]);

        // Giriş yapmamış ziyaretçi panel yerine giriş ekranına gider.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
