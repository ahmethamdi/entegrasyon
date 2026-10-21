{{--
    Adım görseli — panelden gerçek, kırpılmış ekran; üzerinde adımın
    numarasıyla TEK turuncu işaret (ilgili düğme ya da alan).

    width/height verilir (CLS 0), lazy yüklenir. İşaret süstür; adımın
    adı zaten başlıkta, ekran okuyucu atlar.

    Değişkenler: $shot (StepShots::get), $n (adım numarası), $alt
--}}
<figure class="step-shot">
    <img src="{{ asset($shot['src']) }}" alt="{{ $alt }}" width="{{ $shot['w'] }}" height="{{ $shot['h'] }}" loading="lazy" decoding="async">
    <span class="step-shot__mark" style="left: {{ $shot['mx'] }}%; top: {{ $shot['my'] }}%" aria-hidden="true">{{ $n }}</span>
</figure>
