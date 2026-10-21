{{--
    Üst bilgi çubuğu — destek e-postası ve destek dili (21 Eki 2026).

    Telefon BİLEREK yok (kullanıcı kararı): +49 numara Türk satıcıya yabancı
    durur ve WhatsApp hattı yok. Numara alt bilgide ve iletişim sayfasında.
--}}
@php
    $en = ($lang ?? 'tr') === 'en';
    $email = config('site.contact_email');
@endphp
<div class="topbar">
    <div class="wrap flex h-9 items-center justify-between gap-4 sm:justify-end sm:gap-6 text-[0.8125rem]">
        <span>{{ $en ? 'Support:' : 'Destek:' }} <a href="mailto:{{ $email }}" class="font-semibold text-white">{{ $email }}</a></span>
        <span>{{ $en ? 'Turkish & English support' : 'Türkçe destek' }}</span>
    </div>
</div>
