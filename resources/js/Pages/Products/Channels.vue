<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const props = defineProps({
    product: { type: Object, required: true },
    channels: { type: Array, default: () => [] },
});

const page = usePage();

const flashSuccess = computed(() => page.props.flash?.success);

/**
 * ENGELLENEN GÖNDERİM UYARIDIR, BAŞARI DEĞİL.
 *
 * Yeşil bir "gönderiliyor" kutusu, ürün hiç gönderilmemişken satıcıyı
 * her şeyin yolunda olduğuna inandırırdı.
 */
const flashWarning = computed(() => page.props.flash?.warning);
const connectionError = computed(() => page.props.errors?.connection_id);

/** Gönderim sürerken butonu kilitle: çift tıklama iki istek atardı. */
const sending = ref(null);

/**
 * ROZET SIRASI: kalıcı hata > geçici hata > bekliyor > senkron.
 *
 * `error_permanent` kullanıcı müdahalesi bekler; "bekliyor" demek satıcıyı
 * kendiliğinden düzelecek sanmaya iter ve o satıra hiç bakmaz.
 */
const statusLabels = {
    error_permanent: 'Kalıcı hata',
    error_transient: 'Geçici hata',
    blocked: 'Engellendi',
    pending: 'Bekliyor',
    syncing: 'Gönderiliyor',
    synced: 'Senkron',
};

function statusClass(status) {
    if (status === 'error_permanent') return 'bg-red-50 text-red-800 border-red-200';
    if (status === 'error_transient') return 'bg-amber-50 text-amber-900 border-amber-300';
    if (status === 'blocked') return 'bg-amber-50 text-amber-900 border-amber-300';
    if (status === 'synced') return 'bg-emerald-50 text-emerald-800 border-emerald-200';
    return 'bg-stone-50 text-stone-600 border-stone-200';
}

/**
 * YAŞAM DÖNGÜSÜ SENKRON DURUMUNU EZER.
 *
 * Ön koşul engeli ve kanal onayı, senkron durumundan daha belirleyicidir:
 * "bekliyor" demek satıcıyı kendiliğinden düzelecek sanmaya iter, oysa
 * engellenmiş satır KULLANICI müdahalesi bekler ve onay bekleyen satır
 * KANAL'ı bekler. İkisi de "senkron sorunu" değildir.
 */
const lifecycleLabels = {
    blocked: 'Ön koşul eksik',
    pending_approval: 'Kanal onayı bekliyor',
    rejected: 'Kanal reddetti',
};

/** Gönderilmemiş kanalda rozet yok — durumu "henüz gönderilmedi". */
function statusLabel(channel) {
    if (!channel.published) return 'Gönderilmedi';

    // Yaşam döngüsü önce: engel ve onay senkron durumundan önce gelir.
    if (lifecycleLabels[channel.lifecycle]) return lifecycleLabels[channel.lifecycle];

    return statusLabels[channel.syncStatus] ?? channel.syncStatus ?? 'Bekliyor';
}

function badgeClass(channel) {
    if (!channel.published) return 'border-stone-200 bg-stone-50 text-stone-600';
    if (channel.lifecycle === 'rejected') return 'bg-red-50 text-red-800 border-red-200';
    if (channel.lifecycle === 'blocked') return 'bg-amber-50 text-amber-900 border-amber-300';
    if (channel.lifecycle === 'pending_approval') return 'bg-stone-50 text-stone-700 border-stone-300';

    return statusClass(channel.syncStatus);
}

/** Kalıcı hatalı kanal ÜSTTE: kullanıcının ilgilenmesi gereken satır o. */
const sorted = computed(() =>
    [...props.channels].sort((a, b) => {
        const rank = (c) => {
            if (!c.published) return 4;
            // Kullanıcı müdahalesi bekleyenler ÖNCE: red ve ön koşul
            // engeli kendiliğinden düzelmez.
            if (c.lifecycle === 'rejected') return 0;
            if (c.lifecycle === 'blocked') return 1;
            if (c.syncStatus === 'error_permanent') return 2;
            if (c.syncStatus === 'error_transient') return 3;
            return 5;
        };
        return rank(a) - rank(b);
    }),
);

function send(connectionId) {
    sending.value = connectionId;

    router.post(
        `/products/${props.product.id}/channels`,
        { connection_id: connectionId },
        {
            preserveScroll: true,
            onFinish: () => {
                sending.value = null;
            },
        },
    );
}
</script>

