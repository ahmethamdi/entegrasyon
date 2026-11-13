<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import WriteGuard from '../../Components/WriteGuard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, k, useI18n } from '../../lib/i18n';
import { usePermissions } from '../../lib/permissions.js';

const props = defineProps({
    connections: { type: Array, default: () => [] },
});

const page = usePage();
const { t } = useI18n();
// S7 · kanal bağlama yazma işidir.
const { can } = usePermissions();

const flashSuccess = computed(() => page.props.flash?.success);
const flashWarning = computed(() => page.props.flash?.warning);

/**
 * Sağlıksız bağlantı üstte: kullanıcının ilgilenmesi gereken satır o.
 */
const sorted = computed(() =>
    [...props.connections].sort((a, b) => {
        const rank = (c) => (c.health === 'healthy' ? 1 : 0);
        return rank(a) - rank(b);
    }),
);

const healthLabels = {
    healthy: k('Cevap veriyor'),
    unhealthy: k('Cevap vermiyor'),
    unknown: k('Denenmedi'),
};

/* Bağlantı durumu — veritabanındaki ham değer ekrana basılmaz. */
const statusLabels = {
    active: k('Etkin'),
    pending: k('Beklemede'),
    inactive: k('Kapalı'),
};

function healthClass(health) {
    if (health === 'healthy') return 'bg-emerald-50 text-emerald-800 border-emerald-200';
    if (health === 'unhealthy') return 'bg-red-50 text-red-800 border-red-200';
    return 'bg-stone-50 text-stone-600 border-stone-200';
}

/*
 * §25 · token rozeti renkleri.
 *
 * Palet SİSTEMİN kendi paletidir (emerald başarı · amber uyarı · red
 * hata); marka turuncusu KULLANILMAZ çünkü bu panelde renkli yüzey her
 * zaman "durum" demektir ve marka tonu durum sanılırdı.
 *
 * `expired` KIRMIZIDIR çünkü satıcının BİR ŞEY YAPMASI gerekir ve
 * kendiliğinden düzelmez; `expiring` amber çünkü bu bir uyarıdır, henüz
 * arıza değil.
 */
function tokenClass(status) {
    if (status === 'expired') return 'bg-red-50 text-red-800 border-red-200';
    if (status === 'expiring') return 'bg-amber-50 text-amber-900 border-amber-300';
    return 'bg-emerald-50 text-emerald-800 border-emerald-200';
}

/*
 * Rozetin üstüne gelince TAM TARİH görünür.
 *
 * Rozet yalnızca "yakında dolacak" der; "ne zaman" sorusu satıcının
 * planlama sorusudur ve rozete sığmaz. Tarih PROP'TAN gelir ve
 * `toIso8601String()` ile gönderilir — ham kolon metni gönderilseydi
 * tarayıcı onu YEREL saat sanardı (onay ekranında ölçülen tuzak).
 */
function tokenTitle(connection) {
    if (!connection.tokenExpiresAt) return '';

    return t('Yetki bitiş: :date', { date: formatDate(connection.tokenExpiresAt) });
}

/** Yetenekler tip sisteminden gelir; kanal adı kontrol edilmez. */
const capabilityLabels = {
    catalog: k('Ürün'),
    // ⚠️ BU SATIR EKSİKTİ ve `?? key` yedeği yüzünden HATA VERMEDİ:
    // rozet ham anahtarı (`catalog_import`) İngilizce ve alt çizgili
    // olarak bastı. Yedek olmasaydı boş görünürdü — ikisi de sessiz.
    // Gerçek tarayıcı çalıştırmasında bulundu.
    catalog_import: k('İçe aktarma'),
    inventory: k('Stok'),
    pricing: k('Fiyat'),
    orders: k('Sipariş'),
    taxonomy: k('Kategori'),
    approval: k('Onay'),
    fulfillment: k('Kargo'),
    // S4 · müşteri hizmeti yetenekleri — eksikken ham anahtar basılıyordu
    // ("returns · return_decisions · questions"), 13 Kas demo gezisinde görüldü.
    returns: k('İadeler'),
    return_decisions: k('İade kararı'),
    questions: k('Müşteri soruları'),
};

function activeCapabilities(capabilities) {
    return Object.entries(capabilities ?? {})
        .filter(([, enabled]) => enabled)
        .map(([key]) => (capabilityLabels[key] ? t(capabilityLabels[key]) : key));
}

