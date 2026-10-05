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
    <div class="wrap pt-10 pb-20 lg:pt-16 lg:pb-28">
        <div class="grid gap-12 lg:grid-cols-12">
            <article class="lg:col-span-8">
                <header>
                    <p class="eyebrow accent">34Pazar Help</p>
                    <h1 class="display t-2 mt-4">{{ $pageTitle }}</h1>
                </header>

                <div class="prose-site mt-10 lg:mt-14">
                    @yield('help_body')
                </div>
            </article>

            <nav class="lg:col-span-3 lg:col-start-10" aria-labelledby="help-nav">
                <div class="lg:sticky lg:top-28 border-t border-line pt-6">
                    <h2 id="help-nav" class="text-sm font-semibold uppercase tracking-[0.12em]">Help</h2>
                    <ul class="mt-4 space-y-3" role="list">
                        @foreach ($englishPages as $slug => $name)
                            <li>
                                @if (url()->current() === route('site.en', $slug))
                                    <span aria-current="page" class="font-semibold">{{ $name }}</span>
                                @else
                                    <a class="link-u muted" href="{{ route('site.en', $slug) }}">{{ $name }}</a>
                                @endif
                            </li>
                        @endforeach
                        <li><a class="link-u muted" href="{{ route('site.legal', 'privacy') }}">Privacy Policy</a></li>
                        <li><a class="link-u muted" href="mailto:{{ config('site.contact_email') }}">Contact support</a></li>
                    </ul>
                </div>
            </nav>
        </div>
    </div>
@endsection
