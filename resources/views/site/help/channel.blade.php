{{--
    /yardim/{kanal} — tek kanalın bağlama rehberi (S12).

    ⚠️ METİN ELLE YAZILMAZ: alanlar, ipuçları ve "nereden alınır" paragrafı
    `$form`'dan (ChannelConnectForm::present) gelir — panelde satıcının
    gördüğünün aynısı. Bu dosya yalnız düzen ve kanaldan bağımsız, kodda
    doğrulanmış cümleler taşır:
      - Yeni bağlantıda ürün yayını, fiyat ve stok gönderimi KAPALI başlar
        (ADR-010 · outbound write gates, varsayılan false).
      - Ortak sorular panel yardımından (HelpController::channelQuestions).
    Uygulaması sunucuda tanımsız kanal (`appMissing`) "şu an bağlanamaz" der;
    bağlanamayan bir kanalı adım adım anlatmak yanlış vaat olurdu.
--}}
@extends('site.layout')

@php
    $name = $channel['name'];
    $fields = array_merge($form['secretFields'] ?? [], $form['identityFields'] ?? []);
@endphp

@section('title', "{$name} nasıl bağlanır")
@section('description', \Illuminate\Support\Str::limit("{$name} mağazanı 34Pazar'a bağlamak için gereken bilgiler ve onları {$name} panelinde nerede bulacağın.", 157))

@push('jsonld')
    @include('site.partials.seo.faq', ['faqs' => $faqs])
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Yardım', 'url' => route('site.help')],
        ['name' => $name, 'url' => route('site.help.channel', $channel['code'])],
    ]])
@endpush

