<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Kiracı üyesinin rolü — `tenant_users.role` (S7 · 23 Ekim 2026).
 *
 * DÖRT ROL, sabit yetki kümeleri. Özel rol / yetki editörü YOK ve bilinçli:
 * küçük satıcının sorusu "depocum yalnız siparişleri görsün" — yetki
 * matrisi kurmak değil.
 *
 *   owner      her şey; abonelik ve ekip yalnız onda
 *   admin      owner'ın aynısı, abonelik ve ekip hariç
 *   warehouse  sipariş/kargo/iade/soru + stok GÖRÜNTÜLEME; ürün, kanal,
 *              fiyat, finans, ayar, abonelik YOK
 *   viewer     her ekranı görür (katkı raporu dahil — muhasebeci/ortak
 *              için açılır), hiçbir şey yazamaz; abonelik ve ekip görmez
 *
 * TANINMAYAN DEĞER `viewer` SAYILIR (`fromStored`). Eski `member`
 * varsayılanı veya elle yazılmış bir değer en dar role düşer — en geniş
 * role düşseydi bozuk bir satır sessizce yetki verirdi.
 */
enum TenantRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case WAREHOUSE = 'warehouse';
    case VIEWER = 'viewer';

    public static function fromStored(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::VIEWER;
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::OWNER => Permission::cases(),
            self::ADMIN => array_values(array_filter(
                Permission::cases(),
                static fn (Permission $p): bool => ! in_array($p, [Permission::BILLING_MANAGE, Permission::TEAM_MANAGE], true),
            )),
            self::WAREHOUSE => [
                Permission::PANEL,
                Permission::ORDERS_VIEW,
                Permission::ORDERS_WORK,
                Permission::STOCK_VIEW,
            ],
            self::VIEWER => [
                Permission::PANEL,
                Permission::ORDERS_VIEW,
                Permission::STOCK_VIEW,
                Permission::CATALOG_VIEW,
                Permission::CHANNELS_VIEW,
                Permission::FINANCE_VIEW,
                Permission::SETTINGS_VIEW,
            ],
        };
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** Panelde gösterilecek ad. */
    public function label(): string
    {
        return __(match ($this) {
            self::OWNER => 'Sahip',
            self::ADMIN => 'Yönetici',
            self::WAREHOUSE => 'Depo',
            self::VIEWER => 'Salt okunur',
        });
    }

    public function description(): string
    {
        return __(match ($this) {
            self::OWNER => 'Her şey; abonelik ve ekip yönetimi dahil.',
            self::ADMIN => 'Abonelik ve ekip dışında her şey.',
            self::WAREHOUSE => 'Siparişler, kargo, iade ve müşteri soruları; stoğu yalnız görür.',
            self::VIEWER => 'Her ekranı görür, hiçbir şeyi değiştiremez.',
        });
    }
}
