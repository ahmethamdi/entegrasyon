{{--
    Paket karşılaştırması — ana sayfa ve /fiyatlar ortak kullanır.

    DEĞİŞMEZ KURAL — FİYAT ELLE YAZILMAZ: her rakam `$plans`'tan
    (plans tablosu) gelir. Sitede elle fiyat dursaydı panel ile ayrışır,
    satıcı sitede gördüğünden farklı bir fiyatla karşılaşırdı.

    Biçim: sunucu fiyatı "1499.00" METNİ olarak verir (decimal sütun);
    Türk okur "1.499 ₺" bekler, kuruş yoksa ",00" gürültüdür. 0 →
    "Ücretsiz", null limit → "Sınırsız" (PlanSeeder sözleşmesi). TRY
    dışı para biriminde simge uydurulmaz, kod yazılır.

    "Profesyonel" için "en çok tercih edilen" DENMEZ — bunu gösteren
    veri yok. Yerine kimin için olduğunu söyleyen nötr bir not.
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
    {{-- Geniş ekran: yan yana sütunlar. Fark (ürün, kanal) aynı satırda okunur. --}}
    <div class="hidden md:grid" style="grid-template-columns: repeat({{ count($plans) }}, minmax(0, 1fr));">
        @foreach ($plans as $plan)
            @php $hi = $plan['code'] === $highlight; @endphp
            <div class="flex flex-col border-t-4 {{ $hi ? 'plan-col--hi border-brand' : 'border-ink' }} {{ ! $loop->first ? 'border-l border-l-line' : '' }} px-6 pt-6 pb-8 lg:px-8">
                <h3 class="text-lg font-semibold">{{ $plan['name'] }}</h3>
                <p class="mt-1 text-sm muted">{{ $notes[$plan['code']] ?? '' }}</p>
                {{-- Sabit yükseklikli kutu: "Ücretsiz" daha küçük yazılsa da alttaki satırlar hizalı kalır. --}}
                <div class="mt-10 flex h-[5.5rem] flex-col justify-end lg:h-[6rem]">
                    <p class="price {{ $isFree($plan) ? 'text-[clamp(2rem,1rem+1.8vw,3rem)]' : 'text-[clamp(2.25rem,1rem+2.6vw,4rem)]' }}">{{ $price($plan) }}</p>
                    <p class="mt-2 text-sm muted">{{ $isFree($plan) ? 'Kart bilgisi gerekmez' : 'aylık' }}</p>
                </div>
                <dl class="mt-8 space-y-0 border-t border-line text-[0.9875rem]">
                    <div class="flex justify-between gap-4 border-b border-line py-3">
                        <dt class="muted">Ürün</dt>
                        <dd class="font-semibold tabular-nums">{{ $count($plan['productLimit']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b border-line py-3">
                        <dt class="muted">Kanal</dt>
                        <dd class="font-semibold tabular-nums">{{ $count($plan['channelLimit']) }}</dd>
                    </div>
                </dl>
                <a href="{{ $href }}" class="btn {{ $hi ? 'btn-primary' : 'btn-outline' }} mt-8 w-full">
                    {{ $cta($plan) }}<span class="sr-only"> — {{ $plan['name'] }} paketi</span>
                </a>
            </div>
        @endforeach
    </div>

    {{-- Dar ekran: dört sütun sığmaz, aynı veri alt alta. --}}
    <ul class="border-t-4 border-ink md:hidden">
        @foreach ($plans as $plan)
            @php $hi = $plan['code'] === $highlight; @endphp
            <li class="border-b border-line py-7 {{ $hi ? 'plan-col--hi -mx-5 border-l-4 border-l-brand px-5' : '' }}">
                <div class="flex items-baseline justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold">{{ $plan['name'] }}</h3>
                        <p class="text-sm muted">{{ $notes[$plan['code']] ?? '' }}</p>
                    </div>
                    {{-- Paket adı zaten "Ücretsiz" ise fiyatı ikinci kez yazmak tekrar olur. --}}
                    @if ($price($plan) !== $plan['name'])
                        <p class="price shrink-0 text-[2.25rem]">{{ $price($plan) }}</p>
                    @endif
                </div>
                <p class="mt-3 text-[0.9875rem]">
                    <strong class="font-semibold">{{ $count($plan['productLimit']) }}</strong> ürün ·
                    <strong class="font-semibold">{{ $count($plan['channelLimit']) }}</strong> kanal
                    @if (! $isFree($plan)) <span class="muted">· aylık</span> @endif
                </p>
                <a href="{{ $href }}" class="btn {{ $hi ? 'btn-primary' : 'btn-outline' }} btn-sm mt-5">
                    {{ $cta($plan) }}<span class="sr-only"> — {{ $plan['name'] }} paketi</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
