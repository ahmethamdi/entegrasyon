<script setup>
import { computed, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { useI18n } from '../../lib/i18n';

const { t } = useI18n();

const props = defineProps({
    channelTypes: { type: Array, default: () => [] },
});

// Varsayılan seçim BAĞLANABİLİR bir kanaldır. İlk sıradaki alınsaydı
// ekran, tanımı olmayan bir kanalla açılıp "bağlanamazsın" diyebilirdi.
const firstConnectable = props.channelTypes.find((type) => type.connectable);

// ⚠️ HER KANALIN HER ALANI BAŞTAN TANIMLANIR — SONRADAN EKLENMEZ.
//
// `useForm` yalnızca KURULURKEN verilen anahtarları izler; sonradan
// `form[ad] = ''` ile eklenen bir alan ekranda görünür ve yazılabilir
// ama gönderimde SUNUCUYA HİÇ GİTMEZ. İlk yazımda böyleydi ve sonuç
// sessizdi: satıcı `shop_id` alanını doldurur, gönderir ve "shop id
// alanı zorunludur" hatası alırdı — GERÇEK TARAYICI ÇALIŞTIRMASINDA
// bulundu, testler göremedi çünkü hepsi yükü doğrudan POST ediyor ve
// Vue formunu HİÇ sürmüyor.
const everyFieldName = [
    ...new Set(
        props.channelTypes.flatMap((type) => [
            ...(type.secretFields ?? []),
            ...(type.identityFields ?? []),
        ].map((field) => field.name)),
    ),
];

const form = useForm({
    channel_type_code: (firstConnectable ?? props.channelTypes[0])?.code ?? '',
    label: '',
    store_url: '',
    ...Object.fromEntries(everyFieldName.map((name) => [name, ''])),
});

const selected = computed(
    () => props.channelTypes.find((type) => type.code === form.channel_type_code) ?? null,
);

const connectable = computed(() => selected.value?.connectable !== false);

// ⚠️ ALANLAR SUNUCUDAN GELİR — burada `if (code === 'shopify')` YOKTUR.
// Yazılsaydı bu blok sunucudaki doğrulamadan AYRI yaşar ve biri
// değiştiğinde form alanı sorar ama doğrulama reddederdi (ya da tersi).
const secretFields = computed(() => selected.value?.secretFields ?? []);
const identityFields = computed(() => selected.value?.identityFields ?? []);
const allFields = computed(() => [...secretFields.value, ...identityFields.value]);

// ⚠️ OAUTH KANALI ANAHTAR SORMAZ ve düğmenin metni de bunu SÖYLER.
// "Bağla ve doğrula" deseydi satıcı işin bittiğini sanır, oysa asıl
// onay adımı henüz BAŞLAMAMIŞTIR.
const usesOauth = computed(() => selected.value?.oauth === true);

// Hesap kimliği bir alandan gelen kanal (Trendyol) mağaza adresi SORMAZ:
// tek bir API adresi vardır ve satıcıyı satıcı ID'si ayırır.
const asksStoreUrl = computed(() => selected.value?.asksStoreUrl !== false);

// Panelden bağlanmayan kanal (Shopify): form yerine kurulum düğmesi.
// App Store kuralı 2.3.1 mağaza adresinin elle sorulmasını yasaklar.
const installUrl = computed(() => selected.value?.installUrl ?? null);

// Kanal değişince ESKİ KANALIN ALANLARI BOŞALTILIR.
//
// ⚠️ Boşaltılmasaydı Woo'yu deneyip Shopify'a geçen satıcının `ck_...`
// değeri formda KALIRDI ve kullanıcının hiç görmediği bir alanda
// taşınmaya devam ederdi. Anahtar SİLİNMEZ, yalnızca DEĞERİ boşaltılır:
// silinen bir anahtarı `useForm` bir daha izlemez ve o alan gönderimde
// sunucuya hiç gitmez.
watch(
    () => form.channel_type_code,
    () => {
        everyFieldName.forEach((name) => {
            form[name] = '';
        });

        form.clearErrors();
    },
);

function submit() {
    // ⚠️ YALNIZCA SEÇİLİ KANALIN ALANLARI GÖNDERİLİR.
    //
    // Form her kanalın alanını taşır (yukarıdaki kural); hepsi
    // gönderilseydi sunucudaki doğrulama Shopify isteğinde boş bir
    // `consumer_key` görür ve tanımadığı alanlar istekte gereksizce
    // dolaşırdı. Süzme GÖNDERİM ANINDA yapılır, alan tanımından.
    const allowed = [
        'channel_type_code',
        'label',
        ...(asksStoreUrl.value ? ['store_url'] : []),
        ...allFields.value.map((field) => field.name),
    ];

    form.transform((data) => Object.fromEntries(
        Object.entries(data).filter(([key]) => allowed.includes(key)),
    )).post('/channels', {
        onFinish: () => {
            // Sırlar formda kalmaz: gönderim bitince temizlenir.
            secretFields.value.forEach((field) => {
                form[field.name] = '';
            });
        },
    });
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            :section="t('Kanallarım')"
            :title="t('Kanal bağla')"
            :description="t('Bilgilerin şifrelenerek saklanır, kimseye gösterilmez. Kaydedince bağlantıyı hemen deneriz; başarılıysa kanal kullanıma açılır.')"
        />

        <form class="mt-8 max-w-xl space-y-5" @submit.prevent="submit">
            <div>
                <label for="channel_type_code" class="block text-sm font-medium text-stone-700">
                    {{ t('Kanal') }}
                </label>
                <select
                    id="channel_type_code"
                    v-model="form.channel_type_code"
                    required
                    class="mt-1 w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                >
                    <option v-for="type in channelTypes" :key="type.code" :value="type.code">
                        {{ type.name }}
                    </option>
                </select>
                <p v-if="form.errors.channel_type_code" class="mt-1 text-sm text-red-700">
                    {{ form.errors.channel_type_code }}
                </p>
            </div>

            <div
                v-if="installUrl"
                class="rounded-lg border border-stone-200 bg-white p-5"
            >
                <p class="text-sm font-medium text-stone-900">
                    {{ t(':channel mağazanı uygulamayla bağla', { channel: selected?.name ?? '' }) }}
                </p>
                <p class="mt-1 text-sm text-stone-600">
                    {{ t('34Pazar\'ı :channel App Store\'dan mağazana kur. Kurulumu onayladığında mağazan bu hesaba kendiliğinden bağlanır; adres ya da anahtar girmen gerekmez.', { channel: selected?.name ?? '' }) }}
                </p>
                <div class="mt-4 flex items-center gap-3">
                    <a
                        :href="installUrl"
                        target="_blank"
                        rel="noopener"
                        class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700"
                    >
                        {{ t(':channel App Store\'da aç', { channel: selected?.name ?? '' }) }}
                    </a>
                    <Link href="/channels" class="text-sm text-stone-600 underline">
                        {{ t('Vazgeç') }}
                    </Link>
                </div>
            </div>

            <template v-else>
            <div>
                <label for="label" class="block text-sm font-medium text-stone-700">
                    {{ t('Etiket') }}
                </label>
                <input
                    id="label"
                    v-model="form.label"
                    type="text"
                    required
                    :placeholder="t('Ana Mağaza')"
                    class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                >
                <p class="mt-1 text-xs text-stone-500">
                    {{ t('Yalnızca senin göreceğin isim; birden fazla mağazayı ayırt etmek için.') }}
                </p>
                <p v-if="form.errors.label" class="mt-1 text-sm text-red-700">
                    {{ form.errors.label }}
                </p>
            </div>

            <div v-if="asksStoreUrl">
                <label for="store_url" class="block text-sm font-medium text-stone-700">
                    {{ t('Mağaza adresi') }}
                </label>
                <input
                    id="store_url"
                    v-model="form.store_url"
                    type="text"
                    required
                    :placeholder="t('magaza.example.com')"
                    class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                >
                <p class="mt-1 text-xs text-stone-500">
                    {{ t('Bir mağaza yalnızca tek bir hesaba bağlanabilir. Bağlantı HTTPS üzerinden kurulur.') }}
                </p>
                <p v-if="form.errors.store_url" class="mt-1 text-sm text-red-700">
                    {{ form.errors.store_url }}
                </p>
            </div>

            <!--
                Tanımı olmayan kanal: sebep AÇIKÇA yazılır ve hiçbir alan
                gösterilmez. Bu, kanalın `is_active = true` yapılıp form
                tanımının unutulduğu hâldir.
            -->
            <div
                v-if="!connectable"
                class="rounded-lg border border-amber-300 bg-amber-50 p-4"
            >
                <p class="text-sm font-medium text-amber-900">
                    {{ t(':channel panelden bağlanamıyor', { channel: selected?.name ?? '' }) }}
                </p>
                <p class="mt-1 text-sm text-amber-800">
                    {{ t('Bu kanalın kimlik biçimi panelde tanımlı değil.') }}
                </p>
            </div>

            <div v-else-if="allFields.length" class="rounded-lg border border-stone-200 bg-stone-50 p-4">
                <p class="text-sm font-medium text-stone-900">
                    {{ t(':channel kimlik bilgileri', { channel: selected?.name ?? '' }) }}
                </p>
                <p v-if="selected?.help" class="mt-1 text-xs text-stone-600">
                    {{ selected.help }}
                </p>

                <div class="mt-4 space-y-4">
                    <div v-for="field in allFields" :key="field.name">
                        <label :for="field.name" class="block text-sm font-medium text-stone-700">
                            {{ field.label }}
                        </label>
                        <input
                            :id="field.name"
                            v-model="form[field.name]"
                            :type="field.masked ? 'password' : 'text'"
                            :placeholder="field.placeholder ?? ''"
                            :required="!field.optional"
                            autocomplete="off"
                            spellcheck="false"
                            class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 font-mono text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        >
                        <p v-if="field.hint" class="mt-1 text-xs text-stone-500">
                            {{ field.hint }}
                        </p>
                        <p v-if="form.errors[field.name]" class="mt-1 text-sm text-red-700">
                            {{ form.errors[field.name] }}
                        </p>
                    </div>
                </div>
            </div>

            <!--
                OAuth kanalında satıcıya SIRADAKİ ADIM söylenir. Bu kutu
                olmasaydı satıcı "Etsy'ye bağlan" düğmesine basar ve
                kendini beklemediği bir Etsy sayfasında bulurdu.
            -->
            <div
                v-if="connectable && usesOauth"
                class="rounded-lg border border-sky-200 bg-sky-50 p-4"
            >
                <p class="text-sm text-sky-900">
                    {{ t('Kaydettikten sonra :channel sitesine yönlendirileceksin ve izni orada vereceksin. Bağlantı ancak izin verildikten sonra çalışır.', { channel: selected?.name ?? '' }) }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing || !connectable"
                    class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <template v-if="form.processing">
                        {{ usesOauth ? t('Yönlendiriliyor…') : t('Bağlanıyor…') }}
                    </template>
                    <template v-else>
                        {{ usesOauth ? t(':channel ile bağlan', { channel: selected?.name ?? '' }) : t('Bağla ve doğrula') }}
                    </template>
                </button>

                <Link href="/channels" class="text-sm text-stone-600 underline">
                    {{ t('Vazgeç') }}
                </Link>
            </div>
            </template>
        </form>
    </PanelLayout>
</template>
