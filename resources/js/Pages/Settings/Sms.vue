<script setup>
import { Deferred, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import WriteGuard from '../../Components/WriteGuard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, k, useI18n } from '../../lib/i18n';

const props = defineProps({
    // { status, environment, username, sender, senders[], notifyPhone, events[], quietHours, lastError, verifiedAt } | null
    account: { type: Object, default: null },
    events: { type: Array, default: () => [] },
    buyerChannels: { type: Array, default: () => [] },
    // [{ id, kind, recipient, body, status, error, count, at }]
    messages: { type: Array, default: () => [] },
    // [{ name, amount }] — ertelenmiş
    balance: { type: Array, default: null },
});

const { t } = useI18n();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const errors = computed(() => page.props.errors ?? {});

const eventInfo = {
    new_order: { title: k('Yeni sipariş'), help: k('Yeni gelen siparişler size tek SMS ile özetlenir (kanal, sipariş no, tutar).'), to: k('Size') },
    stock_out: { title: k('Stok tükendi'), help: k('Kanallarda satışta olan bir ürünün stoğu bitince. Ürün başına günde en çok bir kez.'), to: k('Size') },
    channel_problem: { title: k('Kanal bağlantı sorunu'), help: k('Bir kanal 15 dakikadan uzun süre sağlıksız kalırsa (şifre değişti, yetki kalktı). Her kopuşta bir kez.'), to: k('Size') },
    buyer_shipped: { title: k('Alıcıya "kargoya verildi"'), help: k('34Pazar\'dan kargoya verdiğiniz siparişte alıcıya takip numarasıyla SMS. Yalnız alıcı telefonunu veren kanallarda (Shopify, WooCommerce); pazaryerleri telefon vermez ve platform dışı mesajı yasaklar.'), to: k('Alıcıya') },
};

const statusLabels = {
    connected: { text: k('Bağlı'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    revoked: { text: k('Bağlantı koptu'), class: 'bg-red-50 text-red-800 border-red-200' },
};

const messageStatus = {
    sent: { text: k('Gönderildi'), class: 'text-emerald-800' },
    failed: { text: k('Gönderilemedi'), class: 'text-red-700' },
    skipped: { text: k('Atlandı'), class: 'text-stone-500' },
};

const kindLabels = {
    new_order: k('Yeni sipariş'),
    stock_out: k('Stok tükendi'),
    channel_problem: k('Kanal sorunu'),
    buyer_shipped: k('Alıcıya kargo'),
    test: k('Deneme'),
};

const editingAccount = ref(props.account === null);

const connectForm = useForm({
    environment: props.account?.environment ?? 'live',
    username: '',
    password: '',
});

const settingsForm = useForm({
    sender: props.account?.sender ?? '',
    notify_phone: '',
    events: [...(props.account?.events ?? [])],
    quiet_hours: props.account?.quietHours ?? true,
});

function connect() {
    connectForm.post('/settings/sms', {
        preserveScroll: true,
        onSuccess: () => {
            connectForm.reset('username', 'password');
            editingAccount.value = false;
        },
    });
}

function saveSettings() {
    settingsForm.put('/settings/sms', {
        preserveScroll: true,
        onSuccess: () => settingsForm.reset('notify_phone'),
    });
}

function sendTest() {
    router.post('/settings/sms/test', {}, { preserveScroll: true });
}

function remove() {
    if (!window.confirm(t('Netgsm hesabı kaldırılsın mı? SMS gönderimi durur.'))) return;
    router.delete('/settings/sms', { preserveScroll: true });
}

function when(iso) {
    return iso ? new Date(iso).toLocaleString(intlLocale(), { dateStyle: 'short', timeStyle: 'short' }) : '';
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Katalog')" :title="t('SMS bildirimleri')" />
        <WriteGuard permission="settings.manage">

            <p v-if="flashSuccess" class="mt-4 rounded-[10px] border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                {{ flashSuccess }}
            </p>
            <p v-if="errors.sms" class="mt-4 rounded-[10px] border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
                {{ errors.sms }}
            </p>

            <p class="mt-6 max-w-2xl text-sm text-stone-600">
                {{ t('Kendi Netgsm hesabınızla SMS bildirimi alın: yeni sipariş, tükenen stok ve kanal sorunu size; Shopify ve WooCommerce siparişlerinde kargo bilgisi alıcıya. Mesajlar sizin gönderici başlığınızla ve sizin Netgsm kredinizden gider.') }}
            </p>

            <div class="mt-6 grid gap-4 lg:grid-cols-2">
                <!-- hesap -->
                <section class="self-start rounded-[14px] border border-stone-200 bg-white p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-base font-semibold text-stone-900">{{ t('Netgsm hesabı') }}</p>
                        <span
                            v-if="account && statusLabels[account.status]"
                            class="rounded-full border px-2.5 py-0.5 text-xs font-medium"
                            :class="statusLabels[account.status].class"
                        >
                            {{ t(statusLabels[account.status].text) }}
                        </span>
                    </div>

                    <div v-if="account && !editingAccount" class="mt-4 text-sm">
                        <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 border-t border-stone-100 pt-4">
                            <dt class="text-stone-500">{{ t('Kullanıcı') }}</dt>
                            <dd class="font-mono text-stone-900">{{ account.username }}</dd>
                            <dt class="text-stone-500">{{ t('Ortam') }}</dt>
                            <dd class="text-stone-900">{{ account.environment === 'test' ? t('Test ortamı (gerçek SMS gitmez)') : t('Canlı') }}</dd>
                            <dt class="text-stone-500">{{ t('Kalan bakiye') }}</dt>
                            <dd class="text-stone-900">
                                <Deferred data="balance">
                                    <template #fallback><span class="text-stone-400">…</span></template>
                                    <span v-if="balance && balance.length" class="tabular-nums">
                                        {{ balance.map((b) => `${b.amount} ${b.name}`).join(' · ') }}
                                    </span>
                                    <span v-else class="text-stone-400">—</span>
                                </Deferred>
                            </dd>
                        </dl>
                        <p v-if="account.lastError" class="mt-2 text-xs text-red-700">{{ account.lastError }}</p>
                        <div class="flex gap-2 pt-4">
                            <button type="button" class="rounded-[10px] border border-stone-300 px-3 py-1.5 text-[13px] font-medium text-stone-700 hover:bg-stone-50" @click="editingAccount = true">
                                {{ t('Bilgileri değiştir') }}
                            </button>
                            <button type="button" class="rounded-[10px] border border-red-200 px-3 py-1.5 text-[13px] font-medium text-red-700 hover:bg-red-50" @click="remove">
                                {{ t('Kaldır') }}
                            </button>
                        </div>
                    </div>

                    <form v-else class="mt-4 space-y-3" @submit.prevent="connect">
                        <label class="block text-xs font-medium text-stone-700">
                            {{ t('Kullanıcı adı (abone numarası)') }}
                            <input v-model="connectForm.username" type="text" autocomplete="off" required class="mt-1 w-full rounded-[10px] border border-stone-300 px-3 py-1.5 text-sm" />
                            <span v-if="connectForm.errors.username" class="mt-1 block font-normal text-red-700">{{ connectForm.errors.username }}</span>
                        </label>
                        <label class="block text-xs font-medium text-stone-700">
                            {{ t('Şifre') }}
                            <input v-model="connectForm.password" type="password" autocomplete="new-password" required class="mt-1 w-full rounded-[10px] border border-stone-300 px-3 py-1.5 text-sm" />
                        </label>
                        <label class="block text-xs font-medium text-stone-700">
                            {{ t('Ortam') }}
                            <select v-model="connectForm.environment" class="mt-1 w-full rounded-[10px] border border-stone-300 px-3 py-1.5 text-sm">
                                <option value="live">{{ t('Canlı') }}</option>
                                <option value="test">{{ t('Test ortamı (gerçek SMS gitmez)') }}</option>
                            </select>
                        </label>
                        <p class="text-xs text-stone-500">
                            {{ t('Netgsm hesabınızda API erişimi açık ve en az bir onaylı gönderici başlığı olmalı. Kaydetmeden önce Netgsm\'de denenir; SMS gitmez.') }}
                        </p>
                        <div class="flex gap-2">
                            <button
                                type="submit"
                                :disabled="connectForm.processing"
                                class="rounded-[10px] bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {{ t('Bağla') }}
                            </button>
                            <button v-if="account" type="button" class="rounded-[10px] border border-stone-300 px-3 py-1.5 text-sm text-stone-700" @click="editingAccount = false">
                                {{ t('Vazgeç') }}
                            </button>
                        </div>
                    </form>
                </section>

                <!-- bildirimler -->
                <section v-if="account" class="rounded-[14px] border border-stone-200 bg-white p-5">
                    <p class="text-base font-semibold text-stone-900">{{ t('Bildirimler') }}</p>
                    <form class="mt-4 space-y-4" @submit.prevent="saveSettings">
                        <label class="block text-xs font-medium text-stone-700">
                            {{ t('Gönderici başlığı') }}
                            <select v-model="settingsForm.sender" class="mt-1 w-full rounded-[10px] border border-stone-300 px-3 py-1.5 text-sm">
                                <option v-for="s in account.senders" :key="s" :value="s">{{ s }}</option>
                            </select>
                            <span v-if="settingsForm.errors.sender" class="mt-1 block font-normal text-red-700">{{ settingsForm.errors.sender }}</span>
                        </label>

                        <label class="block text-xs font-medium text-stone-700">
                            {{ t('Size gidecek SMS\'ler için cep telefonunuz') }}
                            <input
                                v-model="settingsForm.notify_phone"
                                type="tel"
                                autocomplete="tel"
                                :placeholder="account.notifyPhone ?? '05XX XXX XX XX'"
                                class="mt-1 w-full rounded-[10px] border border-stone-300 px-3 py-1.5 text-sm"
                            />
                            <span v-if="account.notifyPhone" class="mt-1 block font-normal text-stone-500">{{ t('Kayıtlı: :phone. Değiştirmek için yeni numarayı yazın.', { phone: account.notifyPhone }) }}</span>
                            <span v-if="settingsForm.errors.notify_phone || errors.notify_phone" class="mt-1 block font-normal text-red-700">{{ settingsForm.errors.notify_phone || errors.notify_phone }}</span>
                        </label>

                        <fieldset class="space-y-3">
                            <label v-for="code in events" :key="code" class="flex items-start gap-3 rounded-[10px] border border-stone-200 px-3 py-2.5">
                                <input v-model="settingsForm.events" type="checkbox" :value="code" class="mt-0.5 size-4 rounded border-stone-300" />
                                <span class="text-sm">
                                    <span class="font-medium text-stone-900">{{ t(eventInfo[code].title) }}</span>
                                    <span class="ml-2 rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-600">{{ t(eventInfo[code].to) }}</span>
                                    <span class="mt-0.5 block text-xs text-stone-500">{{ t(eventInfo[code].help) }}</span>
                                    <span v-if="code === 'buyer_shipped'" class="mt-1 block text-xs" :class="buyerChannels.length ? 'text-stone-600' : 'text-amber-800'">
                                        {{ buyerChannels.length
                                            ? t('Kapsanan kanallarınız: :list', { list: buyerChannels.join(', ') })
                                            : t('Bağlı Shopify ya da WooCommerce kanalınız yok; bu bildirim şimdilik SMS göndermez.') }}
                                    </span>
                                </span>
                            </label>
                        </fieldset>

                        <label class="flex items-start gap-3">
                            <input v-model="settingsForm.quiet_hours" type="checkbox" class="mt-0.5 size-4 rounded border-stone-300" />
                            <span class="text-sm text-stone-800">
                                {{ t('Gece SMS gönderme (22:00–08:00)') }}
                                <span class="block text-xs text-stone-500">{{ t('Gece gelen siparişler sabah 08:00\'de tek SMS ile özetlenir.') }}</span>
                            </span>
                        </label>

                        <div class="flex flex-wrap gap-2">
                            <button
                                type="submit"
                                :disabled="settingsForm.processing"
                                class="rounded-[10px] bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {{ t('Kaydet') }}
                            </button>
                            <button v-if="account.notifyPhone" type="button" class="rounded-[10px] border border-stone-300 px-3 py-1.5 text-sm text-stone-700 hover:bg-stone-50" @click="sendTest">
                                {{ t('Deneme SMS\'i gönder') }}
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <!-- gönderim kaydı -->
            <section v-if="account" class="mt-6 rounded-[14px] border border-stone-200 bg-white">
                <p class="border-b border-stone-100 px-5 py-3 text-base font-semibold text-stone-900">{{ t('Son gönderilenler') }}</p>
                <p v-if="!messages.length" class="px-5 py-4 text-sm text-stone-500">{{ t('Henüz SMS gönderilmedi.') }}</p>
                <ul v-if="messages.length" class="divide-y divide-stone-100 sm:hidden">
                    <li v-for="m in messages" :key="m.id" class="px-5 py-3 text-sm">
                        <div class="flex items-center justify-between gap-3 text-xs">
                            <span class="text-stone-500">{{ when(m.at) }} · {{ t(kindLabels[m.kind] ?? m.kind) }}</span>
                            <span class="font-medium" :class="messageStatus[m.status]?.class">{{ t(messageStatus[m.status]?.text ?? m.status) }}</span>
                        </div>
                        <p class="mt-1 text-stone-800">{{ m.body }}</p>
                        <p class="mt-0.5 font-mono text-xs text-stone-500">{{ m.recipient }}</p>
                        <p v-if="m.error" class="mt-0.5 text-xs text-red-700">{{ m.error }}</p>
                    </li>
                </ul>
                <div v-if="messages.length" class="hidden overflow-x-auto sm:block">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-stone-500">
                            <tr>
                                <th class="px-5 py-2 font-medium">{{ t('Zaman') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('Tür') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('Numara') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('Mesaj') }}</th>
                                <th class="px-5 py-2 font-medium">{{ t('Durum') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            <tr v-for="m in messages" :key="m.id" class="align-top">
                                <td class="whitespace-nowrap px-5 py-2 text-stone-600">{{ when(m.at) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-stone-800">{{ t(kindLabels[m.kind] ?? m.kind) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 font-mono text-stone-700">{{ m.recipient }}</td>
                                <td class="px-3 py-2 text-stone-800">{{ m.body }}</td>
                                <td class="px-5 py-2">
                                    <span class="font-medium" :class="messageStatus[m.status]?.class">{{ t(messageStatus[m.status]?.text ?? m.status) }}</span>
                                    <span v-if="m.error" class="block text-xs text-stone-500">{{ m.error }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </WriteGuard>
    </PanelLayout>
</template>
