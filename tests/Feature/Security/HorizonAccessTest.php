<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kuyruk paneli (/horizon) yalnız işletmeciye açık — iş yükleri kiracı
 * kimliklerini ve hata metinlerini taşır.
 */
final class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_listed_verified_admins_can_view_horizon(): void
    {
        config()->set('entegrasyon.horizon_admin_emails', 'Ops@Example.com, ikinci@example.com');

        $admin = User::factory()->create(['email' => 'ops@example.com']);
        $unverifiedAdmin = User::factory()->unverified()->create(['email' => 'ikinci@example.com']);
        $seller = User::factory()->create(['email' => 'satici@example.com']);

        $this->assertTrue(Gate::forUser($admin)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($unverifiedAdmin)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($seller)->allows('viewHorizon'));
        $this->assertFalse(Gate::allows('viewHorizon'), 'Misafir göremez.');
    }

    #[Test]
    public function an_empty_list_lets_nobody_in(): void
    {
        config()->set('entegrasyon.horizon_admin_emails', '');

        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('viewHorizon'));
    }
}
