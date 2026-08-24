<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const props = defineProps({
    channelTypes: { type: Array, default: () => [] },
    selectedChannelType: { type: String, default: '' },
    taxonomyVersion: { type: String, default: null },
    channelCategories: { type: Array, default: () => [] },
    internalCategories: { type: Array, default: () => [] },
    optionDefinitions: { type: Array, default: () => [] },
});

const page = usePage();

const flashSuccess = computed(() => page.props.flash?.success);
const errors = computed(() => page.props.errors ?? {});

/** Hangi iç kategorinin ayrıntı paneli açık. */
const expanded = ref(null);

/** Kaydetme sürerken kilitle: çift tıklama iki istek atardı. */
const saving = ref(null);

/**
 * SIRA: eşleşmemiş üstte, sonra eksik zorunlu özniteliği olan, sonra
 * bayat, en sonda hazır olanlar. Kullanıcının ilgilenmesi gereken satır
 * üstte durmalı — hazır satırlar zaten iş istemiyor.
 */
const sortedInternal = computed(() =>
    [...props.internalCategories].sort((a, b) => rank(a) - rank(b)),
);

function rank(row) {
    if (!row.mapping) return 0;
    if (!row.mapping.ready) return 1;
    if (row.mapping.stale) return 2;
    return 3;
}

function statusLabel(row) {
    if (!row.mapping) return 'Eşleşmedi';
    if (!row.mapping.ready) return 'Zorunlu öznitelik eksik';
    if (row.mapping.stale) return 'Yeniden doğrula';
    return 'Hazır';
}

function statusClass(row) {
    if (!row.mapping) return 'border-red-200 bg-red-50 text-red-800';
    if (!row.mapping.ready) return 'border-amber-300 bg-amber-50 text-amber-900';
    if (row.mapping.stale) return 'border-stone-300 bg-white text-stone-600';
    return 'border-emerald-200 bg-emerald-50 text-emerald-800';
}

function switchChannel(event) {
    router.get('/mappings', { channel_type: event.target.value }, { preserveScroll: true });
}

function saveCategory(internalCategoryId, channelCategoryId) {
    if (!channelCategoryId) return;

    saving.value = internalCategoryId;

    router.post(
        '/mappings/category',
        { internal_category_id: internalCategoryId, channel_category_id: channelCategoryId },
        { preserveScroll: true, onFinish: () => (saving.value = null) },
    );
}

function saveAttribute(categoryId, optionDefinitionId, externalAttributeId) {
    if (!optionDefinitionId) return;

    saving.value = externalAttributeId;

    router.post(
        '/mappings/attribute',
        {
            option_definition_id: optionDefinitionId,
            channel_category_id: categoryId,
            external_attribute_id: externalAttributeId,
        },
        { preserveScroll: true, onFinish: () => (saving.value = null) },
    );
}

