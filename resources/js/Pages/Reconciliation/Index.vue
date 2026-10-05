<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatCard from '../../Components/StatCard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, k, useI18n } from '../../lib/i18n';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    last_run: { type: Object, default: null },
    filters: { type: Object, default: () => ({}) },
});

const { t } = useI18n();

/**
 * FLASH EKRAN BAŞINA RENDER EDİLİR — layout onu GÖSTERMEZ.
 *
 * `HandleInertiaRequests::share()` `flash.success`'i her isteğe koyar ama
 * onu ÇİZEN bir ortak yer yoktur; her ekran kendi şeridini basar
 * (`Failures`, `Products/Channels`, `Products/Import` böyle yapıyor).
 *
 * Bu satır olmadan kullanıcı düğmeye basar, karar YAZILIR ve ekranda
 * hiçbir şey "oldu" demez — satırın listeden düşmesi tek geri bildirim
 * olurdu ve satıcı bunu bir hata sanardı. GERÇEK TARAYICI
 * ÇALIŞTIRMASINDA bulundu: `assertSessionHas('success')` diyen test
 * YEŞİLDİ, çünkü oturumda mesaj gerçekten vardı — çizilmiyordu.
 */
const flash = computed(() => usePage().props.flash?.success ?? null);

function applyFilter(filter) {
    router.get('/reconciliation', { filter }, {
        preserveState: true,
        preserveScroll: true,
    });
}

/**
 * Durum rozetleri. Sıra ve renk ÖNEMLİDİR.
 *
 * MANUAL_REVIEW en ağırıdır: otomatik onarım orada DURDU (§10 · 3 tur
 * kuralı) ve satır kendiliğinden düzelmeyecek. Sıradan bir sürüklenmeyle
 * aynı renkte gösterilseydi satıcı "sistem hallediyor" sanır ve tam olarak
 * müdahale bekleyen satırı hiç görmezdi.
 *
 * REPAIR_QUEUED sakin bir renktedir: onarım YOLDA ve satıcının yapacağı
 * bir şey yok.
 */
const badges = {
    /*
     * FİYAT ÇAKIŞMASI EN AĞIRDIR ve KENDİ RENGİNİ TAŞIR.
     *
     * Diğer tüm durumlarda sistem bir şey YAPIYOR (onarıyor, deniyor,
     * durdurmuş); burada sistem BİLEREK BEKLİYOR ve satıcı karar verene
     * kadar hiçbir şey olmaz (§9 · PRICE: "üzerine yazma, kullanıcı
     * seçer"). `MANUAL_REVIEW` ile aynı kırmızıyı paylaşsaydı ikisi
     * "bozuk" gibi okunurdu — oysa çakışma bir ARIZA DEĞİL, bir SORUDUR.
     *
     * Mor (`violet-*`) "karar bekliyor" demektir: amber zaten uyarı, red
     * zaten hata. Eskiden marka tonuydu; marka kırmızı-turuncuya (#CE310D)
     * geçince bu rozet yanındaki kırmızı ELLE İNCELEME ile aynı görünecekti.
     */
    PRICE_CONFLICT: {
        text: k('Fiyat çakışması'),
        class: 'bg-violet-50 text-violet-800 border-violet-300',
    },
    MANUAL_REVIEW: {
        text: k('Elle inceleme'),
        class: 'bg-red-50 text-red-900 border-red-300',
    },
    DRIFT_DETECTED: {
        text: k('Farklılık var'),
        class: 'bg-amber-50 text-amber-900 border-amber-300',
    },
    REPAIR_QUEUED: {
        text: k('Onarılıyor'),
        class: 'bg-sky-50 text-sky-800 border-sky-200',
    },
    REMOTE_MISSING: {
        text: k('Kanalda yok'),
        class: 'bg-amber-50 text-amber-900 border-amber-300',
    },
    REMOTE_UNREACHABLE: {
        text: k('Kanal okunamadı'),
        class: 'bg-stone-100 text-stone-700 border-stone-300',
    },
    REPAIRED: {
        text: k('Onarıldı'),
        class: 'bg-emerald-50 text-emerald-800 border-emerald-200',
    },
    MATCHED: {
        text: k('Eşleşti'),
        class: 'bg-emerald-50 text-emerald-800 border-emerald-200',
    },
};

