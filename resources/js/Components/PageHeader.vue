<script setup>
defineProps({
    section: { type: String, required: true },
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <!--
        SAYFA BAŞLIĞI — ON ALTI EKRANIN ORTAK DESENİ TEK DOSYADA.

        Desen ekranlara kopyalanmıştı ve zamanla AYRIŞMIŞTI: bir ekranda
        `flex items-end justify-between`, ötekinde düz `<h1>`, özet
        ekranında ise üst etiket HİÇ yoktu. Tek kaynak olunca on altı
        ekran aynı dili konuşur ve yeni ekran otomatik uyar.

        ALT ÇİZGİ "sayfa kromu" ile "içerik" arasındaki sınırı çizer;
        çizgi olmadan başlık boşlukta yüzer ve ilk kart onunla aynı
        katmandaymış gibi okunur.
    -->
    <header class="border-b border-stone-200 pb-5">
        <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
                <p class="font-mono text-[10px] uppercase tracking-widest text-stone-500">
                    {{ section }}
                </p>
                <h1 class="mt-1.5 text-2xl font-semibold tracking-tight text-stone-900">
                    {{ title }}
                </h1>
                <p v-if="description" class="mt-2 max-w-2xl text-sm leading-relaxed text-stone-600">
                    {{ description }}
                </p>
            </div>

            <!-- Eylem yuvası — sağa hizalı, başlıkla aynı taban çizgisinde. -->
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </div>

        <!-- Filtre/araç yuvası — çizginin ÜSTÜNDE, başlığa bağlı kalır. -->
        <div v-if="$slots.toolbar" class="mt-5">
            <slot name="toolbar" />
        </div>
    </header>
</template>
