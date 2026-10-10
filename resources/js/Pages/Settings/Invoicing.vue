<script setup>
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { k, useI18n } from '../../lib/i18n';

const props = defineProps({
    available: { type: Boolean, default: false },
    account: { type: Object, default: null },
    // Faturalanabilir kanallar (tahsilat eşlemesinin satırları).
    channels: { type: Array, default: () => [] },
    // Paraşüt kasa/banka hesapları — yalnız ekran açılınca okunur.
    ledgerAccounts: { type: Array, default: () => [] },
    ledgerAccountsError: { type: String, default: null },
});

const { t } = useI18n();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const errors = computed(() => page.props.errors ?? {});

const statusLabels = {
    connected: { text: k('Bağlı'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    needs_company: { text: k('Firma seçilmeli'), class: 'bg-amber-50 text-amber-900 border-amber-200' },
    revoked: { text: k('Bağlantı koptu'), class: 'bg-red-50 text-red-800 border-red-200' },
};

const modeOptions = [
    {
        value: 'e_document',
        label: k('e-Fatura / e-Arşiv kes'),
        hint: k('Alıcı e-fatura mükellefiyse e-fatura, değilse e-arşiv kesilir ve PDF pazar yerine yüklenir.'),
    },
    {
        value: 'books_only',
        label: k('Yalnız muhasebeye işle'),
        hint: k('e-Belgenizi başka yerden (ör. pazar yerinin fatura hizmetinden) kesiyorsanız: Paraşüt\'e cari ve satış faturası açılır, e-belge kesilmez.'),
    },
];

const autoIssueOptions = [
    { value: 'off', label: k('Kapalı — faturayı sipariş ekranından ben keserim') },
    { value: 'shipped', label: k('Sipariş kargoya verilince') },
    { value: 'delivered', label: k('Sipariş teslim edilince') },
];

// Eşleme formda kanal kodu → hesap kimliği; boş = tahsilat işlenmez.
const initialPayments = {};
for (const channel of props.channels) {
    initialPayments[channel.code] = props.account?.paymentAccounts?.[channel.code] ?? '';
}

// Firma seçimi bekleyen hesapta yeni bölümler gösterilmez ve gönderilmez:
// sunucu gönderilmeyen alanın eski değerini korur.
const ready = computed(() => props.account?.status === 'connected');

const form = useForm({
    company_id: props.account?.companyId ?? '',
    invoice_series: props.account?.invoiceSeries ?? '',
    mode: props.account?.mode ?? 'e_document',
    auto_issue: props.account?.autoIssue ?? 'off',
    payment_accounts: initialPayments,
});

const autoIssueSince = computed(() => {
    if (!props.account?.autoIssueSince) return null;
    return new Date(props.account.autoIssueSince).toLocaleString();
});

const connectForm = useForm({});

function connect() {
    connectForm.post('/settings/invoicing/parasut/authorize');
}

function save() {
    form.transform((data) => {
        if (ready.value) return data;
        const { mode, auto_issue, payment_accounts, ...rest } = data;
        return rest;
    }).put('/settings/invoicing', { preserveScroll: true });
}

function disconnect() {
    if (!window.confirm(t('Paraşüt bağlantısı kaldırılsın mı? Kesilmiş faturalar Paraşüt\'te kalır.'))) return;
    router.delete('/settings/invoicing', { preserveScroll: true });
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Modüller')" :title="t('Fatura ve muhasebe')" />

        <p v-if="flashSuccess" class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
            {{ flashSuccess }}
        </p>
        <p v-if="errors.parasut" class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
            {{ errors.parasut }}
        </p>

        <p class="mt-6 max-w-2xl text-sm text-stone-600">
            {{ t('Siparişin faturası Paraşüt hesabınızdan kesilir: alıcı e-fatura mükellefiyse e-fatura, değilse e-arşiv. Alıcının adı, adresi ve kimlik numarası fatura anında kanaldan okunur ve 34Pazar\'da saklanmaz.') }}
        </p>

        <!-- Sunucuda Paraşüt uygulaması tanımlı değilse bağlanılamaz. -->
        <div v-if="!available" class="mt-6 rounded-lg border border-stone-200 bg-stone-50 p-4 text-sm text-stone-600">
            {{ t('Paraşüt bağlantısı henüz açık değil.') }}
        </div>

        <div v-else-if="!account" class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
            <p class="text-sm font-medium text-stone-900">Paraşüt</p>
            <p class="mt-1 text-xs text-stone-500">
                {{ t('Paraşüt\'te oturum açıp 34Pazar\'a izin verirsiniz; şifreniz bize gelmez.') }}
            </p>
            <button
                type="button"
                :disabled="connectForm.processing"
                class="mt-4 rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                @click="connect"
            >
                {{ t('Paraşüt\'e bağlan') }}
            </button>
        </div>

        <div v-else class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-sm font-medium text-stone-900">Paraşüt</p>
                    <p v-if="account.companyName" class="mt-0.5 text-xs text-stone-500">{{ account.companyName }}</p>
                </div>
                <span
                    v-if="statusLabels[account.status]"
                    class="rounded-full border px-2.5 py-0.5 text-xs font-medium"
                    :class="statusLabels[account.status].class"
                >
                    {{ t(statusLabels[account.status].text) }}
                </span>
            </div>

            <p v-if="account.lastError" class="mt-2 text-xs text-red-700">{{ account.lastError }}</p>

            <form class="mt-5" @submit.prevent="save">
                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <label for="company" class="block text-xs font-medium text-stone-700">{{ t('Fatura kesilecek firma') }}</label>
                        <select
                            id="company"
                            v-model="form.company_id"
                            required
                            class="mt-1 w-64 rounded-md border border-stone-300 px-3 py-1.5 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        >
                            <option value="" disabled>{{ t('Seçin') }}</option>
                            <option v-for="company in account.companies" :key="company.id" :value="company.id">
                                {{ company.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label for="series" class="block text-xs font-medium text-stone-700">{{ t('Fatura serisi (isteğe bağlı)') }}</label>
                        <input
                            id="series"
                            v-model="form.invoice_series"
                            type="text"
                            maxlength="16"
                            :placeholder="t('örn. TRY')"
                            class="mt-1 w-32 rounded-md border border-stone-300 px-3 py-1.5 font-mono text-sm uppercase focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        >
                    </div>
                </div>

                <template v-if="ready">
                    <!-- KİP: e-belge mi, yalnız muhasebe mi. -->
                    <fieldset class="mt-6 border-t border-stone-100 pt-5">
                        <legend class="text-sm font-medium text-stone-900">{{ t('Faturayı nasıl işleyelim?') }}</legend>
                        <div class="mt-3 space-y-3">
                            <label v-for="option in modeOptions" :key="option.value" class="flex cursor-pointer items-start gap-2">
                                <input v-model="form.mode" type="radio" name="mode" :value="option.value" class="mt-0.5">
                                <span>
                                    <span class="block text-sm text-stone-900">{{ t(option.label) }}</span>
                                    <span class="block text-xs text-stone-500">{{ t(option.hint) }}</span>
                                </span>
                            </label>
                        </div>
                        <p v-if="form.errors.mode" class="mt-2 text-sm text-red-700">{{ form.errors.mode }}</p>
                    </fieldset>

                    <!-- OTOMATİK AKTARIM: geriye dönük fatura yok. -->
                    <fieldset class="mt-6 border-t border-stone-100 pt-5">
                        <legend class="text-sm font-medium text-stone-900">{{ t('Otomatik aktarım') }}</legend>
                        <select
                            id="auto-issue"
                            v-model="form.auto_issue"
                            :aria-label="t('Otomatik aktarım')"
                            class="mt-3 w-full max-w-md rounded-md border border-stone-300 px-3 py-1.5 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        >
                            <option v-for="option in autoIssueOptions" :key="option.value" :value="option.value">{{ t(option.label) }}</option>
                        </select>
                        <p class="mt-2 max-w-2xl text-xs text-stone-500">
                            {{ t('Yalnız ayarı açtıktan sonra verilen siparişler aktarılır; eski siparişlere geriye dönük fatura kesilmez. İptal edilen siparişe fatura kesilmez.') }}
                        </p>
                        <p v-if="account.autoIssue !== 'off' && autoIssueSince" class="mt-1 font-mono text-[11px] text-stone-500">
                            {{ t('Başlangıç: :date', { date: autoIssueSince }) }}
                        </p>
                        <p v-if="form.errors.auto_issue" class="mt-2 text-sm text-red-700">{{ form.errors.auto_issue }}</p>
                    </fieldset>

                    <!-- TAHSİLAT: kanal → kasa/banka. -->
                    <fieldset class="mt-6 border-t border-stone-100 pt-5">
                        <legend class="text-sm font-medium text-stone-900">{{ t('Tahsilat hesapları') }}</legend>
                        <p class="mt-1 max-w-2xl text-xs text-stone-500">
                            {{ t('Pazar yeri parayı müşteriden tahsil eder. Kanal için bir kasa/banka hesabı seçerseniz fatura o hesaba tahsil edilmiş olarak işlenir; seçmezseniz açık hesap kalır.') }}
                        </p>

                        <p v-if="ledgerAccountsError" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                            {{ ledgerAccountsError }}
                        </p>

                        <p v-if="channels.length === 0" class="mt-3 text-xs text-stone-500">
                            {{ t('Faturalanabilir bağlı kanalınız yok.') }}
                        </p>

                        <div v-for="channel in channels" :key="channel.code" class="mt-3 flex flex-wrap items-center gap-3">
                            <label :for="`payment-${channel.code}`" class="w-32 text-sm text-stone-700">{{ channel.name }}</label>
                            <select
                                :id="`payment-${channel.code}`"
                                v-model="form.payment_accounts[channel.code]"
                                :disabled="ledgerAccounts.length === 0"
                                class="w-64 rounded-md border border-stone-300 px-3 py-1.5 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring disabled:bg-stone-50"
                            >
                                <option value="">{{ t('Tahsilat işlenmesin') }}</option>
                                <option v-for="ledger in ledgerAccounts" :key="ledger.id" :value="ledger.id">{{ ledger.name }}</option>
                            </select>
                        </div>
                        <p v-if="form.errors.payment_accounts" class="mt-2 text-sm text-red-700">{{ form.errors.payment_accounts }}</p>

                        <!-- Gider sipariş başına YAZILMAZ: aylık pazar yeri faturasıyla iki kez sayılırdı. -->
                        <p class="mt-4 max-w-2xl text-xs text-stone-500">
                            {{ t('Komisyon ve kargo gideri sipariş başına aktarılmaz: Trendyol bunları ayda bir e-fatura olarak keser ve o fatura Paraşüt\'teki gelen faturalarınıza kendiliğinden düşer. Ayrıca aktarılsaydı gider iki kez sayılırdı.') }}
                        </p>
                    </fieldset>
                </template>

                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ t('Kaydet') }}
                    </button>
                    <p v-if="form.errors.company_id" class="w-full text-sm text-red-700">{{ form.errors.company_id }}</p>
                    <p v-if="form.errors.invoice_series" class="w-full text-sm text-red-700">{{ form.errors.invoice_series }}</p>
                </div>
            </form>

            <div class="mt-6 flex flex-wrap gap-3 border-t border-stone-100 pt-4">
                <button
                    v-if="account.status === 'revoked'"
                    type="button"
                    class="rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700"
                    @click="connect"
                >
                    {{ t('Yeniden bağlan') }}
                </button>
                <button
                    type="button"
                    class="rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100"
                    @click="disconnect"
                >
                    {{ t('Bağlantıyı kaldır') }}
                </button>
            </div>
        </div>
    </PanelLayout>
</template>
