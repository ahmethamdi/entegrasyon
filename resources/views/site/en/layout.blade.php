{{--
    İngilizce yardım sayfalarının ortak iskeleti (Shopify App Store
    listelemesinin FAQ / Changelog / Tutorial / Documentation bağlantıları).
    Bu dosya bir sayfa değil: SiteController yalnız ENGLISH_PAGES'teki
    adları açar.
--}}
@extends('site.layout')

@section('lang', 'en')
@section('og_locale', 'en_GB')
@section('title', $pageTitle)

@section('content')
    <header class="border-b border-line bg-soft">
        <div class="wrap pt-10 pb-10 lg:pt-14 lg:pb-12">
            <p class="eyebrow">34Pazar Help</p>
            <h1 class="h-page mt-3">{{ $pageTitle }}</h1>
        </div>
    </header>
    <div class="wrap py-12 lg:py-16">
        <div class="grid gap-12 lg:grid-cols-12">
            <article class="lg:col-span-8">
                <div class="prose-site">
                    @yield('help_body')
                </div>
            </article>

            <nav class="lg:col-span-3 lg:col-start-10" aria-labelledby="help-nav">
                <div class="card lg:sticky lg:top-24">
                    <h2 id="help-nav" class="text-sm font-semibold muted">Help</h2>
                    <ul class="mt-3 space-y-1" role="list">
                        @foreach ($englishPages as $slug => $name)
                            <li>
                                @if (url()->current() === route('site.en', $slug))
                                    <span aria-current="page" class="flex min-h-10 items-center font-semibold text-brand-700">{{ $name }}</span>
                                @else
                                    <a class="flex min-h-10 items-center hover:text-brand-700 hover:underline" href="{{ route('site.en', $slug) }}">{{ $name }}</a>
                                @endif
                            </li>
                        @endforeach
                        <li><a class="flex min-h-10 items-center hover:text-brand-700 hover:underline" href="{{ route('site.legal', 'privacy') }}">Privacy Policy</a></li>
                        <li><a class="flex min-h-10 items-center hover:text-brand-700 hover:underline" href="mailto:{{ config('site.contact_email') }}">Contact support</a></li>
                    </ul>
                </div>
            </nav>
        </div>
    </div>
@endsection
