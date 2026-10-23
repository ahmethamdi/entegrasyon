<script setup>
import BrandMark from '../../Components/BrandMark.vue';
import { Link, router } from '@inertiajs/vue3';

defineProps({
    // invalid | guest | mismatch
    state: { type: String, required: true },
    tenant: { type: String, default: '' },
    email: { type: String, default: '' },
    role: { type: String, default: '' },
    hasAccount: { type: Boolean, default: false },
    message: { type: String, default: '' },
});

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-stone-50 px-6 py-12">
        <div class="w-full max-w-sm">
            <p>
                <BrandMark size="lg" />
            </p>

            <template v-if="state === 'guest'">
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-stone-900">
                    {{ $t(':tenant ekibine davet edildin.', { tenant }) }}
                </h1>
                <p class="mt-2 text-sm text-stone-600">
                    {{ $t('Rolün: :role. Davet :email adresine gönderildi.', { role, email }) }}
                </p>
                <div class="mt-8 space-y-3">
                    <Link
                        v-if="hasAccount"
                        href="/login"
                        class="block w-full rounded-md bg-stone-900 px-4 py-2 text-center text-sm font-medium text-white hover:bg-stone-700"
                    >
                        {{ $t('Giriş yap ve katıl') }}
                    </Link>
                    <Link
                        v-else
                        href="/register"
                        class="block w-full rounded-md bg-stone-900 px-4 py-2 text-center text-sm font-medium text-white hover:bg-stone-700"
                    >
                        {{ $t('Hesap oluştur ve katıl') }}
                    </Link>
                </div>
            </template>

            <template v-else-if="state === 'mismatch'">
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-stone-900">
                    {{ $t('Bu davet başka bir hesaba') }}
                </h1>
                <p class="mt-2 text-sm text-stone-600">{{ message }}</p>
                <button
                    type="button"
                    class="mt-8 w-full rounded-md border border-stone-300 px-4 py-2 text-sm font-medium text-stone-800 hover:bg-white"
                    @click="logout"
                >
                    {{ $t('Çıkış yap') }}
                </button>
            </template>

            <template v-else>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-stone-900">
                    {{ $t('Davet geçersiz') }}
                </h1>
                <p class="mt-2 text-sm text-stone-600">
                    {{ $t('Bu davet bağlantısı geçersiz ya da süresi dolmuş. Hesap sahibinden yeni davet iste.') }}
                </p>
                <Link href="/login" class="mt-8 inline-block text-sm font-medium text-stone-900 underline">
                    {{ $t('Giriş ekranına dön') }}
                </Link>
            </template>
        </div>
    </div>
</template>
