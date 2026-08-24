<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const props = defineProps({
    channelTypes: { type: Array, default: () => [] },
});

const form = useForm({
    channel_type_code: props.channelTypes[0]?.code ?? '',
    label: '',
    store_url: '',
    consumer_key: '',
    consumer_secret: '',
});

function submit() {
    // Sırlar formda kalmaz: gönderim bitince temizlenir.
    form.post('/channels', {
        onFinish: () => form.reset('consumer_key', 'consumer_secret'),
    });
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            section="Kanallar"
            title="Mağaza bağla"
            description="Anahtarlar şifrelenerek saklanır ve panele bir daha gönderilmez. Kaydettikten sonra kanala bir sağlık isteği gönderilir; cevap gelmezse bağlantı beklemede kalır."
        />

        <form class="mt-8 max-w-xl space-y-5" @submit.prevent="submit">
            <div>
                <label for="channel_type_code" class="block text-sm font-medium text-stone-700">
                    Kanal
                </label>
                <select
                    id="channel_type_code"
                    v-model="form.channel_type_code"
                    required
                    class="mt-1 w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
                >
                    <option v-for="type in channelTypes" :key="type.code" :value="type.code">
                        {{ type.name }}
                    </option>
                </select>
                <p v-if="form.errors.channel_type_code" class="mt-1 text-sm text-red-700">
                    {{ form.errors.channel_type_code }}
                </p>
            </div>

            <div>
                <label for="label" class="block text-sm font-medium text-stone-700">
                    Etiket
                </label>
                <input
                    id="label"
                    v-model="form.label"
                    type="text"
                    required
                    placeholder="Ana Mağaza"
                    class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
                >
                <p class="mt-1 text-xs text-stone-500">
                    Yalnızca senin göreceğin isim; birden fazla mağazayı ayırt etmek için.
                </p>
                <p v-if="form.errors.label" class="mt-1 text-sm text-red-700">
                    {{ form.errors.label }}
                </p>
            </div>

            <div>
                <label for="store_url" class="block text-sm font-medium text-stone-700">
                    Mağaza adresi
                </label>
                <input
                    id="store_url"
                    v-model="form.store_url"
                    type="text"
                    required
                    placeholder="magaza.example.com"
                    class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
                >
                <p class="mt-1 text-xs text-stone-500">
                    Bir mağaza yalnızca tek bir hesaba bağlanabilir. Bağlantı HTTPS üzerinden kurulur.
                </p>
                <p v-if="form.errors.store_url" class="mt-1 text-sm text-red-700">
                    {{ form.errors.store_url }}
                </p>
            </div>

            <div class="rounded-lg border border-stone-200 bg-stone-50 p-4">
                <p class="text-sm font-medium text-stone-900">WooCommerce API anahtarı</p>
                <p class="mt-1 text-xs text-stone-600">
                    WooCommerce yönetiminde <span class="font-mono">Ayarlar → Gelişmiş → REST API</span>
                    altından <span class="font-mono">Okuma/Yazma</span> izinli bir anahtar üret.
                </p>

                <div class="mt-4 space-y-4">
                    <div>
                        <label for="consumer_key" class="block text-sm font-medium text-stone-700">
                            Consumer key
                        </label>
                        <input
                            id="consumer_key"
                            v-model="form.consumer_key"
                            type="text"
                            required
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="ck_..."
                            class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 font-mono text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
                        >
                        <p v-if="form.errors.consumer_key" class="mt-1 text-sm text-red-700">
                            {{ form.errors.consumer_key }}
                        </p>
                    </div>

                    <div>
                        <label for="consumer_secret" class="block text-sm font-medium text-stone-700">
                            Consumer secret
                        </label>
                        <input
                            id="consumer_secret"
                            v-model="form.consumer_secret"
                            type="password"
                            required
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="cs_..."
                            class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 font-mono text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
                        >
                        <p v-if="form.errors.consumer_secret" class="mt-1 text-sm text-red-700">
                            {{ form.errors.consumer_secret }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ form.processing ? 'Bağlanıyor…' : 'Bağla ve doğrula' }}
                </button>

                <Link href="/channels" class="text-sm text-stone-600 underline">
                    Vazgeç
                </Link>
            </div>
        </form>
    </PanelLayout>
</template>
