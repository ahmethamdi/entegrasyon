{{--
    Tek blog yazısı — /blog/{slug}.

    Gövde `{!! !!}` ile basılır: HTML Markdown ayrıştırıcısından gelir ve
    ham HTML girdisi orada SİLİNİR (Blog::converter, html_input=strip).
    JSON-LD: BlogPosting + BreadcrumbList.
--}}
@extends('site.layout')

@section('title', $post->title)
@section('description', $post->description)
@section('canonical', route('site.blog.show', $post->slug))
@if ($post->image)
    @section('og_image', url($post->image))
@endif

@push('jsonld')
    @include('site.partials.seo.article', ['post' => $post])
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Blog', 'url' => route('site.blog')],
        ['name' => $post->title, 'url' => route('site.blog.show', $post->slug)],
    ]])
@endpush

@section('content')
    <article class="wrap pt-10 pb-20 lg:pt-16 lg:pb-28">
        <nav aria-label="Konum" class="text-sm muted">
            <ol class="flex flex-wrap items-center gap-2" role="list">
                <li><a class="link-u" href="{{ route('home') }}">Ana sayfa</a></li>
                <li aria-hidden="true">/</li>
                <li><a class="link-u" href="{{ route('site.blog') }}">Blog</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="truncate max-w-[16rem] sm:max-w-md">{{ $post->title }}</li>
            </ol>
        </nav>

        <header class="mt-10 max-w-4xl lg:mt-14">
            <h1 class="display t-2">{{ $post->title }}</h1>
            <p class="mt-6 text-sm muted">
                <time datetime="{{ $post->publishedAt->toDateString() }}">{{ $post->publishedAt->locale('tr')->translatedFormat('j F Y') }}</time>
                @if ($post->updatedAt && $post->updatedAt->gt($post->publishedAt))
                    <span aria-hidden="true">·</span>
                    Güncellendi: <time datetime="{{ $post->updatedAt->toDateString() }}">{{ $post->updatedAt->locale('tr')->translatedFormat('j F Y') }}</time>
                @endif
                <span aria-hidden="true">·</span>
                {{ $post->readingMinutes }} dk okuma
            </p>
        </header>

        <div class="prose-site mt-12 lg:mt-16">
            {!! $post->html !!}
        </div>

        @if ($post->tags !== [])
            <ul class="mt-12 flex max-w-[68ch] flex-wrap gap-x-4 gap-y-1 border-t border-line pt-6 text-sm muted" aria-label="Etiketler">
                @foreach ($post->tags as $tag)
                    <li>#{{ $tag }}</li>
                @endforeach
            </ul>
        @endif
    </article>

    @if ($related !== [])
        <aside class="bg-sand" aria-labelledby="ilgili-yazilar">
            <div class="wrap py-16 lg:py-20">
                <h2 id="ilgili-yazilar" class="display t-3">İlgili yazılar</h2>
                <ul class="mt-10 grid gap-8 md:grid-cols-3" role="list">
                    @foreach ($related as $item)
                        <li>
                            <p class="text-sm muted">
                                <time datetime="{{ $item->publishedAt->toDateString() }}">{{ $item->publishedAt->locale('tr')->translatedFormat('j F Y') }}</time>
                                <span aria-hidden="true">·</span>
                                {{ $item->readingMinutes }} dk
                            </p>
                            <h3 class="mt-2 text-xl font-semibold leading-snug">
                                <a class="hover:text-brand" href="{{ route('site.blog.show', $item->slug) }}">{{ $item->title }}</a>
                            </h3>
                            <p class="mt-2 muted">{{ $item->description }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </aside>
    @endif
@endsection
