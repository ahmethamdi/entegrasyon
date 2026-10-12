<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Enums\OutboundWrite;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Kanala yazmadan önce sorulan TEK kapı — ADR-010.
 *
 * KAPALIYKEN KAPANIR (fail closed): kolon `false`, `null`, bağlantı yok ya
 * da başka kiracının — dördünde de cevap "hayır"dır. Yalnız veritabanındaki
 * değer tam olarak `true` ise yazılır.
 *
 * DEĞER HER ÇAĞRIDA VERİTABANINDAN OKUNUR. Kuyruktaki işin taşıdığı model
 * kopyası bayattır: iş atıldıktan sonra yönetici izni kapattıysa işin
 * çalıştığı anda kanala hiçbir şey gitmemelidir. Önbellek YOK.
 *
 * Kiracı eşleşmesi sorgudadır: iş yükündeki kiracı kimliği ile bağlantının
 * kiracısı aynı değilse satır bulunmaz ve yazma olmaz.
 */
final class OutboundWriteGate
{
    public function allows(?string $connectionId, ?string $tenantId, OutboundWrite $write): bool
    {
        if ($connectionId === null || $connectionId === '' || $tenantId === null || $tenantId === '') {
            return false;
        }

        $value = TenantContext::runAsSystem(static fn (): mixed => DB::table('channel_connections')
            ->where('id', $connectionId)
            ->where('tenant_id', $tenantId)
            ->value($write->column()));

        return $value === true;
    }
}
