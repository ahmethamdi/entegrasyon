/*
 * Panelin ortak biçimleri — para, kanal adı, sipariş durumu.
 *
 * Satıcının gördüğü her yerde AYNI dil konuşulsun diye tek dosya: "379.80
 * TRY" ile "379,80 ₺" farklı ekranlarda karışık görünseydi panel özensiz
 * dururdu.
 */

import { intlLocale, k } from './i18n';

const moneyFormatters = {};

/** "1098.00" + "TRY" → "1.098,00 ₺" (TR) / "₺1,098.00" (EN). Para birimi bilinmiyorsa kodu yazılır. */
export function money(value, currency = 'TRY') {
    if (value === null || value === undefined || value === '') return '—';
    const code = currency || 'TRY';
    try {
        const locale = intlLocale();
        moneyFormatters[`${locale}:${code}`] ??= new Intl.NumberFormat(locale, { style: 'currency', currency: code });
        return moneyFormatters[`${locale}:${code}`].format(Number(value));
    } catch {
        return `${value} ${code}`;
    }
}

const channelNames = {
    trendyol: 'Trendyol',
    hepsiburada: 'Hepsiburada',
    ikas: 'ikas',
    ticimax: 'Ticimax',
    n11: 'N11',
    shopify: 'Shopify',
    woocommerce: 'WooCommerce',
    etsy: 'Etsy',
    ebay: 'eBay',
};

/** Kanal kodu → görünen ad ("woocommerce" → "WooCommerce"). */
export function channelName(code) {
    return channelNames[code] ?? code ?? '—';
}

/*
 * Sipariş durumu satıcının diliyle.
 *
 * Her kanal kendi kelimesini kullanır (Woo `processing`, Shopify `paid`,
 * Trendyol `Picking`); satıcı bunları ezberlemesin. Eşleme
 * `Order::awaitingShipment` izin listesiyle AYNIDIR — ana sayfadaki sayı
 * ile listedeki rozet aynı şeyi söylemeli. Tanınmayan durum olduğu gibi
 * yazılır: yanlış bir tercüme uydurmaktan iyidir.
 */
const awaiting = ['processing', 'paid', 'partially_paid', 'partial', 'created', 'picking', 'invoiced'];
const shipped = ['shipped', 'completed', 'fulfilled', 'intransit', 'atcollectionpoint'];
const delivered = ['delivered'];
const cancelled = ['cancelled', 'canceled', 'unpacked', 'voided', 'failed'];
const returned = ['refunded', 'returned', 'partially_refunded'];
const unpaid = ['pending', 'on-hold', 'authorized'];

export function orderState(status, hasShipment = false) {
    const s = String(status ?? '').toLowerCase();

    if (cancelled.includes(s)) return { text: k('İptal'), tone: 'muted' };
    if (returned.includes(s)) return { text: k('İade'), tone: 'muted' };
    if (delivered.includes(s)) return { text: k('Teslim edildi'), tone: 'done' };
    if (shipped.includes(s) || hasShipment) return { text: k('Kargoda'), tone: 'done' };
    if (awaiting.includes(s)) return { text: k('Kargo bekliyor'), tone: 'todo' };
    if (unpaid.includes(s)) return { text: k('Ödeme bekleniyor'), tone: 'muted' };

    return { text: status ?? '—', tone: 'muted' };
}

export const toneClass = {
    todo: 'bg-amber-50 text-amber-900 border-amber-200',
    done: 'bg-emerald-50 text-emerald-800 border-emerald-200',
    muted: 'bg-stone-50 text-stone-600 border-stone-200',
};
