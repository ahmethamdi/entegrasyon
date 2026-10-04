<script setup>
import BrandMark from '../../Components/BrandMark.vue';
import { router, useForm } from '@inertiajs/vue3';

defineProps({
    email: { type: String, default: null },
    status: { type: String, default: null },
});

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
                E-postanı doğrula
            </h1>
            <p class="mt-2 text-sm leading-relaxed text-stone-600">
                <strong class="font-medium text-stone-900">{{ email }}</strong>
                adresine bir doğrulama bağlantısı gönderdik. Panele geçmek için
                bağlantıya tıkla. Gelmediyse gereksiz klasörünü kontrol et.
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
                Bağlantıyı yeniden gönder
            </button>

            <p class="mt-6 text-sm text-stone-600">
                Yanlış adres mi yazdın?
                <button type="button" class="font-medium text-stone-900 underline" @click="logout">
                    Çıkış yap
                </button>
                ve yeniden kayıt ol.
            </p>
        </div>
    </div>
</template>
