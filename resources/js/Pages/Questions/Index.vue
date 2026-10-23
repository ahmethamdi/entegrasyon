<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, k, useI18n } from '../../lib/i18n';
import { usePermissions } from '../../lib/permissions.js';

const props = defineProps({
    questions: { type: Object, required: true },
    filter: { type: String, default: 'unanswered' },
    unansweredCount: { type: Number, default: 0 },
});

const { t } = useI18n();
// S7 · soru cevabı depo işi; salt okunur üye cevap formunu görmez.
const { can } = usePermissions();
const page = usePage();
const flash = computed(() => page.props.flash ?? {});

/*
 * KUYRUĞA ALINDI ≠ CEVAPLANDI. "Gönderiliyor" ve "kanala iletildi"
 * ayrı gösterilir; "Cevaplandı" yalnız kanal öyle dediğinde (durum).
 */
const answerTexts = {
    pending: k('Cevap gönderiliyor'),
    submitted: k('Cevap kanala iletildi, yayın onayı bekleniyor'),
    rejected: k('Kanal cevabı reddetti'),
    failed: k('Cevap gönderilemedi'),
    remote_state_unknown: k('Cevabın kanala ulaşıp ulaşmadığı bilinmiyor'),
};

const drafts = ref({});
const busy = ref(null);

function dateText(iso) {
    return iso ? new Date(iso).toLocaleString(intlLocale()) : '—';
}

function setFilter(value) {
    router.get('/questions', { filter: value === 'unanswered' ? undefined : value }, { preserveScroll: true });
}

function send(question) {
    if (busy.value) return;

    busy.value = question.id;
    router.post(`/questions/${question.id}/answer`, { text: drafts.value[question.id] ?? '' }, {
        preserveScroll: true,
        onSuccess: () => { drafts.value[question.id] = ''; },
        onFinish: () => { busy.value = null; },
    });
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Siparişler')" :title="t('Müşteri soruları')" />

        <p v-if="flash.success" class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            {{ flash.success }}
        </p>
        <p v-if="flash.warning" class="mt-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ flash.warning }}
        </p>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <div class="flex rounded-md border border-stone-300 bg-white p-0.5 text-sm">
                <button
                    type="button"
                    class="rounded px-3 py-1.5 transition"
                    :class="filter === 'unanswered' ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'"
                    @click="setFilter('unanswered')"
                >
                    {{ t('Cevap bekleyenler') }} ({{ unansweredCount }})
                </button>
                <button
                    type="button"
                    class="rounded px-3 py-1.5 transition"
                    :class="filter === 'all' ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'"
                    @click="setFilter('all')"
                >
                    {{ t('Hepsi') }}
                </button>
            </div>
        </div>

        <div v-if="questions.data.length === 0" class="mt-6 rounded-lg border border-stone-200 bg-white px-4 py-12 text-center text-sm text-stone-600">
            {{ filter === 'unanswered' ? t('Cevap bekleyen müşteri sorusu yok.') : t('Henüz müşteri sorusu gelmedi.') }}
        </div>

        <ul class="mt-6 space-y-4">
            <li v-for="question in questions.data" :key="question.id" class="rounded-lg border border-stone-200 bg-white px-4 py-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <p class="text-xs text-stone-600">
                        {{ question.channel }} · {{ question.connection }} · {{ question.product ?? question.productRef ?? '—' }} · {{ dateText(question.askedAt) }}
                    </p>
                    <span class="rounded border border-stone-300 bg-stone-50 px-2 py-0.5 text-xs text-stone-800">{{ question.statusLabel }}</span>
                </div>

                <p class="mt-2 text-sm text-stone-900">{{ question.body }}</p>

                <div v-if="question.answerBody" class="mt-3 rounded-md bg-stone-50 px-3 py-2 text-sm text-stone-800">
                    <p class="text-xs text-stone-500">{{ t('Yayındaki cevap') }} · {{ dateText(question.answeredAt) }}</p>
                    {{ question.answerBody }}
                </div>

                <p v-if="question.answerStatus" class="mt-2 text-xs" :class="['failed', 'rejected', 'remote_state_unknown'].includes(question.answerStatus) ? 'text-amber-800' : 'text-stone-600'">
                    {{ t(answerTexts[question.answerStatus] ?? question.answerStatus) }}
                    <span v-if="question.answerReason">— {{ question.answerReason }}</span>
                </p>
                <p v-if="question.submittedAnswer && !question.answerBody" class="mt-1 text-xs text-stone-500">“{{ question.submittedAnswer }}”</p>

                <template v-if="question.answerable">
                    <p v-if="!question.answerSupported" class="mt-3 text-xs text-stone-600">
                        {{ t('Bu kanalda soru 34Pazar\'dan cevaplanamıyor.') }}
                    </p>
                    <p v-else-if="!question.answerEnabled" class="mt-3 text-xs text-stone-600">
                        {{ t('Bu kanalda "Soru cevabı" izni kapalı. Kanallar ekranından açabilirsiniz.') }}
                        <Link href="/channels" class="underline">{{ t('Kanallara git') }}</Link>
                    </p>
                    <form v-else-if="can('orders.work')" class="mt-3 grid gap-2" @submit.prevent="send(question)">
                        <textarea
                            v-model="drafts[question.id]"
                            rows="3"
                            minlength="10"
                            maxlength="2000"
                            required
                            class="w-full rounded-md border border-stone-300 px-2 py-1.5 text-sm"
                            :placeholder="t('Cevabınız (10–2000 karakter). Cevap herkese açık yayınlanır.')"
                        />
                        <button type="submit" class="w-fit rounded-md bg-stone-900 px-3 py-1.5 text-sm text-white transition hover:bg-stone-700 disabled:opacity-50" :disabled="busy !== null">
                            {{ t('Cevabı gönder') }}
                        </button>
                    </form>
                </template>
            </li>
        </ul>

        <div v-if="questions.prev_page_url || questions.next_page_url" class="mt-6 flex justify-between text-sm">
            <Link v-if="questions.prev_page_url" :href="questions.prev_page_url" class="underline">{{ t('Önceki') }}</Link>
            <span v-else />
            <Link v-if="questions.next_page_url" :href="questions.next_page_url" class="underline">{{ t('Sonraki') }}</Link>
        </div>
    </PanelLayout>
</template>
