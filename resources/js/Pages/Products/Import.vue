<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

defineProps({
    rows: { type: Array, default: () => [] },
    columns: { type: Object, default: () => ({ required: [], optional: [] }) },
    // İçe aktarmayı DESTEKLEYEN aktif bağlantılar. Desteklemeyen kanal
    // listeye hiç girmez — düğmeyi gösterip sonra hata vermek satıcıya iş
    // yaptırıp geri almaktır.
    connections: { type: Array, default: () => [] },
});

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
    csv: { text: 'DOSYA', class: 'bg-stone-100 text-stone-700' },
    channel: { text: 'KANAL', class: 'bg-sky-100 text-sky-800' },
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
    pending: { text: 'SIRADA', class: 'bg-stone-50 text-stone-600 border-stone-200' },
    running: { text: 'İŞLENİYOR', class: 'bg-sky-50 text-sky-800 border-sky-200' },
    completed: { text: 'TAMAMLANDI', class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    failed: { text: 'BAŞARISIZ', class: 'bg-red-50 text-red-900 border-red-300' },
};

function badgeFor(status) {
    return badges[status] ?? { text: status, class: 'bg-stone-50 text-stone-600 border-stone-200' };
}

const expanded = ref(null);

function toggleErrors(id) {
    expanded.value = expanded.value === id ? null : id;
}
</script>

