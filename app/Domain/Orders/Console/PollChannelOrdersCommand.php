<?php

declare(strict_types=1);

namespace App\Domain\Orders\Console;

use App\Domain\Orders\Support\PollChannelOrders;
use Illuminate\Console\Command;

/**
 * Sipariş yoklama turu — ince kabuk.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("Sipariş yoklaması"), §15.
 *
 * MANTIK `Support/` ALTINDA, KOMUT İNCE: `Command::run()` REZERVE bir
 * imzadır ve tarama sınıfı `Command`'dan türeyip kendi `run()`'ını
 * tanımlayamaz (fatal error). `OutboxRelay`/`OutboxRelayCommand` ayrımının
 * aynısı.
 *
 * KAYIT VE ZAMANLAMA AYRI KOŞULLARDIR: bu sınıf `bootstrap/app.php`
 * içinde açıkça kaydedilmeli (domain komutları otomatik keşfedilmez) VE
 * `routes/console.php` içinde zamanlanmalıdır. Biri eksikse tarama
 * sessizce hiç çalışmaz — `inbox:recover` bu projede tam olarak böyle bir
 * tur boyunca ölü kaldı.
 */
final class PollChannelOrdersCommand extends Command
{
    protected $signature = 'orders:poll';

    protected $description = 'Webhook göndermeyen kanallardan sipariş yoklar';

    public function handle(PollChannelOrders $poll): int
    {
        $this->line((string) $poll->run());

        return self::SUCCESS;
    }
}
