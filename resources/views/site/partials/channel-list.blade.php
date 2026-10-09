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
                <span class="w-full [overflow-wrap:anywhere] {{ $ch['available'] ? 'text-ink' : '' }}">{{ $ch['name'] }}</span>
                <span class="flex w-full flex-wrap items-center justify-between gap-x-2 gap-y-1">
                    <span class="text-xs font-normal muted">{{ $ch['marketplace'] ? 'Pazaryeri' : 'E-ticaret sitesi' }}</span>
                    @if ($ch['available'])
                        <span class="badge badge--active">Aktif</span>
                    @else
                        <span class="badge badge--soon">Yakında</span>
                    @endif
                </span>
            </a>
        </li>
    @endforeach
</ul>
