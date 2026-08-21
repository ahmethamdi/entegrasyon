<?php

declare(strict_types=1);

/*
 * Türkçe doğrulama mesajları — §13 · Faz 4 · "Türkçe yardım
 * dokümantasyonu ve hata mesajları".
 *
 * KAPATILAN BOŞLUK: panelin TAMAMI Türkçeydi ama doğrulama hataları
 * İNGİLİZCE dönüyordu. Türkçe bir formu dolduran satıcı boş alan
 * bıraktığında "The title field is required." görüyordu — hem yabancı
 * dil hem de ALAN ADI ham veritabanı kolonu (`title`), ekranda yazan
 * etiket (`Ürün adı`) değil.
 *
 * ALAN ADLARI `attributes` İÇİNDE TÜRKÇELEŞTİRİLİR ve bu, mesaj
 * çevirisi kadar önemlidir: satıcı `sku` kolonunu değil "SKU" etiketini
 * görür, `store_url` değil "Mağaza adresi" görür. Aksi hâlde mesaj
 * Türkçe ama işaret ettiği alan bulunamaz olurdu.
 *
 * YALNIZCA KULLANILAN KURALLAR DEĞİL, TAMAMI ÇEVRİLİR: bugün on üç
 * kural kullanılıyor ama yarın eklenen bir kural sessizce İngilizceye
 * düşerdi ve bunu kimse fark etmezdi (hata ancak o alan boş
 * bırakıldığında görünür).
 */