<template>
    <PanelLayout>
        <PageHeader section="Ürün · kanallar" :title="product.title">
            <template #actions>
                <Link
                    :href="`/products/${product.id}/edit`"
                    class="shrink-0 rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    Ürünü düzenle
                </Link>
            </template>

            <template #toolbar>
                <p class="font-mono text-xs text-stone-500">
                    {{ product.sku }} · içerik sürümü v{{ product.contentVersion }}
                </p>
            </template>
        </PageHeader>

        <div
            v-if="flashSuccess"
            class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ flashSuccess }}
        </div>

        <div
            v-if="flashWarning"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            {{ flashWarning }}
        </div>

        <div
            v-if="connectionError"
            class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
        >
            {{ connectionError }}
        </div>

        <!--
            Gönderilebilir kanal yoksa kullanıcıyı kanal bağlamaya yönlendir:
            "hiç kanal yok" mesajı tek başına ne yapacağını söylemiyor.
        -->
        <div
            v-if="!sorted.length"
            class="mt-10 rounded-lg border border-dashed border-stone-300 p-10 text-center"
        >
            <p class="text-sm text-stone-600">
                Ürün gönderilebilecek aktif kanal yok. Kanalın sağlık kontrolünü
                geçmiş olması gerekiyor.
            </p>
            <Link href="/channels" class="mt-3 inline-block text-sm font-medium text-stone-900 underline">
                Kanallara git
            </Link>
        </div>

        <div v-else class="mt-6 space-y-4">
            <article
                v-for="channel in sorted"
                :key="channel.connectionId"
                class="rounded-lg border border-stone-200 bg-white p-5"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="truncate text-sm font-medium text-stone-900">
                                {{ channel.label }}
                            </h2>
                            <span
                                class="rounded border px-2 py-0.5 font-mono text-[10px] uppercase tracking-wider"
                                :class="badgeClass(channel)"
                            >
                                {{ statusLabel(channel) }}
                            </span>
                            <span
                                v-if="channel.published && channel.pendingWork"
                                class="rounded-md border border-stone-300 bg-white px-2 py-0.5 font-mono text-[10px] uppercase tracking-wider text-stone-600"
                            >
                                Bekleyen iş
                            </span>
                        </div>

                        <p class="mt-1 truncate font-mono text-xs text-stone-500">
                            {{ channel.channel }} · {{ channel.account }}
                        </p>
                    </div>

                    <button
                        type="button"
                        :disabled="sending === channel.connectionId"
                        class="shrink-0 rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="send(channel.connectionId)"
                    >
                        {{ channel.published ? 'Yeniden gönder' : 'Kanala gönder' }}
                    </button>
                </div>

                <!--
                    Hata gerekçesi GİZLENMEZ: kullanıcı ancak bu metni görerek
                    başlığı mı düzeltmesi gerektiğini anlayabilir.
                -->
                <p
                    v-if="channel.lastError"
                    class="mt-3 rounded bg-red-50 px-3 py-2 font-mono text-xs text-red-900"
                >
                    {{ channel.lastError }}
                </p>

                <!--
                    RED SEBEBİ AYRI GÖSTERİLİR: senkron hatası "gönderemedik"
                    demektir, red ise "gönderdik ama kanal beğenmedi". İkisi
                    aynı kutuda birleştirilseydi satıcı hangisini
                    düzelteceğini bilemezdi.
                -->
                <p
                    v-if="channel.rejectionReason"
                    class="mt-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-900"
                >
                    Kanal reddetti: {{ channel.rejectionReason }}
                </p>

                <dl class="mt-4 grid grid-cols-2 gap-4 border-t border-stone-100 pt-4 text-xs sm:grid-cols-3">
                    <div>
                        <dt class="text-stone-500">Kanaldaki kimlik</dt>
                        <dd class="mt-0.5 font-mono text-stone-900">
                            {{ channel.externalId ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Yaşam döngüsü</dt>
                        <dd class="mt-0.5 text-stone-700">{{ channel.lifecycle ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Kanalda görüntüle</dt>
                        <dd class="mt-0.5">
                            <a
                                v-if="channel.externalUrl"
                                :href="channel.externalUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-stone-900 underline"
                            >
                                Aç
                            </a>
                            <span v-else class="text-stone-700">—</span>
                        </dd>
                    </div>
                </dl>
            </article>
        </div>
    </PanelLayout>
</template>
