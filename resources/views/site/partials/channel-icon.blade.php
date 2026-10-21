{{--
    Kanalın KARE ikonu, sabit boyutlu kutuda — bütün kartlarda aynı boyut.
    İkon yoksa gri kutuda adın ilk harfi (harf rozeti; logo değil, uydurulmaz).
    Değişkenler: $ch (code, name, icon), $size ('sm' 28px | 'md' 40px)
--}}
@php $px = ($size ?? 'md') === 'sm' ? 'size-7' : 'size-10'; @endphp
<span class="channel-icon {{ $px }}" aria-hidden="true">
    @if ($ch['icon'] ?? null)
        <img src="{{ $ch['icon'] }}" alt="" class="size-full object-contain" loading="lazy" decoding="async">
    @else
        <span class="channel-icon__letter">{{ mb_strtoupper(mb_substr($ch['name'], 0, 1)) }}</span>
    @endif
</span>
