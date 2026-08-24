<script setup>
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const props = defineProps({
    cards: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
});

/**
 * Değeri BİRİMİYLE biçimler.
 *
 * Ham `1247.5` satıcıya hiçbir şey söylemez: milisaniye mi, adet mi,
 * yüzde mi? Aynı ekran üçünü birden gösterdiği için tek bir
 * biçimlendirme kuralı yeterli değildir.
 */
function format(value, unit) {
    if (value === null || value === undefined) {
        return '—';
    }

    if (unit === 'ms') {
        // Saniye ölçeğine çıkan gecikmeler milisaniye olarak okunamaz:
        // "62 000 ms" ile "62,0 sn" aynı sayıdır ama ikincisi eşikle
        // (60 sn) doğrudan karşılaştırılabilir.
        return value >= 1000
            ? `${(value / 1000).toLocaleString('tr-TR', { maximumFractionDigits: 1 })} sn`
            : `${Math.round(value)} ms`;
    }

    if (unit === 's') {
        return value >= 60
            ? `${Math.round(value / 60)} dk`
            : `${Math.round(value)} sn`;
    }

    if (unit === '%') {
        return `%${value.toLocaleString('tr-TR', { maximumFractionDigits: 1 })}`;
    }

    return value.toLocaleString('tr-TR', { maximumFractionDigits: 0 });
}

/**
 * Geçmişi bir sparkline SVG yoluna çevirir.
 *
 * Kütüphane KULLANILMAZ: on üç küçük grafik için bir çizim kütüphanesi
 * eklemek paket boyutunu kartların değerinden fazla büyütürdü. Ölçek her
 * kart için KENDİ aralığına göre kurulur — ortak ölçek kullanılsaydı
 * yüzdelik bir metrik milisaniyelik birinin yanında düz çizgi olurdu.
 */
