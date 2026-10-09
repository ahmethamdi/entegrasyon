<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\ChannelPriceRule;
use App\Domain\Catalog\Support\ChannelPriceRules;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Sync\Actions\RequestResync;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * Bağlantının fiyat kuralını yazar ve canlı ilanların fiyatını yeniden gönderir.
 *
 * ⚠️ KURAL DEĞİŞİNCE HİÇBİR VARYANTIN SÜRÜMÜ DEĞİŞMEZ. Fiyat olayı yalnız
 * varyant fiyatı yazılınca doğar; resync istenmeseydi satıcı "+%15" der,
 * kanalda hiçbir fiyat değişmezdi. `SetChannelPrice` ile aynı yol
 * (`RequestResync`, REPAIR operasyonu sürüm kapısını bilinçli aşar).
 *
 * Elle kanal fiyatı girilmiş ilanlar da yeniden gönderilir: kural onların
 * fiyatını değiştirmez ama zarar koruması onları da denetler.
 */
final class SetChannelPriceRule
{
    public function __construct(
        private readonly RequestResync $requestResync,
        private readonly RecordAuditLog $audit,
        private readonly ChannelPriceRules $rules,
    ) {}

    /**
     * @param  array{markup_percent: string, markup_amount: string, rounding: string, min_margin_percent: string|null}  $values
     * @return int Yeniden gönderilen ilan sayısı (değişiklik yoksa 0)
     */
    public function run(ChannelConnection $connection, array $values, ?string $userId = null): int
    {
        $new = [
            'markup_percent' => self::decimal($values['markup_percent']),
            'markup_amount' => self::decimal($values['markup_amount']),
            'rounding' => $values['rounding'],
            'min_margin_percent' => $values['min_margin_percent'] === null ? null : self::decimal($values['min_margin_percent']),
        ];

        $rule = ChannelPriceRule::query()->firstOrNew(['channel_connection_id' => $connection->id]);

        $old = $rule->exists ? [
            'markup_percent' => self::decimal((string) $rule->markup_percent),
            'markup_amount' => self::decimal((string) $rule->markup_amount),
            'rounding' => $rule->rounding,
            'min_margin_percent' => $rule->min_margin_percent === null ? null : self::decimal((string) $rule->min_margin_percent),
        ] : ['markup_percent' => '0.00', 'markup_amount' => '0.00', 'rounding' => ChannelPriceRule::ROUNDING_NONE, 'min_margin_percent' => null];

        if ($old === $new) {
            return 0;
        }

        $listingIds = DB::transaction(function () use ($rule, $connection, $new, $old, $userId): array {
            $rule->forceFill(['tenant_id' => $connection->tenant_id, ...$new])->save();

            $this->audit->run(
                action: AuditAction::CHANNEL_PRICE_RULE_SET,
                subjectType: 'channel_connections',
                subjectId: $connection->id,
                changes: ['old' => $old, 'new' => $new],
                userId: $userId,
            );

            return Listing::query()
                ->where('channel_connection_id', $connection->id)
                ->where('lifecycle_status', 'live')
                ->pluck('id')
                ->all();
        });

        // Aynı istekte eski kural önbellekten okunmasın.
        $this->rules->forget($connection->id);

        Listing::query()->whereIn('id', $listingIds)->chunkById(200, function ($listings): void {
            foreach ($listings as $listing) {
                $this->requestResync->run($listing, SyncDomain::PRICE, RequestResync::REASON_PRICE_RULE_CHANGED);
            }
        });

        return count($listingIds);
    }

    private static function decimal(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
