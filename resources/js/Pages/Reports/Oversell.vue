<script setup>
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, useI18n } from '../../lib/i18n';

const props = defineProps({
    report: { type: Object, required: true },
    days: { type: Number, default: 30 },
});

const { t } = useI18n();

function date(value) {
    return new Date(value).toLocaleDateString(intlLocale(), { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function seconds(value) {
    if (value === null || value === undefined) return '—';
    return t(':value sn', { value: Number(value).toLocaleString(intlLocale(), { maximumFractionDigits: 1 }) });
}

function count(value) {
    return Number(value).toLocaleString(intlLocale());
}

/*
 * BOŞ DURUM — hiç çok kanallı satış yoksa gecikme ve tükenme kutuları
 * "0" değil "henüz ölçüm yok" der: 0 sn "anında" demek olurdu.
 */
const measured = computed(() => props.report.sales.multi_channel > 0);
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Raporlar')" :title="t('Fazla satış koruması')" />

        <p class="mt-2 max-w-3xl text-sm text-stone-600">
            {{ t('Son :days günün ölçülmüş rakamları (:from – :to). Her sayı kayıttan gelir; tahmin ya da örnek veri yoktur. Tanımlar her kutunun altında yazılıdır.', { days, from: date(report.from), to: date(report.to) }) }}
        </p>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <section class="rounded border border-stone-200 bg-white p-4">
                <h2 class="text-sm font-medium text-stone-600">{{ t('İşlenen sipariş') }}</h2>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-stone-900">{{ count(report.orders.count) }}</p>
                <p class="mt-2 text-xs text-stone-500">{{ t(':count kanaldan gelen siparişler.', { count: report.orders.channels }) }}</p>
            </section>

            <section class="rounded border border-stone-200 bg-white p-4">
                <h2 class="text-sm font-medium text-stone-600">{{ t('Tükenme koruması') }}</h2>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-stone-900">{{ measured ? count(report.stockouts.protected) : '—' }}</p>
                <p class="mt-2 text-xs text-stone-500">{{ t('Satış stoğu sıfıra indirdi ve ürün başka kanalda da satıştaydı; sıfır diğer kanalların hepsine ulaştı. Senkron olmasaydı bu ürünler orada satılmaya devam ederdi.') }}</p>
                <p v-if="report.stockouts.unprotected > 0" class="mt-2 text-xs text-amber-800">
                    {{ t(':count tükenmede sıfır en az bir kanala ulaşmadı.', { count: report.stockouts.unprotected }) }}
                </p>
            </section>

            <section class="rounded border border-stone-200 bg-white p-4">
                <h2 class="text-sm font-medium text-stone-600">{{ t('Stok güncelleme süresi') }}</h2>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-stone-900">{{ seconds(report.latency.p95) }}</p>
                <p class="mt-1 text-xs text-stone-600">
                    {{ t('Satışların %95\'inde bu süre ya da daha kısa.') }}<br />
                    {{ t('ortalama') }} {{ seconds(report.latency.avg) }} · {{ t('en yavaş') }} {{ seconds(report.latency.max) }}
                </p>
                <p class="mt-2 text-xs text-stone-500">{{ t('Satış 34Pazar\'a ulaştıktan sonra yeni stoğun diğer kanalların hepsine ulaşma süresi (en yavaş kanal). Pazaryerinin siparişi bize bildirme süresi dahil değildir.') }}</p>
            </section>

            <section class="rounded border border-stone-200 bg-white p-4">
                <h2 class="text-sm font-medium text-stone-600">{{ t('Kanal kontrolü') }}</h2>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-stone-900">{{ count(report.reconciliation.drift) }}</p>
                <p class="mt-1 text-xs text-stone-600">
                    {{ t('fark / :checked kontrol', { checked: count(report.reconciliation.checked) }) }} · {{ t(':count otomatik onarıldı', { count: report.reconciliation.repaired }) }}
                </p>
                <p class="mt-2 text-xs text-stone-500">{{ t('Kanaldaki stok ve fiyat bizim değerimizle karşılaştırılır; uyuşmayan kalem farktır.') }}</p>
                <p v-if="report.reconciliation.review > 0" class="mt-2 text-xs text-amber-800">{{ t(':count fark senin kararını bekliyor.', { count: report.reconciliation.review }) }}</p>
                <p v-if="report.reconciliation.unreachable > 0" class="mt-1 text-xs text-stone-500">{{ t(':count kontrolde kanala ulaşılamadı (farka sayılmadı).', { count: report.reconciliation.unreachable }) }}</p>
            </section>
        </div>

        <section class="mt-6 rounded border border-stone-200 bg-white p-4 text-sm text-stone-700">
            <h2 class="font-medium text-stone-900">{{ t('Satışlar') }}</h2>
            <p v-if="!measured" class="mt-1 text-stone-600">
                {{ t('Bu dönemde birden fazla kanalda satışta olan bir ürünün satışı yok; süre ve tükenme koruması ilk böyle satışta ölçülür.') }}
            </p>
            <p v-else class="mt-1">
                {{ t(':total kanal satışının :multi tanesinde ürün başka kanalda da satıştaydı; :reached tanesinde yeni stok diğer kanalların hepsine ulaştı.', { total: count(report.sales.total), multi: count(report.sales.multi_channel), reached: count(report.sales.reached) }) }}
                <span v-if="report.sales.pending > 0" class="text-amber-800">{{ t(':count satışta en az bir kanala henüz ulaşmadı ya da gönderim başarısız oldu.', { count: report.sales.pending }) }}</span>
            </p>
            <p v-if="report.sales.not_targeted > 0" class="mt-1 text-stone-600">
                {{ t(':count satışta ürünün bugün canlı olan bir ilanına o an stok gönderilmedi: ilan satış sırasında canlı değildi ya da o kanalda stok gönderimi kapalıydı. Bu ilanlar süreye ve korumaya katılmadı.', { count: report.sales.not_targeted }) }}
            </p>
        </section>
    </PanelLayout>
</template>
