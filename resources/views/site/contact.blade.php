{{--
    /iletisim — form + e-posta, WhatsApp, adres (config/site.php).

    6 Kas 2026 · site sprint 1: FORM EKLENDİ. Gönderim `contact_messages`'a
    kaydedilir ve `site.contact_form_to`'ya (varsayılan info@34devs.com)
    düz metin posta gider (ContactMessageController). Bot kalkanı
    (FormShield: tuzak alan + imzalı zaman damgası) ve IP başına hız sınırı
    (`throttle:contact`). Takvim aracı YOK: "Görüşme planla" konu ön seçili
    bir mesajdır (`?konu=gorusme`), saat yazışarak belirlenir.
--}}
@extends('site.layout')

@php
    $email = config('site.contact_email');
    $topic = old('topic', $presetTopic ?? 'gorusme');
    $service = old('service', $presetService);
    $sent = session('contact_sent', false);
    $err = fn (string $f) => $errors->has($f);
@endphp

@section('title', 'İletişim ve görüşme')
@section('description', '34Pazar ile ilgili görüşme, hizmet teklifi ya da destek için formu doldur, e-posta at ya da WhatsApp\'tan yaz. 34Devs, Korschenbroich, Almanya.')

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'İletişim', 'url' => route('site.contact')],
    ]])
@endpush

@section('content')
    <section class="border-b border-line bg-soft" aria-labelledby="iletisim-h1">
        <div class="wrap pt-12 pb-12 lg:pt-16 lg:pb-14">
            <h1 id="iletisim-h1" class="h-page">Bize ulaş</h1>
            <p class="lead mt-5 max-w-[60ch]">
                Görüşme istiyorsan, bir hizmet için teklif almak istiyorsan, bir kanalı bağlarken takıldıysan ya da listede olmayan bir kanalda satıyorsan yaz. Mesajın doğrudan 34Devs ekibine gelir.
            </p>
        </div>
    </section>

    <div class="wrap grid gap-12 py-12 lg:grid-cols-12 lg:py-16">
        {{-- ═══════════════════════════════ FORM --}}
        <section id="form" class="lg:col-span-7" aria-labelledby="form-h2">
            <h2 id="form-h2" class="heading-2">Mesaj gönder</h2>

            @if ($sent)
                <div class="notice notice--ok mt-6" role="status">
                    <p class="font-semibold text-ink">Mesajın bize ulaştı.</p>
                    <p class="mt-1 muted">Her gün {{ config('site.support_hours') }} (Türkiye saati) arasında, yazdığın e-posta adresinden dönüyoruz. Acil bir durum varsa WhatsApp'tan da yazabilirsin.</p>
                </div>
            @endif

            @if ($errors->any())
                <div class="notice mt-6" role="alert">
                    <p class="font-semibold text-ink">Form gönderilemedi.</p>
                    <ul class="mt-1 list-disc pl-5 text-sm">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('site.contact.store') }}" class="mt-6 grid gap-5" novalidate>
                @csrf
                <input type="hidden" name="ft" value="{{ $formStamp }}">
                {{-- BOT KALKANI (FormShield): ekranda ve ekran okuyucuda olmayan tuzak alan. --}}
                <div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden">
                    <label for="c-website">Web sitesi</label>
                    <input id="c-website" type="text" name="website" tabindex="-1" autocomplete="off" value="">
                </div>

                <div class="field">
                    <label for="c-topic">Konu</label>
                    <select id="c-topic" name="topic" required @if ($err('topic')) aria-invalid="true" @endif>
                        @foreach ($topics as $key => $label)
                            <option value="{{ $key }}" @selected($topic === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="c-service">Hizmet <span class="font-normal muted">(teklif için, isteğe bağlı)</span></label>
                    <select id="c-service" name="service" @if ($err('service')) aria-invalid="true" @endif>
                        <option value="">Seçilmedi</option>
                        @foreach ($services as $slug => $s)
                            <option value="{{ $slug }}" @selected($service === $slug)>{{ $s['title'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="field">
                        <label for="c-name">Adın</label>
                        <input id="c-name" type="text" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name" @if ($err('name')) aria-invalid="true" aria-describedby="c-name-err" @endif>
                        @error('name')<p id="c-name-err" class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="c-email">E-posta</label>
                        <input id="c-email" type="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email" @if ($err('email')) aria-invalid="true" aria-describedby="c-email-err" @endif>
                        @error('email')<p id="c-email-err" class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="field">
                    <label for="c-phone">Telefon <span class="font-normal muted">(isteğe bağlı)</span></label>
                    <input id="c-phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="40" autocomplete="tel" @if ($err('phone')) aria-invalid="true" aria-describedby="c-phone-err" @endif>
                    @error('phone')<p id="c-phone-err" class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="c-message">Mesajın</label>
                    <textarea id="c-message" name="message" required minlength="10" maxlength="5000" @if ($err('message')) aria-invalid="true" aria-describedby="c-message-err" @endif>{{ old('message') }}</textarea>
                    @error('message')<p id="c-message-err" class="field-error">{{ $message }}</p>@enderror
                </div>

                <p class="text-sm muted">
                    Formdaki bilgileri yalnız sana dönmek için kullanırız. Ayrıntılar: <a href="{{ route('site.legal', 'gizlilik') }}" class="plain-link">gizlilik ve KVKK aydınlatma metni</a>.
                </p>

                <div>
                    <button type="submit" class="btn btn-primary btn-lg">Gönder</button>
                </div>
            </form>
        </section>

        {{-- ═══════════════════════════════ DOĞRUDAN İLETİŞİM --}}
        <aside class="lg:col-span-5" aria-labelledby="dogrudan-h2">
            <h2 id="dogrudan-h2" class="heading-2">Doğrudan</h2>
            <dl class="plain-list mt-6">
                <div class="py-5 border-t border-line">
                    <dt class="font-semibold text-ink">E-posta</dt>
                    <dd class="mt-1"><a href="mailto:{{ $email }}" class="plain-link [overflow-wrap:anywhere]">{{ $email }}</a></dd>
                    <dd class="mt-1 text-sm muted">Sorular, destek ve öneriler için.</dd>
                </div>
                {{-- Fiyat sayfasının destek sözüyle aynı: WhatsApp + e-posta, her gün (24 Eki kararı). --}}
                <div class="py-5 border-t border-line">
                    <dt class="font-semibold text-ink">WhatsApp</dt>
                    <dd class="mt-1"><a href="https://wa.me/{{ config('site.whatsapp') }}" class="plain-link">{{ config('site.whatsapp_display') }}</a></dd>
                    <dd class="mt-1 text-sm muted">Her gün {{ config('site.support_hours') }} (Türkiye saati).</dd>
                </div>
                <div class="py-5 border-t border-b border-line">
                    <dt class="font-semibold text-ink">Adres</dt>
                    <dd class="mt-1">
                        <address class="not-italic">
                            {{ config('site.operator') }}<br>
                            {{ config('site.street') }}<br>
                            {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }}
                        </address>
                    </dd>
                </div>
            </dl>

            <div class="mt-8">
                <p class="font-semibold text-ink">Önce kendin bakmak istersen</p>
                <ul class="mt-3 grid gap-2">
                    <li><a href="{{ route('site.faq') }}" class="plain-link">Sık sorulanlar</a></li>
                    <li><a href="{{ route('site.help') }}" class="plain-link">Kanal bağlama rehberleri</a></li>
                    <li><a href="{{ route('site.security') }}" class="plain-link">Güvenlik ve KVKK</a></li>
                </ul>
            </div>
        </aside>
    </div>
@endsection
