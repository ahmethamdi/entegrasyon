<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Support\Tenancy\TenantAwareJob;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Kuyruk işi, kendisini başlatan isteğin dilinde koşar.
 *
 * İngilizce panelden başlatılan içe aktarma özetini/hatasını veritabanına
 * yazar; işçi varsayılan dilde (Türkçe) koşsaydı satıcı İngilizce panelde
 * Türkçe hata görürdü. İşçi süreci kalıcıdır — dil sonraki işe SIZMAMALI.
 */
final class TenantAwareJobLocaleTest extends TestCase
{
    #[Test]
    public function job_runs_in_the_locale_it_was_dispatched_from(): void
    {
        App::setLocale('en');
        $job = new LocaleProbeJob('tenant-1');

        // İşçi kendi varsayılanıyla başlar.
        App::setLocale('tr');
        $job->handle();

        $this->assertSame('en', $job->seenLocale);
        $this->assertSame('Products', $job->seenText);
        $this->assertSame('tr', App::getLocale(), 'Dil işten sonra işçiye sızdı.');
    }

    #[Test]
    public function locale_survives_the_queue_round_trip(): void
    {
        App::setLocale('en');
        $job = unserialize(serialize(new LocaleProbeJob('tenant-1')));

        App::setLocale('tr');
        $job->handle();

        $this->assertSame('en', $job->seenLocale);
    }

    #[Test]
    public function locale_is_restored_even_when_the_job_throws(): void
    {
        App::setLocale('en');
        $job = new LocaleProbeJob('tenant-1', fail: true);

        App::setLocale('tr');

        try {
            $job->handle();
            $this->fail('İş istisna fırlatmalıydı.');
        } catch (RuntimeException) {
            // beklenen
        }

        $this->assertSame('tr', App::getLocale());
    }
}

final class LocaleProbeJob extends TenantAwareJob
{
    public ?string $seenLocale = null;

    public ?string $seenText = null;

    public function __construct(string $tenantId, public bool $fail = false)
    {
        parent::__construct($tenantId);
    }

    protected function handleForTenant(): void
    {
        $this->seenLocale = App::getLocale();
        $this->seenText = __('Ürünler');

        if ($this->fail) {
            throw new RuntimeException('deneme');
        }
    }
}