function toggle(id) {
    expanded.value = expanded.value === id ? null : id;
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            section="Kanal · eşleştirme"
            title="Kategori ve öznitelik eşleştirme"
            description="Ürünlerin kanalda hangi kategoriye açılacağı burada belirlenir. Eksik eşleştirmede ürün kanala gönderilemez; stok akışı etkilenmez."
        >
            <template #actions>
                <div v-if="channelTypes.length > 1" class="shrink-0">
                    <label class="block font-mono text-[10px] uppercase tracking-widest text-stone-500">
                        Kanal
                    </label>
                    <select
                        class="mt-1 rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-2 focus:outline-offset-0 focus:outline-brand-600"
                        :value="selectedChannelType"
                        @change="switchChannel"
                    >
                        <option v-for="type in channelTypes" :key="type.code" :value="type.code">
                            {{ type.name }}
                        </option>
                    </select>
                </div>
            </template>
        </PageHeader>

        <div
            v-if="flashSuccess"
            class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ flashSuccess }}
        </div>

        <div
            v-for="(message, field) in errors"
            :key="field"
            class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
        >
            {{ message }}
        </div>

        <!--
            Taksonomi hiç çekilmemişse eşleştirme yapılamaz. Kullanıcıya
            "kategori yok" demek ne yapacağını söylemiyor; sebebi yazılır.
        -->
        <div
            v-if="!channelCategories.length"
            class="mt-10 rounded-lg border border-dashed border-stone-300 p-10 text-center"
        >
            <p class="text-sm text-stone-600">
                Bu kanalın kategori ağacı henüz çekilmedi. Taksonomi günlük olarak
                güncellenir; kanal bağlıysa bir sonraki turda burada görünecek.
            </p>
            <Link href="/channels" class="mt-3 inline-block text-sm font-medium text-stone-900 underline">
                Kanallara git
            </Link>
        </div>

        <template v-else>
            <p class="mt-6 font-mono text-[10px] uppercase tracking-widest text-stone-500">
                Taksonomi sürümü {{ taxonomyVersion }} · {{ channelCategories.length }} kategori
            </p>

            <!--
                İç kategorisi olan ürün yoksa eşleştirilecek bir şey yoktur.
                Kullanıcı ürün formundaki alanı doldurmalı.
            -->
            <div
                v-if="!sortedInternal.length"
                class="mt-4 rounded-lg border border-dashed border-stone-300 p-10 text-center"
            >
                <p class="text-sm text-stone-600">
                    Hiçbir ürününüzde iç kategori tanımlı değil. Ürünü düzenleyip
                    iç kategori alanını doldurun; eşleştirme o kategoriler üzerinden yapılır.
                </p>
                <Link href="/products" class="mt-3 inline-block text-sm font-medium text-stone-900 underline">
                    Ürünlere git
                </Link>
            </div>

            <div v-else class="mt-4 space-y-3">
                <article
                    v-for="row in sortedInternal"
                    :key="row.id"
                    class="rounded-lg border border-stone-200 bg-white"
                >
                    <div class="flex items-start justify-between gap-4 p-5">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-sm font-medium text-stone-900">
                                    {{ row.id }}
                                </h2>
                                <span
                                    class="rounded border px-2 py-0.5 font-mono text-[10px] uppercase tracking-wider"
                                    :class="statusClass(row)"
                                >
                                    {{ statusLabel(row) }}
                                </span>
                            </div>

                            <p class="mt-1 font-mono text-xs text-stone-500">
                                {{ row.productCount }} ürün
                                <template v-if="row.mapping">
                                    · {{ row.mapping.categoryPath }}
                                </template>
                            </p>

                            <!--
                                EKSİK ZORUNLU ÖZNİTELİK ADIYLA GÖSTERİLİR:
                                sayı tek başına ne yapacağını söylemez.
                            -->
                            <p
                                v-if="row.mapping && row.mapping.missingRequiredAttributes.length"
                                class="mt-2 rounded bg-amber-50 px-3 py-2 text-xs text-amber-900"
                            >
                                Eksik zorunlu öznitelik:
                                {{ row.mapping.missingRequiredAttributes.join(', ') }}
                            </p>

                            <!--
                                BAYAT EŞLEŞTİRME SİLİNMEZ — yalnızca yeniden
                                doğrulama istenir. Satıcının emeği yok olmaz.
                            -->
                            <p
                                v-if="row.mapping && row.mapping.stale"
                                class="mt-2 rounded bg-stone-100 px-3 py-2 text-xs text-stone-700"
                            >
                                Bu eşleştirme {{ row.mapping.taxonomyVersion }} sürümünde yapıldı;
                                kanal o zamandan beri kategori ağacını güncelledi. Eşleştirme
                                geçerli kalır, yeniden seçerek doğrulayabilirsiniz.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="shrink-0 rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100"
                            @click="toggle(row.id)"
                        >
                            {{ expanded === row.id ? 'Kapat' : 'Eşleştir' }}
                        </button>
                    </div>

                    <div v-if="expanded === row.id" class="border-t border-stone-100 bg-stone-50 p-5">
                        <label class="block font-mono text-[10px] uppercase tracking-widest text-stone-500">
                            Kanal kategorisi
                        </label>
                        <div class="mt-1 flex gap-2">
                            <select
                                :id="`cat-${row.id}`"
                                class="w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm"
                                :value="row.mapping?.channelCategoryId ?? ''"
                                @change="saveCategory(row.id, $event.target.value)"
                            >
                                <option value="" disabled>Kategori seçin…</option>
                                <!-- YALNIZCA YAPRAKLAR: ara kategoriye ürün açılamaz. -->
                                <option
                                    v-for="category in channelCategories"
                                    :key="category.id"
                                    :value="category.id"
                                >
                                    {{ category.path }}
                                </option>
                            </select>
                        </div>

                        <!-- Zorunlu öznitelikler: kategori seçildikten sonra anlamlı. -->
                        <div v-if="row.mapping && row.mapping.requiredAttributes.length" class="mt-6">
                            <p class="font-mono text-[10px] uppercase tracking-widest text-stone-500">
                                Zorunlu öznitelikler
                                ({{ row.mapping.mappedRequiredCount }}/{{ row.mapping.requiredAttributeCount }})
                            </p>

                            <div class="mt-2 space-y-2">
                                <div
                                    v-for="attribute in row.mapping.requiredAttributes"
                                    :key="attribute.externalId"
                                    class="flex items-center gap-3 rounded-lg border border-stone-200 bg-white px-3 py-2"
                                >
                                    <span class="w-40 shrink-0 truncate text-sm text-stone-900">
                                        {{ attribute.name }}
                                    </span>

                                    <select
                                        class="w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm"
                                        :value="row.mapping.mappedAttributes[attribute.externalId] ?? ''"
                                        :disabled="saving === attribute.externalId"
                                        @change="saveAttribute(
                                            row.mapping.channelCategoryId,
                                            $event.target.value,
                                            attribute.externalId,
                                        )"
                                    >
                                        <option value="" disabled>Seçenek eşleştirin…</option>
                                        <option
                                            v-for="definition in optionDefinitions"
                                            :key="definition.id"
                                            :value="definition.id"
                                        >
                                            {{ definition.name }}
                                        </option>
                                    </select>

                                    <span
                                        class="shrink-0 rounded border px-2 py-0.5 font-mono text-[10px] uppercase tracking-wider"
                                        :class="row.mapping.mappedAttributes[attribute.externalId]
                                            ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                            : 'border-amber-300 bg-amber-50 text-amber-900'"
                                    >
                                        {{ row.mapping.mappedAttributes[attribute.externalId] ? 'Eşleşti' : 'Eksik' }}
                                    </span>
                                </div>
                            </div>

                            <p v-if="!optionDefinitions.length" class="mt-2 text-xs text-stone-600">
                                Henüz seçenek tanımınız yok (Beden, Renk gibi). Varyantlı ürün
                                oluşturduğunuzda burada görünecekler.
                            </p>
                        </div>

                        <p v-else-if="row.mapping" class="mt-6 text-xs text-stone-600">
                            Bu kategoride zorunlu öznitelik yok.
                        </p>
                    </div>
                </article>
            </div>
        </template>
    </PanelLayout>
</template>
