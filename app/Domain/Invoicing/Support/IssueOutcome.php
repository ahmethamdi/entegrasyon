<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

/**
 * Entegratördeki e-belge işinin durumu.
 */
final readonly class IssueOutcome
{
    private function __construct(
        public string $state,
        public ?string $documentId = null,
        public ?string $invoiceNumber = null,
        public ?string $error = null,
    ) {}

    public static function pending(): self
    {
        return new self('pending');
    }

    public static function issued(?string $documentId, ?string $invoiceNumber): self
    {
        return new self('issued', $documentId, $invoiceNumber);
    }

    public static function failed(string $error): self
    {
        return new self('failed', error: $error);
    }

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }

    public function isIssued(): bool
    {
        return $this->state === 'issued';
    }
}
