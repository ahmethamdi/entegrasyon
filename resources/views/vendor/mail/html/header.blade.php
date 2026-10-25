@props(['url'])
{{--
    Logo MUTLAK adresle: posta istemcisi göreli yolu çözemez. Adres
    `app.url`'den kurulur, yerelde yerel sunucuyu, canlıda 34pazar.com'u
    gösterir. PNG (SVG çoğu posta istemcisinde görünmez). Görsel
    engellenirse alt metin marka adını yazar.
--}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@php($base = rtrim((string) config('app.url'), '/'))
<img src="{{ $base }}/images/34pazar-logo.png" class="logo logo-light" width="140" height="32" alt="{{ config('site.brand', '34Pazar') }}" style="display: block; width: 140px; height: auto; border: 0;">
{{-- Koyu tema: beyaz yazılı logo. Varsayılan gizli; destekleyen istemcide
     medya sorgusu yer değiştirir (layout <style>). Desteklemeyen istemci
     açık temada kalır, beyaz logoyu hiç göstermez. --}}
<!--[if !mso]><!-->
<img src="{{ $base }}/images/34pazar-logo-beyaz.png" class="logo logo-dark" width="140" height="32" alt="" aria-hidden="true" style="display: none; width: 140px; height: auto; border: 0; max-height: 0; overflow: hidden;">
<!--<![endif]-->
</a>
</td>
</tr>
