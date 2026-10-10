<script setup>
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { k, useI18n } from '../../lib/i18n';

const props = defineProps({
    available: { type: Boolean, default: false },
    account: { type: Object, default: null },
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

const form = useForm({
    company_id: props.account?.companyId ?? '',
    invoice_series: props.account?.invoiceSeries ?? '',
});

const connectForm = useForm({});

function connect() {
    connectForm.post('/settings/invoicing/parasut/authorize');
}

function save() {
    form.put('/settings/invoicing', { preserveScroll: true });
}

function disconnect() {
    if (!window.confirm(t('Paraşüt bağlantısı kaldırılsın mı? Kesilmiş faturalar Paraşüt\'te kalır.'))) return;
    router.delete('/settings/invoicing', { preserveScroll: true });
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Modüller')" :title="t('e-Fatura')" />

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

            <form class="mt-5 flex flex-wrap items-end gap-4" @submit.prevent="save">
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
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ t('Kaydet') }}
                </button>
                <p v-if="form.errors.company_id" class="w-full text-sm text-red-700">{{ form.errors.company_id }}</p>
                <p v-if="form.errors.invoice_series" class="w-full text-sm text-red-700">{{ form.errors.invoice_series }}</p>
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
