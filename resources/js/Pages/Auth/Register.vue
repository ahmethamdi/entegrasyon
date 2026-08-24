<script setup>
import { Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    company: '',
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
            <p class="font-mono text-lg uppercase tracking-widest text-stone-900">
                Entegrasyon
            </p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-stone-900">
                Hesap oluştur
            </h1>
            <p class="mt-2 text-sm text-stone-600">
                Şirketin için bir çalışma alanı açılır ve varsayılan depon hazırlanır.
            </p>

            <form class="mt-8 space-y-4" @submit.prevent="submit">
                <div>
                    <label for="name" class="block text-sm font-medium text-stone-700">
                        Ad soyad
                    </label>
                    <input
                        id="name"
                        v-model="form.name"
                        type="text"
                        required
                        autofocus
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
                    >
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-700">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div>
                    <label for="company" class="block text-sm font-medium text-stone-700">
                        Şirket adı
                    </label>
                    <input
                        id="company"
                        v-model="form.company"
                        type="text"
                        required
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
                    >
                    <p v-if="form.errors.company" class="mt-1 text-sm text-red-700">
                        {{ form.errors.company }}
                    </p>
                </div>

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
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
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
                        autocomplete="new-password"
                        required
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
                    >
                    <p v-if="form.errors.password" class="mt-1 text-sm text-red-700">
                        {{ form.errors.password }}
                    </p>
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-stone-700">
                        Parola tekrar
                    </label>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
                    >
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Hesap oluştur
                </button>
            </form>

            <p class="mt-6 text-sm text-stone-600">
                Zaten hesabın var mı?
                <Link href="/login" class="font-medium text-stone-900 underline">
                    Giriş yap
                </Link>
            </p>
        </div>
    </div>
</template>
