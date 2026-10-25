@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
{{--
    Kurşun geçirmez düğme: renk hem hücrede (bgcolor) hem bağlantıda.
    Görseller ya da CSS kapalı olsa da tıklanır bir kutu kalır.
--}}
@php($bg = $color === 'error' ? '#a8280b' : '#ce310d')
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td bgcolor="{{ $bg }}" style="border-radius: 8px; background-color: {{ $bg }};">
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" style="background-color: {{ $bg }}; border: 1px solid {{ $bg }}; border-radius: 8px; color: #ffffff; display: inline-block; font-weight: 600; padding: 12px 24px; text-decoration: none;">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
