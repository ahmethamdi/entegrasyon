<script setup>
import BrandMark from '../../Components/BrandMark.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    // Shopify'dan kuruluyorsa mağaza adı ve sahibinin e-postası (anahtar yok).
    shopifyInstall: { type: Object, default: null },
});

const form = useForm({
    name: '',
    email: props.shopifyInstall?.email ?? '',
    company: props.shopifyInstall?.name ?? '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-stone-50 px-6 py-12">
        <div class="w-full max-w-sm">
            <p>
                <BrandMark size="lg" />
            </p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-stone-900">
                {{ $t('Hesap oluştur') }}
            </h1>
            <p class="mt-2 text-sm text-stone-600">
                {{ $t('Şirketin için bir çalışma alanı açılır ve varsayılan depon hazırlanır.') }}
            </p>

            <!--
                SHOPIFY'DAN KURULUM: mağaza onayı alındı, anahtar oturumda
                bekliyor. Satıcı adres yazmaz; hesabı açınca bağlantı kendiliğinden
                tamamlanır.
            -->
            <div v-if="shopifyInstall" class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                <p class="font-medium">{{ $t(':shop onaylandı.', { shop: shopifyInstall.name ?? shopifyInstall.shop }) }}</p>
                <p class="mt-1">{{ $t('34Pazar hesabını aç, Shopify mağazan otomatik bağlansın. Hesabın varsa giriş yap.') }}</p>
            </div>

            <form class="mt-8 space-y-4" @submit.prevent="submit">
                <div>
                    <label for="name" class="block text-sm font-medium text-stone-700">
                        {{ $t('Ad soyad') }}
                    </label>
                    <input
                        id="name"
                        v-model="form.name"
                        type="text"
                        required
                        autofocus
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-700">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div>
                    <label for="company" class="block text-sm font-medium text-stone-700">
                        {{ $t('Şirket adı') }}
                    </label>
                    <input
                        id="company"
                        v-model="form.company"
                        type="text"
                        required
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                    <p v-if="form.errors.company" class="mt-1 text-sm text-red-700">
                        {{ form.errors.company }}
                    </p>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700">
                        {{ $t('E-posta') }}
                    </label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                    <p v-if="form.errors.email" class="mt-1 text-sm text-red-700">
                        {{ form.errors.email }}
                    </p>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-stone-700">
                        {{ $t('Parola') }}
                    </label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        required
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                    <p v-if="form.errors.password" class="mt-1 text-sm text-red-700">
                        {{ form.errors.password }}
                    </p>
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-stone-700">
                        {{ $t('Parola tekrar') }}
                    </label>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ $t('Hesap oluştur') }}
                </button>
            </form>

            <p class="mt-6 text-sm text-stone-600">
                {{ $t('Zaten hesabın var mı?') }}
                <Link href="/login" class="font-medium text-stone-900 underline">
                    {{ $t('Giriş yap') }}
                </Link>
            </p>
        </div>
    </div>
</template>
