<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { useI18n } from '../../lib/i18n';

const { t } = useI18n();

const form = useForm({ name: '', feed_url: '' });

function submit() {
    form.post('/suppliers');
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            :section="t('Tedarikçi XML')"
            :title="t('Tedarikçi ekle')"
            :description="t('Toptancının sana verdiği XML adresini yapıştır. Adres https ile başlamalı.')"
        />

        <form class="mt-8 max-w-xl space-y-5" @submit.prevent="submit">
            <label class="block">
                <span class="block text-sm font-medium text-stone-900">{{ t('Tedarikçi adı') }}</span>
                <input v-model="form.name" type="text" maxlength="120" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-none">
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-700">{{ form.errors.name }}</p>
            </label>

            <label class="block">
                <span class="block text-sm font-medium text-stone-900">{{ t('XML adresi') }}</span>
                <input v-model="form.feed_url" type="url" placeholder="https://" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 font-mono text-sm focus:border-ring focus:outline-none">
                <p class="mt-1 text-xs text-stone-500">{{ t('Adres şifreli saklanır; içindeki erişim anahtarı ekranda gösterilmez.') }}</p>
                <p v-if="form.errors.feed_url" class="mt-1 text-sm text-red-700">{{ form.errors.feed_url }}</p>
            </label>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-700 disabled:opacity-50">
                    {{ t('Devam') }}
                </button>
                <Link href="/suppliers" class="text-sm text-stone-600 underline">{{ t('Vazgeç') }}</Link>
            </div>
        </form>
    </PanelLayout>
</template>
