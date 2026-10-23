<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Support;

use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Kargo firmalarının SOAP 1.1 servisleri için küçük taşıyıcı.
 *
 * PHP'nin `SoapClient`'ı kullanılmaz: WSDL'i her istekte (ya da önbellekten)
 * okur, `Http::fake` ile sınanamaz ve ağ hatasını sınıflandırılabilir
 * biçimde vermez. Zarf elle kurulur, cevap XPath ile ad alanından bağımsız
 * (`local-name()`) okunur — iki firmanın ad alanları farklıdır.
 *
 * Değerler XML'e KAÇIŞLI yazılır (`text()`); alıcı adı ya da adresindeki
 * `<`/`&` zarfı bozamaz.
 *
 * İstek gövdesi LOG'A YAZILMAZ: alıcının adı, adresi, telefonu ve
 * satıcının firma şifresi içindedir.
 */
final class SoapTransport
{
    public function call(string $url, string $soapAction, string $bodyXml): SoapReply
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .$bodyXml
            .'</soap:Body></soap:Envelope>';

        try {
            $response = Http::timeout((int) config('shipping.timeout', 30))
                ->withHeaders([
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction' => '"'.$soapAction.'"',
                ])
                ->withBody($envelope, 'text/xml; charset=utf-8')
                ->post($url);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');

            throw new CarrierException($timedOut ? ErrorClass::TIMEOUT : ErrorClass::NETWORK, 'Kargo firmasına bağlanılamadı.');
        }

        $body = (string) $response->body();
        $reply = SoapReply::parse($body);

        if ($reply === null) {
            if ($response->status() === 429) {
                throw new CarrierException(ErrorClass::RATE_LIMITED, 'Kargo firması istek sınırına takıldı.');
            }

            throw new CarrierException(ErrorClass::SERVER_ERROR, 'Kargo firmasından okunamayan cevap (HTTP '.$response->status().').');
        }

        $fault = $reply->text('faultstring');

        if ($fault !== null) {
            // .NET servisleri kimlik hatasını da "Fault" olarak döndürür
            // (Aras GetCargoInfo: "Kullanıcı Adı yada Şifreniz yanlış").
            $auth = preg_match('/(kullan[ıi]c[ıi] ad[ıi]|şifre|parola)/iu', $fault) === 1;

            throw new CarrierException($auth ? ErrorClass::AUTHENTICATION : ErrorClass::SERVER_ERROR, $fault);
        }

        if ($response->serverError()) {
            throw new CarrierException(ErrorClass::SERVER_ERROR, 'Kargo firması hata verdi (HTTP '.$response->status().').');
        }

        return $reply;
    }

    /** XML'e güvenli metin. */
    public static function text(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * `<ad>değer</ad>` dizisi — null değerli alanlar YAZILMAZ.
     *
     * @param  array<string, string|int|float|null>  $fields
     */
    public static function fields(array $fields): string
    {
        $xml = '';

        foreach ($fields as $name => $value) {
            if ($value === null) {
                continue;
            }

            $xml .= "<{$name}>".self::text((string) $value)."</{$name}>";
        }

        return $xml;
    }
}
