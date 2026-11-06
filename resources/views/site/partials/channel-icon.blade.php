{{--
    Kanalın KARE ikonu, sabit boyutlu kutuda — bütün kartlarda aynı boyut.

    Resmî ikon dosyası yoksa (ör. Ticimax) harf ya da çizim YOK: boş,
    kesik çizgili kutu kalır ve kanalın adı yanında düz metin olarak yazar
    (6 Kas 2026; tek harf rozeti logo gibi okunuyordu). Başka sitelerden
    logo indirilmez — kaynaklar `public/images/channels/KAYNAKLAR.md`.
    Değişkenler: $ch (code, name, icon), $size ('sm' 28px | 'md' 40px)
--}}
@php $px = ($size ?? 'md') === 'sm' ? 'size-7' : 'size-10'; @endphp
@if ($ch['icon'] ?? null)
    <span class="channel-icon {{ $px }}" aria-hidden="true">
        <img src="{{ $ch['icon'] }}" alt="" class="size-full object-contain" loading="lazy" decoding="async">
    </span>
@else
    <span class="channel-icon channel-icon--none {{ $px }}" aria-hidden="true"></span>
@endif
