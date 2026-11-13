<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { money as formatMoney } from '../../lib/format.js';
import { intlLocale, k, useI18n } from '../../lib/i18n';

const props = defineProps({
    plans: { type: Array, default: () => [] },
    current: { type: Object, default: () => ({}) },
    usage: { type: Object, default: () => ({}) },
    billing: { type: Object, default: () => ({ provider: 'stripe' }) },
    paymentsEnabled: { type: Boolean, default: false },
    cardDeclineHint: { type: String, default: '' },
});

const { t } = useI18n();
const page = usePage();

const errors = computed(() => page.props.errors ?? {});
const flashSuccess = computed(() => page.props.flash?.success);

/*
 * SHOPIFY'DAN FATURALANAN SATICI (App Store kuralı 1.2.1): fiyat USD,
 * onay Shopify'ın sayfasında, ücret Shopify faturasında. Shopify Türk
 * lirasıyla fatura kesmez — TL fiyat burada gösterilmez.
 */
const viaShopify = computed(() => props.billing.provider === 'shopify');

const statusLabels = {
    active: k('aktif'),
    trialing: k('deneme'),
    past_due: k('ödeme bekliyor'),
    cancelled: k('iptal edildi'),
    expired: k('süresi doldu'),
};

function price(plan) {
    return viaShopify.value ? plan.shopifyPrice : plan.price;
}

function priceText(plan) {
    return viaShopify.value ? formatMoney(plan.shopifyPrice ?? 0, 'USD') : formatMoney(plan.price, plan.currency);
}

/*
 * SINIRSIZ `null` TAŞINIR, sıfır DEĞİL. Sıfır gösterilseydi sınırsız
 * plan en kısıtlı plan gibi görünürdü.
 */
function limitText(limit) {
    return limit === null || limit === undefined ? t('sınırsız') : Number(limit).toLocaleString(intlLocale());
}

/* Kullanım oranı — sınırsızda çubuk gösterilmez. */
function ratio(row) {
    if (row.limit === null || row.limit === undefined || row.limit === 0) {
        return null;
    }

    return Math.min(100, Math.round((row.current / row.limit) * 100));
}

function formatDate(iso) {
    if (!iso) return '—';

    return new Intl.DateTimeFormat(intlLocale(), {
        day: '2-digit', month: 'long', year: 'numeric',
    }).format(new Date(iso));
}

/*
 * Ödeme/onay sayfası SUNUCUDA açılır ve ardından YÖNLENDİRİLİR; arada ağ
 * gecikmesi vardır. Bekleme durumu gösterilmezse düğme tepkisiz görünür
 * ve kullanıcı tekrar basar — her basış YENİ bir oturum yaratır.
 * Yönlendirme başladıktan sonra da düğme kilitli kalır.
 */
const buying = ref(null);

/*
 * ÖDEME ÖNCESİ ONAY (mesafeli hizmet sözleşmesi madde 9, cayma hakkı
 * istisnası). Plan düğmesi doğrudan ödemeye GİTMEZ; önce bu kutu açılır.
 * Sözleşme onayı zorunlu, cayma hakkından vazgeçme İSTEĞE BAĞLI.
 */
const selected = ref(null);
const acceptTerms = ref(false);
const waiveWithdrawal = ref(false);

function choose(plan) {
    selected.value = plan;
    acceptTerms.value = false;
    waiveWithdrawal.value = false;
}

function buy() {
    if (buying.value !== null || selected.value === null || !acceptTerms.value) return;

    buying.value = selected.value.code;

    router.post('/billing/checkout', {
        plan_code: selected.value.code,
        accept_terms: acceptTerms.value,
        waive_withdrawal: waiveWithdrawal.value,
    }, {
        onError: () => {
            buying.value = null;
        },
    });
}

/* Ücretsize dönüş — self-servis (kural 1.2.3); onay kutusu gerekmez. */
function downgrade(plan) {
    if (buying.value !== null) return;
    const question = viaShopify.value
        ? t('Shopify aboneliğin iptal edilip ücretsiz plana geçilecek. Emin misin?')
        : t('Aboneliğin ödenmiş dönemin sonunda bitecek ve ücretsiz plana geçeceksin; verilerin silinmez. Emin misin?');
    if (!window.confirm(question)) return;

    buying.value = plan.code;
    router.post('/billing/checkout', { plan_code: plan.code }, {
        onFinish: () => {
            buying.value = null;
        },
    });
}

