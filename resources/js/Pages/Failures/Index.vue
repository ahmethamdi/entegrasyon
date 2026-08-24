<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatCard from '../../Components/StatCard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success ?? null);

/** Aynı satıra iki kez basılmasın — resync ikinci bir operasyon açardı. */
const busy = ref(null);

function retry(id) {
    busy.value = id;

    router.post('/failures/retry', { operation: id }, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = null;
        },
    });
}

function retryAll() {
    busy.value = 'all';

    router.post('/failures/retry', { all: true }, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = null;
        },
    });
}

/**
 * Alan adları — satıcının dilinde.
 *
 * `INVENTORY_PUSH` iç bir kavramdır ve satıcıya hiçbir şey söylemez.
 */
const domains = {
    INVENTORY: 'Stok',
    PRICE: 'Fiyat',
    CONTENT: 'İçerik',
    MEDIA: 'Görsel',
};

/**
 * HATA SINIFI KULLANICIYA NE YAPACAĞINI SÖYLER.
 *
 * `AUTHENTICATION` "anahtarı yenile", `VALIDATION` "ürün verisini düzelt"
 * demektir; ikisi TAMAMEN FARKLI iş yaptırır. Yalnızca "başarısız" deseydik
 * kullanıcı "yeniden dene"yi tek çare sanır ve aynı hatayı sonsuza kadar
 * yeniden üretirdi.
 */
const errors = {
    AUTHENTICATION: {
        text: 'YETKİ',
        advice: 'Kanal anahtarını yenileyin — yeniden deneme tek başına çözmez.',
        class: 'bg-red-50 text-red-900 border-red-300',
    },
    VALIDATION: {
        text: 'DOĞRULAMA',
        advice: 'Kanal ürün verisini reddetti; veriyi düzeltip yeniden deneyin.',
        class: 'bg-red-50 text-red-900 border-red-300',
    },
    RATE_LIMITED: {
        text: 'HIZ SINIRI',
        advice: 'Kanal kotası doldu; yeniden deneme genellikle çözer.',
        class: 'bg-amber-50 text-amber-900 border-amber-300',
    },
    SERVER_ERROR: {
        text: 'KANAL HATASI',
        advice: 'Kanal 5xx döndü; yeniden deneme genellikle çözer.',
        class: 'bg-amber-50 text-amber-900 border-amber-300',
    },
    TIMEOUT: {
        text: 'ZAMAN AŞIMI',
        advice: 'İstek yanıtsız kaldı; yeniden deneme genellikle çözer.',
        class: 'bg-amber-50 text-amber-900 border-amber-300',
    },
    NETWORK: {
        text: 'AĞ',
        advice: 'Kanala ulaşılamadı; yeniden deneme genellikle çözer.',
        class: 'bg-amber-50 text-amber-900 border-amber-300',
    },
    CONFLICT: {
        text: 'ÇAKIŞMA',
        advice: 'Eşzamanlı değişiklik çakıştı; yeniden deneme genellikle çözer.',
        class: 'bg-amber-50 text-amber-900 border-amber-300',
    },
    NOT_FOUND: {
        text: 'KANALDA YOK',
        advice: 'Ürün kanalda bulunamadı; kanal panelinden kontrol edin.',
        class: 'bg-amber-50 text-amber-900 border-amber-300',
    },
};

function errorFor(errorClass) {
    return errors[errorClass] ?? {
        text: errorClass ?? 'BİLİNMEYEN',
        advice: null,
        class: 'bg-stone-100 text-stone-700 border-stone-300',
    };
}

