<?php

declare(strict_types=1);

namespace App\Domain\Questions\Console;

use App\Domain\Questions\Actions\IngestQuestions;
use Illuminate\Console\Command;

/**
 * Kanalların müşteri sorularını yoklar ve açık soruları kanaldan tazeler.
 * Dışarı YAZMAZ; tekrar çalıştırmak güvenlidir (idempotent).
 */
final class SyncQuestionsCommand extends Command
{
    protected $signature = 'questions:sync';

    protected $description = 'Kanal müşteri sorularını içe alır ve açık soruları uzlaştırır';

    public function handle(IngestQuestions $ingest): int
    {
        $s = $ingest->run();

        $this->line("connections={$s['connections']} stored={$s['stored']} refreshed={$s['refreshed']} failed={$s['failed']}");

        return self::SUCCESS;
    }
}
