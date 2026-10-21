{{--
    Kanal kartları — her kartta AYNI boyutlu kare ikon kutusu ve altında ad
    (her zaman yazı). Değişkenler: $channels, $columns (isteğe bağlı grid sınıfları)
--}}
<ul class="grid gap-3 {{ $columns ?? 'grid-cols-2 lg:grid-cols-3' }}">
    @foreach ($channels as $ch)
        <li class="h-full">
            <a href="{{ route('site.channel', $ch['code']) }}" class="channel-card {{ $ch['available'] ? '' : 'channel-card--soon' }}">
                @include('site.partials.channel-icon', ['ch' => $ch])
                <span class="mt-3 block font-semibold [overflow-wrap:anywhere] {{ $ch['available'] ? 'text-ink' : 'muted' }}">{{ $ch['name'] }}</span>
                <span class="mt-1 flex w-full flex-wrap items-center justify-between gap-x-2 gap-y-1">
                    <span class="text-xs font-normal muted">{{ $ch['marketplace'] ? 'Pazaryeri' : 'E-ticaret sitesi' }}</span>
                    @if ($ch['available'] && ($ch['beta'] ?? false))
                        <span class="badge badge--brand">Beta</span>
                    @elseif ($ch['available'])
                        <span class="badge badge--active">Aktif</span>
                    @else
                        <span class="badge badge--soon">Yakında</span>
                    @endif
                </span>
            </a>
        </li>
    @endforeach
</ul>
