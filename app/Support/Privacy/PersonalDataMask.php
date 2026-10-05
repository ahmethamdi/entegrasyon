<?php

declare(strict_types=1);

namespace App\Support\Privacy;

/**
 * Kanal gövdesinden alıcının kişisel verisini SAKLAMADAN ÖNCE çıkarır.
 *
 * Gizlilik politikası "ham bildirimler kayıt altına alınırken kişisel
 * alanlar maskelenir" diyor; 6 Eki 2026'ya kadar `inbox_messages` gövdeyi
 * OLDUĞU GİBİ yazıyordu (Trendyol/Woo siparişinde ad, adres, telefon).
 * Sipariş işleme bu alanların hiçbirini okumaz — yalnız müşteri KİMLİĞİ
 * (`customer.id`, `customer_id`, `customerId`) referans olarak tutulur ve
 * bu sınıf onlara dokunmaz.
 *
 * Anahtarlar küçük harfe çevrilip harf/rakam dışı karakterler atılarak
 * karşılaştırılır: Shopify/Woo `first_name`, Trendyol `firstName`,
 * `customerFirstName` aynı kuralla yakalanır. `name` BİLEREK listede
 * yok (ürün ve kalem adı); adres nesneleri ise içindeki `name` ile
 * birlikte BÜTÜN OLARAK maskelenir.
 */
final class PersonalDataMask
{
    public const MASK = '[redacted]';

    /** Değeri maskelenen alanlar (normalleştirilmiş ad). */
    private const FIELDS = [
        'email', 'contactemail', 'customeremail',
        'phone', 'phonenumber', 'gsm', 'customerphone',
        'firstname', 'lastname', 'fullname', 'customername',
        'customerfirstname', 'customerlastname',
        'address', 'address1', 'address2', 'fulladdress',
        'taxnumber', 'tckimlik', 'identitynumber',
        'browserip',
    ];

    /** Bütünüyle maskelenen adres nesneleri (normalleştirilmiş ad). */
    private const OBJECTS = [
        'billing', 'shipping',
        'billingaddress', 'shippingaddress', 'defaultaddress',
        'shipmentaddress', 'invoiceaddress',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function apply(array $payload): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized = preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? '';

                if (in_array($normalized, self::OBJECTS, true) && is_array($value)) {
                    $out[$key] = self::MASK;

                    continue;
                }

                if (in_array($normalized, self::FIELDS, true) && $value !== null && $value !== '') {
                    $out[$key] = self::MASK;

                    continue;
                }
            }

            $out[$key] = is_array($value) ? self::apply($value) : $value;
        }

        return $out;
    }
}
