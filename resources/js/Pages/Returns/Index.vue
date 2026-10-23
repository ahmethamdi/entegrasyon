<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, k, useI18n } from '../../lib/i18n';
import { usePermissions } from '../../lib/permissions.js';

const props = defineProps({
    requests: { type: Object, required: true },
    filter: { type: String, default: 'action' },
});

const { t } = useI18n();
// S7 · iade kararı depo işi; salt okunur üye kararı görür, düğmeyi görmez.
const { can } = usePermissions();
const page = usePage();
const flash = computed(() => page.props.flash ?? {});

/*
 * "TALEP VAR" ≠ "STOK DÖNDÜ". Stok rozeti YALNIZ defter hareketi varken
 * (restored) yeşildir; kanal "kabul edildi" dese bile ürünün depoya
 * döndüğü kanıtlanmadıysa ekran stok döndü DEMEZ.
 */
const restockBadges = {
    restored: { text: k('Stoğa eklendi'), class: 'border-emerald-200 bg-emerald-50 text-emerald-900' },
    pending: { text: k('Stok eklenmedi'), class: 'border-amber-300 bg-amber-50 text-amber-900' },
    blocked: { text: k('Stoğa eklenmeyecek'), class: 'border-stone-300 bg-stone-50 text-stone-700' },
    not_applicable: { text: k('Stok değişmedi'), class: 'border-stone-200 bg-white text-stone-600' },
};

const decisionTexts = {
    pending: k('Karar gönderiliyor'),
    submitted: k('Karar kanala iletildi'),
    failed: k('Karar gönderilemedi'),
    remote_state_unknown: k('Kararın kanala ulaşıp ulaşmadığı bilinmiyor'),
};

const rejecting = ref(null);
const rejectForm = ref({ reason_id: '', description: '' });
const busy = ref(null);

function dateText(iso) {
    return iso ? new Date(iso).toLocaleString(intlLocale()) : '—';
}

function setFilter(value) {
    router.get('/returns', { filter: value === 'action' ? undefined : value }, { preserveScroll: true });
}

function approve(request) {
    if (busy.value) return;
    if (!window.confirm(t('Ürün deponuza ulaştı ve iadeyi onaylıyorsunuz. Bu karar kanalda geri alınamaz. Devam edilsin mi?'))) return;

    busy.value = request.id;
    router.post(`/returns/${request.id}/approve`, {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = null; },
    });
}

function openReject(request) {
    rejecting.value = request.id;
    rejectForm.value = { reason_id: request.decision.reasons[0]?.id ?? '', description: '' };
}

