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
    if (!window.confirm(t('Shopify aboneliğin iptal edilip ücretsiz plana geçilecek. Emin misin?'))) return;

    buying.value = plan.code;
    router.post('/billing/checkout', { plan_code: plan.code }, {
        onFinish: () => {
            buying.value = null;
        },
    });
}

const usageRows = computed(() => Object.entries(props.usage).map(([key, row]) => ({ key, ...row })));
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

        <p v-if="errors.plan_code || errors.accept_terms" class="mt-6 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
            {{ errors.plan_code ?? errors.accept_terms }}
        </p>

        <!-- Mevcut plan -->
        <section class="mt-8">
            <h2 class="text-sm font-semibold text-stone-900">{{ t('Mevcut planın') }}</h2>

            <div class="mt-3 rounded-lg border border-stone-200 bg-white p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-lg font-medium text-stone-900">
                        {{ current.planName ?? '—' }}
                    </p>
                    <span
                        v-if="current.status"
                        class="rounded px-2 py-0.5 text-[11px] font-medium"
                        :class="current.status === 'active' || current.status === 'trialing'
                            ? 'bg-emerald-100 text-emerald-800'
                            : 'bg-red-100 text-red-800'"
                    >
                        {{ t(statusLabels[current.status] ?? current.status) }}
                    </span>
                    <span v-else class="text-[11px] text-stone-500">
                        {{ t('abonelik yok') }}
                    </span>
                </div>

                <p v-if="current.currentPeriodEnd" class="mt-2 text-xs text-stone-600">
                    {{ t('Yenileme tarihi: :date', { date: formatDate(current.currentPeriodEnd) }) }}
                </p>
            </div>
        </section>

        <!-- Kullanım — DEĞER VE LİMİT BİRLİKTE -->
        <section class="mt-8">
            <h2 class="text-sm font-semibold text-stone-900">{{ t('Kullanımın') }}</h2>

            <dl class="mt-3 grid gap-px overflow-hidden rounded-lg border border-stone-200 bg-stone-200 sm:grid-cols-2">
                <div v-for="row in usageRows" :key="row.key" class="bg-white p-4">
                    <dt class="text-xs font-medium text-stone-500">
                        {{ t(row.label) }}
                    </dt>
                    <dd class="mt-1 text-xl font-medium tabular-nums text-stone-900">
                        {{ Number(row.current).toLocaleString(intlLocale()) }}
                        <span class="text-sm font-normal text-stone-500">
                            / {{ limitText(row.limit) }}
                        </span>
                    </dd>

                    <div v-if="ratio(row) !== null" class="mt-2 h-1.5 overflow-hidden rounded bg-stone-100">
                        <div
                            class="h-full rounded transition-all"
                            :class="ratio(row) >= 100 ? 'bg-red-500' : ratio(row) >= 80 ? 'bg-amber-500' : 'bg-emerald-500'"
                            :style="{ width: `${ratio(row)}%` }"
                        />
                    </div>
                </div>
            </dl>
        </section>

        <!-- Planlar -->
        <section class="mt-8">
            <h2 class="text-sm font-semibold text-stone-900">{{ t('Planlar') }}</h2>

            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="plan in plans"
                    :key="plan.code"
                    class="flex flex-col rounded border bg-white p-4"
                    :class="plan.code === current.planCode
                        ? 'border-stone-900 ring-1 ring-stone-900'
                        : 'border-stone-200'"
                >
                    <p class="text-sm font-semibold text-stone-900">{{ t(plan.name) }}</p>

                    <p class="mt-2 text-xl font-medium tabular-nums text-stone-900">
                        {{ priceText(plan) }}
                        <span class="text-xs font-normal text-stone-500">{{ t('/ ay') }}</span>
                    </p>

                    <ul class="mt-3 flex-1 space-y-1 text-xs text-stone-600">
                        <li>{{ t(':count ürün', { count: limitText(plan.limits.products) }) }}</li>
                        <li>{{ t(':count kanal', { count: limitText(plan.limits.channels) }) }}</li>
                    </ul>

                    <p
                        v-if="plan.code === current.planCode"
                        class="mt-4 rounded bg-stone-100 py-2 text-center text-xs font-medium text-stone-600"
                    >
                        {{ t('Mevcut planın') }}
                    </p>
                    <button
                        v-else-if="Number(price(plan)) > 0"
                        type="button"
                        class="mt-4 rounded-md bg-stone-900 py-2 text-xs font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:bg-stone-300"
                        :disabled="!paymentsEnabled || buying !== null"
                        @click="choose(plan)"
                    >
                        {{ buying === plan.code ? t('Yönlendiriliyor…') : t('Bu plana geç') }}
                    </button>
                    <button
                        v-else-if="billing.canDowngrade"
                        type="button"
                        class="mt-4 rounded-md border border-stone-300 py-2 text-xs font-medium text-stone-700 transition hover:bg-stone-100 disabled:opacity-50"
                        :disabled="buying !== null"
                        @click="downgrade(plan)"
                    >
                        {{ t('Ücretsiz plana geç') }}
                    </button>
                    <p v-else class="mt-4 py-2 text-center text-xs text-stone-500">
                        {{ t('Ücretsiz') }}
                    </p>
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
