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
    <article>
        <header class="border-b border-line bg-soft">
            <div class="wrap pt-8 pb-10 lg:pt-10 lg:pb-14">
                <nav aria-label="Konum" class="text-sm muted">
                    <ol class="flex flex-wrap items-center gap-2" role="list">
                        <li><a class="link-u" href="{{ route('home') }}">Ana sayfa</a></li>
                        <li aria-hidden="true">/</li>
                        <li><a class="link-u" href="{{ route('site.blog') }}">Blog</a></li>
                        <li aria-hidden="true">/</li>
                        <li aria-current="page" class="max-w-[16rem] truncate sm:max-w-md">{{ $post->title }}</li>
                    </ol>
                </nav>

                <h1 class="h-page mt-8 max-w-[30ch]">{{ $post->title }}</h1>
                <p class="mt-5 text-sm muted">
                    <time datetime="{{ $post->publishedAt->toDateString() }}">{{ $post->publishedAt->locale('tr')->translatedFormat('j F Y') }}</time>
                    @if ($post->updatedAt && $post->updatedAt->gt($post->publishedAt))
                        <span aria-hidden="true">·</span>
                        Güncellendi: <time datetime="{{ $post->updatedAt->toDateString() }}">{{ $post->updatedAt->locale('tr')->translatedFormat('j F Y') }}</time>
                    @endif
                    <span aria-hidden="true">·</span>
                    {{ $post->readingMinutes }} dk okuma
                </p>
            </div>
        </header>

        <div class="wrap py-12 lg:py-16">
            <div class="prose-site">
                {!! $post->html !!}
            </div>

            @if ($post->tags !== [])
                <ul class="mt-12 flex max-w-[68ch] flex-wrap gap-2 border-t border-line pt-6 text-sm" aria-label="Etiketler">
                    @foreach ($post->tags as $tag)
                        <li class="rounded-full bg-soft px-3 py-1 muted">#{{ $tag }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </article>

    @if ($related !== [])
        <aside class="border-t border-line bg-soft" aria-labelledby="ilgili-yazilar">
            <div class="wrap py-12 lg:py-16">
                <h2 id="ilgili-yazilar" class="heading-2">İlgili yazılar</h2>
                <ul class="mt-8 grid gap-5 md:grid-cols-3" role="list">
                    @foreach ($related as $item)
                        <li class="card card-link relative">
                            <p class="text-sm muted">
                                <time datetime="{{ $item->publishedAt->toDateString() }}">{{ $item->publishedAt->locale('tr')->translatedFormat('j F Y') }}</time>
                                <span aria-hidden="true">·</span>
                                {{ $item->readingMinutes }} dk
                            </p>
                            <h3 class="heading-3 mt-2">
                                <a class="after:absolute after:inset-0 after:content-[''] hover:text-brand-700" href="{{ route('site.blog.show', $item->slug) }}">{{ $item->title }}</a>
                            </h3>
                            <p class="mt-2 muted">{{ $item->description }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </aside>
    @endif

    @include('site.partials.cta')
@endsection
