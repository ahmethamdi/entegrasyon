<script setup>
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, k, useI18n } from '../../lib/i18n';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    columns: { type: Object, default: () => ({ required: [], optional: [] }) },
    // İçe aktarmayı DESTEKLEYEN aktif bağlantılar. Desteklemeyen kanal
    // listeye hiç girmez — düğmeyi gösterip sonra hata vermek satıcıya iş
    // yaptırıp geri almaktır.
    connections: { type: Array, default: () => [] },
});

const { t } = useI18n();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const form = useForm({ file: null });
const fileInput = ref(null);

function submit() {
    form.post('/products/import', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('file');

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function onFileChange(event) {
    form.file = event.target.files[0] ?? null;
}

const channelForm = useForm({ connection_id: '' });

function submitChannel() {
    channelForm.post('/products/import/channel', { preserveScroll: true });
}

/**
 * Kaynak etiketi — aynı listede duran iki tur farklı şeyler yapmıştır.
 *
 * Dosya turunda satıcı dosyayı düzeltip yeniden yükler; kanal turunda
 * kanaldaki ürünü düzeltir. Ayrılmasaydı hangi işi yapacağını bilemezdi.
 */
const sources = {
    csv: { text: k('Dosya'), class: 'bg-stone-100 text-stone-700' },
    channel: { text: k('Kanal'), class: 'bg-sky-100 text-sky-800' },
};

function sourceFor(source) {
    return sources[source] ?? sources.csv;
}

/**
 * Durum rozetleri.
 *
 * `failed` ile `completed` arasındaki fark KRİTİK: ikincisinde bazı
 * satırlar atlanmış olabilir ama dosya İŞLENMİŞTİR; birincisinde dosya
 * hiç işlenmedi ve kullanıcının yapması gereken belli bir iş var.
 */
const badges = {
    pending: { text: k('Sırada'), class: 'bg-stone-50 text-stone-600 border-stone-200' },
    running: { text: k('İşleniyor'), class: 'bg-sky-50 text-sky-800 border-sky-200' },
    completed: { text: k('Tamamlandı'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    failed: { text: k('Başarısız'), class: 'bg-red-50 text-red-900 border-red-300' },
};

function badgeFor(status) {
    return badges[status] ?? { text: status, class: 'bg-stone-50 text-stone-600 border-stone-200' };
}

/**
 * İş bitene kadar liste kendini tazeler.
 *
 * Tur arka planda koşar; tazeleme olmasaydı satıcı "Sırada" rozetine
 * bakar ve neyin olduğunu bilemezdi. Yalnız `rows` istenir (formlar
 * ve seçimler yerinde kalır); bekleyen tur kalmayınca durur.
 */
const inFlight = computed(() => props.rows.some((row) => row.status === 'pending' || row.status === 'running'));

let pollTimer = null;

function stopPolling() {
    clearTimeout(pollTimer);
    pollTimer = null;
}

function schedulePoll() {
    stopPolling();
    pollTimer = setTimeout(() => {
        router.reload({ only: ['rows'], onFinish: () => inFlight.value && schedulePoll() });
    }, 2000);
}

watch(inFlight, (busy) => (busy ? schedulePoll() : stopPolling()), { immediate: true });
onUnmounted(stopPolling);

/**
 * Üstteki kutu SON TURUN GERÇEK DURUMUNU söyler.
 *
 * Gönderim sonrası mesaj ("çekiliyor") tek seferliktir; tur bir saniyede
 * bitse bile ekranda kalır ve satıcı işin sürdüğünü sanırdı. Bu yüzden
 * mesaj yalnız "az önce başlattın" işaretidir — ne yazılacağına son
 * satırın durumu karar verir.
 */
const latest = computed(() => props.rows[0] ?? null);

const banner = computed(() => {
    if (!flashSuccess.value) {
        return null;
    }

    const row = latest.value;

    if (row === null || inFlight.value) {
        return { busy: true, tone: 'info', text: flashSuccess.value };
    }

    if (row.status === 'failed') {
        return { busy: false, tone: 'error', text: t('İçe aktarma tamamlanamadı. :error', { error: row.lastError ?? '' }).trim() };
    }

    const parts = [t(':count yeni', { count: row.created }), t(':count güncellendi', { count: row.updated })];

    if (row.skipped > 0) {
        parts.push(t(':count atlandı', { count: row.skipped }));
    }

    if (row.errors.length > 0) {
        parts.push(t(':count uyarı — ayrıntı aşağıdaki tabloda', { count: row.errors.length }));
    }

    return { busy: false, tone: row.errors.length > 0 ? 'warn' : 'ok', text: t('Tamamlandı: :parts.', { parts: parts.join(', ') }) };
});

const bannerTones = {
    info: 'border-sky-200 bg-sky-50 text-sky-900',
    ok: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    warn: 'border-amber-200 bg-amber-50 text-amber-900',
    error: 'border-red-300 bg-red-50 text-red-900',
};

const expanded = ref(null);

/*
 * Stok kolonunun ADI dile göre değişir (`stok` / `stock`); not, satıcının
 * listede gördüğü adla konuşmalı. Sunucu kolon listesini dile göre verir.
 */
const stockColumn = computed(() => props.columns.required.find((column) => column === 'stok' || column === 'stock') ?? 'stok');

function toggleErrors(id) {
    expanded.value = expanded.value === id ? null : id;
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Ürünler')" :title="t('Toplu içe aktarma')" />

        <div
            v-if="banner"
            class="mt-6 flex items-center gap-3 rounded-lg border px-4 py-3 text-sm"
            :class="bannerTones[banner.tone]"
            role="status"
            aria-live="polite"
        >
            <span
                v-if="banner.busy"
                class="size-4 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent"
                aria-hidden="true"
            />
            {{ banner.text }}
        </div>

        <!--
            KOLON SÖZLEŞMESİ ÖNCE GÖSTERİLİR: kullanıcı dosyayı yüklemeden
            önce hangi kolonların gerektiğini bilmeli. Yükleyip hata almak
            ve sonra öğrenmek gereksiz bir tur demek.
        -->
        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <form
                    class="rounded-lg border border-stone-200 bg-white p-6"
                    @submit.prevent="submit"
                >
                    <label class="block text-sm font-medium text-stone-700">
                        {{ t('CSV dosyası') }}
                    </label>

                    <input
                        ref="fileInput"
                        type="file"
                        accept=".csv,text/csv"
                        class="mt-2 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-stone-900 file:px-3 file:py-1.5 file:text-sm file:text-white"
                        @change="onFileChange"
                    >

                    <p v-if="form.errors.file" class="mt-2 text-sm text-red-700">
                        {{ form.errors.file }}
                    </p>

                    <p class="mt-3 text-xs text-stone-500">
                        {{ t('Dosya arka planda işlenir; büyük dosyalarda sonucu aşağıdaki listeden takip edebilirsin.') }}
                    </p>

                    <button
                        type="submit"
                        class="mt-4 rounded-md bg-stone-900 px-4 py-2 text-sm text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="form.processing || !form.file"
                    >
                        {{ form.processing ? t('Yükleniyor…') : t('Yükle') }}
                    </button>
                </form>

                <!--
                    KANALDAN ÇEKME — aynı ekranın ikinci kaynağı.
                    Satıcının ürünleri ZATEN bir kanalda duruyor; CSV'ye
                    döküp yeniden yüklemesini istemek, sistemin bağlandığı
                    kanaldan okuyabildiği veriyi elle taşıtmak demektir.
                -->
                <form
                    class="mt-6 rounded-lg border border-stone-200 bg-white p-6"
                    @submit.prevent="submitChannel"
                >
                    <label class="block text-sm font-medium text-stone-700">
                        {{ t('Kanaldan çek') }}
                    </label>

                    <p v-if="connections.length === 0" class="mt-2 text-sm text-stone-600">
                        {{ t('Ürün çekmeyi destekleyen aktif bir kanal bağlantısı yok. Önce Kanallar ekranından bir mağaza bağla.') }}
                    </p>

                    <template v-else>
                        <select
                            v-model="channelForm.connection_id"
                            class="mt-2 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm"
                        >
                            <option value="">{{ t('Kanal seç…') }}</option>
                            <option
                                v-for="connection in connections"
                                :key="connection.id"
                                :value="connection.id"
                            >
                                {{ connection.label }} — {{ connection.channel }}
                            </option>
                        </select>

                        <p v-if="channelForm.errors.connection_id" class="mt-2 text-sm text-red-700">
                            {{ channelForm.errors.connection_id }}
                        </p>

                        <!--
                            STOK SINIRI BURADA DA SÖYLENİR ve kanal turunda
                            DAHA KRİTİKTİR: kanaldaki stok bayat olabilir ve
                            var olan ürüne uygulansaydı satılmış mallar geri
                            gelirdi.
                        -->
                        <p class="mt-3 text-xs leading-relaxed text-stone-500">
                            {{ t('Kanaldaki ürünler SKU ile eşleştirilir. Yeni SKU\'lar açılır, var olanların yalnızca içeriği güncellenir —') }}
                            <span class="font-medium text-stone-700">{{ t('stoğa dokunulmaz.') }}</span>
                        </p>

                        <button
                            type="submit"
                            class="mt-4 rounded-md bg-stone-900 px-4 py-2 text-sm text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="channelForm.processing || inFlight || !channelForm.connection_id"
                        >
                            {{ channelForm.processing ? t('Başlatılıyor…') : inFlight ? t('Çekiliyor…') : t('Ürünleri çek') }}
                        </button>
                    </template>
                </form>
            </div>

            <div class="rounded-lg border border-stone-200 bg-white p-6">
                <p class="text-sm font-medium text-stone-900">{{ t('Kolonlar') }}</p>

                <p class="mt-3 text-xs uppercase tracking-wide text-stone-500">{{ t('Zorunlu') }}</p>
                <ul class="mt-1 space-y-1">
                    <li
                        v-for="column in columns.required"
                        :key="column"
                        class="font-mono text-sm text-stone-900"
                    >
                        {{ column }}
                    </li>
                </ul>

                <p class="mt-4 text-xs uppercase tracking-wide text-stone-500">{{ t('İsteğe bağlı') }}</p>
                <ul class="mt-1 space-y-1">
                    <li
                        v-for="column in columns.optional"
                        :key="column"
                        class="font-mono text-sm text-stone-600"
                    >
                        {{ column }}
                    </li>
                </ul>

                <!--
                    STOK KOLONUNUN SINIRI AÇIKÇA SÖYLENİR. Var olan bir
                    üründe stok satırdan yazılsaydı satıcının SATTIĞI mallar
                    bir dosya yüklemesiyle geri gelir ve bakiye bozulurdu;
                    kullanıcı bunu bilmeden dosyaya stok yazarsa neden
                    değişmediğini anlamaz.
                -->
                <p class="mt-4 border-t border-stone-100 pt-3 text-xs leading-relaxed text-stone-500">
                    <span class="font-medium text-stone-700">{{ stockColumn }}</span>
                    {{ t('yalnızca YENİ ürünün açılış stoğudur. Var olan bir SKU güncellenirken stoğa dokunulmaz — stok düzeltmesi Stok ekranından yapılır.') }}
                </p>
            </div>
        </div>

        <h2 class="mt-10 text-sm font-medium text-stone-900">{{ t('Geçmiş içe aktarmalar') }}</h2>

        <!-- Asgari genişlik sütunların dar ekranda sıkışmasını önler; kutu kayar. -->
        <div class="mt-3 overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full min-w-3xl text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left">
                    <tr>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('Kaynak') }}</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('Durum') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-stone-600">{{ t('Yeni') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-stone-600">{{ t('Güncellenen') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-stone-600">{{ t('Atlanan') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-stone-600">{{ t('Hatalı') }}</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('Başladı') }}</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-stone-100">
                    <template v-for="row in rows" :key="row.id">
                        <tr class="hover:bg-stone-50">
                            <td class="px-4 py-3">
                                <span
                                    class="mr-2 inline-block rounded px-1.5 py-0.5 text-[10px] font-medium tracking-wide"
                                    :class="sourceFor(row.source).class"
                                >
                                    {{ t(sourceFor(row.source).text) }}
                                </span>
                                <span class="font-mono text-stone-900">{{ row.filename }}</span>
                            </td>

                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded border px-2 py-0.5 text-[10px] font-medium tracking-wide"
                                    :class="badgeFor(row.status).class"
                                >
                                    <span
                                        v-if="row.status === 'pending' || row.status === 'running'"
                                        class="size-2.5 animate-spin rounded-full border border-current border-t-transparent"
                                        aria-hidden="true"
                                    />
                                    {{ t(badgeFor(row.status).text) }}
                                </span>
                                <p v-if="row.lastError" class="mt-1 text-xs text-red-700">
                                    {{ t(row.lastError) }}
                                </p>
                            </td>

                            <td class="px-4 py-3 text-right font-mono text-stone-900">{{ row.created }}</td>
                            <td class="px-4 py-3 text-right font-mono text-stone-900">{{ row.updated }}</td>

                            <!--
                                ATLANAN AYRI SAYILIR: "47 ürün geldi" ile
                                "47 geldi, 3 atlandı" farklı şeylerdir ve
                                ikincisi satıcıya yapacak bir iş verir.
                            -->
                            <td class="px-4 py-3 text-right font-mono" :class="row.skipped > 0 ? 'text-amber-900' : 'text-stone-400'">
                                {{ row.skipped }}
                            </td>

                            <td class="px-4 py-3 text-right">
                                <button
                                    v-if="row.errors.length > 0"
                                    type="button"
                                    class="font-mono text-amber-900 underline"
                                    @click="toggleErrors(row.id)"
                                >
                                    {{ row.errors.length }}
                                </button>
                                <span v-else class="font-mono text-stone-400">0</span>
                            </td>

                            <td class="px-4 py-3 text-xs text-stone-500">
                                {{ row.createdAt ? new Date(row.createdAt).toLocaleString(intlLocale()) : '—' }}
                            </td>
                        </tr>

                        <!--
                            HATA LİSTESİ SATIR NUMARASI TAŞIR: sayı tek başına
                            kullanıcıya ne yapacağını söylemez, hangi satırı
                            düzelteceğini bilmeli.
                        -->
                        <tr v-if="expanded === row.id" class="bg-stone-50">
                            <td colspan="7" class="px-4 py-3">
                                <ul class="space-y-1">
                                    <li
                                        v-for="(error, index) in row.errors"
                                        :key="index"
                                        class="text-xs text-stone-700"
                                    >
                                        <!--
                                            SATIR NUMARASI YALNIZCA DOSYA
                                            TURUNDA ANLAMLIDIR. Kanal turunda
                                            satır yoktur ve `0` yazılsaydı
                                            kullanıcı dosyasının sıfırıncı
                                            satırını aramaya giderdi.
                                        -->
                                        <span v-if="error.line > 0" class="font-mono text-stone-900">
                                            {{ t(':line. satır:', { line: error.line }) }}
                                        </span>
                                        {{ error.message }}
                                    </li>
                                </ul>
                            </td>
                        </tr>
                    </template>

                    <tr v-if="rows.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-stone-600">
                            {{ t('Henüz içe aktarma yapılmadı.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PanelLayout>
</template>
