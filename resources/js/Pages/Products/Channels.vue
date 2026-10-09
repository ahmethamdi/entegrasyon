<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, k, useI18n } from '../../lib/i18n';

const props = defineProps({
    product: { type: Object, required: true },
    channels: { type: Array, default: () => [] },
    images: { type: Array, default: () => [] },
    imageChannels: { type: Array, default: () => [] },
});

/** Görsel–kanal seçimi kaydedilirken o kutu kilitlenir. */
const savingImage = ref(null);

function goesTo(image, code) {
    return image.url !== null && !image.excludedChannels.includes(code);
}

function toggleImage(image, code) {
    savingImage.value = `${image.id}:${code}`;

    router.post(
        `/products/${props.product.id}/images/${image.id}/channels`,
        { channel_type_code: code, excluded: goesTo(image, code) },
        {
            preserveScroll: true,
            onFinish: () => {
                savingImage.value = null;
            },
        },
    );
}

/**
 * KANAL BAŞINA ÖZET — sınırı aşan görsel SESSİZCE düşmez.
 *
 * Satıcı "Trendyol'a ilk 8 görsel gider, 3'ü dışarıda" bilgisini görmezse
 * en iyi fotoğrafının neden kanalda olmadığını anlayamaz.
 */
const imageSummary = computed(() =>
    props.imageChannels.map((channel) => {
        const count = props.images.filter((image) => goesTo(image, channel.code)).length;
        const overflow = channel.maxImages !== null ? Math.max(0, count - channel.maxImages) : 0;

        return { ...channel, count, overflow };
    }),
);

const insecureCount = computed(() => props.images.filter((image) => image.url === null).length);

const { t } = useI18n();
const page = usePage();

const flashSuccess = computed(() => page.props.flash?.success);

/**
 * ENGELLENEN GÖNDERİM UYARIDIR, BAŞARI DEĞİL.
 *
 * Yeşil bir "gönderiliyor" kutusu, ürün hiç gönderilmemişken satıcıyı
 * her şeyin yolunda olduğuna inandırırdı.
 */
const flashWarning = computed(() => page.props.flash?.warning);
const connectionError = computed(() => page.props.errors?.connection_id);

/** Gönderim sürerken butonu kilitle: çift tıklama iki istek atardı. */
const sending = ref(null);

/**
 * ROZET SIRASI: kalıcı hata > geçici hata > bekliyor > senkron.
 *
 * `error_permanent` kullanıcı müdahalesi bekler; "bekliyor" demek satıcıyı
 * kendiliğinden düzelecek sanmaya iter ve o satıra hiç bakmaz.
 */
const statusLabels = {
    error_permanent: k('Kalıcı hata'),
    error_transient: k('Geçici hata'),
    blocked: k('Engellendi'),
    pending: k('Bekliyor'),
    syncing: k('Gönderiliyor'),
    synced: k('Senkron'),
};

function statusClass(status) {
    if (status === 'error_permanent') return 'bg-red-50 text-red-800 border-red-200';
    if (status === 'error_transient') return 'bg-amber-50 text-amber-900 border-amber-300';
    if (status === 'blocked') return 'bg-amber-50 text-amber-900 border-amber-300';
    if (status === 'synced') return 'bg-emerald-50 text-emerald-800 border-emerald-200';
    return 'bg-stone-50 text-stone-600 border-stone-200';
}

/**
 * YAŞAM DÖNGÜSÜ SENKRON DURUMUNU EZER.
 *
 * Ön koşul engeli ve kanal onayı, senkron durumundan daha belirleyicidir:
 * "bekliyor" demek satıcıyı kendiliğinden düzelecek sanmaya iter, oysa
 * engellenmiş satır KULLANICI müdahalesi bekler ve onay bekleyen satır
 * KANAL'ı bekler. İkisi de "senkron sorunu" değildir.
 */
const lifecycleLabels = {
    blocked: k('Ön koşul eksik'),
    pending_approval: k('Kanal onayı bekliyor'),
    rejected: k('Kanal reddetti'),
};

