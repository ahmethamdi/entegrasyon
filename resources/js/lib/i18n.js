import { router, usePage } from '@inertiajs/vue3';

/*
 * Panel çevirisi — Laravel JSON çevirisinin Vue karşılığı.
 *
 * ANAHTAR TÜRKÇE METNİN KENDİSİDİR: `t("Ana sayfa")`. Türkçede sözlük boş
 * gelir ve anahtar aynen basılır; İngilizcede `lang/en.json`'daki karşılık.
 * Karşılığı olmayan metin Türkçe kalır (karışık ama okunur) —
 * `EnglishDictionaryCoverageTest` bunu yayına çıkmadan yakalar.
 *
 * Yer tutucular Laravel biçimindedir: `t(":count ürün", { count: 3 })`.
 * Sunucudaki `__()` ile aynı dosya ve aynı sözdizimi: bir metin iki yerde
 * iki ayrı biçimde çevrilmez.
 */
export function translate(dictionary, key, replacements = {}) {
    let text = dictionary?.[key] ?? key;

    // Uzun ad önce: `:count` değiştirilirken `:countAll` bozulmasın.
    Object.keys(replacements)
        .sort((a, b) => b.length - a.length)
        .forEach((name) => {
            text = text.replaceAll(`:${name}`, String(replacements[name]));
        });

    return text;
}

/* Bileşen içinde: `const { t, locale } = useI18n()`. */
export function useI18n() {
    const page = usePage();

    return {
        t: (key, replacements) => translate(page.props.translations, key, replacements),
        locale: () => page.props.locale ?? 'tr',
    };
}

/*
 * Bileşen DIŞINDA üretilen metnin işareti: `k("İptal")` metni aynen
 * döndürür, ekran onu `$t()` ile çevirir. İşaret, kapsam testinin metni
 * anahtar olarak bulabilmesi içindir (`format.js` gibi modüller).
 */
export const k = (key) => key;

/*
 * Geçerli dil — bileşen dışındaki biçimleyiciler (para, tarih) için.
 * `app.js` her sayfa geçişinde günceller.
 */
let current = 'tr';

export function setCurrentLocale(locale) {
    current = locale === 'en' ? 'en' : 'tr';
}

export function currentLocale() {
    return current;
}

/* Tarih/sayı biçimi için Intl dili. */
export function intlLocale(locale = current) {
    return locale === 'en' ? 'en-GB' : 'tr-TR';
}

export function switchLocale(locale) {
    router.post('/locale', { locale }, { preserveScroll: true, preserveState: false });
}

/* Şablonlarda `$t("…")` — `app.use(i18nPlugin)`. */
export const i18nPlugin = {
    install(app) {
        app.config.globalProperties.$t = function (key, replacements) {
            return translate(this.$page?.props?.translations, key, replacements);
        };
    },
};
