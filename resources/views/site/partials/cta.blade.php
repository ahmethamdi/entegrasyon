{{--
    Sayfa sonu çağrısı — ana sayfa, özellikler, fiyatlar, entegrasyonlar,
    kanal ve hakkımızda sayfalarının sonunda aynı blok.

    "Kart bilgisi gerekmez" YALNIZ ücretsiz paket varsa yazılır (kayıt
    formu ödeme sormuyor; ücretli pakete geçişte sorulur).

    Değişkenler (isteğe bağlı):
      $ctaTitle  başlık; yoksa genel başlık
      $ctaText   alt cümle
--}}
@php
    $loggedIn = $isLoggedIn ?? false;
    $hasFree = collect($plans ?? [])->contains(fn ($p) => (float) $p['priceMonthly'] === 0.0);
@endphp
<section class="on-brand" aria-labelledby="cta-baslik">
    <div class="wrap flex flex-col gap-8 py-14 lg:flex-row lg:items-center lg:justify-between lg:py-16">
        <div class="max-w-2xl">
            <h2 id="cta-baslik" class="heading-2">{{ $ctaTitle ?? 'Stoğunu tek yerden yönetmeye bugün başla.' }}</h2>
            <p class="mt-3 text-lg text-white">
                {{ $ctaText ?? ($hasFree ? 'Ücretsiz paketle başla, kart bilgisi gerekmez. Büyüdükçe paketini yükseltirsin.' : 'Hesabını aç, kanallarını bağla, stoğunu tek yerden yönet.') }}
            </p>
        </div>
        <div class="flex flex-wrap gap-3">
            @if ($loggedIn)
                <a href="{{ url('/panel') }}" class="btn btn-light btn-lg">Panele git</a>
            @else
                <a href="{{ route('register') }}" class="btn btn-light btn-lg">Ücretsiz dene</a>
                <a href="{{ route('site.contact') }}" class="btn btn-ghost-light btn-lg">Bize ulaş</a>
            @endif
        </div>
    </div>
</section>
