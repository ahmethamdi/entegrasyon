<script setup>
import { computed } from 'vue';
import { useI18n } from '../lib/i18n';
import { usePermissions } from '../lib/permissions.js';

/*
 * S7 · FORM EKRANI İÇİN SALT OKUNUR KİP.
 *
 * Ayar ve düzenleme ekranlarında salt okunur üye değerleri GÖRMELİ (ekran
 * ona açık), ama hiçbir alanı değiştirmemeli. Her düğmeyi tek tek gizlemek
 * yerine içerik `<fieldset disabled>` içine alınır: tarayıcı içindeki her
 * girdi ve düğmeyi kendisi kapatır, yeni eklenen düğme de unutulmaz.
 * Bağlantılar (`<a>`) etkilenmez — gezinme açık kalır.
 *
 * `display: contents` düzeni bozmaz; fieldset kutusu hiç çizilmez.
 * Yetkinin kendisi sunucudadır (`AuthorizeTenantRoute`).
 */
const props = defineProps({
    permission: { type: String, required: true },
});

const { t } = useI18n();
const { can } = usePermissions();
const allowed = computed(() => can(props.permission));
</script>

<template>
    <p
        v-if="!allowed"
        class="mt-6 rounded-lg border border-stone-200 bg-stone-50 px-4 py-3 text-xs text-stone-600"
        role="note"
    >
        {{ t('Bu ekranı yalnız görüntüleyebilirsin; değişiklik için ekip yöneticisine başvur.') }}
    </p>
    <fieldset :disabled="!allowed" class="contents">
        <slot />
    </fieldset>
</template>
