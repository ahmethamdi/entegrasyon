<script setup>
import BrandMark from '../../Components/BrandMark.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Parola sıfırlandıktan sonra buraya yönlendirilir; mesaj gösterilmezse
// satıcı sıfırlamanın işe yarayıp yaramadığını bilemez.
const page = usePage();
const success = computed(() => page.props.flash?.success);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-stone-50 px-6">
        <div class="w-full max-w-sm">
            <p>
                <BrandMark size="lg" />
            </p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-stone-900">
                Giriş yap
            </h1>

            <p v-if="success" class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                {{ success }}
            </p>

            <form class="mt-8 space-y-4" @submit.prevent="submit">
                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700">
                        E-posta
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

                <div>
                    <label for="password" class="block text-sm font-medium text-stone-700">
                        Parola
                    </label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                    <p v-if="form.errors.password" class="mt-1 text-sm text-red-700">
                        {{ form.errors.password }}
                    </p>
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 text-sm text-stone-700">
                        <input v-model="form.remember" type="checkbox" class="rounded border-stone-300">
                        Beni hatırla
                    </label>
                    <Link href="/forgot-password" class="text-sm text-stone-600 underline hover:text-stone-900">
                        Parolanı mı unuttun?
                    </Link>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Giriş yap
                </button>
            </form>

            <p class="mt-6 text-sm text-stone-600">
                Hesabın yok mu?
                <Link href="/register" class="font-medium text-stone-900 underline">
                    Kayıt ol
                </Link>
            </p>
        </div>
    </div>
</template>
