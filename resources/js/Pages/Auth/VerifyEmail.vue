<script setup>
import BrandMark from '../../Components/BrandMark.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '../../lib/i18n';

const props = defineProps({
    email: { type: String, default: null },
    status: { type: String, default: null },
});

const { t } = useI18n();

/*
 * Cümle TEK anahtardır; adres kalın yazılsın diye `:email` yer
 * tutucusunun iki yanı ayrı basılır. Cümle parçalara bölünüp ayrı ayrı
 * çevrilseydi İngilizcede söz dizimi kurulamazdı.
 */
const sentParts = computed(() => t(':email adresine bir doğrulama bağlantısı gönderdik. Panele geçmek için bağlantıya tıkla. Gelmediyse gereksiz klasörünü kontrol et.').split(':email'));

const form = useForm({});

function resend() {
    form.post('/email/verification-notification');
}

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-stone-50 px-6">
        <div class="w-full max-w-sm">
            <p>
                <BrandMark size="lg" />
            </p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-stone-900">
                {{ t('E-postanı doğrula') }}
            </h1>
            <p class="mt-2 text-sm leading-relaxed text-stone-600">
                {{ sentParts[0] }}<strong class="font-medium text-stone-900">{{ props.email }}</strong>{{ sentParts[1] ?? '' }}
            </p>

            <p v-if="status" class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                {{ status }}
            </p>

            <button
                type="button"
                :disabled="form.processing"
                class="mt-8 w-full rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                @click="resend"
            >
                {{ t('Bağlantıyı yeniden gönder') }}
            </button>

            <p class="mt-6 text-sm text-stone-600">
                {{ t('Yanlış adres mi yazdın?') }}
                <button type="button" class="font-medium text-stone-900 underline" @click="logout">
                    {{ t('Çıkış yap ve yeniden kayıt ol.') }}
                </button>
            </p>
        </div>
    </div>
</template>
