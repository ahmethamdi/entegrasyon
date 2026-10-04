{{--
    Sık sorulanlar — `$faqs` = [['q' => ..., 'a' => ...], ...].

    Aynı dizi JSON-LD'ye de (seo.faq) gider: sayfada görünen ile arama
    motoruna söylenen birebir aynı kalsın. <details> yerel açılır-kapanır;
    klavye ve ekran okuyucu desteği tarayıcıdan gelir, JS gerekmez.
--}}
<div class="faq">
    @foreach ($faqs as $item)
        <details @if ($loop->first && ($openFirst ?? false)) open @endif>
            <summary>{{ $item['q'] }}</summary>
            <div><p>{{ $item['a'] }}</p></div>
        </details>
    @endforeach
</div>
