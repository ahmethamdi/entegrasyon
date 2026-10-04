{{--
    Kanal listesi — büyük yazılı satırlar, her biri kanal sayfasına gider.

    Kanal LOGOSU kullanılmaz: lisanslı logo elimizde yok, taklit çizmek de
    dürüst değil. Ad düz yazıyla, durum metinle. Kapalı kanal da sayfasına
    bağlanır (orada "hazırlanıyor" yazar) ama "Bağlanabilir" DENMEZ.
--}}
<ul class="border-t-2 border-current">
    @foreach ($channels as $ch)
        <li>
            <a href="{{ route('site.channel', $ch['code']) }}" class="channel-row group">
                <span class="channel-row__name {{ $ch['available'] ? '' : 'muted' }}">{{ $ch['name'] }}</span>
                <span class="flex items-center gap-5">
                    @if ($ch['available'])
                        <span class="tag">Bağlanabilir</span>
                    @else
                        <span class="tag tag--soon">Yakında</span>
                    @endif
                    <svg class="hidden size-7 transition-transform duration-300 group-hover:translate-x-1 sm:block" viewBox="0 0 28 28" aria-hidden="true"><path d="M4 14h19M16 6l8 8-8 8" fill="none" stroke="currentColor" stroke-width="2.4"/></svg>
                </span>
            </a>
        </li>
    @endforeach
</ul>
