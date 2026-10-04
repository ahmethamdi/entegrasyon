<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Kuyruk paneli (/horizon) YALNIZ işletmeciye açıktır: iş yükleri
        // kiracı kimliklerini ve hata metinlerini taşır. Liste ortamdan
        // okunur (HORIZON_ADMIN_EMAILS, virgülle); boşsa kimse göremez —
        // yerel ortamda Horizon kendisi herkese açar.
        Gate::define('viewHorizon', function ($user = null): bool {
            $admins = array_filter(array_map(
                static fn (string $email): string => mb_strtolower(trim($email)),
                explode(',', (string) config('entegrasyon.horizon_admin_emails', '')),
            ));

            return $user !== null
                && $user->hasVerifiedEmail()
                && in_array(mb_strtolower((string) $user->email), $admins, true);
        });
    }
}