/** Aday seçim sebebi — "bu satıra neden bakıldı". */
const reasons = {
    recently_sold: k('Yeni satış'),
    previous_error: k('Önceki hata'),
    stale_sync: k('Bekleyen senkron'),
    drift_detected: k('Doğrulama turu'),
    sampled: k('Örneklem'),
};

const scopes = {
    hot: k('Sıcak (5 dk)'),
    warm: k('Ilık (saatlik)'),
    cold: k('Soğuk (günlük)'),
};

const lastRunText = computed(() => {
    if (!props.last_run) {
        return null;
    }

    const scope = scopes[props.last_run.scope] ? t(scopes[props.last_run.scope]) : props.last_run.scope;
    const when = props.last_run.finishedAt ?? props.last_run.startedAt;

    if (!when) {
        return scope;
    }

    return `${scope} · ${new Date(when).toLocaleString(intlLocale())}`;
});

function badgeFor(status) {
    const badge = badges[status];

    return badge
        ? { ...badge, text: t(badge.text) }
        : { text: status, class: 'bg-stone-50 text-stone-600 border-stone-200' };
}

function reasonFor(reason) {
    return reasons[reason] ? t(reasons[reason]) : reason;
}

/**
 * Karar gönderimi — `busy` + `:disabled` + `…` etiketi (panel cilası kalıbı).
 *
 * İSTEK UÇARKEN DÜĞME KİLİTLENİR: çift tıklama iki karar gönderirdi ve
 * ikincisi "kanalınkini kabul et"ten sonra gelen bir "bizimkini gönder"
 * olabilirdi — satıcı bir kez bastığını sanarken kararı tersine dönerdi.
 *
 * Kilit KALEM BAŞINADIR, ekran genelinde değil: tek bir düğmeye basmak
 * diğer satırların düğmelerini de kilitleseydi satıcı sırayla karar
 * veremezdi.
 */
const deciding = ref(null);

function decide(row, decision) {
    if (deciding.value) {
        return;
    }

    deciding.value = `${row.id}:${decision}`;

    router.post('/reconciliation/price-conflict', {
        item: row.id,
        decision,
    }, {
        preserveScroll: true,
        onFinish: () => {
            deciding.value = null;
        },
    });
}

