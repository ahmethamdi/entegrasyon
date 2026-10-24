<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { channelName, money, orderState, toneClass } from '../../lib/format.js';
import { intlLocale, k, useI18n } from '../../lib/i18n';
import { usePermissions } from '../../lib/permissions.js';

const props = defineProps({
    order: { type: Object, required: true },
});

const { t } = useI18n();
const { can } = usePermissions();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

// ── kargo bildirimi ───────────────────────────────────────────────────
/**
 * Firma adı serbest metindir: Shopify tanıdığı adlarda takip bağlantısını
 * kendisi kurar, Woo müşteri notuna yazar. Liste yalnız öneridir.
 */
const carrierSuggestions = [
    'Yurtiçi Kargo', 'Aras Kargo', 'MNG Kargo', 'PTT Kargo', 'Sürat Kargo',
    'DHL', 'DHL Express', 'UPS', 'DPD', 'GLS', 'Hermes', 'FedEx',
];

const shipForm = useForm({ carrier: '', tracking_number: '' });

function submitShipment() {
    shipForm.post(`/orders/${props.order.id}/shipments`, {
        preserveScroll: true,
        onSuccess: () => shipForm.reset(),
    });
}

/**
 * Gönderilmiş ya da gönderilmekte olan panel bildirimi varken form
 * gösterilmez: ikinci istek kanalda hiçbir şey değiştirmez.
 */
const hasActiveShipment = computed(() => props.order.fulfillments.some(
    (f) => f.source === 'panel' && (f.pushStatus === 'pending' || f.pushStatus === 'sent'),
));

const retrying = ref(null);
const retryForm = useForm({ carrier: '', tracking_number: '' });

function openRetry(fulfillment) {
    retrying.value = fulfillment.id;
    retryForm.carrier = fulfillment.carrier ?? '';
    retryForm.tracking_number = fulfillment.trackingNumber ?? '';
    retryForm.clearErrors();
}

function submitRetry(fulfillment) {
    retryForm.post(`/orders/${props.order.id}/shipments/${fulfillment.id}/retry`, {
        preserveScroll: true,
        onSuccess: () => { retrying.value = null; },
    });
}