/** Gönderilmemiş kanalda rozet yok — durumu "henüz gönderilmedi". */
function statusLabel(channel) {
    if (!channel.published) return k('Gönderilmedi');

    // Yaşam döngüsü önce: engel ve onay senkron durumundan önce gelir.
    if (lifecycleLabels[channel.lifecycle]) return lifecycleLabels[channel.lifecycle];

    return statusLabels[channel.syncStatus] ?? channel.syncStatus ?? k('Bekliyor');
}

/* Kanaldaki satış durumu — ham kod (`live`) satıcıya gösterilmez. */
const lifecycleTexts = {
    live: k('Satışta'),
    pending_approval: k('Kanal onayı bekliyor'),
    rejected: k('Kanal reddetti'),
    blocked: k('Ön koşul eksik'),
    delisted: k('Satıştan kaldırıldı'),
};

function badgeClass(channel) {
    if (!channel.published) return 'border-stone-200 bg-stone-50 text-stone-600';
    if (channel.lifecycle === 'rejected') return 'bg-red-50 text-red-800 border-red-200';
    if (channel.lifecycle === 'blocked') return 'bg-amber-50 text-amber-900 border-amber-300';
    if (channel.lifecycle === 'pending_approval') return 'bg-stone-50 text-stone-700 border-stone-300';

    return statusClass(channel.syncStatus);
}

/** Kalıcı hatalı kanal ÜSTTE: kullanıcının ilgilenmesi gereken satır o. */
const sorted = computed(() =>
    [...props.channels].sort((a, b) => {
        const rank = (c) => {
            if (!c.published) return 4;
            // Kullanıcı müdahalesi bekleyenler ÖNCE: red ve ön koşul
            // engeli kendiliğinden düzelmez.
            if (c.lifecycle === 'rejected') return 0;
            if (c.lifecycle === 'blocked') return 1;
            if (c.syncStatus === 'error_permanent') return 2;
            if (c.syncStatus === 'error_transient') return 3;
            return 5;
        };
        return rank(a) - rank(b);
    }),
);

/**
 * KANAL FİYATI — varyant × kanal başına, GÖNDERİLEN fiyat.
 *
 * Alan boşsa ürünün fiyatı gider. Kanalın para birimi ürününkinden farklıysa
 * (USD Etsy mağazası, TL ürün) fiyat girilmeden HİÇ gönderilmez; satıcıya
 * bu satırda söylenir.
 */
const priceInputs = ref(Object.fromEntries(
    props.channels.flatMap((channel) => (channel.prices ?? []).map((row) => [row.listingId, row.channelPrice ?? ''])),
));
const savingPrice = ref(null);
const priceErrors = computed(() => page.props.errors ?? {});

function money(amount, currency) {
    if (amount === null || amount === undefined || amount === '') return '—';
    if (!currency) return String(amount);

    try {
        return new Intl.NumberFormat(intlLocale(), { style: 'currency', currency }).format(Number(amount));
    } catch {
        return `${amount} ${currency}`;
    }
}

function savePrice(row, clear = false) {
    if (savingPrice.value !== null) return;

    savingPrice.value = row.listingId;

    router.put(
        `/products/${props.product.id}/listings/${row.listingId}/price`,
        { price: clear ? null : (priceInputs.value[row.listingId] === '' ? null : priceInputs.value[row.listingId]) },
        {
            preserveScroll: true,
            onSuccess: () => {
                if (clear) priceInputs.value[row.listingId] = '';
            },
            onFinish: () => {
                savingPrice.value = null;
            },
        },
    );
}

/** Gönderilmemiş kanalda: kanal başka birimle satıyorsa önceden söylenir. */
function currencyDiffers(channel) {
    return Boolean(channel.currency && props.product.currency && channel.currency !== props.product.currency.toUpperCase());
}