return [

    'accepted' => ':attribute alanı kabul edilmelidir.',
    'accepted_if' => ':other :value olduğunda :attribute alanı kabul edilmelidir.',
    'active_url' => ':attribute geçerli bir adres değil.',
    'after' => ':attribute :date tarihinden sonra olmalıdır.',
    'after_or_equal' => ':attribute :date tarihinden sonra veya aynı olmalıdır.',
    'alpha' => ':attribute yalnızca harf içerebilir.',
    'alpha_dash' => ':attribute yalnızca harf, rakam, tire ve alt çizgi içerebilir.',
    'alpha_num' => ':attribute yalnızca harf ve rakam içerebilir.',
    'any_of' => ':attribute geçersiz.',
    'array' => ':attribute bir dizi olmalıdır.',
    'ascii' => ':attribute yalnızca tek baytlık harf, rakam ve sembol içerebilir.',
    'before' => ':attribute :date tarihinden önce olmalıdır.',
    'before_or_equal' => ':attribute :date tarihinden önce veya aynı olmalıdır.',

    'between' => [
        'array' => ':attribute :min ile :max arasında öğe içermelidir.',
        'file' => ':attribute :min ile :max kilobayt arasında olmalıdır.',
        'numeric' => ':attribute :min ile :max arasında olmalıdır.',
        'string' => ':attribute :min ile :max karakter arasında olmalıdır.',
    ],

    'boolean' => ':attribute yalnızca doğru veya yanlış olabilir.',
    'can' => ':attribute izin verilmeyen bir değer içeriyor.',
    'confirmed' => ':attribute doğrulaması eşleşmiyor.',
    'contains' => ':attribute zorunlu bir değeri içermiyor.',
    'current_password' => 'Parola hatalı.',
    'date' => ':attribute geçerli bir tarih değil.',
    'date_equals' => ':attribute :date tarihine eşit olmalıdır.',
    'date_format' => ':attribute :format biçimine uymuyor.',
    'decimal' => ':attribute :decimal ondalık basamak içermelidir.',
    'declined' => ':attribute reddedilmelidir.',
    'declined_if' => ':other :value olduğunda :attribute reddedilmelidir.',
    'different' => ':attribute ile :other farklı olmalıdır.',
    'digits' => ':attribute :digits basamaklı olmalıdır.',
    'digits_between' => ':attribute :min ile :max basamak arasında olmalıdır.',
    'dimensions' => ':attribute görsel ölçüleri geçersiz.',
    'distinct' => ':attribute yinelenen bir değer içeriyor.',
    'doesnt_contain' => ':attribute izin verilmeyen bir değer içeriyor.',
    'doesnt_end_with' => ':attribute şunlardan biriyle bitemez: :values.',
    'doesnt_start_with' => ':attribute şunlardan biriyle başlayamaz: :values.',
    'email' => ':attribute geçerli bir e-posta adresi olmalıdır.',
    'ends_with' => ':attribute şunlardan biriyle bitmelidir: :values.',
    'enum' => 'Seçilen :attribute geçersiz.',
    'exists' => 'Seçilen :attribute geçersiz.',
    'extensions' => ':attribute şu uzantılardan birini taşımalıdır: :values.',
    'file' => ':attribute bir dosya olmalıdır.',
    'filled' => ':attribute alanı boş bırakılamaz.',

    'gt' => [
        'array' => ':attribute :value öğeden fazla içermelidir.',
        'file' => ':attribute :value kilobayttan büyük olmalıdır.',
        'numeric' => ':attribute :value değerinden büyük olmalıdır.',
        'string' => ':attribute :value karakterden uzun olmalıdır.',
    ],

    'gte' => [
        'array' => ':attribute en az :value öğe içermelidir.',
        'file' => ':attribute en az :value kilobayt olmalıdır.',
        'numeric' => ':attribute en az :value olmalıdır.',
        'string' => ':attribute en az :value karakter olmalıdır.',
    ],

    'hex_color' => ':attribute geçerli bir renk kodu olmalıdır.',
    'image' => ':attribute bir görsel olmalıdır.',
    'in' => 'Seçilen :attribute geçersiz.',
    'in_array' => ':attribute değeri :other içinde bulunmuyor.',
    'in_array_keys' => ':attribute şu anahtarlardan en az birini içermelidir: :values.',
    'integer' => ':attribute bir tam sayı olmalıdır.',
    'ip' => ':attribute geçerli bir IP adresi olmalıdır.',
    'ipv4' => ':attribute geçerli bir IPv4 adresi olmalıdır.',
    'ipv6' => ':attribute geçerli bir IPv6 adresi olmalıdır.',
    'json' => ':attribute geçerli bir JSON metni olmalıdır.',
    'list' => ':attribute bir liste olmalıdır.',

    'lt' => [
        'array' => ':attribute :value öğeden az içermelidir.',
        'file' => ':attribute :value kilobayttan küçük olmalıdır.',
        'numeric' => ':attribute :value değerinden küçük olmalıdır.',
        'string' => ':attribute :value karakterden kısa olmalıdır.',
    ],

    'lte' => [
        'array' => ':attribute en fazla :value öğe içermelidir.',
        'file' => ':attribute en fazla :value kilobayt olmalıdır.',
        'numeric' => ':attribute en fazla :value olmalıdır.',
        'string' => ':attribute en fazla :value karakter olmalıdır.',
    ],

    'mac_address' => ':attribute geçerli bir MAC adresi olmalıdır.',

    'max' => [
        'array' => ':attribute en fazla :max öğe içerebilir.',
        'file' => ':attribute en fazla :max kilobayt olabilir.',
        'numeric' => ':attribute en fazla :max olabilir.',
        'string' => ':attribute en fazla :max karakter olabilir.',
    ],

    'max_digits' => ':attribute en fazla :max basamak içerebilir.',
    'mimes' => ':attribute şu türlerden biri olmalıdır: :values.',
    'mimetypes' => ':attribute şu türlerden biri olmalıdır: :values.',

    'min' => [
        'array' => ':attribute en az :min öğe içermelidir.',
        'file' => ':attribute en az :min kilobayt olmalıdır.',
        'numeric' => ':attribute en az :min olmalıdır.',
        'string' => ':attribute en az :min karakter olmalıdır.',
    ],

    'min_digits' => ':attribute en az :min basamak içermelidir.',
    'missing' => ':attribute alanı bulunmamalıdır.',
    'missing_if' => ':other :value olduğunda :attribute bulunmamalıdır.',
    'missing_unless' => ':other :value olmadıkça :attribute bulunmamalıdır.',
    'missing_with' => ':values varken :attribute bulunmamalıdır.',
    'missing_with_all' => ':values varken :attribute bulunmamalıdır.',
    'multiple_of' => ':attribute :value değerinin katı olmalıdır.',
    'not_in' => 'Seçilen :attribute geçersiz.',
    'not_regex' => ':attribute biçimi geçersiz.',

    'numeric' => ':attribute bir sayı olmalıdır.',

    'password' => [
        'letters' => ':attribute en az bir harf içermelidir.',
        'mixed' => ':attribute en az bir büyük ve bir küçük harf içermelidir.',
        'numbers' => ':attribute en az bir rakam içermelidir.',
        'symbols' => ':attribute en az bir sembol içermelidir.',
        'uncompromised' => ':attribute bir veri sızıntısında görüldü. Lütfen başka bir parola seçin.',
    ],

    'present' => ':attribute alanı bulunmalıdır.',
    'present_if' => ':other :value olduğunda :attribute bulunmalıdır.',
    'present_unless' => ':other :value olmadıkça :attribute bulunmalıdır.',
    'present_with' => ':values varken :attribute bulunmalıdır.',
    'present_with_all' => ':values varken :attribute bulunmalıdır.',
    'prohibited' => ':attribute alanı doldurulamaz.',
    'prohibited_if' => ':other :value olduğunda :attribute doldurulamaz.',
    'prohibited_if_accepted' => ':other kabul edildiğinde :attribute doldurulamaz.',
    'prohibited_if_declined' => ':other reddedildiğinde :attribute doldurulamaz.',
    'prohibited_unless' => ':other :values içinde olmadıkça :attribute doldurulamaz.',
    'prohibits' => ':attribute alanı :other alanının doldurulmasını engeller.',
    'regex' => ':attribute biçimi geçersiz.',

    'required' => ':attribute alanı zorunludur.',
    'required_array_keys' => ':attribute şu anahtarları içermelidir: :values.',
    'required_if' => ':other :value olduğunda :attribute zorunludur.',
    'required_if_accepted' => ':other kabul edildiğinde :attribute zorunludur.',
    'required_if_declined' => ':other reddedildiğinde :attribute zorunludur.',
    'required_unless' => ':other :values içinde olmadıkça :attribute zorunludur.',
    'required_with' => ':values varken :attribute zorunludur.',
    'required_with_all' => ':values varken :attribute zorunludur.',
    'required_without' => ':values yokken :attribute zorunludur.',
    'required_without_all' => ':values hiçbiri yokken :attribute zorunludur.',
    'same' => ':attribute ile :other aynı olmalıdır.',

    'size' => [
        'array' => ':attribute :size öğe içermelidir.',
        'file' => ':attribute :size kilobayt olmalıdır.',
        'numeric' => ':attribute :size olmalıdır.',
        'string' => ':attribute :size karakter olmalıdır.',
    ],

    'starts_with' => ':attribute şunlardan biriyle başlamalıdır: :values.',
    'string' => ':attribute bir metin olmalıdır.',
    'timezone' => ':attribute geçerli bir saat dilimi olmalıdır.',
    'unique' => ':attribute zaten kullanılıyor.',
    'uploaded' => ':attribute yüklenemedi.',
    'uppercase' => ':attribute büyük harf olmalıdır.',
    'url' => ':attribute geçerli bir adres olmalıdır.',
    'ulid' => ':attribute geçerli bir ULID olmalıdır.',
    'uuid' => ':attribute geçerli bir UUID olmalıdır.',
    'lowercase' => ':attribute küçük harf olmalıdır.',

    /*
     * Alana özgü mesajlar.
     *
     * Genel mesaj "ne yanlış" der; buradakiler "NE YAPMALI" der. §12'nin
     * ölü mektup ekranı kuralının aynısı: sınıf ve TAVSİYE birlikte
     * gösterilir.
     */
    'custom' => [
        'sku' => [
            'required' => 'SKU zorunludur — kanallarla eşleşmenin anahtarı budur.',
        ],
        'store_url' => [
            'required' => 'Mağaza adresi zorunludur (örnek: magazam.com).',
        ],
        'consumer_secret' => [
            'required' => 'API gizli anahtarı zorunludur; kanal bağlantısı onsuz doğrulanamaz.',
        ],
        'quantity' => [
            'min' => 'Düzeltme miktarı en az 1 olmalıdır — sıfır bir düzeltme değildir.',
        ],
        'opening_stock' => [
            'min' => 'Açılış stoğu negatif olamaz.',
        ],
        'price' => [
            'min' => 'Fiyat negatif olamaz.',
        ],
        'file' => [
            'required' => 'Bir CSV dosyası seçin.',
        ],
    ],

    /*
     * ALAN ADLARI EKRANDAKİ ETİKETLE AYNI OLMALIDIR.
     *
     * Çevrilmezse mesaj Türkçe ama alan adı ham kolon olur ("The title
     * field is required." → "title alanı zorunludur.") ve satıcı formda
     * "title" diye bir alan ARAYAMAZ; ekranda yazan "Ürün adı"dır.
     */
    'attributes' => [
        'all' => 'hepsi',
        'barcode' => 'Barkod',
        'brand' => 'Marka',
        'channel_category_id' => 'Kanal kategorisi',
        'channel_type_code' => 'Kanal türü',
        'connection_id' => 'Kanal bağlantısı',
        'consumer_key' => 'API anahtarı',
        'consumer_secret' => 'API gizli anahtarı',
        'description' => 'Açıklama',
        'email' => 'E-posta',
        'external_attribute_id' => 'Kanal özniteliği',
        'external_value_id' => 'Kanal değeri',
        'external_value_label' => 'Kanal değeri adı',
        'file' => 'Dosya',
        'internal_category_id' => 'İç kategori',
        'label' => 'Bağlantı adı',
        'name' => 'Ad',
        'note' => 'Not',
        'opening_stock' => 'Açılış stoğu',
        'operation' => 'İşlem',
        'option_definition_id' => 'Öznitelik',
        'option_value_id' => 'Öznitelik değeri',
        'password' => 'Parola',
        'password_confirmation' => 'Parola doğrulaması',
        'plan_code' => 'Plan',
        'price' => 'Fiyat',
        'quantity' => 'Miktar',
        'sku' => 'SKU',
        'store_url' => 'Mağaza adresi',
        'title' => 'Ürün adı',
        'variant_id' => 'Varyant',
    ],

];
