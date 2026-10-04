{{--
    BlogPosting JSON-LD.

    Kullanım: @include('site.partials.seo.article', ['post' => $post]) — $post bir BlogPost.
    Yazar kişi değil kuruluş (34Pazar): yazılar ekip adına yayımlanıyor;
    var olmayan bir "uzman yazar" profili uydurulmaz.
--}}
@php
    /** @var \App\Support\Site\BlogPost $post */
    $article = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $post->title,
        'description' => $post->description,
        'inLanguage' => 'tr',
        'url' => route('site.blog.show', $post->slug),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => route('site.blog.show', $post->slug)],
        'datePublished' => $post->publishedAt->toAtomString(),
        'dateModified' => $post->modifiedAt()->toAtomString(),
        'wordCount' => $post->wordCount,
        'keywords' => implode(', ', $post->tags),
        'image' => $post->image ? url($post->image) : asset('images/site/panel-ana-sayfa.png'),
        'author' => ['@type' => 'Organization', 'name' => config('site.brand'), 'url' => url('/')],
        'publisher' => [
            '@type' => 'Organization',
            'name' => config('site.brand'),
            'logo' => ['@type' => 'ImageObject', 'url' => asset('images/34pazar-logo.png')],
        ],
    ];
@endphp
<script type="application/ld+json">{!! json_encode($article, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