function domainFor(domain) {
    return domains[domain] ?? domain ?? '—';
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            section="Senkron"
            title="Başarısız işlemler"
            description="Tüm denemelerini tüketen gönderimler burada listelenir. Yetki ve doğrulama hataları yeniden denemeyle çözülmez."
        >
            <template #actions>
                <button
                    v-if="rows.length > 0"
                    type="button"
                    class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="busy !== null"
                    @click="retryAll"
                >
                    {{ busy === 'all' ? 'Kuyruğa alınıyor…' : `Hepsini yeniden dene (${rows.length})` }}
                </button>
            </template>
        </PageHeader>

        <p
            v-if="flash"
            class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ flash }}
        </p>

        <!--
            İKİ SAYI, İKİ FARKLI EYLEM. Kalıcı hata (yetki, doğrulama)
            KULLANICI MÜDAHALESİ bekler; geçici hata kanal düzelince
            yeniden denemeyle geçer. Tek sayıda birleştirilselerdi satıcı
            hangi satırların kendisini beklediğini bilemezdi.
        -->
        <div class="mt-6 grid gap-3 sm:grid-cols-2">
            <StatCard label="Başarısız işlem" :value="summary.total ?? 0" />

            <StatCard
                label="Müdahale bekliyor"
                :value="summary.needs_user ?? 0"
                :tone="summary.needs_user > 0 ? 'error' : 'neutral'"
                :hint="summary.needs_user > 0 ? 'Yeniden denemeyle çözülmez' : null"
            />
        </div>

        <!--
            MÜDAHALE UYARISI: bu satırlarda yeniden deneme TEK BAŞINA
            çözmez. Yetki hatasında anahtar geçersizdir, doğrulama
            hatasında kanal veriyi reddetmiştir; ikisi de düzeltilmeden
            aynı hata tekrarlanır.
        -->
        <div
            v-if="summary.needs_user > 0"
            class="mt-6 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900"
        >
            <span class="font-semibold">{{ summary.needs_user }} işlem müdahale bekliyor.</span>
            Yetki ve doğrulama hataları yeniden denemeyle çözülmez: önce kanal anahtarını
            yenileyin veya ürün verisini düzeltin, sonra yeniden deneyin.
        </div>

        <!--
            `min-w-208` TAŞMA ÜRETMEK İÇİN DEĞİL, KIRPMAYI ÖNLEMEK
            İÇİN. Tek başına `overflow-x-auto` yetmez: tablo `w-full`
            olduğu için tarayıcı sütunları dar ekrana SIKIŞTIRIR ve en
            uzun metni taşıyan "Hata" sütunu ~40px'lik bir şeride düşüp
            kelime ortasından kırpılırdı (390px'te ölçüldü). Asgari
            genişlik verilince sütunlar doğal boyutlarını korur ve kutu
            KAYAR — kaydırma zaten tasarlanan davranıştı.
        -->
        <div class="mt-8 overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full min-w-208 text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left">
                    <tr>
                        <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">SKU</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Kanal</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Alan</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Hata</th>
                        <th class="px-4 py-2.5 text-right font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Deneme</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Zaman</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600"></th>
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

                        <td class="px-4 py-3 text-stone-700">{{ row.channel ?? '—' }}</td>

                        <td class="px-4 py-3 text-stone-700">{{ domainFor(row.domain) }}</td>

                        <td class="px-4 py-3">
                            <span
                                class="inline-block rounded border px-2 py-0.5 text-[10px] font-medium tracking-wide"
                                :class="errorFor(row.errorClass).class"
                            >
                                {{ errorFor(row.errorClass).text }}
                            </span>

                            <p v-if="row.errorMessage" class="mt-1 max-w-md text-xs text-stone-600">
                                {{ row.errorMessage }}
                            </p>

                            <!--
                                TAVSİYE HATA SINIFINDAN GELİR: "ne yapmalıyım"
                                sorusuna cevap vermeyen bir hata ekranı destek
                                yükünü azaltmaz, artırır.
                            -->
                            <p
                                v-if="row.needsUser && errorFor(row.errorClass).advice"
                                class="mt-1 max-w-md text-xs text-red-800"
                            >
                                {{ errorFor(row.errorClass).advice }}
                            </p>
                        </td>

                        <!--
                            "BEŞ KEZ DENENDİ" İLE "HİÇ DENENMEDİ" FARKLI
                            SORUNLARDIR: sıfır deneme worker'ın hiç
                            çalışmadığını, beş deneme kanalın gerçekten
                            reddettiğini söyler.
                        -->
                        <td class="px-4 py-3 text-right font-mono text-stone-700">
                            {{ row.attemptCount }}
                        </td>

                        <td class="px-4 py-3 text-xs text-stone-500">
                            {{ row.failedAt ? new Date(row.failedAt).toLocaleString('tr-TR') : '—' }}
                        </td>

                        <td class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="busy !== null"
                                @click="retry(row.id)"
                            >
                                {{ busy === row.id ? 'Kuyruğa alınıyor…' : 'Yeniden dene' }}
                            </button>
                        </td>
                    </tr>

                    <tr v-if="rows.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center">
                            <p class="text-sm text-stone-600">
                                Başarısız işlem yok — tüm gönderimler kanala ulaştı.
                            </p>
                            <p class="mt-1 text-xs text-stone-500">
                                Bir gönderim tüm denemelerini tüketirse burada listelenir.
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PanelLayout>
</template>
