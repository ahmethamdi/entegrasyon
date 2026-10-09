{{--
    Yasal sayfaların ortak iskeleti — site.layout'u genişletir.

    Her yasal sayfa yalnız `description` ve `legal_body` doldurur; başlık
    SiteController::LEGAL_PAGES'ten (`$legalTitle`) gelir. Tek yerde durur:
    "Son güncelleme" satırı ve yasal sayfalar arası gezinme beş sayfada
    ayrı ayrı yazılsaydı biri eskide kalırdı.

    Bu dosya bir sayfa DEĞİL: SiteController yalnız LEGAL_PAGES'teki
    adları açar, `/yasal/layout` 404 verir.
--}}
@extends('site.layout')

@section('title', $legalTitle)

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => $legalTitle, 'url' => url()->current()],
    ]])
@endpush

@section('content')
    @php($english = trim($__env->yieldContent('lang')) === 'en')
    {{-- Yalnız yerleşim/tipografi; metinlerin İÇERİĞİ avukat kontrolünden geçti, dokunulmaz. --}}
    <header class="border-b border-line bg-soft">
        <div class="wrap pt-10 pb-10 lg:pt-14 lg:pb-12">
            <p class="eyebrow">{{ $english ? 'Legal' : 'Yasal' }}</p>
            <h1 class="h-page mt-3">{{ $legalTitle }}</h1>
            @if ($english)
                <p class="mt-4 text-sm muted">Last updated: <time datetime="2026-10-05">5 October 2026</time></p>
            @else
                <p class="mt-4 text-sm muted">Son güncelleme: <time datetime="2026-10-05">5 Ekim 2026</time></p>
            @endif
        </div>
    </header>
    <div class="wrap py-12 lg:py-16">
        <div class="grid gap-12 lg:grid-cols-12">
            <article class="lg:col-span-8">
                <div class="prose-site">
                    @yield('legal_body')
                </div>
            </article>

            <nav class="lg:col-span-3 lg:col-start-10" aria-labelledby="yasal-nav">
                <div class="card lg:sticky lg:top-24">
                    <h2 id="yasal-nav" class="text-sm font-semibold muted">{{ $english ? 'Legal' : 'Yasal metinler' }}</h2>
                    <ul class="mt-3 space-y-1" role="list">
                        @foreach ($legalPages as $slug => $name)
                            <li>
                                @if (url()->current() === route('site.legal', $slug))
                                    <span aria-current="page" class="flex min-h-10 items-center font-semibold text-brand-700">{{ $name }}</span>
                                @else
                                    <a class="flex min-h-10 items-center hover:text-brand-700 hover:underline" href="{{ route('site.legal', $slug) }}">{{ $name }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </nav>
        </div>
    </div>
@endsection
