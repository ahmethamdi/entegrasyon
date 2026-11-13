<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], required: true },
    hint: { type: String, default: null },
    /* 'neutral' | 'good' | 'warning' | 'error' */
    tone: { type: String, default: 'neutral' },
});

/*
 * ANA SAYFA KARTIYLA AYNI DİL (Yön A): beyaz kart, 14px köşe, gölge ve
 * üst renk çubuğu YOK. Durum iki sinyalle verilir — etiketin önündeki
 * nokta ve sayının rengi; renk körlüğünde ikisinden biri yetmezse
 * ipucu satırı metinle söyler.
 */
const dots = {
    neutral: null,
    good: 'bg-emerald-500',
    warning: 'bg-amber-500',
    error: 'bg-red-600',
};

const values = {
    neutral: 'text-stone-900',
    good: 'text-stone-900',
    warning: 'text-amber-800',
    error: 'text-red-800',
};
</script>

<template>
    <div class="flex flex-col gap-2 rounded-[14px] border border-stone-200 bg-white p-4 sm:p-5">
        <span class="flex items-center gap-2 text-[13px] text-stone-600">
            <span v-if="dots[tone]" class="h-2 w-2 shrink-0 rounded-full" :class="dots[tone]" aria-hidden="true" />
            {{ label }}
        </span>
        <span class="text-2xl font-bold tracking-tight tabular-nums sm:text-[30px]" :class="values[tone] ?? values.neutral">
            {{ value }}
        </span>
        <span v-if="hint" class="text-[13px] text-stone-600">{{ hint }}</span>
    </div>
</template>