@section('content')
    <section class="border-b border-line bg-soft" aria-labelledby="rehber-h1">
        <div class="wrap pt-10 pb-12 lg:pt-14 lg:pb-14">
            <nav aria-label="Konum" class="text-sm muted">
                <ol class="flex flex-wrap gap-2">
                    <li><a href="{{ route('site.help') }}" class="link">Yardım</a> /</li>
                    <li aria-current="page" class="font-semibold text-ink">{{ $name }}</li>
                </ol>
            </nav>
            <div class="mt-6 flex flex-wrap items-center gap-3">
                @include('site.partials.channel-icon', ['ch' => $channel])
            </div>
            <h1 id="rehber-h1" class="h-page mt-4 max-w-[26ch] [overflow-wrap:anywhere]">{{ $name }} nasıl bağlanır</h1>
        </div>
    </section>

    <div class="wrap grid gap-12 py-12 lg:grid-cols-12 lg:py-16">
        <div class="lg:col-span-8">
            @if ($form['appMissing'] ?? false)
                <div class="card">
                    <h2 class="heading-3">Şu an bağlanamaz</h2>
                    <p class="mt-2 muted">{{ $name }} için 34Pazar uygulamasının kurulumu tamamlanmadı; panel bu kanalı bağlamaya izin vermiyor. Hazır olduğunda bu sayfada yazacak. Beklemek istemiyorsan <a href="{{ route('site.contact') }}" class="link">bize yaz</a>.</p>
                </div>
            @else
                <section aria-labelledby="adimlar-h2">
                    <h2 id="adimlar-h2" class="heading-2">Adımlar</h2>
                    <ol class="mt-6 space-y-6">
                        <li class="card">
                            <p class="font-semibold text-ink">1. {{ $name }} tarafında hazırlık</p>
                            @if ($form['help'] ?? null)
                                <p class="mt-2 muted">{{ $form['help'] }}</p>
                            @elseif ($installOnly)
                                <p class="mt-2 muted">{{ $name }} mağazası 34Pazar panelinden değil, {{ $name }}'ın kendi uygulama kurulumundan bağlanır: 34Pazar uygulamasını mağazana kurup izinleri onaylarsın, bağlantı kendiliğinden açılır.</p>
                                @if ($installPublic && ($form['installUrl'] ?? null))
                                    <a href="{{ $form['installUrl'] }}" class="link mt-3 inline-block" rel="noopener">34Pazar uygulamasını {{ $name }}'da aç →</a>
                                @else
                                    <p class="mt-2 muted">Uygulama şu an {{ $name }}'ın incelemesinde, mağaza listesinde henüz görünmüyor. Kurmak için <a href="{{ route('site.contact') }}" class="link">bize yaz</a>; kurulum bağlantısını gönderelim.</p>
                                @endif
                            @else
                                <p class="mt-2 muted">{{ $name }} panelinden API bilgilerini al; aşağıdaki alanlar panelde aynı adlarla sorulur.</p>
                            @endif
                        </li>
                        @unless ($installOnly)
                            <li class="card">
                                <p class="font-semibold text-ink">2. 34Pazar'da bağla</p>
                                <p class="mt-2 muted">Panelde <strong class="font-semibold text-ink">Kanallar → Mağaza bağla → {{ $name }}</strong>.
                                    @if ($fields === [] && ($form['oauth'] ?? false))
                                        Formda bir şey yazman gerekmez; kaydedince {{ $name }}'ın izin ekranına yönlendirilirsin ve izni orada verirsin.
                                    @elseif ($form['oauth'] ?? false)
                                        Aşağıdaki bilgileri gir; kaydedince {{ $name }}'ın izin ekranına yönlendirilirsin ve izni orada verirsin.
                                    @else
                                        Aşağıdaki bilgileri gir ve kaydet; 34Pazar bağlantıyı {{ $name }}'da dener ve sonucu Kanallar ekranında gösterir.
                                    @endif
                                </p>
                            </li>
                        @endunless
                        <li class="card">
                            <p class="font-semibold text-ink">{{ $installOnly ? '2' : '3' }}. Gönderim izinlerini aç</p>
                            <p class="mt-2 muted">Yeni bağlantıda ürün yayını, fiyat ve stok gönderimi kapalı başlar; bağlantı kurmak kanala tek bir şey yazmaz. Hazır olduğunda <strong class="font-semibold text-ink">Kanallar</strong> ekranında bağlantının kartındaki "Kanala yazma izinleri" bölümünden istediğin izni açarsın.</p>
                        </li>
                    </ol>
                </section>

                @if ($fields !== [])
                    <section class="mt-12" aria-labelledby="alanlar-h2">
                        <h2 id="alanlar-h2" class="heading-2">Panelde istenen bilgiler</h2>
                        <dl class="mt-6 divide-y divide-line rounded-xl border border-line">
                            @foreach ($fields as $field)
                                <div class="p-4 sm:p-5">
                                    <dt class="flex flex-wrap items-center gap-2 font-semibold text-ink">
                                        {{ $field['label'] }}
                                        @if (($field['optional'] ?? false) && ! str_contains($field['label'], 'isteğe bağlı'))
                                            <span class="text-sm font-normal muted">· isteğe bağlı</span>
                                        @endif
                                        @if ($field['masked'] ?? false)
                                            <span class="text-sm font-normal muted">· gizli tutulur</span>
                                        @endif
                                    </dt>
                                    @if (($field['hint'] ?? '') !== '')
                                        <dd class="mt-1 muted">{{ $field['hint'] }}</dd>
                                    @endif
                                </div>
                            @endforeach
                        </dl>
                        <p class="mt-3 text-sm muted">"Gizli tutulur" işaretli bilgiler şifreli saklanır ve panelde bir daha gösterilmez.</p>
                    </section>
                @endif
            @endif

            @if (count($faqs))
                <section class="mt-12" aria-labelledby="sss-h2">
                    <h2 id="sss-h2" class="heading-2">Sık sorulanlar</h2>
                    <div class="mt-6">
                        @include('site.partials.faq', ['faqs' => $faqs])
                    </div>
                </section>
            @endif
        </div>

        <aside class="lg:col-span-4">
            <div class="card">
                <p class="font-semibold text-ink">{{ $name }} ile neler çalışır?</p>
                <p class="mt-2 muted">Stok, fiyat, sipariş ve ürün tarafında hangi işin desteklendiği kanalın sayfasında yazıyor.</p>
                <a href="{{ route('site.channel', $channel['code']) }}" class="link mt-3 inline-block">{{ $name }} entegrasyonu →</a>
            </div>
        </aside>
    </div>

    @include('site.partials.cta')
@endsection