/*
 * S14 · KART REDDİ REHBERİ — ödeme sayfasından vazgeçip dönen (`?durum=iptal`),
 * ödemesi alınamayan (`past_due`) ya da ödemeye geçmek üzere olan satıcıya.
 * Türk bankaları yurt dışı satıcıyı ilk denemede sık reddeder.
 */
const returnedFromCheckout = computed(() => new URLSearchParams(page.url.split('?')[1] ?? '').get('durum') === 'iptal');
const showCardHint = computed(() => !viaShopify.value && props.cardDeclineHint !== ''
    && (returnedFromCheckout.value || props.current.status === 'past_due'));

const usageRows = computed(() => Object.entries(props.usage).map(([key, row]) => ({ key, ...row })));

const currentPlan = computed(() => props.plans.find((p) => p.code === props.current.planCode) ?? null);

/* Plan kartındaki özellikler — sınırlar `limits`ten, null = sınırsız. */
const planFeatures = [
    ['products', k(':count ürün')],
    ['channels', k(':count kanal')],
    ['users', k(':count kullanıcı')],
    ['invoices', k('Ayda :count e-fatura')],
];
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Abonelik')" :title="t('Plan ve kullanım')" />

        <div
            v-if="flashSuccess"
            class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
            role="status"
        >
            {{ flashSuccess }}
        </div>

        <!-- Ödeme altyapısı yoksa SÖYLENİR: sessizce başarısız olan bir
             düğme, sebebi hiç anlaşılmayan bir hatadır. -->
        <div
            v-if="!paymentsEnabled"
            class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4"
        >
            <p class="text-sm font-medium text-amber-900">
                {{ t('Ödeme altyapısı henüz yapılandırılmadı.') }}
            </p>
            <p class="mt-1 text-xs leading-relaxed text-amber-800">
                {{ t('Planlar görüntülenebilir ama satın alma yapılamaz.') }}
            </p>
        </div>

        <!-- Shopify faturası — satıcı nerede ödeyeceğini bilmeli. -->
        <div
            v-if="viaShopify"
            class="mt-6 rounded-lg border border-stone-200 bg-white p-4 text-sm text-stone-700"
        >
            {{ t('Ödemeler :shop mağazanın Shopify faturasına eklenir. Fiyatlar USD\'dir; onay Shopify\'da verilir.', { shop: billing.shop }) }}
        </div>

        <div
            v-if="showCardHint"
            class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4"
            role="note"
        >
            <p class="text-sm font-medium text-amber-900">
                {{ current.status === 'past_due' ? t('Son ödemen alınamadı.') : t('Ödeme tamamlanmadı.') }}
            </p>
            <p class="mt-1 text-xs leading-relaxed text-amber-800">{{ t(cardDeclineHint) }}</p>
        </div>

        <p v-if="errors.plan_code || errors.accept_terms" class="mt-6 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
            {{ errors.plan_code ?? errors.accept_terms }}
        </p>

        <!-- Mevcut plan — Yön A (13 Kas 2026) -->
        <section class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-[14px] border border-stone-200 bg-white p-5 sm:p-6" :aria-label="t('Mevcut planın')">
            <div class="min-w-0">
                <p class="text-[13px] text-stone-600">{{ t('Mevcut planın') }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-2.5">
                    <p class="text-2xl font-bold tracking-tight text-stone-900">{{ current.planName ? t(current.planName) : '—' }}</p>
                    <span
                        v-if="current.status"
                        class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="current.status === 'active' || current.status === 'trialing' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'"
                    >
                        {{ t(statusLabels[current.status] ?? current.status) }}
                    </span>
                    <span v-else class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-600 first-letter:uppercase">{{ t('abonelik yok') }}</span>
                </div>
                <p v-if="current.cancelAt" class="mt-1.5 text-[13px] text-amber-800">
                    {{ t('Aboneliğin :date tarihinde bitecek; sonra ücretsiz plana geçersin. Verilerin silinmez.', { date: formatDate(current.cancelAt) }) }}
                </p>
                <p v-else-if="current.currentPeriodEnd" class="mt-1.5 text-[13px] text-stone-600">
                    {{ t('Yenileme tarihi: :date', { date: formatDate(current.currentPeriodEnd) }) }}
                </p>
            </div>
            <div class="flex items-center gap-4">
                <p v-if="currentPlan && Number(price(currentPlan) ?? 0) > 0" class="text-right">
                    <span class="block text-2xl font-bold tabular-nums text-stone-900">{{ priceText(currentPlan) }}</span>
                    <span class="text-[13px] text-stone-500">{{ t('/ ay') }}</span>
                </p>
                <a href="#planlar" class="inline-flex h-11 items-center rounded-[10px] border border-stone-300 px-4 text-sm font-semibold text-stone-900 transition hover:bg-stone-50">{{ t('Planı değiştir') }}</a>
            </div>
        </section>

        <!-- Kullanım — DEĞER VE LİMİT BİRLİKTE -->
        <section class="mt-6">
            <h2 class="text-base font-semibold text-stone-900">{{ t('Kullanımın') }}</h2>

            <dl class="mt-3 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                <div v-for="row in usageRows" :key="row.key" class="flex flex-col gap-1.5 rounded-[14px] border border-stone-200 bg-white p-4 sm:p-5">
                    <dt class="text-[13px] text-stone-600 first-letter:uppercase">{{ t(row.label) }}</dt>
                    <dd class="text-xl font-bold tabular-nums tracking-tight text-stone-900 sm:text-[26px]">
                        {{ Number(row.current).toLocaleString(intlLocale()) }}
                        <span class="text-sm font-normal text-stone-500">/ {{ limitText(row.limit) }}</span>
                    </dd>
                    <dd v-if="ratio(row) !== null">
                        <div class="h-2 overflow-hidden rounded-full bg-stone-100">
                            <div
                                class="h-full rounded-full transition-all"
                                :class="ratio(row) >= 100 ? 'bg-red-500' : ratio(row) >= 80 ? 'bg-amber-500' : 'bg-emerald-500'"
                                :style="{ width: `${Math.max(ratio(row), 2)}%` }"
                            />
                        </div>
                        <p class="mt-1.5 text-xs text-stone-500">{{ t('%:pct kullanıldı', { pct: ratio(row) }) }}</p>
                    </dd>
                    <dd v-else class="text-xs text-stone-500">{{ t('Sınır yok') }}</dd>
                </div>
            </dl>
        </section>

        <!-- Planlar -->
        <section id="planlar" class="mt-8 scroll-mt-6">
            <h2 class="text-base font-semibold text-stone-900">{{ t('Planlar') }}</h2>

            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="plan in plans"
                    :key="plan.code"
                    class="relative flex flex-col rounded-[14px] border bg-white p-5"
                    :class="plan.code === current.planCode ? 'border-2 border-brand-600' : 'border-stone-200'"
                >
                    <span v-if="plan.code === current.planCode" class="absolute -top-3 left-5 rounded-full bg-brand-600 px-2.5 py-0.5 text-[11px] font-semibold text-white">{{ t('Mevcut planın') }}</span>
                    <p class="text-sm font-semibold text-stone-900">{{ t(plan.name) }}</p>

                    <p class="mt-2 tabular-nums text-stone-900">
                        <template v-if="Number(price(plan) ?? 0) > 0">
                            <span class="text-[28px] font-bold tracking-tight">{{ priceText(plan) }}</span>
                            <span class="text-sm text-stone-500"> {{ t('/ ay') }}</span>
                        </template>
                        <span v-else class="text-[28px] font-bold tracking-tight">{{ t('Ücretsiz') }}</span>
                    </p>

                    <ul class="mt-4 flex-1 space-y-2 text-[13px] text-stone-700">
                        <li v-for="[key, label] in planFeatures" :key="key" class="flex items-start gap-2">
                            <svg class="mt-0.5 size-4 shrink-0 text-emerald-600" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 10.5l3 3 7-7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            {{ t(label, { count: limitText(plan.limits[key]) }) }}
                        </li>
                    </ul>

                    <p
                        v-if="plan.code === current.planCode"
                        class="mt-5 rounded-[10px] bg-stone-100 py-2.5 text-center text-sm font-medium text-stone-600"
                    >
                        {{ t('Kullanıyorsun') }}
                    </p>
                    <button
                        v-else-if="Number(price(plan)) > 0"
                        type="button"
                        class="mt-5 rounded-[10px] py-2.5 text-sm font-semibold transition disabled:cursor-not-allowed disabled:bg-stone-300 disabled:text-white"
                        :class="Number(price(plan)) > Number(price(currentPlan ?? {}) ?? 0) ? 'bg-brand-600 text-white hover:bg-brand-700' : 'border border-stone-300 text-stone-900 hover:bg-stone-50'"
                        :disabled="!paymentsEnabled || buying !== null"
                        @click="choose(plan)"
                    >
                        {{ buying === plan.code ? t('Yönlendiriliyor…') : t('Bu plana geç') }}
                    </button>
                    <button
                        v-else-if="billing.canDowngrade"
                        type="button"
                        class="mt-5 rounded-[10px] border border-stone-300 py-2.5 text-sm font-medium text-stone-700 transition hover:bg-stone-100 disabled:opacity-50"
                        :disabled="buying !== null"
                        @click="downgrade(plan)"
                    >
                        {{ t('Ücretsiz plana geç') }}
                    </button>
                    <p v-else class="mt-5 py-2.5 text-center text-sm text-stone-500">{{ t('Her zaman ücretsiz') }}</p>
                </div>
            </div>

            <!-- Ödeme öncesi onay — plan seçilince açılır. -->
            <div
                v-if="selected"
                class="mt-6 rounded-lg border border-stone-300 bg-white p-5"
                role="region"
                :aria-label="t('Ödeme onayı')"
            >
                <p class="font-medium text-stone-900">
                    {{ t(selected.name) }} · {{ priceText(selected) }} {{ t('/ ay') }}
                </p>
                <p class="mt-1 text-sm text-stone-600">
                    {{ viaShopify
                        ? t('Abonelik her ay yenilenir ve Shopify faturana eklenir; istediğin zaman iptal edebilirsin.')
                        : t('Abonelik her ay yenilenir, istediğin zaman iptal edebilirsin.') }}
                </p>
                <p v-if="!viaShopify && cardDeclineHint" class="mt-2 text-xs leading-relaxed text-stone-500">
                    {{ t(cardDeclineHint) }}
                </p>

                <label class="mt-4 flex items-start gap-3 text-sm text-stone-800">
                    <input v-model="acceptTerms" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-stone-400">
                    <span>
                        <a href="/yasal/mesafeli-satis" target="_blank" class="underline underline-offset-2">{{ t('Mesafeli hizmet sözleşmesini') }}</a>
                        {{ t('ve') }}
                        <a href="/yasal/kullanim-kosullari" target="_blank" class="underline underline-offset-2">{{ t('kullanım koşullarını') }}</a>
                        {{ t('okudum; hizmetin temel nitelikleri, ücreti ve cayma hakkı konusunda bilgilendirildim.') }}
                        <span class="text-red-700">*</span>
                    </span>
                </label>

                <label class="mt-3 flex items-start gap-3 text-sm text-stone-800">
                    <input v-model="waiveWithdrawal" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-stone-400">
                    <span>
                        {{ t('Hizmetin hemen başlamasını istiyorum; hizmet başladığında 14 günlük cayma hakkımın sona ereceğini biliyorum.') }}
                        <span class="block text-xs text-stone-500">{{ t('İsteğe bağlı. İşaretlemezsen hizmet yine hemen açılır ve 14 gün içinde cayma hakkın devam eder.') }}</span>
                    </span>
                </label>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:bg-stone-300"
                        :disabled="!acceptTerms || buying !== null"
                        @click="buy"
                    >
                        {{ buying ? t('Yönlendiriliyor…') : (viaShopify ? t('Shopify\'da onayla') : t('Ödemeye geç')) }}
                    </button>
                    <button type="button" class="text-sm text-stone-600 underline" @click="selected = null">{{ t('Vazgeç') }}</button>
                </div>
            </div>
        </section>
    </PanelLayout>
</template>
