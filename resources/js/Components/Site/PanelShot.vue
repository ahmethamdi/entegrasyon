<script setup>
import { onMounted, ref, useTemplateRef } from 'vue';

/**
 * Panelden GERÇEK ekran görüntüsü — tarayıcı ya da telefon çerçevesinde.
 *
 * Sitede uydurma HTML arayüz çizilmez: satıcı panelde ne görecekse sitede
 * de onu görmeli. Görseller `public/images/site/` altına sonradan konur;
 * dosya henüz yoksa ya da yüklenemezse kırık görsel simgesi yerine aynı
 * ölçüde nötr bir kutu ve açıklama gösterilir — sayfa düzeni kaymaz.
 */
const props = defineProps({
    src: { type: String, required: true },
    alt: { type: String, required: true },
    // Gerçek piksel oranı: genişlik/yükseklik verilmezse görsel gelince sayfa kayar (CLS).
    width: { type: Number, default: 1440 },
    height: { type: Number, default: 900 },
    device: { type: String, default: 'browser' }, // browser | phone
    eager: { type: Boolean, default: false },
    /*
     * Dar ekran kırpımında görselin ne kadar yukarı kayacağı (kutu
     * genişliğinin yüzdesi). Asıl anlatılan öğe ekranın altındaysa
     * (ör. kargo formu) kırpımın dışında kalmasın diye.
     */
    mobileShiftY: { type: Number, default: 0 },
});

const failed = ref(false);
const img = useTemplateRef('img');

/*
 * Önbellekten gelen bozuk görselde `error` olayı Vue dinleyiciyi
 * bağlamadan önce tetiklenmiş olabilir; bağlandıktan sonra bir kez
 * elle kontrol edilir.
 */
onMounted(() => {
    const el = img.value;
    if (el && el.complete && el.naturalWidth === 0) {
        failed.value = true;
    }
});
</script>

<template>
    <figure
        class="overflow-hidden bg-white"
        :class="device === 'phone'
            ? 'rounded-[2rem] border-[6px] border-stone-900 shadow-[0_30px_60px_-30px_rgba(28,25,23,0.45)]'
            : 'rounded-xl border border-stone-300 shadow-[0_40px_80px_-40px_rgba(28,25,23,0.35)]'"
    >
        <div
            v-if="device === 'browser'"
            class="flex h-7 items-center gap-1.5 border-b border-stone-200 bg-stone-100 px-3"
            aria-hidden="true"
        >
            <span class="size-2 rounded-full bg-stone-300" />
            <span class="size-2 rounded-full bg-stone-300" />
            <span class="size-2 rounded-full bg-stone-300" />
        </div>

        <!--
            DAR EKRANDA YAKINLAŞTIRMA: 1440px'lik panel ekranı 350px'e
            küçülünce yazılar okunmaz (ölçüldü, 390 genişlikte). Telefonda
            görsel 1,75 kat büyütülür ve panelin sol kenar çubuğu (genişliğin
            ~%18'i) dışarıda kalacak şekilde kaydırılır; görünen kısım ana
            içeriktir. sm ve üstünde görsel tamamı gösterilir.
        -->
        <div
            v-if="!failed"
            :class="device === 'browser' ? 'aspect-[4/3] overflow-hidden sm:aspect-auto' : ''"
        >
            <img
                ref="img"
                :src="props.src"
                :alt="alt"
                :width="width"
                :height="height"
                :loading="eager ? 'eager' : 'lazy'"
                decoding="async"
                class="block h-auto"
                :class="device === 'browser' ? 'w-[175%] max-w-none -ml-[32%] mt-(--shot-dy) sm:mt-0 sm:ml-0 sm:w-full sm:max-w-full' : 'w-full'"
                :style="{ '--shot-dy': `-${mobileShiftY}%` }"
                @error="failed = true"
            >
        </div>
        <div
            v-else
            role="img"
            :aria-label="alt"
            class="flex w-full items-center justify-center bg-stone-100 p-6 text-center"
            :style="{ aspectRatio: `${width} / ${height}` }"
        >
            <span class="max-w-xs text-sm text-stone-500">{{ alt }}</span>
        </div>
    </figure>
</template>
