<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { brandIcon, channelName } from '../../lib/format.js';
import { useI18n } from '../../lib/i18n';
import { usePermissions } from '../../lib/permissions.js';

const props = defineProps({
    connections: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    days: { type: Number, default: 90 },
});

const { t } = useI18n();
const { can } = usePermissions();
const editable = computed(() => can('finance.manage'));

/* Bağlantı başına ayrı form — biri kaydedilirken diğeri bozulmaz. */
const forms = reactive(Object.fromEntries(props.connections.map((c) => [c.id, useForm({
    connection_id: c.id,
    commission_percent: c.commissionPercent ?? '',
    shipping_fee: c.shippingFee ?? '',
    packaging_fee: c.packagingFee ?? '',
    categories: c.categories.map((r) => ({ category: r.category, commission_percent: r.commissionPercent ?? '' })),
})])));

function num(value) {
    const raw = String(value ?? '').replace(',', '.').trim();
    return raw === '' ? null : raw;
}

function save(id) {
    const form = forms[id];
    form.transform((data) => ({
        connection_id: data.connection_id,
        commission_percent: num(data.commission_percent),
        shipping_fee: num(data.shipping_fee),
        packaging_fee: num(data.packaging_fee),
        categories: data.categories
            .filter((r) => String(r.category ?? '').trim() !== '')
            .map((r) => ({ category: r.category.trim(), commission_percent: num(r.commission_percent) })),
    })).post('/settings/charge-rules', { preserveScroll: true });
}

function addCategory(id) {
    forms[id].categories.push({ category: '', commission_percent: '' });
}

function removeCategory(id, index) {
    forms[id].categories.splice(index, 1);
}

