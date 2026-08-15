<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ApprovalStatusBatch;

/**
 * Onay süreci yeteneği — pazaryerlerine özgü.
 *
 * Mimari Karar Dokümanı v2.2 · §7, §14.
 *
 * Trendyol'da gönderilen ürün hemen yayına girmez; onay bekler ve
 * reddedilebilir. Bu durum listing lifecycle'ından AYRIDIR: ürün bizde
 * "gönderildi" ama kanalda "beklemede" olabilir. Panel bu farkı göstermek
 * zorundadır, yoksa kullanıcı ürünün neden görünmediğini anlayamaz.
 */
interface SupportsApprovalWorkflow
{
    /** @param list<Listing> $listings */
    public function fetchApprovalStatus(array $listings): ApprovalStatusBatch;
}
