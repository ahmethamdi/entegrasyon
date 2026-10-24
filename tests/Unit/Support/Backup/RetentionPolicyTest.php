<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Backup;

use App\Support\Backup\RetentionPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    /** @return array<string, DateTimeImmutable> günlük yedekler: bugünden geriye $days gün */
    private function nightly(int $days, string $today = '2026-10-25'): array
    {
        $out = [];
        $base = new DateTimeImmutable($today.'T00:45:00Z');
        for ($i = 0; $i < $days; $i++) {
            $at = $base->modify("-{$i} days");
            $out['b-'.$at->format('Ymd')] = $at;
        }

        return $out;
    }

    #[Test]
    public function one_year_of_nightly_backups_keeps_7_daily_4_weekly_6_monthly(): void
    {
        $backups = $this->nightly(365);
        $keep = (new RetentionPolicy)->keep($backups);

        // 7 günlük: 25..19 Ekim.
        foreach (['20261025', '20261024', '20261019'] as $d) {
            $this->assertContains("b-{$d}", $keep);
        }
        $this->assertNotContains('b-20261017', $keep, 'günlük kova 7 ile sınırlı');

        // 4 haftalık (ISO hafta, her haftanın en yeni yedeği = Pazar): 25 Ekim haftası günlükte, sonra 18, 11, 4 Ekim.
        foreach (['20261018', '20261011', '20261004'] as $d) {
            $this->assertContains("b-{$d}", $keep);
        }
        $this->assertNotContains('b-20260927', $keep, '5. hafta tutulmaz (aylık kova Eylül için 30 Eylül\'ü tutar)');

        // Aylık: en yeni 6 ay (Ekim..Mayıs), her ayın EN YENİ yedeği.
        foreach (['20260930', '20260831', '20260731', '20260630', '20260531'] as $d) {
            $this->assertContains("b-{$d}", $keep);
        }
        $this->assertNotContains('b-20260430', $keep, '7. ay tutulmaz');

        // 7 günlük + 3 ek haftalık + 5 ek aylık (kovalar çakışınca bir kez sayılır).
        $this->assertCount(15, $keep);
    }

    #[Test]
    public function a_long_gap_does_not_empty_the_buckets(): void
    {
        // 10 günlük kesintiden önceki 30 gecelik yedek: hepsi "eski" ama yedeği olan en yeni günler tutulur.
        $backups = $this->nightly(30, '2026-10-15');
        $keep = (new RetentionPolicy)->keep($backups);

        $this->assertContains('b-20261015', $keep);
        $this->assertContains('b-20261009', $keep);
        $this->assertGreaterThanOrEqual(7, count($keep));
    }

    #[Test]
    public function newest_is_always_kept_and_prune_is_the_complement(): void
    {
        $backups = $this->nightly(40);
        $policy = new RetentionPolicy(daily: 0, weekly: 0, monthly: 0);

        $this->assertSame(['b-20261025'], $policy->keep($backups));
        $this->assertCount(39, $policy->prune($backups));
        $this->assertNotContains('b-20261025', $policy->prune($backups));
    }

    #[Test]
    public function within_a_day_the_newest_backup_wins(): void
    {
        $backups = [
            'sabah' => new DateTimeImmutable('2026-10-25T06:00:00Z'),
            'gece' => new DateTimeImmutable('2026-10-25T00:45:00Z'),
            'dun' => new DateTimeImmutable('2026-10-24T00:45:00Z'),
        ];

        $prune = (new RetentionPolicy(daily: 7, weekly: 0, monthly: 0))->prune($backups);

        $this->assertSame(['gece'], $prune);
    }

    #[Test]
    public function empty_input_keeps_nothing_and_prunes_nothing(): void
    {
        $this->assertSame([], (new RetentionPolicy)->keep([]));
        $this->assertSame([], (new RetentionPolicy)->prune([]));
    }
}
