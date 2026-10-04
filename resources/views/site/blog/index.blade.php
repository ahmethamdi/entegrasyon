{{--
    Blog listesi — /blog. Yazılar App\Support\Site\Blog'dan, en yeni önce.
    Tarih Türkçe biçimde ("21 Eylül 2026"); makine için <time datetime>.
--}}
@extends('site.layout')

@section('title', 'Blog — çok kanallı satış ve stok yönetimi')
@section('description', 'Pazaryeri entegrasyonu, stok senkronizasyonu, fazla satış ve stok kodu düzeni üzerine pratik yazılar. Birden çok kanalda satan satıcılar için.')

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Blog', 'url' => route('site.blog')],
    ]])
@endpush

@section('content')
    <section class="wrap pt-14 pb-20 lg:pt-24 lg:pb-28">
        <header class="max-w-3xl">
            <p class="eyebrow accent">Blog</p>
            <h1 class="display t-2 mt-4">Çok kanallı satış üzerine notlar</h1>
            <p class="lead muted mt-6">Stok, sipariş ve pazaryeri entegrasyonu hakkında pratik rehberler. Reklam değil, işine yarayacak bilgi.</p>
        </header>

        @if ($posts === [])
            <p class="muted mt-16">Henüz yayımlanmış yazı yok.</p>
        @else
            <ol class="mt-14 border-t border-line lg:mt-20" role="list">
                @foreach ($posts as $post)
                    <li class="border-b border-line">
                        <article class="grid gap-3 py-8 lg:grid-cols-12 lg:gap-10 lg:py-10">
                            <p class="text-sm muted lg:col-span-3 lg:pt-2">
                                <time datetime="{{ $post->publishedAt->toDateString() }}">{{ $post->publishedAt->locale('tr')->translatedFormat('j F Y') }}</time>
                                <span aria-hidden="true">·</span>
                                {{ $post->readingMinutes }} dk okuma
                            </p>
                            <div class="lg:col-span-9">
                                <h2 class="display t-3">
                                    <a class="hover:text-brand" href="{{ route('site.blog.show', $post->slug) }}">{{ $post->title }}</a>
                                </h2>
                                <p class="mt-3 max-w-[68ch] muted">{{ $post->description }}</p>
                                @if ($post->tags !== [])
                                    <ul class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-sm muted" aria-label="Etiketler">
                                        @foreach ($post->tags as $tag)
                                            <li>#{{ $tag }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </article>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@endsection