<template>
    <PanelLayout>
        <div>
            <p class="font-mono text-[10px] uppercase tracking-widest text-stone-500">
                Ürünler
            </p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">
                Toplu içe aktarma
            </h1>
        </div>

        <div
            v-if="flashSuccess"
            class="mt-6 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ flashSuccess }}
        </div>

        <!--
            KOLON SÖZLEŞMESİ ÖNCE GÖSTERİLİR: kullanıcı dosyayı yüklemeden
            önce hangi kolonların gerektiğini bilmeli. Yükleyip hata almak
            ve sonra öğrenmek gereksiz bir tur demek.
        -->
        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <form
                    class="rounded border border-stone-200 bg-white p-6"
                    @submit.prevent="submit"
                >
                    <label class="block text-sm font-medium text-stone-700">
                        CSV dosyası
                    </label>

                    <input
                        ref="fileInput"
                        type="file"
                        accept=".csv,text/csv"
                        class="mt-2 block w-full rounded border border-stone-300 px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-stone-900 file:px-3 file:py-1.5 file:text-sm file:text-white"
                        @change="onFileChange"
                    >

                    <p v-if="form.errors.file" class="mt-2 text-sm text-red-700">
                        {{ form.errors.file }}
                    </p>

                    <p class="mt-3 text-xs text-stone-500">
                        Dosya arka planda işlenir; büyük dosyalarda sonucu aşağıdaki
                        listeden takip edebilirsin.
                    </p>

                    <button
                        type="submit"
                        class="mt-4 rounded bg-stone-900 px-4 py-2 text-sm text-white transition hover:bg-stone-700 disabled:opacity-50"
                        :disabled="form.processing || !form.file"
                    >
                        {{ form.processing ? 'Yükleniyor…' : 'Yükle' }}
                    </button>
                </form>

                <!--
                    KANALDAN ÇEKME — aynı ekranın ikinci kaynağı.
                    Satıcının ürünleri ZATEN bir kanalda duruyor; CSV'ye
                    döküp yeniden yüklemesini istemek, sistemin bağlandığı
                    kanaldan okuyabildiği veriyi elle taşıtmak demektir.
                -->
                <form
                    class="mt-6 rounded border border-stone-200 bg-white p-6"
                    @submit.prevent="submitChannel"
                >
                    <label class="block text-sm font-medium text-stone-700">
                        Kanaldan çek
                    </label>

                    <p v-if="connections.length === 0" class="mt-2 text-sm text-stone-600">
                        Ürün çekmeyi destekleyen aktif bir kanal bağlantısı yok.
                        Önce Kanallar ekranından bir mağaza bağla.
                    </p>

                    <template v-else>
                        <select
                            v-model="channelForm.connection_id"
                            class="mt-2 block w-full rounded border border-stone-300 px-3 py-2 text-sm"
                        >
                            <option value="">Kanal seç…</option>
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
                            Kanaldaki ürünler SKU ile eşleştirilir. Yeni SKU'lar açılır,
                            var olanların yalnızca içeriği güncellenir —
                            <span class="font-medium text-stone-700">stoğa dokunulmaz.</span>
                        </p>

                        <button
                            type="submit"
                            class="mt-4 rounded bg-stone-900 px-4 py-2 text-sm text-white transition hover:bg-stone-700 disabled:opacity-50"
                            :disabled="channelForm.processing || !channelForm.connection_id"
                        >
                            {{ channelForm.processing ? 'Başlatılıyor…' : 'Ürünleri çek' }}
                        </button>
                    </template>
                </form>
            </div>

            <div class="rounded border border-stone-200 bg-white p-6">
                <p class="text-sm font-medium text-stone-900">Kolonlar</p>

                <p class="mt-3 text-xs uppercase tracking-wide text-stone-500">Zorunlu</p>
                <ul class="mt-1 space-y-1">
                    <li
                        v-for="column in columns.required"
                        :key="column"
                        class="font-mono text-sm text-stone-900"
                    >
                        {{ column }}
                    </li>
                </ul>

                <p class="mt-4 text-xs uppercase tracking-wide text-stone-500">İsteğe bağlı</p>
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
                    <span class="font-medium text-stone-700">stok</span> yalnızca YENİ ürünün
                    açılış stoğudur. Var olan bir SKU güncellenirken stoğa dokunulmaz —
                    stok düzeltmesi Stok ekranından yapılır.
                </p>
            </div>
        </div>

        <h2 class="mt-10 text-sm font-medium text-stone-900">Geçmiş içe aktarmalar</h2>

        <!-- Asgari genişlik sütunların dar ekranda sıkışmasını önler; kutu kayar. -->
        <div class="mt-3 overflow-x-auto rounded border border-stone-200 bg-white">
            <table class="w-full min-w-3xl text-sm">
                <thead class="border-b border-stone-200 bg-stone-50">
                    <tr class="text-left text-xs uppercase tracking-wide text-stone-500">
                        <th class="px-4 py-3 font-medium">Kaynak</th>
                        <th class="px-4 py-3 font-medium">Durum</th>
                        <th class="px-4 py-3 text-right font-medium">Yeni</th>
                        <th class="px-4 py-3 text-right font-medium">Güncellenen</th>
                        <th class="px-4 py-3 text-right font-medium">Atlanan</th>
                        <th class="px-4 py-3 text-right font-medium">Hata</th>
                        <th class="px-4 py-3 font-medium">Başladı</th>
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
                                    {{ sourceFor(row.source).text }}
                                </span>
                                <span class="font-mono text-stone-900">{{ row.filename }}</span>
                            </td>

                            <td class="px-4 py-3">
                                <span
                                    class="inline-block rounded border px-2 py-0.5 text-[10px] font-medium tracking-wide"
                                    :class="badgeFor(row.status).class"
                                >
                                    {{ badgeFor(row.status).text }}
                                </span>
                                <p v-if="row.lastError" class="mt-1 text-xs text-red-700">
                                    {{ row.lastError }}
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
                                {{ row.createdAt ? new Date(row.createdAt).toLocaleString('tr-TR') : '—' }}
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
                                            {{ error.line }}. satır:
                                        </span>
                                        {{ error.message }}
                                    </li>
                                </ul>
                            </td>
                        </tr>
                    </template>

                    <tr v-if="rows.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-stone-600">
                            Henüz içe aktarma yapılmadı.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PanelLayout>
</template>
