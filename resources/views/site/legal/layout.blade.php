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
    <div class="wrap pt-10 pb-20 lg:pt-16 lg:pb-28">
        <div class="grid gap-12 lg:grid-cols-12">
            <article class="lg:col-span-8">
                <header>
                    <p class="eyebrow accent">Yasal</p>
                    <h1 class="display t-2 mt-4">{{ $legalTitle }}</h1>
                    <p class="mt-6 text-sm muted">Son güncelleme: <time datetime="2026-10-04">4 Ekim 2026</time></p>
                </header>

                <div class="prose-site mt-10 lg:mt-14">
                    @yield('legal_body')
                </div>
            </article>

            <nav class="lg:col-span-3 lg:col-start-10" aria-labelledby="yasal-nav">
                <div class="lg:sticky lg:top-28 border-t border-line pt-6">
                    <h2 id="yasal-nav" class="text-sm font-semibold uppercase tracking-[0.12em]">Yasal metinler</h2>
                    <ul class="mt-4 space-y-3" role="list">
                        @foreach ($legalPages as $slug => $name)
                            <li>
                                @if (url()->current() === route('site.legal', $slug))
                                    <span aria-current="page" class="font-semibold">{{ $name }}</span>
                                @else
                                    <a class="link-u muted" href="{{ route('site.legal', $slug) }}">{{ $name }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </nav>
        </div>
    </div>
@endsection
