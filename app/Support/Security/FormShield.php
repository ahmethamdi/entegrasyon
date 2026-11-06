<?php

declare(strict_types=1);

namespace App\Support\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Serbest adrese posta gönderen açık formlar için bot kalkanı.
 *
 * NEDEN: 2–4 Kasım 2026'da Tor çıkışlarından gelen botlar canlıda beş
 * sahte kayıt açtı — rastgele "Xxxx LLC" firma adları, GERÇEK kişilerin
 * e-posta adresleri, hiçbiri doğrulanmadı. Her kayıt o yabancılara bizim
 * adresimizden doğrulama postası gönderdi; bu gönderen itibarımızı yer ve
 * spam şikâyeti doğrudan alan adına yazılır. Saatte 5/IP sınırı
 * (`throttle:register`) Tor'da işe yaramaz: her istek başka çıkıştan gelir.
 *
 * Aynı dalga kardeş proje Prova'da da vardı; orada bu iki katman işe
 * yaradığı ÖLÇÜLDÜ (`prova/src/web/botkalkan.js`) ve buraya birebir taşındı:
 *
 *   1. TUZAK ALAN (`website`): ekranda yok, insan doldurmaz; formu körlemesine
 *      dolduran bot doldurur → 'bot'.
 *   2. İMZALI ZAMAN DAMGASI (`ft`): form açılırken sunucuda imzalanır. Bot
 *      sayfayı açıp aynı saniyede gönderiyordu; 3 sn'den hızlı → 'fast'.
 *      Damgası olmayan, imzası bozuk ya da 24 saatten eski → 'stale'.
 *
 * 'bot' SESSİZCE DÜŞER ama başarı gibi görünür: hata görseydi bot alanı
 * boş bırakmayı öğrenirdi. 'fast'/'stale' SESSİZ DÜŞMEZ: aceleci ya da
 * sekmeyi bir gün açık bırakmış GERÇEK insan da buraya düşebilir — ona
 * "yeniden gönder" denir ve ikinci denemede geçer.
 *
 * İMZA FORM ADINA BAĞLIDIR: kayıt sayfasından alınan damga sıfırlama
 * formunda geçmez. Anahtar `app.key`'den türetilir (ayrı bir sır yönetmek
 * gerekmesin); ham anahtar değil türevi kullanılır ki damga başka bir
 * HMAC kullanımıyla çakışmasın.
 *
 * TEK KULLANIMLIK DEĞİLDİR ve bu bilinçli: Prova'da olmadan da işe yaradı;
 * nonce deposu her form açılışında yazma ve temizleme işi getirirdi.
 * Damgayı toplayıp yeniden kullanan bot için hız sınırı hâlâ yerinde.
 */
final class FormShield
{
    public const HONEYPOT = 'website';

    public const STAMP = 'ft';

    public const MIN_MS = 3_000;

    public const MAX_MS = 24 * 3_600_000;

    /** Forma gömülecek damga: "<ms>.<imza>". `$atMs` yalnız testler içindir. */
    public static function stamp(string $form, ?int $atMs = null): string
    {
        $ts = $atMs ?? now()->getTimestampMs();

        return $ts.'.'.self::sign($ts, $form);
    }

    /** @return 'ok'|'bot'|'fast'|'stale' */
    public static function check(string $form, Request $request): string
    {
        if (trim((string) $request->input(self::HONEYPOT, '')) !== '') {
            return 'bot';
        }

        $parts = explode('.', (string) $request->input(self::STAMP, ''), 2);

        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || $parts[1] === '') {
            return 'stale';
        }

        $ts = (int) $parts[0];

        // hash_equals: imzayı karakter karakter karşılaştırıp ilk farkta
        // dönen `===` zamanlama farkıyla doğru imzayı tahmin ettirirdi.
        if (! hash_equals(self::sign($ts, $form), $parts[1])) {
            return 'stale';
        }

        $elapsed = now()->getTimestampMs() - $ts;

        return match (true) {
            $elapsed < self::MIN_MS => 'fast',
            $elapsed > self::MAX_MS => 'stale',
            default => 'ok',
        };
    }

    /**
     * Denetleyicinin tek satırı: true → devam et; false → bot, HİÇBİR ŞEY
     * yapma ama başarı gibi yanıt ver. 'fast'/'stale' doğrulama hatası atar.
     *
     * Kayıt TÜRÜ loglanır (IP ve e-posta yazılmadan): kalkanın canlıda neyi
     * kestiğini ölçmeden "işe yarıyor" denemez.
     */
    public static function admit(string $form, Request $request): bool
    {
        $verdict = self::check($form, $request);

        if ($verdict === 'ok') {
            return true;
        }

        Log::info('form_shield.rejected', ['form' => $form, 'verdict' => $verdict]);

        if ($verdict === 'bot') {
            return false;
        }

        throw ValidationException::withMessages([
            self::STAMP => __('Lütfen formu yeniden gönder.'),
        ]);
    }

    private static function sign(int $ts, string $form): string
    {
        $key = hash('sha256', 'form-shield:'.config('app.key'), true);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $ts.'.'.$form, $key, true)), '+/', '-_'), '=');
    }
}
