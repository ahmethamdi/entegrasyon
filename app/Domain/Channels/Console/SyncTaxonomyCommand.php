<?php

declare(strict_types=1);

namespace App\Domain\Channels\Console;

use App\Domain\Channels\Support\SyncTaxonomyForChannels;
use Illuminate\Console\Command;

/**
 * §13 · Faz 2 · taksonomi turu — günlük.
 *
 * İNCE KABUK: mantık `SyncTaxonomyForChannels` içinde. `Command::run()`
 * rezerve imzadır ve `Command`'dan türeyen sınıf kendi `run(...)` metodunu
 * tanımlayamaz.
 *
 * GÜNLÜK, SAATLİK DEĞİL: kategori ağacı sık değişmez. Saatlik çekim 30 bin
 * satırlık ağacı boşuna yeniden okur ve kanal kotasını harcar.
 *
 * ÖZNİTELİKLER VARSAYILAN OLARAK ÇEKİLMEZ: yaprak başına ayrı bir istektir
 * ve 30 bin yaprakta tur saatler sürer. Eşleştirme ekranı bir kategoriye
 * ihtiyaç duyduğunda talebe bağlı çekilir; `--with-attributes` tüm ağacı
 * ısıtmak isteyen kurulumlar içindir.
 */
final class SyncTaxonomyCommand extends Command
{
    protected $signature = 'taxonomy:sync {--with-attributes : Yaprak özniteliklerini de çek}';

    protected $description = 'Kanal kategori ağacını çeker ve önbelleğe yazar';

    public function handle(SyncTaxonomyForChannels $sweeper): int
    {
        $synced = $sweeper->sweep(withAttributes: (bool) $this->option('with-attributes'));

        $this->info("Taksonomi turu bitti: {$synced} kanal türü.");

        return self::SUCCESS;
    }
}
