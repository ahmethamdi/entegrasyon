<?php

declare(strict_types=1);

namespace Tests\Unit\Sync;

use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\RetryPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Yeniden deneme politikası ve hata sınıflandırması.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 26, §12.
 *
 * Saf birim testi: veritabanı yok, bağlam yok.
 */
final class RetryPolicyTest extends TestCase
{
    /** Kalıcı hatalar HİÇBİR denemede yeniden denenmez. */
    #[Test]
    public function permanent_errors_are_never_retried(): void
    {
        foreach ([ErrorClass::VALIDATION, ErrorClass::AUTHENTICATION] as $class) {
            $this->assertTrue($class->isPermanent());

            foreach ([1, 2, 3] as $attempt) {
                $this->assertNull(
                    RetryPolicy::delayFor($class, $attempt),
                    "{$class->value} yeniden denenmemeli (deneme {$attempt}).",
                );
            }
        }
    }

    /** Geçici hatalar kalıcı sayılmaz — mutabakat onları aday seçer. */
    #[Test]
    public function transient_errors_are_reconcilable(): void
    {
        foreach ([
            ErrorClass::RATE_LIMITED,
            ErrorClass::SERVER_ERROR,
            ErrorClass::TIMEOUT,
            ErrorClass::NETWORK,
            ErrorClass::CONFLICT,
            ErrorClass::NOT_FOUND,
        ] as $class) {
            $this->assertFalse($class->isPermanent(), "{$class->value} kalıcı olmamalı.");
            $this->assertTrue($class->isReconcilable());
        }
    }

    /** Kalıcı ve geçici hata sync state'e farklı durum yazar. */
    #[Test]
    public function sync_state_status_reflects_permanence(): void
    {
        $this->assertSame('error_permanent', ErrorClass::VALIDATION->syncStateStatus());
        $this->assertSame('error_permanent', ErrorClass::AUTHENTICATION->syncStateStatus());
        $this->assertSame('error_transient', ErrorClass::SERVER_ERROR->syncStateStatus());
        $this->assertSame('error_transient', ErrorClass::RATE_LIMITED->syncStateStatus());
    }

    /** Hız sınırında kanalın söylediği süreye uyulur. */
    #[Test]
    public function rate_limited_honours_retry_after_header(): void
    {
        $this->assertSame(30, RetryPolicy::delayFor(ErrorClass::RATE_LIMITED, 1, retryAfter: 30));
        $this->assertSame(120, RetryPolicy::delayFor(ErrorClass::RATE_LIMITED, 2, retryAfter: 120));
    }

    /** Retry-After yoksa 60 saniye varsayılır. */
    #[Test]
    public function rate_limited_falls_back_to_sixty_seconds(): void
    {
        $this->assertSame(60, RetryPolicy::delayFor(ErrorClass::RATE_LIMITED, 1));
    }

    /**
     * Geçici hatalarda gecikme üstel büyür: ~5, 15, 45, 135.
     *
     * Jitter ±%20 olduğu için tam değer yerine aralık doğrulanır.
     */
    #[Test]
    public function transient_errors_back_off_exponentially(): void
    {
        $expected = [1 => 5, 2 => 15, 3 => 45, 4 => 135];

        foreach ($expected as $attempt => $base) {
            $delay = RetryPolicy::delayFor(ErrorClass::SERVER_ERROR, $attempt);

            $this->assertNotNull($delay);
            $this->assertGreaterThanOrEqual((int) floor($base * 0.8), $delay);
            $this->assertLessThanOrEqual((int) ceil($base * 1.2), $delay);
        }
    }

    /**
     * JITTER ZORUNLU — aynı gecikme iki kez üst üste dönmemeli.
     *
     * Bir kanal kesintiye girdiğinde yüzlerce iş aynı anda başarısız olur;
     * jitter olmadan hepsi aynı saniyede yeniden dener ve kanal ayağa
     * kalktığı anda tekrar çöker.
     */
    #[Test]
    public function delays_carry_jitter(): void
    {
        $delays = [];

        foreach (range(1, 40) as $ignored) {
            $delays[] = RetryPolicy::delayFor(ErrorClass::SERVER_ERROR, 4);
        }

        $this->assertGreaterThan(
            1,
            count(array_unique($delays)),
            'Gecikme sabit — jitter uygulanmamış.',
        );
    }

    /** Çakışma bir kez, kısa beklemeyle denenir; sonra uzak durum okunur. */
    #[Test]
    public function conflict_is_retried_once_then_handed_over(): void
    {
        $this->assertSame(10, RetryPolicy::delayFor(ErrorClass::CONFLICT, 1));
        $this->assertNull(RetryPolicy::delayFor(ErrorClass::CONFLICT, 2));
    }

    /** NOT_FOUND yeniden denenmez — mutabakat devralır. */
    #[Test]
    public function not_found_is_handed_to_reconciliation(): void
    {
        $this->assertNull(RetryPolicy::delayFor(ErrorClass::NOT_FOUND, 1));
    }

    /** Geçici hata da sonsuza kadar denenmez. */
    #[Test]
    public function transient_errors_stop_after_max_attempts(): void
    {
        $this->assertNotNull(RetryPolicy::delayFor(ErrorClass::SERVER_ERROR, RetryPolicy::MAX_ATTEMPTS - 1));
        $this->assertNull(RetryPolicy::delayFor(ErrorClass::SERVER_ERROR, RetryPolicy::MAX_ATTEMPTS));
        $this->assertNull(RetryPolicy::delayFor(ErrorClass::RATE_LIMITED, RetryPolicy::MAX_ATTEMPTS));
    }

    #[Test]
    public function should_retry_mirrors_delay_availability(): void
    {
        $this->assertTrue(RetryPolicy::shouldRetry(ErrorClass::SERVER_ERROR, 1));
        $this->assertFalse(RetryPolicy::shouldRetry(ErrorClass::VALIDATION, 1));
        $this->assertFalse(RetryPolicy::shouldRetry(ErrorClass::CONFLICT, 2));
    }
}
