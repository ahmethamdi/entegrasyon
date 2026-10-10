<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

/**
 * Satıcının ayarlarından gelen, faturanın NASIL işleneceği.
 *
 * Kanal taslağından (`InvoiceDraft`) AYRIDIR: taslak siparişin gerçeğidir
 * (alıcı, kalemler), bu nesne satıcının tercihidir. İkisi karışsaydı kanal
 * adapter'ı satıcı ayarını bilmek zorunda kalırdı.
 *
 * Entegratörden BAĞIMSIZ kavramlar: "e-belge kesilsin mi" her Türk
 * entegratöründe vardır; tahsilat hesabı entegratörün kendi kimliğidir ve
 * çekirdek onu yalnız taşır, yorumlamaz.
 */
final readonly class SubmitOptions
{
    /**
     * @param  bool  $issueEDocument  false = yalnız muhasebeye işle (cari + satış
     *                                faturası + tahsilat), e-belge isteme
     * @param  string|null  $paymentAccountId  entegratördeki kasa/banka hesabı;
     *                                         null = tahsilat işlenmez
     */
    public function __construct(
        public bool $issueEDocument = true,
        public ?string $paymentAccountId = null,
    ) {}
}
