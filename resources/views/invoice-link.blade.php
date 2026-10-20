<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Fatura · Invoice</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #fafaf9; color: #1c1917; }
        main { max-width: 32rem; margin: 15vh auto; padding: 0 1.25rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { line-height: 1.55; margin: 0 0 .75rem; color: #44403c; }
        .en { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e7e5e4; font-size: .9rem; }
    </style>
</head>
<body>
<main>
    {{-- Faturanın sahibi satıcıdır; 34Pazar yalnız bağlantıyı taşır. Alıcı verisi bu sayfada YOK. --}}
    @if ($status === 410)
        <h1>Bu fatura iptal edildi</h1>
        <p>Satıcı bu faturayı iptal etti. Sorunuz varsa siparişi verdiğiniz pazaryeri üzerinden satıcıya yazın.</p>
        <div class="en">
            <p><strong>This invoice was cancelled.</strong> The seller cancelled this invoice. For questions, contact the seller through the marketplace where you placed the order.</p>
        </div>
    @else
        <h1>Fatura şu an görüntülenemiyor</h1>
        <p>Bağlantı geçersiz olabilir ya da fatura geçici olarak alınamıyor. Biraz sonra yeniden deneyin; sorun sürerse siparişi verdiğiniz pazaryeri üzerinden satıcıya yazın.</p>
        <div class="en">
            <p><strong>The invoice can't be shown right now.</strong> The link may be invalid or the invoice is temporarily unavailable. Try again later; if it persists, contact the seller through the marketplace.</p>
        </div>
    @endif
</main>
</body>
</html>
