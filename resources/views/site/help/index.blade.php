{{--
    /yardim — kanal bağlama rehberleri (S12). Liste `$guides`'tan: yalnız
    açık ve panelden bağlanma tanımı olan kanallar (SiteController::hasGuide).
--}}
@extends('site.layout')

@section('title', 'Yardım: kanal bağlama rehberleri')
@section('description', 'Her kanalı 34Pazar\'a bağlamak için gereken bilgiler ve nereden alınacakları. Panelde istenen alanların aynısı, adım adım.')

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Yardım', 'url' => route('site.help')],
    ]])
@endpush

@section('content')
    <section class="border-b border-line bg-soft" aria-labelledby="yardim-h1">
        <div class="wrap pt-12 pb-12 lg:pt-16 lg:pb-14">
            <p class="eyebrow">Yardım</p>
            <h1 id="yardim-h1" class="h-page mt-3 max-w-[24ch]">Kanal bağlama rehberleri</h1>
            <p class="lead mt-5 max-w-[56ch]">
                Her kanal için panelin senden istediği bilgiler ve onları kanalın kendi panelinde nerede bulacağın. Bağlanırken takılırsan <a href="{{ route('site.contact') }}" class="link">bize yaz</a>.
            </p>
        </div>
    </section>

    <section class="wrap py-12 lg:py-16" aria-label="Rehberler">
        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($guides as $guide)
                <li>
                    <a href="{{ route('site.help.channel', $guide['code']) }}" class="card flex h-full items-center justify-between gap-4 hover:border-ink">
                        <span class="flex items-center gap-3">
                            @include('site.partials.channel-icon', ['ch' => $guide, 'size' => 'sm'])
                            <span class="font-semibold text-ink">{{ $guide['name'] }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    @include('site.partials.cta')
@endsection
