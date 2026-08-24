<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], required: true },
    hint: { type: String, default: null },
    /* 'neutral' | 'good' | 'warning' | 'error' */
    tone: { type: String, default: 'neutral' },
});

/*
 * DURUM DOLGU DEĞİL ÇUBUKTUR — sidebar'ın aktif öğesiyle AYNI ilke.
 *
 * Önceki hâlde kötü durumda kartın TAMAMI `bg-red-50` oluyordu: üçlü
 * ızgarada ~300×90px'lik kırmızı bir yüzey ve hemen altındaki tablonun
 * satır tonlarıyla yarışıyordu. Durum artık 3px'lik üst çubukta.
 *
 * SAYININ RENGİ ÇUBUĞU TEKRARLAR: renk körlüğünde tek sinyale
 * güvenilmez, ikisi birden kayarsa bile sayı hâlâ okunur.
 *
 * NÖTR ÇUBUK ŞEFFAF DEĞİL `stone-200`: yalnızca kötü durumda beliren bir
 * çubuk kartın okunuşunu 3px kaydırırdı. Çubuk hep var, yalnızca RENGİ
 * değişir.
 */
const rails = {
    neutral: 'bg-stone-200',
    good: 'bg-emerald-500',
    warning: 'bg-amber-500',
    error: 'bg-red-500',
};

const values = {
    neutral: 'text-stone-900',
    good: 'text-stone-900',
    warning: 'text-amber-800',
    error: 'text-red-800',
};
</script>

<template>
    <div class="relative overflow-hidden rounded-lg border border-stone-200 bg-white p-4">
        <span class="absolute inset-x-0 top-0 h-[3px]" :class="rails[tone] ?? rails.neutral" aria-hidden="true" />

        <p class="font-mono text-[10px] uppercase tracking-wider text-stone-500">
            {{ label }}
        </p>
        <p class="mt-1.5 text-2xl font-semibold tabular-nums" :class="values[tone] ?? values.neutral">
            {{ value }}
        </p>
        <p v-if="hint" class="mt-1 text-xs text-stone-500">{{ hint }}</p>
    </div>
</template>
