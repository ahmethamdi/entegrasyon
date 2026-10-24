{{--
    Paket kartları — ana sayfa ve /fiyatlar ortak kullanır.

    DEĞİŞMEZ KURAL — FİYAT ELLE YAZILMAZ: her rakam `$plans`'tan
    (plans tablosu) gelir. Sitede elle fiyat dursaydı panel ile ayrışır,
    satıcı sitede gördüğünden farklı bir fiyatla karşılaşırdı.

    Biçim: sunucu fiyatı "1499.00" METNİ olarak verir (decimal sütun);
    Türk okur "1.499 ₺" bekler, kuruş yoksa ",00" gürültüdür. 0 →
    "Ücretsiz", null limit → "Sınırsız" (PlanSeeder sözleşmesi). TRY
    dışı para biriminde simge uydurulmaz, kod yazılır.

    "Profesyonel" için "en çok tercih edilen" DENMEZ — bunu gösteren
    veri yok. Yerine kimin için olduğunu söyleyen nötr bir not.

    Paketler ürün, kanal, kullanıcı ve aylık fatura sayısıyla ayrılır
    (QuotaMetric); AI öneri hakkı yalnız özellik açıkken görünür (S8).
    Bir limit satırı yalnız planın limitlerinde anahtar varsa basılır.
    `$lang` = en → İngilizce metin.
--}}
@php
    $loggedIn = $isLoggedIn ?? false;
    $en = ($lang ?? 'tr') === 'en';
    $t = fn (string $tr, string $enText): string => $en ? $enText : $tr;
    $href = $loggedIn ? url('/billing') : route('register');
    // Notlar RAKAM İÇERMEZ: limit veritabanında değişince not yalan kalmasın.
    $notes = $en
        ? ['free' => 'To try it out', 'starter' => 'For new sellers', 'pro' => 'For growing sellers', 'business' => 'For large catalogs']
        : ['free' => 'Denemek için', 'starter' => 'Yeni başlayanlar için', 'pro' => 'Büyüyen satıcılar için', 'business' => 'Büyük kataloglar için'];
    $highlight = 'pro';

    $isFree = fn (array $p): bool => (float) $p['priceMonthly'] === 0.0;
    $price = function (array $p) use ($isFree, $t): string {
        if ($isFree($p)) {
            return $t('Ücretsiz', 'Free');
        }
        $amount = (float) $p['priceMonthly'];
        $decimals = floor($amount) === $amount ? 0 : 2;
        $formatted = number_format($amount, $decimals, ',', '.');

        return ($p['currency'] ?? 'TRY') === 'TRY' ? $formatted.' ₺' : $formatted.' '.$p['currency'];
    };
    $count = fn (?int $v): string => $v === null ? $t('Sınırsız', 'Unlimited') : number_format($v, 0, ',', '.');
    $cta = fn (array $p): string => $loggedIn ? $t('Paketleri gör', 'See plans') : ($isFree($p) ? $t('Ücretsiz başla', 'Start free') : $t('Hesap aç', 'Sign up'));

    // Pakete dahil olanlar — yalnız GERÇEKTE verilenler (24 Eki 2026
    // kullanıcı kararları: destek her gün, kurulum ve iade ücretli pakette).
    $hours = config('site.support_hours');
    $refundDays = (int) config('site.refund_days');
    $perks = function (array $p) use ($isFree, $t, $hours, $refundDays): array {
        $list = [
            $t('Bütün kanallar ve bütün özellikler', 'Every channel and every feature'),
            $t("WhatsApp ve e-posta destek, her gün {$hours}", "WhatsApp and email support, daily {$hours} (TRT)"),
        ];
        if (! $isFree($p)) {
            $list[] = $t('Kurulum ve ilk ürün aktarımı birlikte, ücretsiz', 'Free setup and first product import, done together');
            $list[] = $t("İlk ödemeden {$refundDays} gün içinde tam iade", "Full refund within {$refundDays} days of your first payment");
        }

        return $list;
    };
@endphp

@if (count($plans))
    {{-- Sütun sınıfı sabit metin: Tailwind yalnız dosyada yazılı sınıfı derler. --}}
    @php $cols = [1 => 'xl:grid-cols-1', 2 => 'xl:grid-cols-2', 3 => 'xl:grid-cols-3'][count($plans)] ?? 'xl:grid-cols-4'; @endphp
    <ul class="grid gap-4 sm:grid-cols-2 {{ $cols }}">
        @foreach ($plans as $plan)
            @php $hi = $plan['code'] === $highlight; @endphp
            <li class="plan-card {{ $hi ? 'plan-card--hi' : '' }}">
                <h3 class="heading-3">{{ $plan['name'] }}</h3>
                <p class="mt-1 text-sm {{ $hi ? 'font-semibold text-brand-700' : 'muted' }}">{{ $notes[$plan['code']] ?? '' }}</p>

                <p class="mt-6 flex items-baseline gap-2">
                    <span class="price text-[2.25rem]">{{ $price($plan) }}</span>
                    @unless ($isFree($plan))
                        <span class="text-sm muted">{{ $t('/ ay', '/ month') }}</span>
                    @endunless
                </p>
                <p class="mt-1 text-sm muted">{{ $isFree($plan) ? $t('Kart bilgisi gerekmez', 'No card required') : $t('Aylık ödeme, yıllık taahhüt yok', 'Billed monthly, no annual contract') }}</p>

                <dl class="mt-6 border-t border-line text-[0.9875rem]">
                    <div class="flex justify-between gap-4 border-b border-line py-3">
                        <dt class="muted">{{ $t('Ürün', 'Products') }}</dt>
                        <dd class="font-semibold tabular-nums">{{ $count($plan['productLimit']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b border-line py-3">
                        <dt class="muted">{{ $t('Kanal (mağaza)', 'Channels (stores)') }}</dt>
                        <dd class="font-semibold tabular-nums">{{ $count($plan['channelLimit']) }}</dd>
                    </div>
                    @if ($plan['hasInvoiceLimit'] ?? false)
                        <div class="flex justify-between gap-4 border-b border-line py-3">
                            <dt class="muted">{{ $t('E-fatura / ay', 'E-invoices / month') }}</dt>
                            <dd class="font-semibold tabular-nums">{{ $count($plan['invoiceLimit']) }}</dd>
                        </div>
                    @endif
                    @if ($plan['hasUserLimit'] ?? false)
                        <div class="flex justify-between gap-4 border-b border-line py-3">
                            <dt class="muted">{{ $t('Kullanıcı', 'Users') }}</dt>
                            <dd class="font-semibold tabular-nums">{{ $count($plan['userLimit']) }}</dd>
                        </div>
                    @endif
                    @if ($plan['showAi'] ?? false)
                        <div class="flex justify-between gap-4 border-b border-line py-3">
                            <dt class="muted">{{ $t('AI önerisi / ay', 'AI suggestions / month') }}</dt>
                            <dd class="font-semibold tabular-nums">{{ $count($plan['aiLimit']) }}</dd>
                        </div>
                    @endif
                </dl>

                <ul class="mt-5 mb-6 grid gap-2 text-sm">
                    @foreach ($perks($plan) as $perk)
                        <li class="flex gap-2"><span class="text-ok" aria-hidden="true">✓</span><span>{{ $perk }}</span></li>
                    @endforeach
                </ul>

                <a href="{{ $href }}" class="btn {{ $hi ? 'btn-primary' : 'btn-secondary' }} mt-auto w-full">
                    {{ $cta($plan) }}<span class="sr-only"> — {{ $plan['name'] }} {{ $t('paketi', 'plan') }}</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
