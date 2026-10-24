{{--
    Sayfa sonu çağrısı — ana sayfa, özellikler, fiyatlar, entegrasyonlar,
    kanal ve hakkımızda sayfalarının sonunda aynı blok.

    "Kart bilgisi gerekmez" YALNIZ ücretsiz paket varsa yazılır (kayıt
    formu ödeme sormuyor; ücretli pakete geçişte sorulur).

    Değişkenler (isteğe bağlı):
      $ctaTitle  başlık; yoksa genel başlık
      $ctaText   alt cümle
      $ctaTone   'navy' → lacivert zemin (ana sayfa); yoksa marka kırmızısı
      $lang      'en' → İngilizce metin
      $demoAvailable  S12 · demo kuruluysa "Demoyu incele" (/demo)
--}}
@php
    $loggedIn = $isLoggedIn ?? false;
    $en = ($lang ?? 'tr') === 'en';
    $t = fn (string $tr, string $enText): string => $en ? $enText : $tr;
    $hasFree = collect($plans ?? [])->contains(fn ($p) => (float) $p['priceMonthly'] === 0.0);
@endphp
<section class="{{ ($ctaTone ?? null) === 'navy' ? 'on-navy' : 'on-brand' }}" aria-labelledby="cta-baslik">
    <div class="wrap flex flex-col gap-8 py-14 lg:flex-row lg:items-center lg:justify-between lg:py-16">
        <div class="max-w-2xl">
            <h2 id="cta-baslik" class="heading-2">{{ $ctaTitle ?? $t('Stoğunu tek yerden yönetmeye bugün başla.', 'Start managing your stock from one place today.') }}</h2>
            <p class="mt-3 text-lg text-white">
                {{ $ctaText ?? ($hasFree ? $t('Ücretsiz paketle başla, kart bilgisi gerekmez. Büyüdükçe paketini yükseltirsin.', 'Start on the free plan, no card required. Upgrade as you grow.') : $t('Hesabını aç, kanallarını bağla, stoğunu tek yerden yönet.', 'Sign up, connect your channels, manage stock from one place.')) }}
            </p>
        </div>
        <div class="flex flex-wrap gap-3">
            @if ($loggedIn)
                <a href="{{ url('/panel') }}" class="btn btn-light btn-lg">{{ $t('Panele git', 'Open panel') }}</a>
            @else
                <a href="{{ route('register') }}" class="btn {{ ($ctaTone ?? null) === 'navy' ? 'btn-primary' : 'btn-light' }} btn-lg">{{ $t('Ücretsiz dene', 'Try free') }}</a>
                @if ($demoAvailable ?? false)
                    <a href="{{ route('demo') }}" class="btn btn-ghost-light btn-lg" rel="nofollow">{{ $t('Demoyu incele', 'Explore the demo') }}</a>
                @endif
                <a href="{{ route('site.contact') }}" class="btn btn-ghost-light btn-lg">{{ $t('Bize ulaş', 'Contact us') }}</a>
            @endif
        </div>
    </div>
</section>
