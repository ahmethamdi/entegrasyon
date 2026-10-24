<script setup>
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import WriteGuard from '../../Components/WriteGuard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { k, useI18n } from '../../lib/i18n';

const props = defineProps({
    // [{ code, name, autoTracking, account: { status, environment, username, lastError, verifiedAt } | null }]
    carriers: { type: Array, default: () => [] },
});

const { t } = useI18n();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const errors = computed(() => page.props.errors ?? {});

const statusLabels = {
    connected: { text: k('Bağlı'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    revoked: { text: k('Bağlantı koptu'), class: 'bg-red-50 text-red-800 border-red-200' },
};

// Firma başına ayrı form: biri gönderilirken öteki etkilenmez.
const forms = reactive({});
for (const carrier of props.carriers) {
    forms[carrier.code] = useForm({
        provider: carrier.code,
        environment: carrier.account?.environment ?? 'live',
        username: '',
        password: '',
    });
}

const editing = reactive({});

function save(code) {
    forms[code].post('/settings/shipping', {
        preserveScroll: true,
        onSuccess: () => {
            forms[code].reset('username', 'password');
            editing[code] = false;
        },
    });
}

function remove(carrier) {
    if (!window.confirm(t(':name hesabı kaldırılsın mı? Açılmış gönderiler firmada kalır.', { name: carrier.name }))) return;
    router.delete(`/settings/shipping/${carrier.code}`, { preserveScroll: true });
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Modüller')" :title="t('Kargo')" />
        <WriteGuard permission="settings.manage">

            <p v-if="flashSuccess" class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                {{ flashSuccess }}
            </p>

            <p class="mt-6 max-w-2xl text-sm text-stone-600">
                {{ t('Kendi kargo sözleşmenizle gönderi açın: siparişten Yurtiçi ya da Aras gönderisi oluşturulur, etiket buradan basılır. Alıcının adı, adresi ve telefonu gönderi anında kanaldan okunur ve 34Pazar\'da saklanmaz.') }}
            </p>
            <p class="mt-2 max-w-2xl text-sm text-stone-600">
                {{ t('Etiketin hazır olması kargoya verildi demek değildir: takip numarası kanala, paket kargoya teslim edilince gider.') }}
            </p>

            <div class="mt-6 grid gap-4 lg:grid-cols-2">
                <section v-for="carrier in carriers" :key="carrier.code" class="rounded-lg border border-stone-200 bg-white p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-medium text-stone-900">{{ carrier.name }}</p>
                        </div>
                        <span
                            v-if="carrier.account && statusLabels[carrier.account.status]"
                            class="rounded-full border px-2.5 py-0.5 text-xs font-medium"
                            :class="statusLabels[carrier.account.status].class"
                        >
                            {{ t(statusLabels[carrier.account.status].text) }}
                        </span>
                    </div>

                    <p class="mt-2 text-xs text-stone-500">
                        {{ carrier.autoTracking
                            ? t('Durum kendiliğinden izlenir: kargo paketi teslim alınca takip numarası kanala bildirilir.')
                            : t('Firma durum sorgusu sunmuyor: paketi kuryeye verince sipariş ekranından "Kargoya verdim" deyin; takip numarası kanala gider.') }}
                    </p>

                    <p v-if="errors['carrier_' + carrier.code]" class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
                        {{ errors['carrier_' + carrier.code] }}
                    </p>

                    <div v-if="carrier.account && !editing[carrier.code]" class="mt-4 space-y-1 text-sm text-stone-700">
                        <p>{{ t('Kullanıcı') }}: <span class="font-mono">{{ carrier.account.username }}</span></p>
                        <p>
                            {{ t('Ortam') }}:
                            {{ carrier.account.environment === 'test' ? t('Test ortamı (gerçek gönderi açılmaz)') : t('Canlı') }}
                        </p>
                        <p v-if="carrier.account.lastError" class="text-xs text-red-700">{{ carrier.account.lastError }}</p>
                        <div class="flex gap-2 pt-3">
                            <button type="button" class="rounded-md border border-stone-300 px-3 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50" @click="editing[carrier.code] = true">
                                {{ t('Bilgileri değiştir') }}
                            </button>
                            <button type="button" class="rounded-md border border-red-200 px-3 py-1 text-xs font-medium text-red-700 hover:bg-red-50" @click="remove(carrier)">
                                {{ t('Kaldır') }}
                            </button>
                        </div>
                    </div>

                    <form v-else class="mt-4 space-y-3" @submit.prevent="save(carrier.code)">
                        <label class="block text-xs font-medium text-stone-700">
                            {{ t('Web servis kullanıcı adı') }}
                            <input v-model="forms[carrier.code].username" type="text" autocomplete="off" required class="mt-1 w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm" />
                        </label>
                        <label class="block text-xs font-medium text-stone-700">
                            {{ t('Web servis şifresi') }}
                            <input v-model="forms[carrier.code].password" type="password" autocomplete="new-password" required class="mt-1 w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm" />
                        </label>
                        <label class="block text-xs font-medium text-stone-700">
                            {{ t('Ortam') }}
                            <select v-model="forms[carrier.code].environment" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm">
                                <option value="live">{{ t('Canlı') }}</option>
                                <option value="test">{{ t('Test ortamı (gerçek gönderi açılmaz)') }}</option>
                            </select>
                        </label>
                        <p class="text-xs text-stone-500">
                            {{ t('Web servis bilgilerini kargo şubenizden ya da müşteri temsilcinizden alırsınız. Kaydetmeden önce firmada denenir.') }}
                        </p>
                        <div class="flex gap-2">
                            <button
                                type="submit"
                                :disabled="forms[carrier.code].processing"
                                class="rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {{ t('Bağla') }}
                            </button>
                            <button v-if="carrier.account" type="button" class="rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-700" @click="editing[carrier.code] = false">
                                {{ t('Vazgeç') }}
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </WriteGuard>
    </PanelLayout>
</template>