/** Gönderim durumu rozetleri; kanaldan gelen satırın rozeti yoktur. */
const pushBadges = {
    pending: { text: k('Kanala gönderiliyor'), class: 'bg-sky-50 text-sky-800 border-sky-200' },
    sent: { text: k('Kanala gönderildi'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    failed: { text: k('Gönderilemedi'), class: 'bg-red-50 text-red-800 border-red-200' },
};

const lineBadges = {
    OVERSOLD: { text: k('Fazla satış'), class: 'bg-red-50 text-red-800 border-red-200' },
    PENDING: { text: k('Stok düşülmedi'), class: 'bg-amber-50 text-amber-900 border-amber-200' },
    APPLIED: { text: k('Stok düştü'), class: 'bg-stone-50 text-stone-600 border-stone-200' },
    // Sonradan eşleşti; satış açılış stoğundan önceydi veya satır tamamen
    // iptal edilmişti — stok bilerek düşülmedi.
    SKIPPED: { text: k('Stoktan düşülmedi'), class: 'bg-slate-50 text-slate-700 border-slate-200' },
};

/**
 * Olay etiketleri. OVERSELL_DETECTED bizim ürettiğimiz DENETİM olayıdır;
 * kanaldan gelmez ve `external_ref` taşımaz.
 */
const eventLabels = {
    created: k('Sipariş alındı'),
    updated: k('Güncellendi'),
    cancelled: k('İptal edildi'),
    returned: k('İade edildi'),
    fulfilled: k('Kargolandı'),
    OVERSELL_DETECTED: k('Fazla satış tespit edildi'),
};

/* Olayın nereden geldiği — "webhook" / "polling" satıcıya bir şey söylemez. */
const sourceLabels = {
    webhook: k('kanaldan geldi'),
    polling: k('kanaldan alındı'),
    channel: k('kanaldan geldi'),
    panel: k('panelden'),
    system: k('sistem'),
};

// ── e-fatura ──────────────────────────────────────────────────────────
const invoiceBadges = {
    pending: { text: k('Fatura kesiliyor'), class: 'bg-sky-50 text-sky-800 border-sky-200' },
    issuing: { text: k('Fatura kesiliyor'), class: 'bg-sky-50 text-sky-800 border-sky-200' },
    issued: { text: k('Fatura kesildi'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    failed: { text: k('Fatura kesilemedi'), class: 'bg-red-50 text-red-800 border-red-200' },
};

// S6 · Kargo II — satıcının kendi Yurtiçi/Aras sözleşmesi.
const carrierBadges = {
    pending: { text: k('Açılıyor'), class: 'bg-stone-50 text-stone-700 border-stone-200' },
    creating: { text: k('Açılıyor'), class: 'bg-stone-50 text-stone-700 border-stone-200' },
    created: { text: k('Etiket hazır · kargoya verilmedi'), class: 'bg-sky-50 text-sky-800 border-sky-200' },
    shipped: { text: k('Kargoda'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    delivered: { text: k('Teslim edildi'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    cancelling: { text: k('İptal ediliyor'), class: 'bg-amber-50 text-amber-900 border-amber-200' },
    cancelled: { text: k('İptal edildi'), class: 'bg-stone-50 text-stone-600 border-stone-200' },
    failed: { text: k('Açılamadı'), class: 'bg-red-50 text-red-800 border-red-200' },
};
const shipmentError = computed(() => page.props.errors?.shipment);
const carrierBusy = ref(false);
const carrierDone = { preserveScroll: true, onFinish: () => { carrierBusy.value = false; } };

function createCarrierShipment(code) {
    carrierBusy.value = true;
    router.post(`/orders/${props.order.id}/carrier-shipment`, { carrier: code }, carrierDone);
}

function cancelCarrierShipment() {
    if (!window.confirm(t('Gönderi kargo firmasında iptal edilsin mi?'))) return;
    carrierBusy.value = true;
    router.post(`/orders/${props.order.id}/carrier-shipment/cancel`, {}, carrierDone);
}

function handOverCarrierShipment() {
    if (!window.confirm(t('Paket kuryeye verildi mi? Takip numarası kanala bildirilecek.'))) return;
    carrierBusy.value = true;
    router.post(`/orders/${props.order.id}/carrier-shipment/hand-over`, {}, carrierDone);
}

const documentLabels = { e_archive: k('e-Arşiv'), e_invoice: k('e-Fatura') };

const invoiceError = computed(() => page.props.errors?.invoice);
const invoicing = ref(false);

function requestInvoice() {
    router.post(`/orders/${props.order.id}/invoice`, {}, {
        preserveScroll: true,
        onStart: () => { invoicing.value = true; },
        onFinish: () => { invoicing.value = false; },
    });
}

function retryInvoice() {
    router.post(`/orders/${props.order.id}/invoice/retry`, {}, { preserveScroll: true });
}

// Kesilen faturanın PDF'i kanala yüklenir; `uploadStatus` null = kanal
// dosya almıyor, rozet hiç çıkmaz. Yükleme hatası faturayı "kesilemedi"
// yapmaz — iki rozet ayrıdır.
const uploadBadges = {
    pending: { text: k('Kanala yükleniyor'), class: 'bg-sky-50 text-sky-800 border-sky-200' },
    sent: { text: k('Kanala yüklendi'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    failed: { text: k('Kanala yüklenemedi'), class: 'bg-red-50 text-red-800 border-red-200' },
};

function retryInvoiceUpload() {
    router.post(`/orders/${props.order.id}/invoice/upload/retry`, {}, { preserveScroll: true });
}

// ── fatura düzeltmeleri (S6): iptal ve iade kaydı ─────────────────────
const correctionLabels = { cancel: k('Fatura iptali'), return: k('İade kaydı') };

const correctionBadges = {
    pending: { text: k('İşleniyor'), class: 'bg-sky-50 text-sky-800 border-sky-200' },
    issued: { text: k('Paraşüt\'e işlendi'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    failed: { text: k('İşlenemedi'), class: 'bg-red-50 text-red-800 border-red-200' },
};

function cancelInvoice() {
    if (!window.confirm(t('Fatura Paraşüt\'te iptal edilecek. Bu geri alınamaz. Devam edilsin mi?'))) return;
    router.post(`/orders/${props.order.id}/invoice/cancel`, {}, { preserveScroll: true });
}

function recordReturn(candidate) {
    router.post(`/orders/${props.order.id}/invoice/returns/${candidate.id}`, {}, { preserveScroll: true });
}

function retryCorrection(correction) {
    router.post(`/orders/${props.order.id}/invoice/corrections/${correction.id}/retry`, {}, { preserveScroll: true });
}

function stamp(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString(intlLocale(), {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Sipariş')" :title="order.externalNumber ?? order.externalId">
            <template #actions>
                <Link
                    href="/orders"
                    class="rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                    {{ t('Listeye dön') }}
                </Link>
            </template>

            <template #toolbar>
                <p class="text-sm text-stone-600">
                    {{ channelName(order.channel.type) }}
                    <span v-if="order.channel.label" class="text-stone-500">· {{ order.channel.label }}</span>
                    · {{ stamp(order.placedAt) }}
                    <span
                        class="ml-2 rounded-full border px-2.5 py-0.5 text-xs font-medium"
                        :class="toneClass[orderState(order.status, order.fulfillments.length > 0, order.state).tone]"
                    >{{ $t(orderState(order.status, order.fulfillments.length > 0, order.state).text) }}</span>
                </p>
            </template>
        </PageHeader>

        <div
            v-if="flashSuccess"
            class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ flashSuccess }}
        </div>

        <!-- tutarlar -->
        <div class="mt-6 grid gap-4 sm:grid-cols-4">
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">{{ t('Ara toplam') }}</p>
                <p class="mt-1 text-lg font-medium tabular-nums text-stone-900">
                    {{ money(order.subtotal, order.currency) }}
                </p>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">{{ t('Kargo') }}</p>
                <p class="mt-1 text-lg font-medium tabular-nums text-stone-900">
                    {{ money(order.shippingTotal, order.currency) }}
                </p>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">{{ t('Vergi') }}</p>
                <p class="mt-1 text-lg font-medium tabular-nums text-stone-900">
                    {{ money(order.taxTotal, order.currency) }}
                </p>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">{{ t('Genel toplam') }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums text-stone-900">
                    {{ money(order.grandTotal, order.currency) }}
                </p>
            </div>
        </div>

        <!-- satırlar -->
        <h2 class="mt-10 text-sm font-semibold text-stone-900">{{ t('Kalemler') }}</h2>

        <!-- Asgari genişlik sütunların dar ekranda sıkışmasını önler; kutu kayar. -->
        <div class="relative mt-3 overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full min-w-2xl text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left">
                    <tr>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('Ürün') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-stone-600">{{ t('Adet') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-stone-600">{{ t('İptal / İade') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-stone-600">{{ t('Tutar') }}</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('Stok') }}</th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="line in order.lines"
                        :key="line.id"
                        class="border-b border-stone-100"
                        :class="line.isOversold ? 'bg-red-50/60 hover:bg-red-100/60' : (!line.isMatched ? 'bg-amber-50/50 hover:bg-amber-100/50' : 'hover:bg-stone-50')"
                    >
                        <td class="px-4 py-3">
                            <p class="text-xs text-stone-900">{{ line.title }}</p>
                            <p class="mt-0.5 font-mono text-[11px] text-stone-500">{{ line.sku }}</p>
                        </td>

                        <td class="px-4 py-3 text-right text-sm tabular-nums text-stone-700">
                            {{ line.quantity }}
                        </td>

                        <td class="px-4 py-3 text-right text-sm tabular-nums text-stone-700">
                            {{ line.quantityCancelled }} / {{ line.quantityReturned }}
                        </td>

                        <td class="px-4 py-3 text-right text-sm tabular-nums text-stone-900">
                            {{ money(line.lineTotal, order.currency) }}
                        </td>

                        <td class="px-4 py-3">
                            <span
                                class="rounded-full border px-2.5 py-0.5 text-xs font-medium"
                                :class="lineBadges[line.stockStatus]?.class"
                            >
                                {{ lineBadges[line.stockStatus] ? t(lineBadges[line.stockStatus].text) : line.stockStatus }}
                            </span>

                            <!--
                                EŞLEŞMEMİŞ SKU: sipariş KAYBEDİLMEDİ ama stok
                                düşülmedi. Satıcı eşleştirmeyi yapana kadar
                                bakiye olduğundan fazla görünür.
                            -->
                            <p v-if="!line.isMatched" class="mt-0.5 text-[11px] text-amber-800">
                                {{ t('Bu stok kodu kataloğunda yok · stok düşülmedi') }}
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!--
            KARGO. Satıcı takip numarasını burada TEK yerden girer ve
            sipariş geldiği kanala gönderilir. Kanaldan gelen kargo da
            burada listelenir (rozetsiz).
        -->
        <h2 class="mt-10 text-sm font-semibold text-stone-900">{{ t('Kargo') }}</h2>

        <div class="mt-3 rounded-lg border border-stone-200 bg-white">
            <ul v-if="order.fulfillments.length">
                <li
                    v-for="fulfillment in order.fulfillments"
                    :key="fulfillment.id"
                    class="border-b border-stone-100 px-4 py-3 last:border-b-0"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-xs text-stone-900">
                                {{ fulfillment.carrier ?? t('Kargo firması belirtilmedi') }}
                                <span v-if="fulfillment.trackingNumber" class="font-mono text-stone-600">
                                    · {{ fulfillment.trackingNumber }}
                                </span>
                            </p>
                            <p class="mt-0.5 font-mono text-[11px] text-stone-500">
                                {{ fulfillment.source === 'panel' ? t('panelden girildi') : t('kanaldan geldi') }}
                                · {{ stamp(fulfillment.shippedAt) }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <span
                                v-if="pushBadges[fulfillment.pushStatus]"
                                class="rounded-full border px-2.5 py-0.5 text-xs font-medium"
                                :class="pushBadges[fulfillment.pushStatus].class"
                            >
                                {{ t(pushBadges[fulfillment.pushStatus].text) }}
                            </span>
                            <button
                                v-if="fulfillment.pushStatus === 'failed' && retrying !== fulfillment.id && can('orders.work')"
                                type="button"
                                class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100"
                                @click="openRetry(fulfillment)"
                            >
                                {{ t('Düzelt ve tekrar gönder') }}
                            </button>
                        </div>
                    </div>

                    <!-- Hata metni gizlenmez: satıcı neyi düzelteceğini buradan anlar. -->
                    <p v-if="fulfillment.pushError" class="mt-1.5 text-[11px] text-red-700">
                        {{ t(fulfillment.pushError) }}
                    </p>

                    <form
                        v-if="retrying === fulfillment.id"
                        class="mt-3 flex flex-wrap items-end gap-3"
                        @submit.prevent="submitRetry(fulfillment)"
                    >
                        <div>
                            <label :for="`retry-carrier-${fulfillment.id}`" class="block text-xs font-medium text-stone-700">{{ t('Kargo firması') }}</label>
                            <input
                                :id="`retry-carrier-${fulfillment.id}`"
                                v-model="retryForm.carrier"
                                type="text"
                                list="carrier-suggestions"
                                class="mt-1 w-48 rounded-md border border-stone-300 px-3 py-1.5 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                            >
                        </div>
                        <div>
                            <label :for="`retry-tracking-${fulfillment.id}`" class="block text-xs font-medium text-stone-700">{{ t('Takip numarası') }}</label>
                            <input
                                :id="`retry-tracking-${fulfillment.id}`"
                                v-model="retryForm.tracking_number"
                                type="text"
                                class="mt-1 w-56 rounded-md border border-stone-300 px-3 py-1.5 font-mono text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                            >
                        </div>
                        <button
                            type="submit"
                            :disabled="retryForm.processing"
                            class="rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ t('Tekrar gönder') }}
                        </button>
                        <button type="button" class="text-sm text-stone-600 underline" @click="retrying = null">
                            {{ t('Vazgeç') }}
                        </button>
                    </form>
                </li>
            </ul>

            <p v-else class="px-4 py-3 text-xs text-stone-500">{{ t('Henüz kargo bilgisi yok.') }}</p>
        </div>

        <!-- Kanal desteklemiyorsa form yerine ne yapılacağı söylenir. -->
        <p v-if="!order.canShip" class="mt-3 text-xs text-stone-500">
            {{ t('Bu kanal kargo bildirimini desteklemiyor; takip numarasını kanalın kendi panelinden girin.') }}
        </p>

        <form
            v-else-if="!hasActiveShipment && can('orders.work')"
            class="mt-3 flex flex-wrap items-end gap-3 rounded-lg border border-stone-200 bg-stone-50 p-4"
            @submit.prevent="submitShipment"
        >
            <div>
                <label for="ship-carrier" class="block text-xs font-medium text-stone-700">{{ t('Kargo firması') }}</label>
                <input
                    id="ship-carrier"
                    v-model="shipForm.carrier"
                    type="text"
                    list="carrier-suggestions"
                    :placeholder="t('örn. Yurtiçi Kargo')"
                    class="mt-1 w-48 rounded-md border border-stone-300 px-3 py-1.5 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                >
            </div>
            <div>
                <label for="ship-tracking" class="block text-xs font-medium text-stone-700">{{ t('Takip numarası') }}</label>
                <input
                    id="ship-tracking"
                    v-model="shipForm.tracking_number"
                    type="text"
                    required
                    class="mt-1 w-56 rounded-md border border-stone-300 px-3 py-1.5 font-mono text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                >
            </div>
            <button
                type="submit"
                :disabled="shipForm.processing"
                class="rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                {{ t('Kargoya verildi') }}
            </button>

            <p class="w-full text-xs text-stone-500">
                {{ t('Takip numarası siparişin geldiği kanala (:channel) gönderilir ve sipariş orada kargolandı olarak işaretlenir.', { channel: channelName(order.channel.type) }) }}
            </p>
            <p v-if="shipForm.errors.tracking_number" class="w-full text-sm text-red-700">
                {{ shipForm.errors.tracking_number }}
            </p>
            <p v-if="shipForm.errors.carrier" class="w-full text-sm text-red-700">
                {{ shipForm.errors.carrier }}
            </p>
        </form>

        <datalist id="carrier-suggestions">
            <option v-for="name in carrierSuggestions" :key="name" :value="name" />
        </datalist>

        <!--
            KARGO GÖNDERİSİ (S6) — satıcının kendi Yurtiçi/Aras
            sözleşmesi. Alıcı kanaldan anlık okunur; etiket hazır olması
            kargoya verildi demek değildir.
        -->
        <template v-if="order.carrierChannelSupported || order.carrierShipment">
            <h2 class="mt-10 flex items-center gap-2 text-sm font-semibold text-stone-900">
                {{ t('Kargo gönderisi') }}
            </h2>

            <div class="mt-3 rounded-lg border border-stone-200 bg-white px-4 py-3">
                <p v-if="shipmentError" class="mb-2 text-xs text-red-700">{{ shipmentError }}</p>

                <template v-if="order.carrierShipment && order.carrierShipment.status !== 'cancelled'">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-sm text-stone-900">
                                {{ order.carrierShipment.carrier }}
                                <span class="font-mono text-xs text-stone-500">· {{ order.carrierShipment.cargoKey }}</span>
                                <span v-if="order.carrierShipment.environment === 'test'" class="text-xs text-amber-800">· {{ t('test ortamı') }}</span>
                            </p>
                            <p v-if="order.carrierShipment.trackingNumber" class="mt-0.5 text-xs text-stone-600">
                                {{ t('Takip no') }}:
                                <a v-if="order.carrierShipment.trackingUrl" :href="order.carrierShipment.trackingUrl" target="_blank" rel="noopener" class="font-mono underline">{{ order.carrierShipment.trackingNumber }}</a>
                                <span v-else class="font-mono">{{ order.carrierShipment.trackingNumber }}</span>
                            </p>
                            <p v-if="order.carrierShipment.carrierStatus" class="mt-0.5 text-[11px] text-stone-500">{{ order.carrierShipment.carrierStatus }}</p>
                        </div>
                        <span class="rounded-full border px-2.5 py-0.5 text-xs font-medium" :class="carrierBadges[order.carrierShipment.status]?.class">
                            {{ t(carrierBadges[order.carrierShipment.status]?.text ?? order.carrierShipment.status) }}
                        </span>
                    </div>

                    <p v-if="order.carrierShipment.reason" class="mt-1.5 text-[11px] text-red-700">
                        {{ order.carrierShipment.reason }}<template v-if="order.carrierShipment.error"> — {{ order.carrierShipment.error }}</template>
                    </p>

                    <div v-if="can('orders.work')" class="mt-3 flex flex-wrap gap-2">
                        <a v-if="order.carrierShipment.hasZpl" :href="`/orders/${order.id}/carrier-shipment/label.zpl`" class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 hover:bg-stone-100">
                            {{ t('Etiket (ZPL)') }}
                        </a>
                        <a v-if="order.carrierShipment.hasPdf" :href="`/orders/${order.id}/carrier-shipment/label.pdf`" target="_blank" rel="noopener" class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 hover:bg-stone-100">
                            {{ t('Etiket (PDF)') }}
                        </a>
                        <button
                            v-if="order.carrierShipment.status === 'created' && !order.carrierShipment.notified && order.carrierShipment.trackingNumber"
                            type="button"
                            :disabled="carrierBusy"
                            class="rounded-md bg-stone-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-stone-700 disabled:opacity-50"
                            @click="handOverCarrierShipment"
                        >
                            {{ t('Kargoya verdim') }}
                        </button>
                        <button
                            v-if="order.carrierShipment.cancellable"
                            type="button"
                            :disabled="carrierBusy"
                            class="rounded-md border border-red-200 px-3 py-1.5 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50"
                            @click="cancelCarrierShipment"
                        >
                            {{ t('Gönderiyi iptal et') }}
                        </button>
                        <button
                            v-if="order.carrierShipment.status === 'failed'"
                            type="button"
                            :disabled="carrierBusy"
                            class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 hover:bg-stone-100 disabled:opacity-50"
                            @click="createCarrierShipment(order.carrierShipment.provider)"
                        >
                            {{ t('Tekrar dene') }}
                        </button>
                    </div>
                </template>

                <template v-else>
                    <p v-if="!order.carrierAccounts.length && can('settings.manage')" class="text-xs text-stone-500">
                        {{ t('Kendi kargo sözleşmenizle gönderi açmak için') }}
                        <Link href="/settings/shipping" class="underline">{{ t('kargo ayarlarından hesabınızı bağlayın') }}</Link>.
                    </p>
                    <div v-else-if="order.carrierAccounts.length && can('orders.work')" class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs text-stone-500">
                            {{ t('Teslimat adresi :channel\'dan okunur, gönderi kargo firmasında açılır.', { channel: channelName(order.channel.type) }) }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="carrier in order.carrierAccounts"
                                :key="carrier.code"
                                type="button"
                                :disabled="carrierBusy"
                                class="rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="createCarrierShipment(carrier.code)"
                            >
                                {{ t(':name gönderisi aç', { name: carrier.name }) }}
                            </button>
                        </div>
                    </div>
                    <p v-else class="text-xs text-stone-500">{{ t('Henüz kargo gönderisi açılmadı.') }}</p>
                </template>
            </div>
        </template>

        <!--
            E-FATURA. Fatura Paraşüt'ten kesilir; alıcı bilgisi kanaldan
            anlık okunur, burada gösterilmez ve saklanmaz.
        -->
        <h2 class="mt-10 text-sm font-semibold text-stone-900">{{ t('Fatura') }}</h2>

        <div class="mt-3 rounded-lg border border-stone-200 bg-white px-4 py-3">
            <div v-if="order.invoice" class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-xs text-stone-900">
                        {{ order.invoice.documentType ? t(documentLabels[order.invoice.documentType]) : t('Fatura') }}
                        <span v-if="order.invoice.number" class="font-mono text-stone-600">· {{ order.invoice.number }}</span>
                    </p>
                    <p v-if="order.invoice.issuedAt" class="mt-0.5 font-mono text-[11px] text-stone-500">{{ stamp(order.invoice.issuedAt) }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <span
                        class="rounded-full border px-2.5 py-0.5 text-xs font-medium"
                        :class="invoiceBadges[order.invoice.status]?.class"
                    >
                        <!-- Belge türü yoksa "yalnız muhasebeye işle" kipidir: e-belge kesilmedi. -->
                        {{ order.invoice.status === 'issued' && !order.invoice.documentType
                            ? t('Muhasebeye işlendi')
                            : t(invoiceBadges[order.invoice.status]?.text ?? order.invoice.status) }}
                    </span>
                    <a
                        v-if="order.invoice.status === 'issued' && order.invoice.documentType"
                        :href="`/orders/${order.id}/invoice/pdf`"
                        target="_blank"
                        rel="noopener"
                        class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100"
                    >
                        {{ t('PDF') }}
                    </a>
                    <button
                        v-if="order.invoice.status === 'failed' && can('orders.work')"
                        type="button"
                        class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100"
                        @click="retryInvoice"
                    >
                        {{ t('Tekrar dene') }}
                    </button>
                </div>
            </div>
            <!-- Hata metni gizlenmez: satıcı neyi düzelteceğini buradan anlar. -->
            <p v-if="order.invoice?.error" class="mt-1.5 text-[11px] text-red-700">{{ order.invoice.error }}</p>

            <div
                v-if="order.invoice?.status === 'issued' && order.invoice.uploadStatus"
                class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-stone-100 pt-2"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="rounded-full border px-2.5 py-0.5 text-xs font-medium"
                        :class="uploadBadges[order.invoice.uploadStatus]?.class"
                    >
                        {{ t(uploadBadges[order.invoice.uploadStatus]?.text ?? order.invoice.uploadStatus) }}
                    </span>
                    <span v-if="order.invoice.uploadedAt" class="font-mono text-[11px] text-stone-500">{{ stamp(order.invoice.uploadedAt) }}</span>
                </div>
                <button
                    v-if="order.invoice.uploadStatus === 'failed' && can('orders.work')"
                    type="button"
                    class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100"
                    @click="retryInvoiceUpload"
                >
                    {{ t(':channel\'a tekrar yükle', { channel: channelName(order.channel.type) }) }}
                </button>
            </div>
            <p v-if="order.invoice?.uploadError" class="mt-1.5 text-[11px] text-red-700">{{ order.invoice.uploadError }}</p>

            <template v-if="!order.invoice">
                <p v-if="!order.invoiceChannelSupported" class="text-xs text-stone-500">
                    {{ t('Bu kanalın siparişine henüz fatura kesilemiyor.') }}
                </p>
                <p v-else-if="!order.invoiceAccountReady && can('settings.manage')" class="text-xs text-stone-500">
                    {{ t('Fatura kesmek için önce') }}
                    <Link href="/settings/invoicing" class="underline">{{ t('e-fatura ayarlarından Paraşüt hesabınızı bağlayın') }}</Link>.
                </p>
                <div v-else-if="order.invoiceAccountReady && can('orders.work')" class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-stone-500">
                        {{ t('Alıcı bilgisi :channel\'dan okunur, fatura Paraşüt\'ten kesilir.', { channel: channelName(order.channel.type) }) }}
                    </p>
                    <button
                        type="button"
                        :disabled="invoicing"
                        class="rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="requestInvoice"
                    >
                        {{ t('Fatura kes') }}
                    </button>
                </div>
                <!-- Yetkisi olmayan üyede kutu boş kalmasın: durum yazılır. -->
                <p v-else-if="!order.invoiceAccountReady" class="text-xs text-stone-500">
                    {{ t('E-fatura hesabı bağlı değil; ekip yöneticisi bağlayabilir.') }}
                </p>
                <p v-else class="text-xs text-stone-500">{{ t('Henüz fatura kesilmedi.') }}</p>
            </template>

            <!--
                DÜZELTMELER — iptal edilen siparişin faturası iptal edilir,
                kabul edilen iade muhasebeye kaydedilir. İkisi birbirini
                dışlar; düğmeler yalnız işe yarayacağı yerde görünür.
            -->
            <ul v-if="order.invoiceCorrections.length" class="mt-2 border-t border-stone-100 pt-2">
                <li v-for="correction in order.invoiceCorrections" :key="correction.id" class="py-1">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-stone-900">
                            {{ t(correctionLabels[correction.kind] ?? correction.kind) }}
                            <span v-if="correction.issuedAt" class="font-mono text-[11px] text-stone-500">· {{ stamp(correction.issuedAt) }}</span>
                        </p>
                        <div class="flex items-center gap-2">
                            <span class="rounded-full border px-2.5 py-0.5 text-xs font-medium" :class="correctionBadges[correction.status]?.class">
                                {{ t(correctionBadges[correction.status]?.text ?? correction.status) }}
                            </span>
                            <button
                                v-if="correction.status === 'failed' && can('finance.manage')"
                                type="button"
                                class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100"
                                @click="retryCorrection(correction)"
                            >
                                {{ t('Tekrar dene') }}
                            </button>
                        </div>
                    </div>
                    <p v-if="correction.error" class="mt-1 text-[11px] text-red-700">{{ correction.error }}</p>
                </li>
            </ul>

            <div v-if="order.canCancelInvoice && can('finance.manage')" class="mt-2 flex flex-wrap items-center justify-between gap-3 border-t border-stone-100 pt-2">
                <p class="text-xs text-stone-500">{{ t('Sipariş iptal edildi. Faturayı Paraşüt\'te iptal edebilirsiniz.') }}</p>
                <button
                    type="button"
                    class="rounded-md border border-red-300 px-3 py-1.5 text-xs text-red-800 transition hover:bg-red-50"
                    @click="cancelInvoice"
                >
                    {{ t('Faturayı iptal et') }}
                </button>
            </div>

            <div
                v-for="candidate in (can('finance.manage') ? order.returnInvoiceCandidates : [])"
                :key="candidate.id"
                class="mt-2 flex flex-wrap items-center justify-between gap-3 border-t border-stone-100 pt-2"
            >
                <p class="text-xs text-stone-500">
                    {{ t('İade talebi :claim', { claim: candidate.claim }) }}
                    <span v-if="candidate.reason" class="block text-[11px]">{{ candidate.reason }}</span>
                </p>
                <button
                    v-if="candidate.recordable"
                    type="button"
                    class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100"
                    @click="recordReturn(candidate)"
                >
                    {{ t('İadeyi muhasebeye kaydet') }}
                </button>
            </div>

            <p v-if="invoiceError" class="mt-2 text-sm text-red-700">{{ invoiceError }}</p>
        </div>

        <!-- olay geçmişi -->
        <h2 class="mt-10 text-sm font-semibold text-stone-900">{{ t('Geçmiş') }}</h2>

        <div class="mt-3 rounded-lg border border-stone-200 bg-white">
            <ul>
                <li
                    v-for="event in order.events"
                    :key="event.id"
                    class="flex items-center justify-between border-b border-stone-100 px-4 py-3 last:border-b-0"
                >
                    <div>
                        <p
                            class="text-xs"
                            :class="event.type === 'OVERSELL_DETECTED'
                                ? 'font-medium text-red-800'
                                : 'text-stone-900'"
                        >
                            {{ eventLabels[event.type] ? t(eventLabels[event.type]) : event.type }}
                            <span v-if="event.quantity" class="font-mono text-stone-500">
                                · {{ t(':count adet', { count: event.quantity }) }}
                            </span>
                        </p>
                        <p class="mt-0.5 text-xs text-stone-500">{{ sourceLabels[event.source] ? t(sourceLabels[event.source]) : event.source }}</p>
                    </div>

                    <p class="font-mono text-[11px] text-stone-500">{{ stamp(event.occurredAt) }}</p>
                </li>
            </ul>
        </div>
    </PanelLayout>
</template>
