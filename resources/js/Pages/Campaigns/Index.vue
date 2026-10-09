<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { k, useI18n } from '../../lib/i18n';

defineProps({
    campaigns: { type: Array, default: () => [] },
});

const { t } = useI18n();

// Yürürlükte olan yeşil, planlanan sakin mavi; bitmiş/iptal gri.
const badges = {
    active: { text: k('Yürürlükte'), class: 'bg-emerald-50 text-emerald-900 border-emerald-200' },
    scheduled: { text: k('Planlandı'), class: 'bg-sky-50 text-sky-800 border-sky-200' },
    ended: { text: k('Bitti'), class: 'bg-stone-50 text-stone-600 border-stone-200' },
    cancelled: { text: k('İptal edildi'), class: 'bg-stone-50 text-stone-500 border-stone-200' },
};

const cancelling = ref(null);

function discountText(campaign) {
    return campaign.discountType === 'percent'
        ? t('%:value indirim', { value: Number(campaign.discountValue).toLocaleString('tr-TR') })
        : t(':value indirim', { value: Number(campaign.discountValue).toLocaleString('tr-TR', { minimumFractionDigits: 2 }) });
}

function cancel(campaign) {
    if (!window.confirm(t(':name iptal edilsin mi? Başladıysa fiyatlar normale döner.', { name: campaign.name }))) return;

    cancelling.value = campaign.id;
    router.post(`/campaigns/${campaign.id}/cancel`, {}, {
        preserveScroll: true,
        onFinish: () => { cancelling.value = null; },
    });
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            :section="t('Modüller')"
            :title="t('Kampanyalar')"
            :description="t('Seçtiğin ürünlere, seçtiğin kanallarda belirli tarihler arasında indirim. Bitince fiyatlar kendiliğinden geri döner.')"
        >
            <template #actions>
                <Link
                    href="/campaigns/create"
                    class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700"
                >
                    {{ t('Kampanya oluştur') }}
                </Link>
            </template>
        </PageHeader>

        <div v-if="!campaigns.length" class="mt-10 rounded-lg border border-dashed border-stone-300 px-6 py-10 text-center">
            <p class="text-sm text-stone-700">{{ t('Henüz kampanya yok.') }}</p>
            <p class="mt-1 text-xs text-stone-500">{{ t('Örnek: Kasım indirimi — Trendyol ve Shopify kanallarında tüm kremlerde %20, 1–30 Kasım.') }}</p>
        </div>

        <ul v-else class="mt-8 divide-y divide-stone-200 rounded-lg border border-stone-200 bg-white">
            <li v-for="campaign in campaigns" :key="campaign.id" class="flex flex-wrap items-center gap-4 px-5 py-4">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="truncate text-sm font-medium text-stone-900">{{ campaign.name }}</p>
                        <span
                            class="whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-medium"
                            :class="badges[campaign.status]?.class"
                        >
                            {{ t(badges[campaign.status]?.text ?? campaign.status) }}
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-stone-600">
                        {{ discountText(campaign) }} · {{ campaign.startsAt }} → {{ campaign.endsAt }}
                    </p>
                    <p class="mt-0.5 text-xs text-stone-500">
                        {{ t(':variants varyant · :connections kanal', { variants: campaign.variantCount, connections: campaign.connectionCount }) }}
                    </p>
                </div>

                <button
                    v-if="campaign.status === 'active' || campaign.status === 'scheduled'"
                    type="button"
                    :disabled="cancelling !== null"
                    class="rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="cancel(campaign)"
                >
                    {{ cancelling === campaign.id ? t('İptal ediliyor…') : t('İptal et') }}
                </button>
            </li>
        </ul>
    </PanelLayout>
</template>
