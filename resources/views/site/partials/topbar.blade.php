{{--
    Üst bilgi çubuğu — destek e-postası ve destek dili (21 Eki 2026).

    Telefon araması vaadi yok; 24 Eki 2026 kararıyla destek WhatsApp +
    e-posta, her gün (fiyat sayfasıyla aynı söz, `config/site.php`).
--}}
@php
    $en = ($lang ?? 'tr') === 'en';
    $email = config('site.contact_email');
@endphp
<div class="topbar">
    <div class="wrap flex h-9 items-center justify-between gap-4 sm:justify-end sm:gap-6 text-[0.8125rem]">
        <span>{{ $en ? 'Support:' : 'Destek:' }} <a href="mailto:{{ $email }}" class="font-semibold text-white">{{ $email }}</a></span>
        <span>WhatsApp: <a href="https://wa.me/{{ config('site.whatsapp') }}" class="font-semibold text-white">{{ config('site.whatsapp_display') }}</a></span>
        <span class="hidden sm:inline">{{ $en ? 'Every day' : 'Her gün' }} {{ config('site.support_hours') }}</span>
    </div>
</div>
