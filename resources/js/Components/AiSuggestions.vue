<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from '../lib/i18n';
import { usePermissions } from '../lib/permissions.js';

/*
 * S8 · AI ile listeleme — bir kanal kartının öneri bölümü.
 *
 * HİÇBİR ŞEY KENDİLİĞİNDEN KANALA GİTMEZ: öneri burada görünür, satıcı
 * düzenler ve onaylar; onay mevcut yazma yollarından geçer ve kanala
 * yansıması "Yeniden gönder" iledir. Anahtar yoksa `ai` prop'u null gelir
 * ve bölüm hiç çizilmez (ebeveyn `v-if`). Depo/salt okunur üye görmez.
 */
const props = defineProps({
    productId: { type: String, required: true },
    channel: { type: Object, required: true },
    ai: { type: Object, required: true },
});

const { t } = useI18n();
const { can } = usePermissions();
const page = usePage();

const code = computed(() => props.channel.channelCode);
const rules = computed(() => props.ai.rules?.[code.value] ?? null);
const own = computed(() => props.ai.suggestions.filter((s) => s.channelCode === code.value));
const content = computed(() => own.value.find((s) => s.kind === 'content') ?? null);
const attributes = computed(() => own.value.find((s) => s.kind === 'attributes') ?? null);
const category = computed(() => own.value.find((s) => s.kind === 'category') ?? null);
const current = computed(() => props.ai.contents?.[code.value] ?? null);
const error = computed(() => page.props.errors?.ai ?? null);
const applyError = computed(() => page.props.errors?.title ?? page.props.errors?.category ?? null);

/* İstek sürerken düğmeler kilitli: model 5–20 sn sürebilir, çift tık iki hak yerdi. */
const busy = ref(null);

/* Satıcının düzenlemesi — öneri değişince tazelenir. */
const draft = reactive({ title: '', description: '' });
const picked = ref([]);

watch(content, (s) => {
    draft.title = s?.payload?.title ?? '';
    draft.description = s?.payload?.description ?? '';
}, { immediate: true });

watch(attributes, (s) => {
    picked.value = (s?.payload?.items ?? []).map((i) => i.attributeId);
}, { immediate: true });

const titleLength = computed(() => [...draft.title.trim()].length);
const titleTooLong = computed(() => rules.value !== null && titleLength.value > rules.value.titleMax);

function ask(kind) {
    busy.value = kind;
    router.post(`/products/${props.productId}/ai-suggestions`, { kind, connection_id: props.channel.connectionId }, {
        preserveScroll: true,
        onFinish: () => { busy.value = null; },
    });
}

function apply(suggestion, body = {}) {
    busy.value = suggestion.id;
    router.post(`/products/${props.productId}/ai-suggestions/${suggestion.id}/apply`, body, {
        preserveScroll: true,
        onFinish: () => { busy.value = null; },
    });
}

function dismiss(suggestion) {
    router.post(`/products/${props.productId}/ai-suggestions/${suggestion.id}/dismiss`, {}, { preserveScroll: true });
}
</script>

