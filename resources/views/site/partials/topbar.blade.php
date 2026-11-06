{{--
    Üst bilgi çubuğu — destek e-postası, WhatsApp ve görüşme isteği.

    Telefon araması vaadi yok; 24 Eki 2026 kararıyla destek WhatsApp +
    e-posta, her gün (fiyat sayfasıyla aynı söz, `config/site.php`).
    6 Kas 2026: açık zemin (tam genişlik lacivert kaldırıldı).
--}}
@php
    $en = ($lang ?? 'tr') === 'en';
    $email = config('site.contact_email');
@endphp
<div class="topbar">
    <div class="wrap flex h-9 items-center justify-between gap-4 sm:justify-end sm:gap-6 text-[0.8125rem]">
        <span class="hidden md:inline">{{ $en ? 'Support:' : 'Destek:' }} <a href="mailto:{{ $email }}">{{ $email }}</a></span>
        <span>WhatsApp: <a href="https://wa.me/{{ config('site.whatsapp') }}">{{ config('site.whatsapp_display') }}</a></span>
        <span class="hidden lg:inline">{{ $en ? 'Every day' : 'Her gün' }} {{ config('site.support_hours') }}</span>
        @unless ($en)
            <a href="{{ route('site.contact', ['konu' => 'gorusme']) }}#form">Görüşme planla</a>
        @endunless
    </div>
</div>
