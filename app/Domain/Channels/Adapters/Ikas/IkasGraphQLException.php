<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ikas;

use RuntimeException;

/**
 * ikas'ın 200 içinde döndürdüğü GraphQL hatası.
 *
 * GraphQL taşıma katmanında başarılıdır ama sorgu reddedilmiştir; HTTP
 * durumuna bakan kod onu BAŞARI sanır ve boş `data`'yı "kanalda bir şey
 * yok" diye okurdu. Kod `extensions.code`'dan gelir ve
 * `IkasAdapter::classifyError()` onu sınıfa çevirir.
 */
final class IkasGraphQLException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'GRAPHQL_ERROR')
    {
        parent::__construct($message);
    }

    /** @param array<int, mixed> $errors */
    public static function fromErrors(array $errors): self
    {
        $first = is_array($errors[0] ?? null) ? $errors[0] : [];
        $code = $first['extensions']['code'] ?? 'GRAPHQL_ERROR';

        return new self(
            'ikas: '.(is_string($first['message'] ?? null) ? $first['message'] : 'GraphQL hatası'),
            is_string($code) ? strtoupper($code) : 'GRAPHQL_ERROR',
        );
    }
}
