<script setup>
import { Link } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { channelName, money } from '../../lib/format.js';
import { k, useI18n } from '../../lib/i18n';

defineProps({
    order: { type: Object, required: true },
    expected: { type: Object, required: true },
    settled: { type: Object, required: true },
});

const { t } = useI18n();

const components = [
    ['sale', k('Satış')],
    ['refund', k('İade')],
    ['commission', k('Komisyon')],
    ['shipping', k('Kargo')],
    ['other', k('Diğer')],
    ['cost', k('Maliyet')],
];

const statusText = { complete: k('Tamam'), incomplete: k('Eksik'), not_available: k('Hakediş yok') };
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Katkı raporu')" :title="t('Sipariş :number — neden bu rakam?', { number: order.number })" />
        <p class="mt-1 text-sm text-stone-600">
            {{ channelName(order.channel) }} · {{ t('Paket') }} {{ order.package }} · {{ order.state ?? '—' }}
            · <Link :href="`/orders/${order.id}`" class="underline">{{ t('Siparişe git') }}</Link>
        </p>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section v-for="view in [expected, settled]" :key="view.basis" class="rounded border border-stone-200 bg-white p-4">
                <h2 class="text-sm font-medium text-stone-900">
                    {{ view.basis === 'expected' ? t('Beklenen katkı (KDV dahil)') : t('Gerçekleşen katkı (KDV dahil)') }}
                    <span class="ml-2 rounded border px-2 py-0.5 text-xs">{{ t(statusText[view.status]) }}</span>
                </h2>

                <dl class="mt-3 grid grid-cols-2 gap-y-1 text-sm">
                    <template v-for="[key, label] in components" :key="key">
                        <dt class="text-stone-600">{{ t(label) }}</dt>
                        <dd class="text-right tabular-nums" :class="view[key] === null ? 'text-amber-800' : ''">
                            {{ view[key] === null ? t('bilinmiyor') : money(view[key], view.currency) }}
                        </dd>
                    </template>
                    <dt class="border-t border-stone-200 pt-1 font-medium">{{ t('Katkı') }}</dt>
                    <dd class="border-t border-stone-200 pt-1 text-right font-medium tabular-nums">
                        <template v-if="view.contribution !== null">{{ money(view.contribution, view.currency) }}</template>
                        <span v-else class="text-amber-800">{{ t('eksik') }} — {{ t('bilinenler: :amount', { amount: money(view.partial, view.currency) }) }}</span>
                    </dd>
                </dl>

                <div v-if="view.reasons.length" class="mt-3 rounded bg-amber-50 p-2 text-xs text-amber-900">
                    <p class="font-medium">{{ t('Neden eksik?') }}</p>
                    <ul class="mt-1 list-disc pl-4">
                        <li v-for="reason in view.reasons" :key="reason">{{ reason }}</li>
                    </ul>
                </div>

                <h3 class="mt-4 text-xs font-medium uppercase text-stone-500">{{ t('Kaynak kayıtlar') }}</h3>
                <table class="mt-1 w-full text-xs">
                    <tbody>
                        <tr v-for="(item, i) in view.trace" :key="i" class="border-t border-stone-100 align-top" :class="item.included ? '' : 'text-stone-400'">
                            <td class="py-1 pr-2">
                                {{ item.label }}
                                <div class="text-stone-500">{{ item.source }}<span v-if="item.ref"> · {{ item.ref }}</span></div>
                                <div v-if="item.note" class="text-stone-500">{{ item.note }}</div>
                            </td>
                            <td class="py-1 text-right tabular-nums">{{ item.amount === null ? '—' : money(item.amount, view.currency) }}</td>
                            <td class="py-1 pl-2">{{ item.included ? t('dahil') : t('hariç') }}</td>
                        </tr>
                        <tr v-if="view.trace.length === 0"><td class="py-1 text-stone-500">{{ t('Kayıt yok.') }}</td></tr>
                    </tbody>
                </table>
            </section>
        </div>
    </PanelLayout>
</template>
