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
    <section class="border-b border-line bg-soft">
        <div class="wrap pt-12 pb-12 lg:pt-16 lg:pb-14">
            <p class="eyebrow">Blog</p>
            <h1 class="h-page mt-3 max-w-[24ch]">Çok kanallı satış üzerine notlar</h1>
            <p class="lead mt-5 max-w-[56ch]">Stok, sipariş ve pazaryeri entegrasyonu hakkında pratik rehberler. Reklam değil, işine yarayacak bilgi.</p>
        </div>
    </section>

    <section class="wrap py-12 lg:py-16">
        @if ($posts === [])
            <p class="muted">Henüz yayımlanmış yazı yok.</p>
        @else
            <ol class="grid gap-5 md:grid-cols-2 lg:grid-cols-3" role="list">
                @foreach ($posts as $post)
                    <li>
                        <article class="card card-link relative flex h-full flex-col">
                            <p class="text-sm muted">
                                <time datetime="{{ $post->publishedAt->toDateString() }}">{{ $post->publishedAt->locale('tr')->translatedFormat('j F Y') }}</time>
                                <span aria-hidden="true">·</span>
                                {{ $post->readingMinutes }} dk okuma
                            </p>
                            <h2 class="heading-3 mt-3">
                                {{-- Bağlantı bütün kartı kaplar (after:inset-0); başlık tek bağlantı olarak okunur. --}}
                                <a class="after:absolute after:inset-0 after:content-[''] hover:text-brand-700" href="{{ route('site.blog.show', $post->slug) }}">{{ $post->title }}</a>
                            </h2>
                            <p class="mt-2 muted">{{ $post->description }}</p>
                            @if ($post->tags !== [])
                                <ul class="mt-auto flex flex-wrap gap-2 pt-5 text-xs" aria-label="Etiketler">
                                    @foreach ($post->tags as $tag)
                                        <li class="rounded-full bg-soft px-2.5 py-1 muted">#{{ $tag }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </article>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@endsection
