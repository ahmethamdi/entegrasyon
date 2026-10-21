{{--
    Kanal kutucukları — ad + durum; her biri kanal sayfasına gider.

    Durum `channel_types.is_active`'ten (SiteController): açık kanal
    "Aktif", kapalı kanal "Yakında". Kapalı kanal da sayfasına bağlanır
    (orada "hazırlanıyor" yazar) ama "Aktif" DENMEZ.

    Kanal LOGOSU kullanılmaz: lisanslı logo elimizde yok, taklit çizmek de
    dürüst değil. Ad düz yazıyla, durum rozetle.

    Değişkenler: $channels, $columns (isteğe bağlı grid sınıfları)
--}}
<ul class="grid gap-3 {{ $columns ?? 'grid-cols-2 lg:grid-cols-3' }}">
    @foreach ($channels as $ch)
        <li>
            {{-- Ad üst satırda tam genişlikte: dar sütunda "WooCommerce" kırpılmasın. --}}
            <a href="{{ route('site.channel', $ch['code']) }}" class="channel-tile {{ $ch['available'] ? '' : 'channel-tile--soon' }}">
                <span class="flex h-7 w-full items-center gap-2.5 [overflow-wrap:anywhere] {{ $ch['available'] ? 'text-ink' : '' }}">
                    @if ($ch['logo'] ?? null)
                        <img src="{{ $ch['logo'] }}" alt="{{ $ch['name'] }} logosu" class="h-7 w-auto {{ ($ch['wordmark'] ?? false) ? 'max-w-full' : 'max-w-[7rem]' }} min-w-0 shrink object-contain object-left" loading="lazy" decoding="async">
                    @endif
                    {{-- Yazı-logosu adı zaten taşır; ad yalnız ekran okuyucuya. --}}
                    <span class="min-w-0 {{ ($ch['logo'] ?? null) && ($ch['wordmark'] ?? false) ? 'sr-only' : '' }}">{{ $ch['name'] }}</span>
                </span>
                <span class="flex w-full flex-wrap items-center justify-between gap-x-2 gap-y-1">
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
