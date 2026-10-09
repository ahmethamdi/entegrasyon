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

    Paketler YALNIZ ürün ve kanal sayısıyla ayrılır (QuotaMetric'te
    yalnız bu ikisi var); "tüm paketlerde" listesi bu yüzden ortak.
--}}
@php
    $loggedIn = $isLoggedIn ?? false;
    $href = $loggedIn ? url('/billing') : route('register');
    // Notlar RAKAM İÇERMEZ: limit veritabanında değişince not yalan kalmasın.
    $notes = ['free' => 'Denemek için', 'starter' => 'Yeni başlayanlar için', 'pro' => 'Büyüyen satıcılar için', 'business' => 'Büyük kataloglar için'];
    $highlight = 'pro';

    $isFree = fn (array $p): bool => (float) $p['priceMonthly'] === 0.0;
    $price = function (array $p) use ($isFree): string {
        if ($isFree($p)) {
            return 'Ücretsiz';
        }
        $amount = (float) $p['priceMonthly'];
        $decimals = floor($amount) === $amount ? 0 : 2;
        $formatted = number_format($amount, $decimals, ',', '.');

        return ($p['currency'] ?? 'TRY') === 'TRY' ? $formatted.' ₺' : $formatted.' '.$p['currency'];
    };
    $count = fn (?int $v): string => $v === null ? 'Sınırsız' : number_format($v, 0, ',', '.');
    $cta = fn (array $p): string => $loggedIn ? 'Paketleri gör' : ($isFree($p) ? 'Ücretsiz başla' : 'Hesap aç');
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
                        <span class="text-sm muted">/ ay</span>
                    @endunless
                </p>
                <p class="mt-1 text-sm muted">{{ $isFree($plan) ? 'Kart bilgisi gerekmez' : 'Aylık ödeme, yıllık taahhüt yok' }}</p>

                <dl class="mt-6 border-t border-line text-[0.9875rem]">
                    <div class="flex justify-between gap-4 border-b border-line py-3">
                        <dt class="muted">Ürün</dt>
                        <dd class="font-semibold tabular-nums">{{ $count($plan['productLimit']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b border-line py-3">
                        <dt class="muted">Kanal (mağaza)</dt>
                        <dd class="font-semibold tabular-nums">{{ $count($plan['channelLimit']) }}</dd>
                    </div>
                </dl>

                <a href="{{ $href }}" class="btn {{ $hi ? 'btn-primary' : 'btn-secondary' }} mt-6 w-full">
                    {{ $cta($plan) }}<span class="sr-only"> — {{ $plan['name'] }} paketi</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
