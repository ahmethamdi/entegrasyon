<script setup>
import BrandMark from '../../Components/BrandMark.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from '../../lib/i18n';

const props = defineProps({
    status: { type: String, default: null },
    // Bot kalkanı: form açılışının imzalı zaman damgası (FormShield).
    formStamp: { type: String, default: '' },
});

const { t } = useI18n();

const form = useForm({ email: '', website: '', ft: props.formStamp });

/*
 * Damga reddedilip (çok hızlı / eski) sayfa geri döndüğünde sunucu YENİ
 * damga verir; form verisi korunduğu için eskisi kalırdı ve "yeniden
 * gönder" denen kullanıcı aynı hatayı tekrar alırdı.
 */
watch(() => props.formStamp, (stamp) => {
    form.ft = stamp;
});

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
            <p v-if="status" class="mt-6 rounded-[10px] border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                {{ status }} {{ t('Birkaç dakika içinde gelmezse gereksiz klasörünü kontrol et.') }}
            </p>

            <form class="mt-8 space-y-4" @submit.prevent="submit">
                <!--
                    BOT KALKANI (bkz. FormShield): ekranda olmayan tuzak alan.
                    İnsan görmez ve sekmeyle de ulaşamaz; formu körlemesine
                    dolduran bot doldurur ve istek sessizce düşer.
                -->
                <div style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden" aria-hidden="true">
                    <label>Website<input v-model="form.website" name="website" type="text" tabindex="-1" autocomplete="off"></label>
                </div>

                <p v-if="form.errors.ft" class="rounded-[10px] border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                    {{ form.errors.ft }}
                </p>

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
                        class="mt-1 w-full rounded-[10px] border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                    <p v-if="form.errors.email" class="mt-1 text-sm text-red-700">
                        {{ form.errors.email }}
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-[10px] bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
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
