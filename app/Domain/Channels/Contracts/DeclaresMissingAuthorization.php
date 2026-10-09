<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

/**
 * Bağlantı ÇALIŞIYOR ama bir yetenek için izni EKSİK.
 *
 * Token geçerlidir, stok ve sipariş akar; ancak bağlantı, adapter'a sonradan
 * eklenen bir yetenek (ör. Etsy kargo bildirimi → `transactions_w`)
 * istenmeden ÖNCE yetkilendirilmiştir. Token süresine bakan kart bunu
 * göremez ve "İzin ver" düğmesini çıkarmazdı — satıcının eksik izni
 * vermenin yolu olmazdı.
 *
 * Sağlık durumunu DEĞİŞTİRMEZ: bağlantı bozuk değildir ve kırmızıya
 * boyanmamalıdır.
 */
interface DeclaresMissingAuthorization
{
    /**
     * Eksik izinler (kanalın scope adları); boşsa yeniden yetki gerekmez.
     *
     * @return list<string>
     */
    public function missingAuthorizationScopes(): array;
}
