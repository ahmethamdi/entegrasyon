<script setup>
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import WriteGuard from '../../Components/WriteGuard.vue';
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

// Nilvera: satıcı portalda kendi API anahtarını üretir (34Pazar'ın uygulama
// anahtarına gerek yok). Anahtar kaydedilmeden önce sunucuda denenir.
const nilveraForm = useForm({ api_key: '', environment: 'live' });
const showNilveraForm = computed(() => !props.account || (props.account.provider === 'nilvera' && props.account.status !== 'connected'));

function connectNilvera() {
    nilveraForm.post('/settings/invoicing/nilvera', {
        preserveScroll: true,
        onSuccess: () => nilveraForm.reset('api_key'),
    });
}

const reconnectNilvera = computed(() => props.account?.provider === 'nilvera');

function save() {
    form.transform((data) => {
        if (ready.value) return data;
        const { mode, auto_issue, payment_accounts, ...rest } = data;
        return rest;
    }).put('/settings/invoicing', { preserveScroll: true });
}

function disconnect() {
    if (!window.confirm(t('e-Fatura bağlantısı kaldırılsın mı? Kesilmiş faturalar :provider hesabınızda kalır.', { provider: props.account?.providerName ?? '' }))) return;
    router.delete('/settings/invoicing', { preserveScroll: true });
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Modüller')" :title="t('Fatura ve muhasebe')" />
        <WriteGuard permission="settings.manage">

            <p v-if="flashSuccess" class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                {{ flashSuccess }}
            </p>
            <p v-if="errors.parasut" class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
                {{ errors.parasut }}
            </p>

            <p class="mt-6 max-w-2xl text-sm text-stone-600">
                {{ t('Siparişin faturası kendi e-fatura entegratörü hesabınızdan kesilir: alıcı e-fatura mükellefiyse e-fatura, değilse e-arşiv. Alıcının adı, adresi ve kimlik numarası fatura anında kanaldan okunur ve 34Pazar\'da saklanmaz.') }}
            </p>

            <!--
                NİLVERA: satıcının kendi API anahtarıyla — her kurulumda açık.
                Hesap yokken ya da Nilvera bağlantısı koptuğunda görünür.
            -->
            <div v-if="showNilveraForm" class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
                <p class="text-sm font-medium text-stone-900">Nilvera</p>
                <p class="mt-1 max-w-2xl text-xs text-stone-500">
                    {{ t('Nilvera portalında API Tanımları → Yeni Anahtar ile bir anahtar üretip buraya yapıştırın. Anahtar şifreli saklanır ve ekrana geri gelmez. Kontörünüz Nilvera hesabınızdan düşer.') }}
                </p>
                <form class="mt-4 flex flex-wrap items-end gap-4" @submit.prevent="connectNilvera">
                    <div>
                        <label for="nilvera-key" class="block text-xs font-medium text-stone-700">{{ t('API anahtarı') }}</label>
                        <input
                            id="nilvera-key"
                            v-model="nilveraForm.api_key"
                            type="password"
                            autocomplete="off"
                            required
                            class="mt-1 w-80 max-w-full rounded-md border border-stone-300 px-3 py-1.5 font-mono text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        >
                    </div>
                    <label class="flex items-center gap-2 text-xs text-stone-700">
                        <input v-model="nilveraForm.environment" type="checkbox" true-value="test" false-value="live">
                        {{ t('Test ortamı anahtarı') }}
                    </label>
                    <button
                        type="submit"
                        :disabled="nilveraForm.processing"
                        class="rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ t('Nilvera\'ya bağlan') }}
                    </button>
                    <p v-if="nilveraForm.errors.api_key" class="w-full text-sm text-red-700">{{ nilveraForm.errors.api_key }}</p>
                </form>
            </div>

            <!-- Paraşüt yalnız sunucuda uygulama tanımlıysa bağlanabilir. -->
            <div v-if="!account && available" class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
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

            <div v-if="account" class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-sm font-medium text-stone-900">
                            {{ account.providerName }}
                            <span v-if="account.environment === 'test'" class="ml-1 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-900">{{ t('Test ortamı') }}</span>
                        </p>
                        <p v-if="account.companyName" class="mt-0.5 text-xs text-stone-500">{{ account.companyName }}</p>
                        <p v-for="credit in account.credits ?? []" :key="credit.name" class="mt-0.5 font-mono text-[11px] text-stone-500">
                            {{ t(':name: :count kontör kaldı', { name: credit.name, count: credit.remaining }) }}
                        </p>
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
                                :maxlength="account.provider === 'nilvera' ? 3 : 16"
                                :placeholder="account.provider === 'nilvera' ? t('örn. EAR') : t('örn. TRY')"
                                class="mt-1 w-32 rounded-md border border-stone-300 px-3 py-1.5 font-mono text-sm uppercase focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                            >
                        </div>
                    </div>

                    <template v-if="ready">
                        <!-- KİP: e-belge mi, yalnız muhasebe mi (yalnız muhasebe tutan entegratörde). -->
                        <fieldset v-if="account.keepsBooks" class="mt-6 border-t border-stone-100 pt-5">
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

                        <!-- TAHSİLAT: kanal → kasa/banka (yalnız muhasebe tutan entegratörde). -->
                        <fieldset v-if="account.keepsBooks" class="mt-6 border-t border-stone-100 pt-5">
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
                        v-if="account.status === 'revoked' && !reconnectNilvera"
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
        </WriteGuard>
    </PanelLayout>
</template>