/*
 * Sağlık kontrolü bir AĞ ÇAĞRISIDIR ve saniyeler sürebilir (kanal
 * yavaşsa daha da uzun). Bekleme durumu gösterilmezse düğme tıklamaya
 * tepkisiz görünür ve kullanıcı tekrar tekrar basar; her basış kanala
 * YENİ bir istek gönderir ve hız sınırı kotasını boşa harcar.
 */
const checking = ref(null);

// Çok depolu Shopify mağazasında stoğun yazılacağı depo. Varsayılan SEÇİLMEZ:
// yanlış depo stoğu yanlış yere yazar (ShopifyAdapter::healthCheck).
const chosenLocation = ref({});
const savingLocation = ref(null);

function saveLocation(id) {
    const location = chosenLocation.value[id];
    if (!location || savingLocation.value !== null) return;

    savingLocation.value = id;

    router.post(`/channels/${id}/shopify/location`, { location }, {
        preserveScroll: true,
        onFinish: () => {
            savingLocation.value = null;
        },
    });
}

// Yeniden izin yönlendirmesi POST'tur (GET ön yüklemesi el sıkışmayı
// habersiz başlatırdı). Sunucu Inertia isteğine `Inertia::location` ile
// kanalın izin ekranına tam sayfa yönlendirme döner.
const authorizing = ref(null);

function authorize(connection) {
    if (authorizing.value !== null) return;

    authorizing.value = connection.id;

    router.post(connection.authorizeUrl, {}, {
        onFinish: () => {
            authorizing.value = null;
        },
    });
}

function recheck(id) {
    if (checking.value !== null) return;

    checking.value = id;

    router.post(`/channels/${id}/health`, {}, {
        preserveScroll: true,
        onFinish: () => {
            checking.value = null;
        },
    });
}

/*
 * ADR-010 · YAZMA İZİNLERİ — "bağlı" ile "kanala yazabilir" ayrı sorulardır.
 * Kimlik doğrulaması hiçbir izni açmaz; her biri burada açık bir eylemle
 * açılır. Doğrulaması bitmemiş kanalda (`writesLocked`) açma düğmesi
 * çizilmez; sunucu da reddeder.
 */
const writeLabels = {
    product_publish: k('Ürün yayını'),
    price_sync: k('Fiyat gönderimi'),
    stock_sync: k('Stok gönderimi'),
    // S4 · müşteri hizmeti yazmaları — senkrondan ayrı izinler.
    return_decision: k('İade kararı'),
    question_answer: k('Soru cevabı'),
};

const switching = ref(null);

