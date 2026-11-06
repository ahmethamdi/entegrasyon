{{--
    Doğrulama rozetlerinin anlamı — S15 matrisinin sitedeki açıklaması
    (`SiteProof::legend()`, `CertificationStatus::publicNote()`). Elle metin yok.
    Değişkenler: $en (İngilizce sayfa → yalnız etiketler)
--}}
@php $enLegend = $en ?? false; @endphp
<dl class="mt-6 grid gap-2 text-sm">
    @foreach (\App\Support\Site\SiteProof::legend() as $item)
        <div class="grid items-baseline gap-x-4 gap-y-1 sm:grid-cols-[13rem_1fr]">
            <dt><span class="badge badge--{{ $item['tone'] }}">{{ $enLegend ? match ($item['tone']) { 'live' => 'Verified live', 'sandbox' => 'Verified in sandbox', 'testing' => 'Being tested', default => 'In channel panel' } : $item['label'] }}</span></dt>
            <dd class="muted">{{ $enLegend ? match ($item['tone']) { 'live' => 'Tried end to end on a real seller account.', 'sandbox' => 'Tried with real requests on the channel\'s official test server.', 'testing' => 'Built and tested against documented examples; not yet tried on a real account.', default => 'Done in the channel\'s own seller panel.' } : $item['note'] }}</dd>
        </div>
    @endforeach
</dl>