function isDeciding(row, decision) {
    return deciding.value === `${row.id}:${decision}`;
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Gelişmiş')" :title="t('Fiyat ve stok kontrolü')">
            <template #actions>
                <p v-if="lastRunText" class="text-xs text-stone-500">
                    {{ t('Son tur: :time', { time: lastRunText }) }}
                </p>
            </template>
        </PageHeader>

        <!--
            ÜÇ SAYI, ÜÇ FARKLI EYLEM. Tek sayıda birleştirilselerdi satıcı
            hangi eylemin gerektiğini bilemezdi: elle inceleme müdahale
            ister, sürüklenme kendiliğinden onarılır, okunamayan kanal
            bağlantı sağlığına bakmayı gerektirir.
        -->
        <!-- Karar geri bildirimi — gerekçe `flash` tanımında. -->
        <p
            v-if="flash"
            class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ flash }}
        </p>

        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <!--
                AYRI SAYI, AYRI EYLEM: sürüklenme kendiliğinden onarılır,
                çakışma KARAR bekler. `warning` tonu taşır çünkü bir arıza
                değil bekleyen bir iştir; `error` olsaydı satıcı bozuk bir
                şey aramaya başlardı.
            -->
            <StatCard
                :label="t('Fiyat çakışması')"
                :value="summary.price_conflict ?? 0"
                :tone="summary.price_conflict > 0 ? 'warning' : 'neutral'"
                :hint="summary.price_conflict > 0 ? t('Kararınız bekleniyor') : null"
            />

            <StatCard
                :label="t('Elle inceleme')"
                :value="summary.manual_review ?? 0"
                :tone="summary.manual_review > 0 ? 'error' : 'neutral'"
                :hint="summary.manual_review > 0 ? t('Otomatik onarım durdu') : null"
            />

            <!-- Sürüklenme kendiliğinden onarılır: UYARI, hata değil. -->
            <StatCard
                :label="t('Sürüklenme')"
                :value="summary.drift ?? 0"
                :tone="summary.drift > 0 ? 'warning' : 'neutral'"
            />

            <!--
                `REMOTE_UNREACHABLE` sürüklenme SAYILMAZ (§10) ama ayrı
                gösterilir: sessizce yutulsaydı satıcı kanalının
                okunamadığını hiç bilmezdi.
            -->
            <StatCard :label="t('Kanal okunamadı')" :value="summary.unreachable ?? 0" />

            <StatCard :label="t('Onarıldı')" :value="summary.repaired ?? 0" tone="good" />
        </div>

        <!--
            FİYAT ÇAKIŞMASI ŞERİDİ — "ne oldu, neden bekliyoruz, ne
            yapmalısın" üçünü birden söyler.
            §9'un gerekçesi satıcıya AÇIKÇA anlatılır: kanal fiyatını
            sessizce ezmediğimizi bilmezse, sistemin bozuk olduğunu sanar.
        -->
        <div
            v-if="summary.price_conflict > 0"
            class="mt-6 rounded border border-violet-300 bg-violet-50 px-4 py-3 text-sm text-violet-900"
        >
            <span class="font-semibold">
                {{ t(':count üründe kanaldaki fiyat sizinkinden farklı.', { count: summary.price_conflict }) }}
            </span>
            {{ t('Kanal panelinden kampanya yapmış olabilirsiniz, bu yüzden fiyatı otomatik olarak değiştirmedik. Her satır için kanaldaki fiyatı kabul edebilir ya da kendi fiyatınızı gönderebilirsiniz.') }}
        </div>

        <!--
            ELLE İNCELEME UYARISI: bu satırlarda otomatik onarım DURDU ve
            kullanıcı müdahale etmezse hiçbir şey değişmeyecek.
        -->
        <div
            v-if="summary.manual_review > 0"
            class="mt-6 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900"
        >
            <span class="font-semibold">{{ t(':count ürün elle inceleme bekliyor.', { count: summary.manual_review }) }}</span>
            {{ t('Bu satırlarda kanal üç tur üst üste bizim gönderdiğimiz değeri uygulamadı; otomatik onarım durduruldu. Kanal panelinden ürünün stok yönetimi ayarını ve yetkileri kontrol edin.') }}
        </div>

        <!-- filtreler -->
        <div class="mt-8 flex flex-wrap items-center gap-3">
            <div class="flex rounded-md border border-stone-300 bg-white p-0.5">
                <button
                    type="button"
                    class="rounded px-3 py-1.5 text-sm transition"
                    :class="filters.filter === 'open'
                        ? 'bg-stone-900 text-white'
                        : 'text-stone-700 hover:bg-stone-100'"
                    @click="applyFilter('open')"
                >
                    {{ t('Açık sorunlar') }}
                </button>
                <button
                    type="button"
                    class="rounded px-3 py-1.5 text-sm transition"
                    :class="filters.filter === 'all'
                        ? 'bg-stone-900 text-white'
                        : 'text-stone-700 hover:bg-stone-100'"
                    @click="applyFilter('all')"
                >
                    {{ t('Tüm geçmiş') }}
                </button>
            </div>
        </div>

        <!--
            Asgari genişlik KIRPMAYI önler: `w-full` tablo dar ekranda
            sütunları sıkıştırır ve "Sebep" gibi metin taşıyan sütun
            okunamaz hâle gelir. Genişlik verilince kutu KAYAR.
        -->
        <div class="mt-6 overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <!-- Karar sütunu iki düğme taşıyor: asgari genişlik ONA GÖRE. -->
            <table class="w-full min-w-5xl text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left">
                    <tr>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('SKU') }}</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('Durum') }}</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('Sebep') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-stone-600">{{ t('Bizde') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-stone-600">{{ t('Kanalda') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-stone-600">{{ t('Fark') }}</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('Kontrol') }}</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('Karar') }}</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-stone-100">
                    <tr v-for="row in rows" :key="row.id" class="hover:bg-stone-50">
                        <td class="px-4 py-3">
                            <!-- SKU bir KİMLİKTİR; kelime ortasından bölünürse okunmaz. -->
                            <p class="font-mono text-sm whitespace-nowrap text-stone-900">{{ row.sku ?? '—' }}</p>
                            <p v-if="row.externalId" class="font-mono text-[11px] text-stone-500">
                                #{{ row.externalId }}
                            </p>
                        </td>

                        <td class="px-4 py-3">
                            <span
                                class="inline-block rounded border px-2 py-0.5 text-[10px] font-medium tracking-wide"
                                :class="badgeFor(row.status).class"
                            >
                                {{ badgeFor(row.status).text }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-stone-600">{{ reasonFor(row.reason) }}</td>

                        <!--
                            FAZLA SATIŞTA İKİ DEĞER AYRIŞIR: kanonik bakiye
                            negatifse kanala giden değer 0'dır ve kanaldaki 0
                            DOĞRUDUR. Yalnızca biri gösterilseydi satıcı ya
                            olmayan bir sürüklenme arar ya fazla satışı hiç
                            göremezdi (§10 · §17 · P0).
                        -->
                        <!--
                            STOK ADET, FİYAT PARA GÖSTERİR. Aynı sütun iki
                            ölçeği taşır ve ayrım `domain` alanından gelir;
                            yazılmasaydı "17 → 99" satırının stok mu fiyat mı
                            olduğu ANLAŞILMAZDI.
                        -->
                        <td class="px-4 py-3 text-right">
                            <p class="font-mono whitespace-nowrap text-stone-900">
                                {{ row.domain === 'PRICE' ? (row.our_price ?? '—') : (row.expected_remote ?? '—') }}
                            </p>
                            <p v-if="row.oversold" class="font-mono text-[11px] text-red-700">
                                {{ t('bakiye :count', { count: row.available }) }}
                            </p>
                        </td>

                        <td class="px-4 py-3 text-right font-mono whitespace-nowrap text-stone-900">
                            {{ row.domain === 'PRICE' ? (row.channel_price ?? '—') : (row.observed_remote ?? '—') }}
                        </td>

                        <!--
                            FİYATTA FARK KURUŞ CİNSİNDEN TAM SAYIDIR
                            (karşılaştırma float ile yapılamaz) ve o hâliyle
                            gösterilirse satıcı 1000'i "bin lira" sanar.
                            Liraya çevrilir.
                        -->
                        <td class="px-4 py-3 text-right font-mono whitespace-nowrap">
                            <span :class="row.drift_magnitude > 0 ? 'text-amber-900' : 'text-stone-400'">
                                <template v-if="row.drift_magnitude == null">—</template>
                                <template v-else-if="row.domain === 'PRICE'">
                                    {{ (row.drift_magnitude / 100).toFixed(2) }}
                                </template>
                                <template v-else>{{ row.drift_magnitude }}</template>
                            </span>
                        </td>

                        <td class="px-4 py-3 text-xs text-stone-500">
                            {{ row.checkedAt ? new Date(row.checkedAt).toLocaleString(intlLocale()) : '—' }}
                        </td>

                        <!--
                            KARAR SÜTUNU YALNIZCA ÇAKIŞMADA DOLAR. Diğer
                            durumlarda satıcının vereceği bir karar YOKTUR:
                            sürüklenme kendiliğinden onarılır, elle inceleme
                            kanal tarafında iş ister. Boş sütun burada
                            DÜRÜSTTÜR — düğme koymak "bir şey yapabilirsin"
                            demek olurdu.
                        -->
                        <td class="px-4 py-3">
                            <div v-if="row.status === 'PRICE_CONFLICT'" class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="rounded-md border border-stone-300 bg-white px-2.5 py-1 text-xs text-stone-800 transition hover:bg-stone-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50"
                                    :disabled="deciding !== null"
                                    @click="decide(row, 'accept_channel')"
                                >
                                    {{ isDeciding(row, 'accept_channel') ? t('Kaydediliyor…') : t('Kanalınki kalsın') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md bg-stone-900 px-2.5 py-1 text-xs text-white transition hover:bg-stone-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50"
                                    :disabled="deciding !== null"
                                    @click="decide(row, 'push_ours')"
                                >
                                    {{ isDeciding(row, 'push_ours') ? t('Gönderiliyor…') : t('Bizimki gitsin') }}
                                </button>
                            </div>
                            <span v-else class="text-xs text-stone-400">—</span>
                        </td>
                    </tr>

                    <tr v-if="rows.length === 0">
                        <td colspan="8" class="px-4 py-12 text-center">
                            <p class="text-sm text-stone-600">
                                <template v-if="last_run">
                                    {{ t('Açık sürüklenme yok — kanallardaki stok bizdekiyle uyuşuyor.') }}
                                </template>
                                <template v-else>
                                    {{ t('Henüz mutabakat turu koşmadı.') }}
                                </template>
                            </p>
                            <p v-if="!last_run" class="mt-1 text-xs text-stone-500">
                                {{ t('Turlar otomatik çalışır: sıcak 5 dakikada, ılık saatlik, soğuk günlük.') }}
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PanelLayout>
</template>
