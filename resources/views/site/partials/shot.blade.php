{{--
    Panelden GERÇEK ekran görüntüsü, açık tarayıcı çerçevesinde.

    Sitede uydurma HTML arayüz çizilmez (kullanıcı kararı, 4 Ekim 2026):
    satıcı panelde ne görecekse sitede de onu görür.

    Değişkenler:
      $src    /images/site/... (2880×1800, 1440×900'ün @2x'i)
      $alt    ne gösterdiğini anlatan metin
      $eager  ilk ekrandaki görsel (hero) için true — LCP gecikmesin
      $dy     kadrajın ne kadar aşağı kayacağı (%); anlatılan öğe
              ekranın altındaysa (ör. kargo formu) görünür kalsın
      $crop   ['ratio' => '16 / 9', 'zoom' => '150%', 'x' => '-30%', 'y' => '-25%']
              geniş ekranda belirli bir bölgeyi büyütür
      $url    çerçevedeki adres; süs, ekran okuyucu atlar

    width/height her zaman verilir: görsel gelince sayfa kaymasın (CLS).
--}}
@php
    $crop = $crop ?? null;
    $style = '--dy: '.($dy ?? 0).';';
    if ($crop) {
        $style .= "--ratio: {$crop['ratio']}; --zoom: {$crop['zoom']}; --x: {$crop['x']}; --y: {$crop['y']};";
    }
@endphp
<figure class="shot {{ $crop ? 'shot--crop' : '' }} {{ $class ?? '' }}">
    <div class="shot__bar" aria-hidden="true">
        <i></i><i></i><i></i>
        <span>{{ $url ?? '34pazar.com/panel' }}</span>
    </div>
    <div class="shot__view" style="{{ $style }}">
        <img
            src="{{ asset($src) }}"
            alt="{{ $alt }}"
            width="1440"
            height="900"
            @if ($eager ?? false) fetchpriority="high" @else loading="lazy" @endif
            decoding="async"
        >
    </div>
</figure>