<template>
    <section v-if="can('catalog.manage')" class="mt-4 border-t border-stone-100 pt-4" data-ai-section>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-xs font-medium text-stone-900">{{ t('AI önerisi') }}</h3>
            <p v-if="ai.remaining !== null" class="text-xs text-stone-500">
                {{ t('Bu ay kalan AI hakkı: :count', { count: ai.remaining }) }}
            </p>
        </div>
        <p class="mt-1 text-xs text-stone-500">
            {{ t('Öneriler kanala kendiliğinden gitmez; kontrol edip onaylarsın.') }}
        </p>

        <div class="mt-3 flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="busy !== null"
                @click="ask('content')"
            >
                {{ busy === 'content' ? t('Hazırlanıyor…') : t('Başlık ve açıklama öner') }}
            </button>
            <button
                v-if="channel.requirements?.categoryId"
                type="button"
                class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="busy !== null"
                @click="ask('attributes')"
            >
                {{ busy === 'attributes' ? t('Hazırlanıyor…') : t('Öznitelik öner') }}
            </button>
            <button
                v-if="channel.requirements && !channel.requirements.categoryId"
                type="button"
                class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="busy !== null"
                @click="ask('category')"
            >
                {{ busy === 'category' ? t('Hazırlanıyor…') : t('Kategori öner') }}
            </button>
        </div>

        <p v-if="error" class="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-900">{{ error }}</p>
        <p v-if="applyError" class="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-900">{{ applyError }}</p>

        <p v-if="current" class="mt-3 text-xs text-stone-600">
            <span class="font-medium text-stone-800">{{ t('Bu kanala özel başlık:') }}</span> {{ current.title }}
        </p>

        <!-- BAŞLIK + AÇIKLAMA -->
        <div v-if="content" class="mt-3 rounded-md border border-stone-200 p-3" data-ai-content>
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-medium text-stone-900">{{ t('Başlık ve açıklama') }}</p>
                <span v-if="content.stale" class="text-xs text-amber-800">{{ t('Ürün öneriden sonra değişti.') }}</span>
            </div>
            <label class="mt-2 block text-xs text-stone-600">
                {{ t('Başlık') }}
                <span :class="titleTooLong ? 'text-red-700' : 'text-stone-500'">
                    ({{ titleLength }}/{{ rules?.titleMax }}<template v-if="rules && !rules.titleVerified"> · {{ t('tahmini sınır') }}</template>)
                </span>
                <input v-model="draft.title" type="text" class="mt-1 w-full rounded-md border border-stone-300 px-2 py-1.5 text-xs text-stone-900">
            </label>
            <label class="mt-2 block text-xs text-stone-600">
                {{ t('Açıklama') }}
                <textarea v-model="draft.description" rows="6" class="mt-1 w-full rounded-md border border-stone-300 px-2 py-1.5 font-mono text-xs text-stone-900" />
            </label>
            <ul v-if="content.warnings.length" class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-amber-900">
                <li v-for="w in content.warnings" :key="w">{{ w }}</li>
            </ul>
            <div class="mt-3 flex gap-2">
                <button
                    type="button"
                    class="rounded-md bg-stone-900 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="busy !== null || titleTooLong"
                    @click="apply(content, { title: draft.title, description: draft.description })"
                >
                    {{ t('Onayla ve kaydet') }}
                </button>
                <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 hover:bg-stone-100" @click="dismiss(content)">
                    {{ t('Vazgeç') }}
                </button>
            </div>
        </div>

        <!-- ÖZNİTELİKLER -->
        <div v-if="attributes" class="mt-3 rounded-md border border-stone-200 p-3" data-ai-attributes>
            <p class="text-xs font-medium text-stone-900">{{ t('Öznitelik önerileri') }}</p>
            <ul class="mt-2 space-y-1">
                <li v-for="item in attributes.payload.items" :key="item.attributeId" class="flex items-center gap-2 text-xs">
                    <input :id="`ai-${attributes.id}-${item.attributeId}`" v-model="picked" type="checkbox" :value="item.attributeId" class="rounded border-stone-300">
                    <label :for="`ai-${attributes.id}-${item.attributeId}`" class="text-stone-700">
                        {{ item.name }}<span v-if="item.required" class="text-red-700"> *</span>:
                        <span class="font-medium text-stone-900">{{ item.valueLabel ?? item.customValue }}</span>
                        <span v-if="item.customValue" class="text-stone-500"> ({{ t('serbest metin') }})</span>
                    </label>
                </li>
            </ul>
            <ul v-if="attributes.warnings.length" class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-amber-900">
                <li v-for="w in attributes.warnings" :key="w">{{ w }}</li>
            </ul>
            <div class="mt-3 flex gap-2">
                <button
                    v-if="attributes.payload.items?.length"
                    type="button"
                    class="rounded-md bg-stone-900 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="busy !== null || picked.length === 0"
                    @click="apply(attributes, { attributes: picked })"
                >
                    {{ t('Seçilenleri kaydet') }}
                </button>
                <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 hover:bg-stone-100" @click="dismiss(attributes)">
                    {{ t('Vazgeç') }}
                </button>
            </div>
        </div>

        <!-- KATEGORİ -->
        <div v-if="category" class="mt-3 rounded-md border border-stone-200 p-3" data-ai-category>
            <p class="text-xs font-medium text-stone-900">{{ t('Kategori önerisi') }}</p>
            <template v-if="category.payload.categoryId">
                <p class="mt-1 text-xs text-stone-800">{{ category.payload.path }}</p>
                <p class="mt-0.5 text-xs text-stone-500">{{ t('Güven: :confidence/100', { confidence: category.payload.confidence }) }} · {{ category.payload.reason }}</p>
                <p class="mt-1 text-xs text-stone-500">{{ t('Eşleme iç kategori düzeyindedir: aynı iç kategorideki tüm ürünler bu kategoriye gider.') }}</p>
            </template>
            <ul v-if="category.warnings.length" class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-amber-900">
                <li v-for="w in category.warnings" :key="w">{{ w }}</li>
            </ul>
            <div class="mt-3 flex gap-2">
                <button
                    v-if="category.payload.categoryId"
                    type="button"
                    class="rounded-md bg-stone-900 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="busy !== null"
                    @click="apply(category)"
                >
                    {{ t('Eşlemeyi kaydet') }}
                </button>
                <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 hover:bg-stone-100" @click="dismiss(category)">
                    {{ t('Vazgeç') }}
                </button>
            </div>
        </div>
    </section>
</template>
