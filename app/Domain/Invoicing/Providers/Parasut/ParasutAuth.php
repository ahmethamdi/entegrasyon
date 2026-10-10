<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Providers\Parasut;

use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Sync\Enums\ErrorClass;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Paraşüt OAuth 2 — yetkilendirme kodu akışı.
 *
 * `/oauth/token` hatayı JSON:API `errors` biçiminde DEĞİL, OAuth
 * standardında (`error`, `error_description`) döner; ayrı okunur.
 *
 * ⚠️ YENİLEME TOKEN'I DÖNEBİLİR (rotasyon): yanıttaki yeni refresh token
 * eskisinin yerine yazılır. Eskisi saklanmaya devam etseydi bir sonraki
 * yenileme `invalid_grant` alır ve hesap sessizce "bağlantı koptu"ya
 * düşerdi.
 */
final class ParasutAuth
{
    public const REDIRECT_ROUTE = 'settings.invoicing.parasut.callback';

    public static function authorizeUrl(string $redirectUri, string $state): string
    {
        return ParasutApp::baseUrl().'/oauth/authorize?'.http_build_query([
            'client_id' => ParasutApp::clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    /**
     * @return array{credentials: array{access_token: string, refresh_token: string|null}, expires_at: DateTimeImmutable|null}
     */
    public static function exchangeCode(string $code, string $redirectUri): array
    {
        return self::requestToken([
            'grant_type' => 'authorization_code',
            'client_id' => ParasutApp::clientId(),
            'client_secret' => ParasutApp::clientSecret(),
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * @return array{credentials: array{access_token: string, refresh_token: string|null}, expires_at: DateTimeImmutable|null}
     */
    public static function refresh(string $refreshToken): array
    {
        return self::requestToken([
            'grant_type' => 'refresh_token',
            'client_id' => ParasutApp::clientId(),
            'client_secret' => ParasutApp::clientSecret(),
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Token sahibinin firmaları — `GET /v4/me?include=companies`.
     *
     * Firma kimliği SATICIYA SORULMAZ (Etsy `shop_id` kuralı): yazım
     * hatası başka bir firmanın adına fatura kesilmesi demekti.
     *
     * @return list<array{id: string, name: string}>
     */
    public static function companies(string $accessToken): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withToken($accessToken)
                ->get(ParasutApp::baseUrl().'/v4/me', ['include' => 'companies']);
        } catch (ConnectionException $e) {
            throw new InvoiceProviderException(ErrorClass::NETWORK, 'Paraşüt\'e ulaşılamadı.');
        }

        if ($response->failed()) {
            throw new InvoiceProviderException(ErrorClass::AUTHENTICATION, 'Paraşüt hesap bilgisi okunamadı (HTTP '.$response->status().').');
        }

        $companies = [];

        foreach ((array) $response->json('included', []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'companies' || ! isset($item['id'])) {
                continue;
            }

            $companies[] = [
                'id' => (string) $item['id'],
                'name' => (string) ($item['attributes']['name'] ?? $item['id']),
            ];
        }

        return $companies;
    }

    /**
     * @param  array<string, string>  $form
     * @return array{credentials: array{access_token: string, refresh_token: string|null}, expires_at: DateTimeImmutable|null}
     */
    private static function requestToken(array $form): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(15)
                ->post(ParasutApp::baseUrl().'/oauth/token', $form);
        } catch (ConnectionException $e) {
            throw new InvoiceProviderException(ErrorClass::NETWORK, 'Paraşüt\'e ulaşılamadı.');
        }

        if ($response->failed()) {
            throw self::tokenError($response);
        }

        $access = $response->json('access_token');

        if (! is_string($access) || $access === '') {
            throw new InvoiceProviderException(ErrorClass::AUTHENTICATION, 'Paraşüt yanıtı erişim anahtarı taşımıyor.');
        }

        $refresh = $response->json('refresh_token');
        $seconds = $response->json('expires_in');

        return [
            'credentials' => [
                'access_token' => $access,
                'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            ],
            // 60 sn erken: istek yoldayken süresi dolmasın.
            'expires_at' => is_numeric($seconds)
                ? new DateTimeImmutable('@'.(time() + (int) $seconds - 60))
                : null,
        ];
    }

    /**
     * `invalid_grant` = kod/yenileme anahtarı geçersiz (iptal edildi, süresi
     * doldu) → kalıcı, satıcı yeniden bağlamalı. 5xx geçicidir.
     */
    private static function tokenError(Response $response): InvoiceProviderException
    {
        $error = (string) ($response->json('error') ?? '');
        $description = (string) ($response->json('error_description') ?? '');

        $class = $response->serverError() ? ErrorClass::SERVER_ERROR : ErrorClass::AUTHENTICATION;

        $text = trim("Paraşüt yetkilendirmesi reddedildi: {$error} {$description}");

        return new InvoiceProviderException($class, mb_substr($text, 0, 500));
    }
}
