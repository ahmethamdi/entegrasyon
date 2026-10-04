{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
{{--
    Site haritası — SiteController::sitemap `$urls` verir: [['loc' => …, 'lastmod' => Carbon|null]].
    XML bildirimi parçalanarak basılır ('<'.'?xml'): Blade, içinde PHP
    açılış etiketi geçen satırı derlemeden olduğu gibi bırakıyor ve PHP
    onu kısa etiket sanıp sözdizimi hatası veriyor (ölçüldü: 500). Bildirim DOSYANIN İLK SATIRINDA:
    önünde tek bir boş satır bile olsa XML geçersiz olur (bu yorum
    bloğu bile bildirimin önüne konamaz).
--}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
@if (! empty($url['lastmod']))
        <lastmod>{{ $url['lastmod'] instanceof \DateTimeInterface ? $url['lastmod']->format('Y-m-d') : \Illuminate\Support\Carbon::parse($url['lastmod'])->format('Y-m-d') }}</lastmod>
@endif
    </url>
@endforeach
</urlset>
