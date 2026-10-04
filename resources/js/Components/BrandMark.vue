<script setup>
/**
 * 34PAZAR logosu — 7 ekran (giriş sayfaları + panel başlığı) buradan
 * beslenir; logo değişince yalnız `public/images/` değişir.
 *
 * `inverse` koyu zemin içindir (beyaz "PAZAR"). Yükseklik sabit, genişlik
 * orandan: sabit genişlik verilseydi logo dar kenar çubuğunda ezilirdi.
 */
defineProps({
    size: { type: String, default: 'md' },
    inverse: { type: Boolean, default: false },
});

const heights = { sm: 'h-4', md: 'h-5', lg: 'h-8' };
</script>

<template>
    <img
        :src="inverse ? '/images/34pazar-logo-beyaz.png' : '/images/34pazar-logo.png'"
        alt="34Pazar"
        class="inline-block w-auto"
        :class="heights[size]"
    >
</template>
