{{--
    Kanal kartları — her kartta AYNI boyutlu kare ikon kutusu ve altında ad
    (her zaman yazı). Değişkenler: $channels, $columns (isteğe bağlı grid sınıfları),
    $lang ('en' → İngilizce etiketler)
--}}
@php $enList = ($lang ?? 'tr') === 'en'; @endphp
<ul class="grid gap-3 {{ $columns ?? 'grid-cols-2 lg:grid-cols-3' }}">
    @foreach ($channels as $ch)
        <li class="h-full">
            <a href="{{ route('site.channel', $ch['code']) }}" class="channel-card {{ $ch['available'] ? '' : 'channel-card--soon' }}">
                @include('site.partials.channel-icon', ['ch' => $ch])
                <span class="mt-3 block font-semibold [overflow-wrap:anywhere] {{ $ch['available'] ? 'text-ink' : 'muted' }}">{{ $ch['name'] }}</span>
                <span class="mt-1 flex w-full flex-wrap items-center justify-between gap-x-2 gap-y-1">
                    <span class="text-xs font-normal muted">{{ $ch['marketplace'] ? ($enList ? 'Marketplace' : 'Pazaryeri') : ($enList ? 'Online store' : 'E-ticaret sitesi') }}</span>
                    {{-- Kapalı kanal "Yakında"; açık kanal doğrulama düzeyi (S15 matrisi, 6 Kas 2026). --}}
                    @if (! $ch['available'])
                        <span class="badge badge--soon">{{ $enList ? 'Soon' : 'Yakında' }}</span>
                    @elseif (($ch['level'] ?? null) && ! $enList)
                        <span class="badge badge--{{ $ch['level']->publicTone() }}" data-level="{{ $ch['level']->value }}">{{ $ch['level']->publicLabel() }}</span>
                    @endif
                </span>
            </a>
        </li>
    @endforeach
</ul>