function sparkline(history) {
    if (!history || history.length < 2) {
        return null;
    }

    const values = history.map((point) => point.value);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min;

    const width = 120;
    const height = 32;

    // SABİT SERİ ORTADAN ÇİZİLİR. `span` sıfırken `1`e sabitlemek (yaygın
    // kısayol) çizgiyi kutunun EN ALTINA koyar ve satıcı "değer dibe
    // vurdu" sanır — oysa değer hiç DEĞİŞMEDİ. Gerçek çalıştırmada
    // görüldü: beş turun beşi de aynı değeri ölçtüğünde beş kart birden
    // yanıltıcı göründü.
    if (span === 0) {
        const mid = (height / 2).toFixed(1);

        return `M0,${mid} L${width},${mid}`;
    }

    return values
        .map((value, index) => {
            const x = (index / (values.length - 1)) * width;
            // SVG'de y aşağı doğru büyür; yüksek değer YUKARIDA görünmeli.
            const y = height - ((value - min) / span) * height;

            return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
}

/**
 * Son iki ölçümün yönü — "artıyor mu" sorusunun en kısa cevabı.
 *
 * Grafiği okumaya vakti olmayan satıcı bu okla anlar. Yön TEK BAŞINA
 * yeterli değildir (küçük dalgalanma da ok üretir) ve bu yüzden rozetin
 * yerini almaz, yanında durur.
 */
function trend(history) {
    if (!history || history.length < 2) {
        return null;
    }

    const last = history[history.length - 1].value;
    const previous = history[history.length - 2].value;

    if (last === previous) {
        return null;
    }

    return last > previous ? 'up' : 'down';
}

const lastCapture = computed(() => {
    if (!props.summary.capturedAt) {
        return null;
    }

    return new Date(props.summary.capturedAt.replace(' ', 'T') + 'Z').toLocaleString('tr-TR');
});
</script>

<template>
    <PanelLayout>
        <PageHeader section="Gözlemlenebilirlik" title="Sistem sağlığı">
            <template #actions>
                <p v-if="lastCapture" class="text-xs text-stone-500">
                    Son ölçüm: {{ lastCapture }}
                </p>
            </template>
        </PageHeader>

        <!--
            AŞAN METRİK SAYISI ÜSTTE: on üç kart arasında tek bir kırmızı
            gözden kaçar ve ekranın amacı tam olarak onu göstermektir.
        -->
        <div
            v-if="summary.breaching > 0"
            class="mt-6 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900"
        >
            <span class="font-semibold">{{ summary.breaching }} metrik eşiği aştı.</span>
            Eşikler mimari dokümanın §11 tablosundan gelir; aşan metrikler aşağıda en üstte
            listelenir.
        </div>

        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="card in cards"
                :key="card.metric"
                class="rounded border p-4"
                :class="card.breaching
                    ? 'border-red-300 bg-red-50'
                    : (card.nearThreshold ? 'border-amber-300 bg-amber-50' : 'border-stone-200 bg-white')"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p
                            class="text-xs"
                            :class="card.breaching
                                ? 'text-red-800'
                                : (card.nearThreshold ? 'text-amber-900' : 'text-stone-500')"
                        >
                            {{ card.label }}
                        </p>
                        <p class="mt-0.5 font-mono text-[10px] uppercase tracking-wide text-stone-400">
                            {{ card.scopeKind }}
                        </p>
                    </div>

                    <!--
                        EŞİĞE DAYANMIŞ AMA AŞMAMIŞ DEĞER AYRI İŞARETLENİR:
                        "5 / eşik 5" aşım DEĞİLDİR ve kırmızı gösterilemez,
                        ama sessizce sıradan göstermek satıcıyı bir adım
                        ötede olduğundan habersiz bırakır.
                    -->
                    <span
                        v-if="card.breaching"
                        class="shrink-0 rounded border border-red-300 bg-white px-2 py-0.5 text-[10px] font-medium tracking-wide text-red-900"
                    >
                        EŞİK AŞILDI
                    </span>
                    <span
                        v-else-if="card.nearThreshold"
                        class="shrink-0 rounded border border-amber-300 bg-white px-2 py-0.5 text-[10px] font-medium tracking-wide text-amber-900"
                    >
                        EŞİĞE YAKIN
                    </span>
                </div>

                <div class="mt-3 flex items-end justify-between gap-3">
                    <p
                        class="text-2xl font-semibold"
                        :class="card.breaching ? 'text-red-900' : 'text-stone-900'"
                    >
                        {{ format(card.value, card.unit) }}
                        <span
                            v-if="trend(card.history) === 'up'"
                            class="align-middle text-sm text-stone-400"
                            title="Önceki ölçüme göre arttı"
                        >↑</span>
                        <span
                            v-else-if="trend(card.history) === 'down'"
                            class="align-middle text-sm text-stone-400"
                            title="Önceki ölçüme göre düştü"
                        >↓</span>
                    </p>

                    <!--
                        SPARKLINE: ekranın tüm amacı "artıyor mu" sorusudur
                        ve tek sayı onu asla cevaplayamaz. Tek ölçüm varsa
                        çizgi çizilmez — iki noktası olmayan bir eğilim
                        yoktur ve düz bir çizgi "sabit" diye yanıltırdı.
                    -->
                    <svg
                        v-if="sparkline(card.history)"
                        class="shrink-0"
                        width="120"
                        height="32"
                        viewBox="0 0 120 32"
                        preserveAspectRatio="none"
                        aria-hidden="true"
                    >
                        <path
                            :d="sparkline(card.history)"
                            fill="none"
                            stroke-width="1.5"
                            :stroke="card.breaching ? '#b91c1c' : (card.nearThreshold ? '#b45309' : '#78716c')"
                        />
                    </svg>
                </div>

                <!--
                    EŞİK GÖSTERİLİR: ham sayı tek başına "iyi mi kötü mü"
                    sorusunu cevaplamaz — satıcı 1247 ms'in normal olup
                    olmadığını bilemez.
                -->
                <p class="mt-2 text-[11px] text-stone-500">
                    Eşik: {{ format(card.threshold, card.unit) }}
                    <span v-if="card.history.length > 1" class="text-stone-400">
                        · son {{ card.history.length }} ölçüm
                    </span>
                </p>
            </div>
        </div>

        <div
            v-if="cards.length === 0"
            class="mt-8 rounded-lg border border-stone-200 bg-white px-4 py-12 text-center"
        >
            <p class="text-sm text-stone-600">Henüz ölçüm yok.</p>
            <p class="mt-1 text-xs text-stone-500">
                Metrikler saatlik toplanır; ilk tur koştuktan sonra burada görünür.
            </p>
        </div>
    </PanelLayout>
</template>