function submitReject(request) {
    if (busy.value) return;

    busy.value = request.id;
    router.post(`/returns/${request.id}/reject`, rejectForm.value, {
        preserveScroll: true,
        onSuccess: () => { rejecting.value = null; },
        onFinish: () => { busy.value = null; },
    });
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Siparişler')" :title="t('İadeler')" />

        <p v-if="flash.success" class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            {{ flash.success }}
        </p>
        <p v-if="flash.warning" class="mt-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ flash.warning }}
        </p>

        <div class="mt-6 flex rounded-md border border-stone-300 bg-white p-0.5 text-sm sm:w-fit">
            <button
                type="button"
                class="flex-1 rounded px-3 py-1.5 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                :class="filter === 'action' ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'"
                @click="setFilter('action')"
            >
                {{ t('İlgilenmeniz gerekenler') }}
            </button>
            <button
                type="button"
                class="flex-1 rounded px-3 py-1.5 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                :class="filter === 'all' ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'"
                @click="setFilter('all')"
            >
                {{ t('Hepsi') }}
            </button>
        </div>

        <div v-if="requests.data.length === 0" class="mt-6 rounded-lg border border-stone-200 bg-white px-4 py-12 text-center text-sm text-stone-600">
            {{ filter === 'action' ? t('İlgilenmeniz gereken iade talebi yok.') : t('Henüz iade talebi gelmedi.') }}
        </div>

        <ul class="mt-6 space-y-4">
            <li v-for="request in requests.data" :key="request.id" class="rounded-lg border border-stone-200 bg-white">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-100 px-4 py-3">
                    <div>
                        <p class="text-sm font-medium text-stone-900">
                            {{ request.channel }} · {{ request.connection }}
                            <span class="text-stone-500">· {{ t('Talep') }} {{ request.claimId }}</span>
                        </p>
                        <p class="mt-0.5 text-xs text-stone-600">
                            {{ t('Sipariş') }}
                            <Link v-if="request.orderId" :href="`/orders/${request.orderId}`" class="underline hover:text-stone-900">{{ request.orderNumber ?? '—' }}</Link>
                            <span v-else>{{ request.orderNumber ?? '—' }} · {{ t('34Pazar\'da eşleşmedi') }}</span>
                            · {{ dateText(request.claimedAt) }}
                        </p>
                    </div>
                    <span class="rounded border border-stone-300 bg-stone-50 px-2 py-0.5 text-xs text-stone-800">{{ request.statusLabel }}</span>
                </div>

                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="line in request.lines" :key="line.id" class="border-b border-stone-100 last:border-0 align-top">
                            <td class="px-4 py-3">
                                <p class="text-stone-900">{{ line.product ?? '—' }}</p>
                                <p class="text-xs text-stone-500">{{ line.sku }} · {{ t(':n adet', { n: line.quantity }) }}</p>
                                <p v-if="line.reason" class="mt-1 text-xs text-stone-700">{{ t('Sebep') }}: {{ line.reason }}</p>
                                <p v-if="line.customerNote" class="mt-1 text-xs text-stone-600">“{{ line.customerNote }}”</p>
                                <p v-if="line.matchStatus !== 'matched'" class="mt-1 text-xs text-amber-800">
                                    {{ line.matchStatus === 'ambiguous' ? t('Siparişte birden çok uygun kalem var; elle eşleşme gerekli.') : t('Sipariş kalemiyle eşleşmedi.') }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-xs text-stone-700">
                                <p>{{ line.statusLabel }}</p>
                                <p class="text-stone-500">{{ line.channelStatus }}</p>
                                <p v-if="line.decisionStatus" class="mt-1" :class="line.decisionStatus === 'failed' || line.decisionStatus === 'remote_state_unknown' ? 'text-amber-800' : 'text-stone-600'">
                                    {{ t(decisionTexts[line.decisionStatus] ?? line.decisionStatus) }}
                                    <span v-if="line.decisionReason">— {{ line.decisionReason }}</span>
                                </p>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <span class="inline-block rounded border px-2 py-0.5 text-xs" :class="restockBadges[line.restockStatus]?.class">
                                    {{ t(restockBadges[line.restockStatus]?.text ?? line.restockStatus) }}
                                </span>
                                <p v-if="line.restockReason" class="mt-1 text-xs text-stone-600">{{ line.restockReason }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="request.decision.available && can('orders.work')" class="border-t border-stone-100 px-4 py-3">
                    <p v-if="!request.decision.enabled" class="text-xs text-stone-600">
                        {{ t('Bu kanalda "İade kararı" izni kapalı. Kanallar ekranından açabilirsiniz; kapalıyken kanala karar gönderilmez.') }}
                    </p>
                    <div v-else-if="rejecting !== request.id" class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-md bg-stone-900 px-3 py-1.5 text-sm text-white transition hover:bg-stone-700 disabled:opacity-50"
                            :disabled="busy !== null"
                            @click="approve(request)"
                        >
                            {{ t('İadeyi onayla') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-800 transition hover:bg-stone-50 disabled:opacity-50"
                            :disabled="busy !== null"
                            @click="openReject(request)"
                        >
                            {{ t('Reddet') }}
                        </button>
                    </div>
                    <form v-else class="grid gap-2 sm:max-w-lg" @submit.prevent="submitReject(request)">
                        <label class="text-xs text-stone-700">
                            {{ t('Red sebebi') }}
                            <select v-model="rejectForm.reason_id" class="mt-1 w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm">
                                <option v-for="r in request.decision.reasons" :key="r.id" :value="r.id">{{ r.label }}</option>
                            </select>
                        </label>
                        <label class="text-xs text-stone-700">
                            {{ t('Açıklama (kanala gider, en çok 500 karakter)') }}
                            <textarea v-model="rejectForm.description" maxlength="500" rows="3" required class="mt-1 w-full rounded-md border border-stone-300 px-2 py-1.5 text-sm" />
                        </label>
                        <p class="text-xs text-stone-500">{{ t('Fotoğraf/belge gerektiren red sebepleri yalnız kanalın satıcı panelinden seçilebilir.') }}</p>
                        <div class="flex gap-2">
                            <button type="submit" class="rounded-md bg-red-700 px-3 py-1.5 text-sm text-white transition hover:bg-red-800 disabled:opacity-50" :disabled="busy !== null">
                                {{ t('Red talebini gönder') }}
                            </button>
                            <button type="button" class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm" @click="rejecting = null">
                                {{ t('Vazgeç') }}
                            </button>
                        </div>
                    </form>
                </div>
                <p v-else-if="!request.decision.supported && request.status === 'awaiting_action'" class="border-t border-stone-100 px-4 py-3 text-xs text-stone-600">
                    {{ t('Bu kanalda iade kararı 34Pazar\'dan verilemiyor; kanalın satıcı panelini kullanın.') }}
                </p>
            </li>
        </ul>

        <div v-if="requests.prev_page_url || requests.next_page_url" class="mt-6 flex justify-between text-sm">
            <Link v-if="requests.prev_page_url" :href="requests.prev_page_url" class="underline">{{ t('Önceki') }}</Link>
            <span v-else />
            <Link v-if="requests.next_page_url" :href="requests.next_page_url" class="underline">{{ t('Sonraki') }}</Link>
        </div>
    </PanelLayout>
</template>
