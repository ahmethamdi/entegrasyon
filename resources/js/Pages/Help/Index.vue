<script setup>
import { ref } from 'vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

defineProps({
    sections: { type: Array, default: () => [] },
});

/*
 * Açık soru — AYNI ANDA YALNIZCA BİRİ.
 *
 * Hepsi açık başlasaydı ekran uzun bir metin duvarı olur ve satıcı
 * aradığı soruyu bulmak için kaydırmak zorunda kalırdı; yardım
 * ekranının işi tam olarak aramayı kısaltmaktır. Başlıklar açıkken
 * sayfa tek bakışta taranabiliyor.
 *
 * Anahtar `bölüm:soru` biçiminde: yalnızca sıra numarası kullanılsaydı
 * iki bölümdeki aynı numaralı sorular birlikte açılırdı.
 */
const open = ref(null);

function toggle(key) {
    open.value = open.value === key ? null : key;
}
</script>

<template>
    <PanelLayout>
        <div>
            <p class="font-mono text-[10px] uppercase tracking-widest text-stone-500">
                Destek
            </p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">
                Yardım
            </h1>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-stone-600">
                Panelde sık karşılaşılan durumlar ve sistemin neden öyle davrandığı.
                Aradığınızı bulamazsanız ilgili ekrandaki uyarı metinleri de aynı
                gerekçeleri anlatır.
            </p>
        </div>

        <!--
            Bölüm kimlikleri (`id`) SÖZLEŞMEDİR: başka ekranlardan
            `/help#stok` gibi doğrudan bağlantı verilebilsin diye sabit
            tutulur ve değiştirilirse o bağlantılar sessizce kırılır.
        -->
        <section
            v-for="section in sections"
            :id="section.id"
            :key="section.id"
            class="mt-10 scroll-mt-6"
        >
            <h2 class="text-lg font-semibold tracking-tight text-stone-900">
                {{ section.title }}
            </h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-stone-600">
                {{ section.intro }}
            </p>

            <div class="mt-4 divide-y divide-stone-100 overflow-hidden rounded border border-stone-200 bg-white">
                <div v-for="(item, index) in section.items" :key="index">
                    <button
                        type="button"
                        class="flex w-full items-start justify-between gap-4 px-4 py-3 text-left transition hover:bg-stone-50"
                        :aria-expanded="open === `${section.id}:${index}`"
                        @click="toggle(`${section.id}:${index}`)"
                    >
                        <span class="text-sm font-medium text-stone-900">{{ item.q }}</span>
                        <span
                            class="mt-0.5 shrink-0 font-mono text-xs text-stone-400"
                            aria-hidden="true"
                        >{{ open === `${section.id}:${index}` ? '−' : '+' }}</span>
                    </button>

                    <p
                        v-if="open === `${section.id}:${index}`"
                        class="max-w-2xl px-4 pb-4 text-sm leading-relaxed text-stone-700"
                    >
                        {{ item.a }}
                    </p>
                </div>
            </div>
        </section>
    </PanelLayout>
</template>