function error(form, key) {
    return form.errors[key] ?? null;
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Finans')" :title="t('Kesinti varsayılanları')" />

        <p class="mt-1 max-w-3xl text-[13px] leading-relaxed text-stone-600">
            {{ t('Pazaryerinin her siparişten kestiği komisyonu ve kargo ücretini, ayrıca sipariş başına paketleme maliyetini gir. Yeni siparişler gelince beklenen kesinti bunlardan yazılır; pazaryeri hakedişi gelince gerçek rakam Katkı raporunda ayrıca görünür.') }}
        </p>
        <p class="mt-1 max-w-3xl text-[13px] leading-relaxed text-stone-600">
            {{ t('Kaydedince son :days günün kesintisi olmayan siparişlerine de uygulanır. Değişiklik, önceden kesintisi yazılmış siparişleri değiştirmez.', { days }) }}
        </p>

        <div v-if="!connections.length" class="mt-6 rounded-[14px] border border-stone-200 bg-white px-5 py-8 text-center">
            <p class="text-sm text-stone-700">{{ t('Henüz bağlı kanalın yok.') }}</p>
            <Link href="/channels/create" class="mt-2 inline-block text-[13px] font-semibold text-brand-700">{{ t('İlk kanalını bağla') }} →</Link>
        </div>

        <datalist id="charge-rule-categories">
            <option v-for="c in categories" :key="c" :value="c" />
        </datalist>

        <section v-for="c in connections" :key="c.id" class="mt-4 rounded-[14px] border border-stone-200 bg-white p-5 sm:p-[22px]" :aria-labelledby="`rule-${c.id}`">
            <h2 :id="`rule-${c.id}`" class="flex items-center gap-2 text-base font-semibold text-stone-900">
                <img v-if="brandIcon(c.channel)" :src="brandIcon(c.channel)" alt="" class="size-5 rounded object-contain">
                {{ c.label || channelName(c.channel) }}
                <span v-if="c.label" class="text-xs font-normal text-stone-500">{{ channelName(c.channel) }}</span>
            </h2>

            <fieldset :disabled="!editable" class="contents">
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <label class="block text-[13px] text-stone-700">
                        {{ t('Komisyon (%)') }}
                        <input v-model="forms[c.id].commission_percent" type="text" inputmode="decimal" placeholder="18" class="mt-1 h-9 w-full rounded-[10px] border border-stone-300 px-3 text-sm tabular-nums disabled:bg-stone-50" />
                        <span class="mt-1 block text-xs text-stone-500">{{ t('Satış tutarı (KDV dahil) üzerinden') }}</span>
                        <span v-if="error(forms[c.id], 'commission_percent')" class="mt-1 block text-xs text-brand-700">{{ error(forms[c.id], 'commission_percent') }}</span>
                    </label>
                    <label class="block text-[13px] text-stone-700">
                        {{ t('Kargo kesintisi (sipariş başı, ₺)') }}
                        <input v-model="forms[c.id].shipping_fee" type="text" inputmode="decimal" placeholder="42,90" class="mt-1 h-9 w-full rounded-[10px] border border-stone-300 px-3 text-sm tabular-nums disabled:bg-stone-50" />
                        <span class="mt-1 block text-xs text-stone-500">{{ t('Pazaryerinin kestiği kargo ücreti') }}</span>
                        <span v-if="error(forms[c.id], 'shipping_fee')" class="mt-1 block text-xs text-brand-700">{{ error(forms[c.id], 'shipping_fee') }}</span>
                    </label>
                    <label class="block text-[13px] text-stone-700">
                        {{ t('Paketleme (sipariş başı, ₺)') }}
                        <input v-model="forms[c.id].packaging_fee" type="text" inputmode="decimal" placeholder="5,00" class="mt-1 h-9 w-full rounded-[10px] border border-stone-300 px-3 text-sm tabular-nums disabled:bg-stone-50" />
                        <span class="mt-1 block text-xs text-stone-500">{{ t('Kutu, bant, dolgu — Kâr panelinde "Diğer"') }}</span>
                        <span v-if="error(forms[c.id], 'packaging_fee')" class="mt-1 block text-xs text-brand-700">{{ error(forms[c.id], 'packaging_fee') }}</span>
                    </label>
                </div>

                <div class="mt-5 border-t border-stone-100 pt-4">
                    <h3 class="text-[13px] font-semibold text-stone-900">{{ t('Kategori istisnaları') }}</h3>
                    <p class="mt-0.5 text-xs text-stone-500">{{ t('Bazı kategorilerde komisyon farklıysa buraya yaz; yazılmayan kategoriler yukarıdaki yüzdeyi kullanır.') }}</p>

                    <ul v-if="forms[c.id].categories.length" class="mt-3 space-y-2">
                        <li v-for="(row, i) in forms[c.id].categories" :key="i" class="flex flex-wrap items-center gap-2">
                            <label class="sr-only" :for="`cat-${c.id}-${i}`">{{ t('Kategori') }}</label>
                            <input :id="`cat-${c.id}-${i}`" v-model="row.category" list="charge-rule-categories" type="text" :placeholder="t('Kategori')" class="h-9 w-64 rounded-[10px] border border-stone-300 px-3 text-sm disabled:bg-stone-50" />
                            <label class="sr-only" :for="`catp-${c.id}-${i}`">{{ t('Komisyon (%)') }}</label>
                            <input :id="`catp-${c.id}-${i}`" v-model="row.commission_percent" type="text" inputmode="decimal" placeholder="%" class="h-9 w-24 rounded-[10px] border border-stone-300 px-3 text-right text-sm tabular-nums disabled:bg-stone-50" />
                            <button v-if="editable" type="button" class="h-9 rounded-[10px] px-2 text-[13px] text-stone-500 hover:text-brand-700" @click="removeCategory(c.id, i)">{{ t('Kaldır') }}</button>
                            <span v-if="error(forms[c.id], `categories.${i}.category`) || error(forms[c.id], `categories.${i}.commission_percent`)" class="w-full text-xs text-brand-700">
                                {{ error(forms[c.id], `categories.${i}.category`) || error(forms[c.id], `categories.${i}.commission_percent`) }}
                            </span>
                        </li>
                    </ul>
                    <button v-if="editable" type="button" class="mt-3 text-[13px] font-semibold text-brand-700 hover:text-brand-800" @click="addCategory(c.id)">+ {{ t('Kategori istisnası ekle') }}</button>
                </div>

                <div v-if="editable" class="mt-5 flex justify-end">
                    <button type="button" class="h-9 rounded-[10px] bg-stone-900 px-4 text-sm font-semibold text-white hover:bg-stone-700 disabled:opacity-50" :disabled="forms[c.id].processing" @click="save(c.id)">{{ t('Kaydet') }}</button>
                </div>
            </fieldset>
        </section>
    </PanelLayout>
</template>
