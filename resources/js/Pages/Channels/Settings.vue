<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { useI18n } from '../../lib/i18n';

const { t } = useI18n();

const props = defineProps({
    connection: { type: Object, required: true },
    fields: { type: Array, default: () => [] },
});

// ⚠️ ALANLAR SUNUCUDAN GELİR ve HEPSİ BAŞTAN TANIMLANIR: `useForm` yalnız
// kurulurken verilen anahtarları gönderir (Create.vue'daki ders).
const form = useForm(
    Object.fromEntries(props.fields.map((field) => [field.key, field.value ?? ''])),
);

// Kullanılamayan seçenekler GİZLENMEZ: satıcı Etsy'de gördüğü profili
// burada bulamasaydı nedenini bilemezdi. Nedenler alanın altında yazılır.
function blockedOptions(field) {
    return field.options.filter((option) => !option.usable && option.note);
}

function submit() {
    form.put(`/channels/${props.connection.id}/settings`, { preserveScroll: true });
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            :section="t('Kanallarım')"
            :title="t(':label ayarları', { label: connection.label })"
            :description="t('Bu ayarlar :channel\'de açılan her yeni ilana uygulanır.', { channel: connection.channel })"
        />

        <form class="mt-8 max-w-xl space-y-6" @submit.prevent="submit">
            <div v-for="field in fields" :key="field.key">
                <label :for="field.key" class="block text-sm font-medium text-stone-700">
                    {{ field.label }}
                    <span v-if="!field.required" class="font-normal text-stone-500">{{ t('(isteğe bağlı)') }}</span>
                </label>

                <p
                    v-if="field.optionsError"
                    class="mt-1 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900"
                >
                    {{ field.optionsError }}
                </p>

                <select
                    v-else
                    :id="field.key"
                    v-model="form[field.key]"
                    :required="field.required"
                    class="mt-1 w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                >
                    <option value="" :disabled="field.required">
                        {{ field.required ? t('Seç…') : t('Seçilmedi') }}
                    </option>
                    <option
                        v-for="option in field.options"
                        :key="option.value"
                        :value="option.value"
                        :disabled="!option.usable"
                    >
                        {{ option.label }}{{ option.usable ? '' : ` — ${t('kullanılamaz')}` }}
                    </option>
                </select>

                <p v-if="!field.optionsError && !field.options.length" class="mt-1 text-sm text-amber-800">
                    {{ t(':channel mağazanda bu ayar için seçenek yok.', { channel: connection.channel }) }}
                </p>

                <p v-if="field.hint" class="mt-1 text-xs text-stone-500">
                    {{ field.hint }}
                </p>

                <ul
                    v-if="blockedOptions(field).length"
                    class="mt-2 space-y-1 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900"
                >
                    <li v-for="option in blockedOptions(field)" :key="option.value">
                        <span class="font-medium">{{ option.label }}</span>: {{ option.note }}
                    </li>
                </ul>

                <p v-if="form.errors[field.key]" class="mt-1 text-sm text-red-700">
                    {{ form.errors[field.key] }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ form.processing ? t('Kaydediliyor…') : t('Kaydet') }}
                </button>
                <Link href="/channels" class="text-sm text-stone-600 underline">
                    {{ t('Vazgeç') }}
                </Link>
            </div>
        </form>
    </PanelLayout>
</template>
