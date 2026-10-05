<script setup>
import BrandMark from '../../Components/BrandMark.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from '../../lib/i18n';

defineProps({
    status: { type: String, default: null },
});

const { t } = useI18n();

const form = useForm({ email: '' });

function submit() {
    form.post('/forgot-password');
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-stone-50 px-6">
        <div class="w-full max-w-sm">
            <p>
                <BrandMark size="lg" />
            </p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-stone-900">
                {{ t('Parolanı sıfırla') }}
            </h1>
            <p class="mt-2 text-sm text-stone-600">
                {{ t('Hesabının e-posta adresini yaz; parola sıfırlama bağlantısı gönderelim.') }}
            </p>

            <!-- Yanıt adresin kayıtlı olup olmadığını SÖYLEMEZ (bkz. denetleyici). -->
            <p v-if="status" class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                {{ status }} {{ t('Birkaç dakika içinde gelmezse gereksiz klasörünü kontrol et.') }}
            </p>

            <form class="mt-8 space-y-4" @submit.prevent="submit">
                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700">
                        {{ t('E-posta') }}
                    </label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        autofocus
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                    <p v-if="form.errors.email" class="mt-1 text-sm text-red-700">
                        {{ form.errors.email }}
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ t('Sıfırlama bağlantısı gönder') }}
                </button>
            </form>

            <p class="mt-6 text-sm text-stone-600">
                <Link href="/login" class="font-medium text-stone-900 underline">
                    {{ t('Girişe dön') }}
                </Link>
            </p>
        </div>
    </div>
</template>