function send(connectionId) {
    sending.value = connectionId;

    router.post(
        `/products/${props.product.id}/channels`,
        { connection_id: connectionId },
        {
            preserveScroll: true,
            onFinish: () => {
                sending.value = null;
            },
        },
    );
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Ürün · hangi kanallarda')" :title="product.title">
            <template #actions>
                <Link
                    :href="`/products/${product.id}/edit`"
                    class="shrink-0 rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                    {{ t('Ürünü düzenle') }}
                </Link>
            </template>

            <template #toolbar>
                <p class="font-mono text-xs text-stone-500">
                    {{ product.sku }}
                </p>
            </template>
        </PageHeader>

        <div
            v-if="flashSuccess"
            class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ flashSuccess }}
        </div>

        <div
            v-if="flashWarning"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            {{ flashWarning }}
        </div>

        <div
            v-if="connectionError"
            class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
        >
            {{ connectionError }}
        </div>

        <!--
            GÖRSELLER (A15): her görsel varsayılan olarak her kanala gider;
            satıcı bir görseli belirli bir kanaldan çıkarabilir.
        -->
        <section v-if="imageChannels.length" class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-medium text-stone-900">{{ t('Görseller') }}</h2>

            <p v-if="!images.length" class="mt-2 text-sm text-amber-900">
                {{ t('Bu üründe görsel yok. Görselsiz ürünü çoğu pazaryeri kabul etmez; ürünü kanaldan içe aktararak görsellerini getirebilirsin.') }}
            </p>

            <template v-else>
                <ul class="mt-3 space-y-1 text-xs">
                    <li v-for="channel in imageSummary" :key="channel.code">
                        <span class="font-medium text-stone-900">{{ channel.name }}:</span>
                        <span v-if="channel.count === 0" class="text-red-800">
                            {{ t('hiç görsel gitmeyecek — ürün reddedilebilir.') }}
                        </span>
                        <span v-else-if="channel.overflow > 0" class="text-amber-900">
                            {{ t('ilk :max görsel gider, :overflow görsel dışarıda kalır.', { max: channel.maxImages, overflow: channel.overflow }) }}
                        </span>
                        <span v-else class="text-stone-600">{{ t(':count görsel gider.', { count: channel.count }) }}</span>
                    </li>
                    <li v-if="insecureCount" class="text-amber-900">
                        {{ t(':count görselin adresi HTTPS değil; hiçbir kanala gitmez.', { count: insecureCount }) }}
                    </li>
                </ul>

                <ul class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <li
                        v-for="(image, index) in images"
                        :key="image.id"
                        class="rounded-md border border-stone-200 p-2"
                    >
                        <img
                            v-if="image.url"
                            :src="image.url"
                            :alt="t('Görsel :number', { number: index + 1 })"
                            loading="lazy"
                            class="aspect-square w-full rounded object-cover"
                        >
                        <div
                            v-else
                            class="flex aspect-square w-full items-center justify-center rounded bg-stone-100 p-2 text-center text-[10px] text-stone-500"
                        >
                            {{ t('HTTPS değil') }}
                        </div>

                        <p class="mt-1 font-mono text-[10px] text-stone-500">
                            {{ t(':number. görsel', { number: index + 1 }) }}{{ image.imported ? ` · ${t('içe aktarıldı')}` : '' }}
                        </p>

                        <fieldset class="mt-2 space-y-1">
                            <legend class="sr-only">{{ t(':number. görselin gideceği kanallar', { number: index + 1 }) }}</legend>
                            <label
                                v-for="channel in imageChannels"
                                :key="channel.code"
                                class="flex items-center gap-2 text-xs text-stone-700"
                            >
                                <input
                                    type="checkbox"
                                    :checked="goesTo(image, channel.code)"
                                    :disabled="image.url === null || savingImage === `${image.id}:${channel.code}`"
                                    class="rounded border-stone-300"
                                    @change="toggleImage(image, channel.code)"
                                >
                                {{ channel.name }}
                            </label>
                        </fieldset>
                    </li>
                </ul>
            </template>
        </section>

        <!--
            Gönderilebilir kanal yoksa kullanıcıyı kanal bağlamaya yönlendir:
            "hiç kanal yok" mesajı tek başına ne yapacağını söylemiyor.
        -->
        <div
            v-if="!sorted.length"
            class="mt-10 rounded-lg border border-dashed border-stone-300 p-10 text-center"
        >
            <p class="text-sm text-stone-600">
                {{ t('Ürün gönderilebilecek aktif kanal yok. Kanalın sağlık kontrolünü geçmiş olması gerekiyor.') }}
            </p>
            <Link href="/channels" class="mt-3 inline-block text-sm font-medium text-stone-900 underline">
                {{ t('Kanallara git') }}
            </Link>
        </div>

        <div v-else class="mt-6 space-y-4">
            <article
                v-for="channel in sorted"
                :key="channel.connectionId"
                class="rounded-lg border border-stone-200 bg-white p-5"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="truncate text-sm font-medium text-stone-900">
                                {{ channel.label }}
                            </h2>
                            <span
                                class="whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-medium"
                                :class="badgeClass(channel)"
                            >
                                {{ t(statusLabel(channel)) }}
                            </span>
                            <span
                                v-if="channel.published && channel.pendingWork"
                                class="whitespace-nowrap rounded-full border border-stone-300 bg-white px-2.5 py-0.5 text-xs font-medium text-stone-600"
                            >
                                {{ t('Bekleyen iş') }}
                            </span>
                        </div>

                        <p class="mt-1 truncate font-mono text-xs text-stone-500">
                            {{ channel.channel }} · {{ channel.account }}
                        </p>
                    </div>

                    <button
                        type="button"
                        :disabled="sending === channel.connectionId"
                        class="shrink-0 rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="send(channel.connectionId)"
                    >
                        {{ channel.published ? t('Yeniden gönder') : t('Kanala gönder') }}
                    </button>
                </div>

                <!--
                    Hata gerekçesi GİZLENMEZ: kullanıcı ancak bu metni görerek
                    başlığı mı düzeltmesi gerektiğini anlayabilir.
                -->
                <p
                    v-if="channel.lastError"
                    class="mt-3 rounded bg-red-50 px-3 py-2 font-mono text-xs text-red-900"
                >
                    {{ t(channel.lastError) }}
                </p>

                <!--
                    Stok/fiyat: kanal toplu işte bu satırı REDDETTİ (sonuç
                    sonradan okunur). Gönderim hatasından ayrı: ürün kanalda,
                    ama stoğu ya da fiyatı uygulanmadı.
                -->
                <p
                    v-if="channel.stockPriceError"
                    class="mt-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-900"
                >
                    <span class="font-semibold">{{ t('Stok/fiyat kanalda uygulanmadı:') }}</span>
                    <span class="font-mono">{{ channel.stockPriceError }}</span>
                </p>

                <!--
                    RED SEBEBİ AYRI GÖSTERİLİR: senkron hatası "gönderemedik"
                    demektir, red ise "gönderdik ama kanal beğenmedi". İkisi
                    aynı kutuda birleştirilseydi satıcı hangisini
                    düzelteceğini bilemezdi.
                -->
                <p
                    v-if="channel.rejectionReason"
                    class="mt-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-900"
                >
                    {{ t('Kanal reddetti: :reason', { reason: channel.rejectionReason }) }}
                </p>

                <!-- KANAL FİYATI: varyant başına; boşsa ürün fiyatı gider. -->
                <section
                    v-if="channel.prices?.length"
                    class="mt-4 border-t border-stone-100 pt-4"
                >
                    <h3 class="text-xs font-medium text-stone-900">{{ t('Bu kanaldaki fiyat') }}</h3>
                    <p class="mt-0.5 text-xs text-stone-500">
                        {{ t('Boş bırakırsan ürünün fiyatı gider. Kanala özel fiyat girersen yalnız bu kanalda o fiyat kullanılır.') }}
                    </p>

                    <ul class="mt-3 space-y-3">
                        <li v-for="row in channel.prices" :key="row.listingId">
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-mono text-xs text-stone-500">{{ row.sku }}</p>
                                    <p class="text-xs text-stone-700">
                                        {{ t('Ürün fiyatı') }}: {{ money(row.variantPrice, row.variantCurrency) }}
                                    </p>
                                    <p
                                        v-if="row.channelPrice === null && row.outgoingPrice !== null && row.outgoingPrice !== row.variantPrice"
                                        class="text-xs text-stone-900"
                                    >
                                        {{ t('Fiyat kuralıyla kanala giden') }}: {{ money(row.outgoingPrice, row.outgoingCurrency) }}
                                    </p>
                                </div>

                                <label class="block">
                                    <span class="sr-only">{{ t(':sku için kanal fiyatı', { sku: row.sku ?? '' }) }}</span>
                                    <span class="flex items-center rounded-md border border-stone-300 bg-white focus-within:border-ring">
                                        <input
                                            v-model="priceInputs[row.listingId]"
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            inputmode="decimal"
                                            :placeholder="t('Kanal fiyatı')"
                                            class="w-32 rounded-l-md border-0 px-3 py-1.5 text-sm focus:outline-none"
                                        >
                                        <span class="px-2 font-mono text-xs text-stone-500">
                                            {{ row.channelPriceCurrency ?? channel.currency ?? row.variantCurrency ?? '' }}
                                        </span>
                                    </span>
                                </label>

                                <button
                                    type="button"
                                    :disabled="savingPrice !== null"
                                    class="rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="savePrice(row)"
                                >
                                    {{ savingPrice === row.listingId ? t('Kaydediliyor…') : t('Kaydet') }}
                                </button>
                                <button
                                    v-if="row.channelPrice !== null"
                                    type="button"
                                    :disabled="savingPrice !== null"
                                    class="text-sm text-stone-600 underline disabled:opacity-50"
                                    @click="savePrice(row, true)"
                                >
                                    {{ t('Kaldır') }}
                                </button>
                            </div>

                            <p v-if="row.floorViolation" class="mt-1 rounded bg-red-50 px-2 py-1 text-xs text-red-900">
                                {{ t(row.floorViolation) }}
                            </p>
                            <p v-if="row.needsChannelPrice" class="mt-1 rounded bg-red-50 px-2 py-1 text-xs text-red-900">
                                {{ t('Bu kanal :currency ile satıyor, ürünün fiyatı :product. Kanal fiyatı girilmeden fiyat gönderilmez.', { currency: channel.currency, product: row.variantCurrency }) }}
                            </p>
                            <p v-else-if="row.channelPrice !== null" class="mt-1 text-xs text-emerald-800">
                                {{ t('Bu kanalda :price kullanılıyor.', { price: money(row.channelPrice, row.channelPriceCurrency) }) }}
                            </p>
                        </li>
                    </ul>

                    <p v-if="priceErrors.price" class="mt-2 text-sm text-red-700">{{ priceErrors.price }}</p>
                </section>

                <p
                    v-else-if="!channel.published && currencyDiffers(channel)"
                    class="mt-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-900"
                >
                    {{ t('Bu kanal :currency ile satıyor, ürünün fiyatı :product. Önce kanala gönder (ilan fiyatsız açılmaz ve hata verir), sonra burada çıkan alana :currency fiyat gir ve Yeniden gönder düğmesine bas.', { currency: channel.currency, product: product.currency }) }}
                </p>

                <dl class="mt-4 grid grid-cols-2 gap-4 border-t border-stone-100 pt-4 text-xs sm:grid-cols-3">
                    <div>
                        <dt class="text-stone-500">{{ t('Kanaldaki kimlik') }}</dt>
                        <dd class="mt-0.5 font-mono text-stone-900">
                            {{ channel.externalId ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">{{ t('Yaşam döngüsü') }}</dt>
                        <dd class="mt-0.5 text-stone-700">{{ channel.lifecycle ? t(lifecycleTexts[channel.lifecycle] ?? channel.lifecycle) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">{{ t('Kanalda görüntüle') }}</dt>
                        <dd class="mt-0.5">
                            <a
                                v-if="channel.externalUrl"
                                :href="channel.externalUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-stone-900 underline"
                            >
                                {{ t('Aç') }}
                            </a>
                            <span v-else class="text-stone-700">—</span>
                        </dd>
                    </div>
                </dl>
            </article>
        </div>
    </PanelLayout>
</template>
