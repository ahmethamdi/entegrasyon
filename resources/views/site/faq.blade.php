{{--
    /sss — sık sorulanlar (6 Kas 2026 · site sprint 1). Ana sayfadaki #sss'in
    tamamı; tek kaynak `App\Support\Site\Faq`. Kanal bağlama soruları panel
    yardımından (`HelpController::channelQuestions`), ikinci kez yazılmaz.
--}}
@extends('site.layout')

@section('title', 'Sık sorulanlar')
@section('description', '34Pazar hakkında sık sorulanlar: hangi kanallar bağlanıyor ve ne kadar doğrulandı, fazla satış, fiyat kuralları, ödeme, iptal ve kanal bağlama.')

@push('jsonld')
    @include('site.partials.seo.faq', ['faqs' => array_merge($faqs, $channelFaqs)])
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Sık sorulanlar', 'url' => route('site.faq')],
    ]])
@endpush

@section('content')
    <section class="border-b border-line bg-soft" aria-labelledby="sss-h1">
        <div class="wrap-narrow pt-12 pb-12 lg:pt-16 lg:pb-14">
            <h1 id="sss-h1" class="h-page">Sık sorulanlar</h1>
            <p class="lead mt-5">Cevabını bulamazsan <a href="{{ route('site.contact') }}" class="plain-link">bize yaz</a>; her gün {{ config('site.support_hours') }} arasında dönüyoruz.</p>
        </div>
    </section>

    <section class="section" aria-labelledby="genel-h2">
        <div class="wrap-narrow">
            <h2 id="genel-h2" class="heading-2">Genel</h2>
            <div class="mt-8">
                @include('site.partials.faq', ['faqs' => $faqs, 'openFirst' => true])
            </div>
        </div>
    </section>

    @if (count($channelFaqs))
        <section class="section bg-soft home-band" aria-labelledby="kanal-sss-h2">
            <div class="wrap-narrow">
                <h2 id="kanal-sss-h2" class="heading-2">Kanal bağlama</h2>
                <p class="mt-3 muted">Kanal kanal adımlar için <a href="{{ route('site.help') }}" class="plain-link">bağlama rehberleri</a>.</p>
                <div class="mt-8">
                    @include('site.partials.faq', ['faqs' => $channelFaqs])
                </div>
            </div>
        </section>
    @endif

    @include('site.partials.cta')
@endsection
