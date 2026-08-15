<?php

declare(strict_types=1);

namespace Tests\Unit\Uuid;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

/**
 * Test I — UUIDv7 birincil anahtar.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 23.
 */
final class HasUuidV7Test extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function primary_key_is_generated_automatically(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNotEmpty($tenant->id);
        $this->assertTrue(Uuid::isValid($tenant->id));
    }

    #[Test]
    public function generated_uuid_is_version_7(): void
    {
        $tenant = Tenant::factory()->create();

        $uuid = Uuid::fromString($tenant->id);

        // UUIDv7 zaman sıralıdır; indeks bölünmesi yaratmaz.
        // Uuid::fromString() sürüm bitlerine bakıp doğru alt sınıfı döndürür.
        $this->assertInstanceOf(UuidV7::class, $uuid);

        // Sürüm bitleri: 13. onaltılık hane '7' olmalı.
        $this->assertSame('7', substr(str_replace('-', '', $tenant->id), 12, 1));
    }

    #[Test]
    public function uuids_are_time_ordered(): void
    {
        $first = Tenant::factory()->create();
        usleep(2000);
        $second = Tenant::factory()->create();

        // Zaman sıralı olduğu için sözlüksel karşılaştırma da sıralıdır.
        $this->assertLessThan(0, strcmp($first->id, $second->id));
    }

    #[Test]
    public function explicit_id_is_not_overwritten(): void
    {
        $explicit = (string) Uuid::v7();

        $tenant = Tenant::factory()->create(['id' => $explicit]);

        $this->assertSame($explicit, $tenant->id);
    }

    #[Test]
    public function key_type_is_string_and_not_incrementing(): void
    {
        $tenant = new Tenant;

        $this->assertSame('string', $tenant->getKeyType());
        $this->assertFalse($tenant->getIncrementing());
    }
}
