<?php

declare(strict_types=1);

use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
| Bu turda yalnızca iskeletin ayakta olduğunu gösteren tek sayfa var.
| Panel ekranları sonraki fazlarda eklenecek.
*/
Route::get('/', fn () => Inertia::render('Dashboard', [
    'tenantCount' => Tenant::count(),
    'channelTypes' => ChannelType::query()
        ->orderBy('code')
        ->get(['code', 'name', 'kind', 'is_active']),
]))->name('dashboard');
