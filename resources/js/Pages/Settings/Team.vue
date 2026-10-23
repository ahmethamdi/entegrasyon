<script setup>
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { useI18n } from '../../lib/i18n';

const props = defineProps({
    // [{ id, name, email, role, joinedAt, isSelf }]
    members: { type: Array, default: () => [] },
    // [{ id, email, role, expiresAt, expired }]
    invitations: { type: Array, default: () => [] },
    // [{ value, label, description }]
    roles: { type: Array, default: () => [] },
    // { used, limit|null }
    seats: { type: Object, default: () => ({ used: 0, limit: null }) },
});

const { t } = useI18n();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const errors = computed(() => page.props.errors ?? {});

// Sahip rolü davetle verilmez (sunucu da reddeder) — katılandan sonra.
const inviteRoles = computed(() => props.roles.filter((r) => r.value !== 'owner'));
const roleLabel = (value) => props.roles.find((r) => r.value === value)?.label ?? value;
const seatsFull = computed(() => props.seats.limit !== null && props.seats.used >= props.seats.limit);

const form = useForm({ email: '', role: 'warehouse' });

function invite() {
    form.post('/settings/team/invitations', {
        preserveScroll: true,
        onSuccess: () => form.reset('email'),
    });
}

function changeRole(member, role) {
    router.put(`/settings/team/members/${member.id}/role`, { role }, { preserveScroll: true });
}

function remove(member) {
    const text = member.isSelf
        ? t('Kendini ekipten çıkarırsan bu firmaya erişimin biter. Devam edilsin mi?')
        : t(':name ekipten çıkarılsın mı?', { name: member.name });
    if (!window.confirm(text)) return;
    router.delete(`/settings/team/members/${member.id}`, { preserveScroll: true });
}

function resend(invitation) {
    router.post(`/settings/team/invitations/${invitation.id}/resend`, {}, { preserveScroll: true });
}

function cancel(invitation) {
    if (!window.confirm(t(':email davetini iptal et?', { email: invitation.email }))) return;
    router.delete(`/settings/team/invitations/${invitation.id}`, { preserveScroll: true });
}

function formatDate(iso) {
    return iso ? new Date(iso).toLocaleDateString(page.props.locale === 'en' ? 'en-GB' : 'tr-TR') : '';
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Hesap')" :title="t('Ekip')" />

        <p v-if="flashSuccess" class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
            {{ flashSuccess }}
        </p>
        <p v-if="errors.member" class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
            {{ errors.member }}
        </p>

        <p class="mt-6 max-w-2xl text-sm text-stone-600">
            {{ t('Ekibine kişi davet et ve her birine rol ver. Depo rolündeki kişi siparişlerde çalışır ama ürün, fiyat ve kanal ayarlarına dokunamaz.') }}
        </p>

        <section class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-medium text-stone-900">{{ t('Davet et') }}</h2>
                <p class="text-xs text-stone-500">
                    {{ seats.limit === null
                        ? t(':used kullanıcı (sınırsız)', { used: seats.used })
                        : t(':used / :limit kullanıcı', { used: seats.used, limit: seats.limit }) }}
                </p>
            </div>
            <form class="mt-4 grid gap-3 sm:grid-cols-[1fr_12rem_auto]" @submit.prevent="invite">
                <label class="block text-xs font-medium text-stone-700">
                    {{ t('E-posta') }}
                    <input v-model="form.email" type="email" required autocomplete="off" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm" />
                </label>
                <label class="block text-xs font-medium text-stone-700">
                    {{ t('Rol') }}
                    <select v-model="form.role" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm">
                        <option v-for="role in inviteRoles" :key="role.value" :value="role.value">{{ role.label }}</option>
                    </select>
                </label>
                <div class="flex items-end">
                    <button
                        type="submit"
                        :disabled="form.processing || seatsFull"
                        class="w-full rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ t('Davet gönder') }}
                    </button>
                </div>
            </form>
            <p v-if="form.errors.email" class="mt-2 text-xs text-red-700">{{ form.errors.email }}</p>
            <p v-else-if="seatsFull" class="mt-2 text-xs text-amber-800">
                {{ t('Planındaki kullanıcı sınırı doldu. Daha fazla kişi davet etmek için planını yükselt.') }}
            </p>
            <ul class="mt-4 space-y-1 text-xs text-stone-500">
                <li v-for="role in roles" :key="role.value">
                    <span class="font-medium text-stone-700">{{ role.label }}:</span> {{ role.description }}
                </li>
            </ul>
        </section>

        <section class="mt-6 rounded-lg border border-stone-200 bg-white">
            <h2 class="border-b border-stone-200 px-5 py-3 text-sm font-medium text-stone-900">{{ t('Üyeler') }}</h2>
            <ul class="divide-y divide-stone-100">
                <li v-for="member in members" :key="member.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm text-stone-900">
                            {{ member.name }}
                            <span v-if="member.isSelf" class="text-xs text-stone-500">({{ t('sen') }})</span>
                        </p>
                        <p class="truncate text-xs text-stone-500">{{ member.email }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <select
                            :value="member.role"
                            :aria-label="t('Rol')"
                            class="rounded-md border border-stone-300 px-2 py-1 text-xs"
                            @change="changeRole(member, $event.target.value)"
                        >
                            <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                        </select>
                        <button type="button" class="rounded-md border border-red-200 px-3 py-1 text-xs font-medium text-red-700 hover:bg-red-50" @click="remove(member)">
                            {{ t('Çıkar') }}
                        </button>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="invitations.length" class="mt-6 rounded-lg border border-stone-200 bg-white">
            <h2 class="border-b border-stone-200 px-5 py-3 text-sm font-medium text-stone-900">{{ t('Bekleyen davetler') }}</h2>
            <ul class="divide-y divide-stone-100">
                <li v-for="invitation in invitations" :key="invitation.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm text-stone-900">{{ invitation.email }}</p>
                        <p class="text-xs" :class="invitation.expired ? 'text-red-700' : 'text-stone-500'">
                            {{ roleLabel(invitation.role) }} ·
                            {{ invitation.expired
                                ? t('Süresi doldu')
                                : t(':date tarihine kadar geçerli', { date: formatDate(invitation.expiresAt) }) }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="rounded-md border border-stone-300 px-3 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50" @click="resend(invitation)">
                            {{ t('Yeniden gönder') }}
                        </button>
                        <button type="button" class="rounded-md border border-red-200 px-3 py-1 text-xs font-medium text-red-700 hover:bg-red-50" @click="cancel(invitation)">
                            {{ t('İptal') }}
                        </button>
                    </div>
                </li>
            </ul>
        </section>
    </PanelLayout>
</template>
