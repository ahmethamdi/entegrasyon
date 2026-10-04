<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-stone-50 px-6">
        <div class="w-full max-w-sm">
            <p class="font-mono text-lg uppercase tracking-widest text-stone-900">
                Entegrasyon
            </p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-stone-900">
                Yeni parola belirle
            </h1>

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
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
                    >
                    <p v-if="form.errors.email" class="mt-1 text-sm text-red-700">
                        {{ form.errors.email }}
                    </p>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-stone-700">
                        Yeni parola
                    </label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        required
                        autofocus
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
                    >
                    <p v-if="form.errors.password" class="mt-1 text-sm text-red-700">
                        {{ form.errors.password }}
                    </p>
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-stone-700">
                        Yeni parola (tekrar)
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
                    Parolayı güncelle
                </button>
            </form>
        </div>
    </div>
</template>