function setWrite(connection, write, enabled) {
    if (switching.value !== null) return;

    switching.value = `${connection.id}:${write}`;

    router.put(`/channels/${connection.id}/outbound`, { write, enabled }, {
        preserveScroll: true,
        onFinish: () => {
            switching.value = null;
        },
    });
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(intlLocale());
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Mağazam')" :title="t('Kanallarım')">
            <template v-if="can('channels.manage')" #actions>
                <Link
                    href="/channels/create"
                    class="rounded-[10px] bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700"
                >
                    {{ t('Mağaza bağla') }}
                </Link>
            </template>
        </PageHeader>
        <WriteGuard permission="channels.manage">

            <div
                v-if="flashSuccess"
                class="mt-6 rounded-[14px] border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
            >
                {{ flashSuccess }}
            </div>

            <div
                v-if="flashWarning"
                class="mt-6 rounded-[12px] border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                {{ flashWarning }}
            </div>

            <div v-if="!sorted.length" class="mt-10 rounded-[14px] border border-dashed border-stone-300 p-10 text-center">
                <p class="text-sm text-stone-600">
                    {{ t('Henüz bağlı mağaza yok. Senkron için en az bir kanal gerekiyor.') }}
                </p>
                <Link href="/channels/create" class="mt-3 inline-block text-sm font-medium text-stone-900 underline">
                    {{ t('İlk mağazayı bağla') }}
                </Link>
            </div>

            <div v-else class="mt-6 space-y-4">
                <article
                    v-for="connection in sorted"
                    :key="connection.id"
                    class="rounded-[14px] border border-stone-200 bg-white p-5"
                >
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex min-w-0 items-start gap-3.5">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-[10px] border border-stone-200 bg-stone-50">
                            <img v-if="connection.logo" :src="connection.logo" :alt="t(':channel logosu', { channel: connection.channel })" class="size-6 object-contain">
                            <span v-else class="text-sm font-semibold text-stone-500">{{ connection.channel?.charAt(0) }}</span>
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-base font-semibold text-stone-900">
                                    {{ connection.label }}
                                </h2>
                                <span
                                    class="whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-medium"
                                    :class="healthClass(connection.health)"
                                >
                                    {{ healthLabels[connection.health] ? t(healthLabels[connection.health]) : connection.health }}
                                </span>

                                <!--
                                    §25 · TOKEN ROZETİ — sağlıktan AYRI bir
                                    soru. `health` kanalın SON cevabını
                                    taşır; bugün cevap veren bir bağlantının
                                    token'ı yarın ölebilir ve o an sağlık
                                    rozeti DEĞİŞMEZ.

                                    Süresiz anahtar taşıyan kanalda (Woo,
                                    Trendyol, Shopify) rozet HİÇ ÇİZİLMEZ:
                                    "geçerli" demek satıcıya orada izlenecek
                                    bir ömür olduğunu düşündürürdü.
                                -->
                                <span
                                    v-if="connection.tokenStatus"
                                    class="whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-medium"
                                    :class="tokenClass(connection.tokenStatus)"
                                    :title="tokenTitle(connection)"
                                >
                                    {{ connection.tokenStatusLabel }}
                                </span>
                            </div>

                            <p class="mt-0.5 truncate text-[13px] text-stone-600">
                                {{ connection.channel }} · <span class="font-mono text-xs text-stone-500">{{ connection.account }}</span>
                            </p>
                            <p v-if="connection.priceRule" class="mt-1 text-xs text-stone-600">
                                {{ t('Fiyat') }}: {{ connection.priceRule }}
                            </p>
                            <details v-if="connection.works?.length" class="mt-2 text-xs text-stone-600">
                                <summary class="cursor-pointer select-none text-stone-700">{{ t('Bu kanalda çalışanlar') }}</summary>
                                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                    <li v-for="item in connection.works" :key="item">{{ item }}</li>
                                </ul>
                            </details>
                        </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <Link
                                v-if="connection.capabilities?.pricing"
                                :href="`/channels/${connection.id}/pricing`"
                                class="rounded-[10px] border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100"
                            >
                                {{ t('Fiyat kuralı') }}
                            </Link>
                            <Link
                                v-if="connection.hasSettings"
                                :href="`/channels/${connection.id}/settings`"
                                class="rounded-[10px] border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100"
                            >
                                {{ t('Ayarlar') }}
                            </Link>

                            <!--
                                İzin yoksa ya da süresi bittiyse "Tekrar dene"
                                aynı yetkisiz isteği tekrarlar; satıcıyı kanalın
                                izin ekranına götüren düğme çıkar.
                            -->
                            <button
                                v-if="connection.authorizeUrl"
                                type="button"
                                class="rounded-[10px] bg-stone-900 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="authorizing !== null"
                                @click="authorize(connection)"
                            >
                                {{ authorizing === connection.id ? t('Yönlendiriliyor…') : t('İzin ver') }}
                            </button>
                            <button
                                v-else
                                type="button"
                                class="rounded-[10px] border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="checking !== null"
                                @click="recheck(connection.id)"
                            >
                                {{ checking === connection.id ? t('Kontrol ediliyor…') : t('Tekrar dene') }}
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="connection.settingsMissing > 0 && !connection.authorizeUrl"
                        class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-[10px] border border-amber-200 bg-amber-50 px-3 py-2"
                    >
                        <p class="text-sm text-amber-900">
                            {{ t('İlan açabilmek için :count ayar eksik.', { count: connection.settingsMissing }) }}
                        </p>
                        <Link
                            :href="`/channels/${connection.id}/settings`"
                            class="text-sm font-medium text-amber-900 underline"
                        >
                            {{ t('Ayarları tamamla') }}
                        </Link>
                    </div>

                    <!--
                        Sağlıksızlık gerekçesi GİZLENMEZ: kullanıcı anahtarı mı
                        yanlış girdi, mağaza mı kapalı — ancak bu metni görerek
                        ayırt edebilir.
                    -->
                    <p
                        v-if="connection.lastError"
                        class="mt-3 rounded bg-red-50 px-3 py-2 font-mono text-xs text-red-900"
                    >
                        {{ t(connection.lastError) }}
                    </p>

                    <div
                        v-if="connection.locationChoices?.length"
                        class="mt-3 rounded-[10px] border border-amber-200 bg-amber-50 p-3"
                    >
                        <label :for="`depo-${connection.id}`" class="block text-sm font-medium text-stone-900">
                            {{ t('Stok hangi depoya yazılsın?') }}
                        </label>
                        <p class="mt-0.5 text-xs text-stone-600">
                            {{ t('Mağazanda birden fazla depo var. Seçtiğin depodaki stok 34Pazar\'la eşitlenir.') }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <select
                                :id="`depo-${connection.id}`"
                                v-model="chosenLocation[connection.id]"
                                class="min-w-0 flex-1 rounded-[10px] border border-stone-300 px-2 py-1.5 text-sm"
                            >
                                <option :value="undefined" disabled>{{ t('Depo seç…') }}</option>
                                <option v-for="location in connection.locationChoices" :key="location.id" :value="location.id">
                                    {{ location.name }}
                                </option>
                            </select>
                            <button
                                type="button"
                                class="rounded-[10px] bg-stone-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                                :disabled="!chosenLocation[connection.id] || savingLocation !== null"
                                @click="saveLocation(connection.id)"
                            >
                                {{ savingLocation === connection.id ? t('Kaydediliyor…') : t('Kaydet') }}
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 border-t border-stone-100 pt-4">
                        <p class="text-[13px] font-medium text-stone-700">{{ t('Kanala yazma izinleri') }}</p>
                        <!--
                            Anahtar (switch) görünümü: önceki yeşil kutular beş
                            izni beş ayrı uyarı gibi okutuyordu. Durum hem
                            anahtarın konumu hem metinle ("Açık/Kapalı") verilir.
                        -->
                        <ul class="mt-2 grid gap-x-6 sm:grid-cols-2 lg:grid-cols-3">
                            <li
                                v-for="(label, write) in writeLabels"
                                :key="write"
                                class="flex items-center justify-between gap-3 border-b border-stone-100 py-2.5 text-sm"
                            >
                                <span class="min-w-0">
                                    <span class="text-stone-900">{{ t(label) }}</span>
                                    <span class="ml-1.5 text-xs" :class="connection.writes?.[write] ? 'text-emerald-700' : 'text-stone-500'">
                                        {{ switching === `${connection.id}:${write}` ? t('Kaydediliyor…') : (connection.writes?.[write] ? t('Açık') : t('Kapalı')) }}
                                    </span>
                                </span>
                                <button
                                    v-if="connection.writes?.[write] || !connection.writesLocked"
                                    type="button"
                                    role="switch"
                                    :aria-checked="connection.writes?.[write] ? 'true' : 'false'"
                                    :aria-label="t(label)"
                                    :title="connection.writes?.[write] ? t('Kapat') : t('Aç')"
                                    class="relative inline-flex h-6 w-10 shrink-0 items-center rounded-full transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50"
                                    :class="connection.writes?.[write] ? 'bg-emerald-600' : 'bg-stone-300'"
                                    :disabled="switching !== null"
                                    @click="setWrite(connection, write, !connection.writes?.[write])"
                                >
                                    <span class="inline-block size-[18px] rounded-full bg-white transition" :class="connection.writes?.[write] ? 'translate-x-[19px]' : 'translate-x-[3px]'" aria-hidden="true" />
                                </button>
                            </li>
                        </ul>
                        <p v-if="connection.writesLocked" class="mt-2 text-xs text-stone-600">
                            {{ t('Bu kanalın canlı doğrulaması tamamlanmadı; kanala yazma izinleri açılamaz.') }}
                        </p>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-4 border-t border-stone-100 pt-4 text-xs sm:grid-cols-3">
                        <div>
                            <dt class="text-stone-500">{{ t('Durum') }}</dt>
                            <dd class="mt-0.5 font-medium text-stone-900">{{ $page.props.demo ? t('Örnek bağlantı (demo)') : statusLabels[connection.status] ? t(statusLabels[connection.status]) : connection.status }}</dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">{{ t('Son sağlıklı') }}</dt>
                            <dd class="mt-0.5 text-stone-700">{{ formatDate(connection.lastHealthyAt) }}</dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">{{ t('Yetenekler') }}</dt>
                            <dd class="mt-0.5 text-stone-700">
                                {{ activeCapabilities(connection.capabilities).join(' · ') || '—' }}
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>
        </WriteGuard>
    </PanelLayout>
</template>
