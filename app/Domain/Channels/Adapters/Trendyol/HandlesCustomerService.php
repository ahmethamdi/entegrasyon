<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Trendyol;

use App\Domain\Questions\Enums\QuestionReason;
use App\Domain\Questions\Exceptions\QuestionAnswerRejected;
use App\Domain\Questions\Support\RemoteQuestion;
use App\Domain\Questions\Support\RemoteQuestionPage;
use App\Domain\Returns\Exceptions\ReturnActionRejected;
use App\Domain\Returns\Support\RemoteReturn;
use App\Domain\Returns\Support\RemoteReturnItem;
use App\Domain\Returns\Support\RemoteReturnPage;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;

/**
 * Trendyol iade talepleri ve müşteri soruları — S4.
 *
 * Belgeler (v2.0, 13 Eki 2026 okundu): getClaims, approveClaimLineItems,
 * createClaimIssue, getClaimsIssueReasons, getClaimItemAudits, questions
 * filter / by id / answers.
 *
 * KİMLİKLER:
 *   claimId — talep; claimItem.id — TEK BİRİMİN iadesi (`orderLineItemId`
 *   birim kimliğidir, bir sipariş satırı N adetse N claimItem olabilir);
 *   orderLine.id — sipariş satırı; orderOutboundPackageId — GİDEN paket
 *   (bizde `orders.external_id`); orderShipmentPackageId İADE paketidir.
 *
 * Müşteri adı/soyadı, adres ve kullanıcı adı OKUNMAZ.
 */
trait HandlesCustomerService
{
    /** Kanalın tarih aralığı sınırı belirtilmemiş; soru ucuyla aynı (14 gün). */
    public function returnWindowDays(): int
    {
        return 14;
    }

    /** Süzgeç `lastModifiedDate`'e bakar; 1 gün örtüşme yeter. */
    public function returnRescanDays(): int
    {
        return 1;
    }

    public function fetchReturnPage(CarbonInterface $from, CarbonInterface $to, int $page): RemoteReturnPage
    {
        // Tarih süzgeci talebin `lastModifiedDate`'ine bakar: pencere
        // "DEĞİŞEN talepler"dir — durum ilerledikçe talep yeniden görünür.
        $response = $this->get($this->sellerUrl('order', 'claims'), [
            'startDate' => $from->getTimestampMs(),
            'endDate' => $to->getTimestampMs(),
            'page' => $page,
            'size' => 50,
        ]);

        // HATA SAYFASI BOŞ SAYFA SAYILMAZ (finans yoklamasıyla aynı karar).
        $response->throw();

        $returns = [];

        foreach ((array) ($response->json('content') ?? []) as $row) {
            if (is_array($row) && ($parsed = self::parseClaim($row)) !== null) {
                $returns[] = $parsed;
            }
        }

        $totalPages = (int) ($response->json('totalPages') ?? 0);

        return new RemoteReturnPage($returns, $page, $page + 1 < $totalPages);
    }

    public function fetchReturn(string $externalClaimId): ?RemoteReturn
    {
        // `claimIds` verilince öteki parametreler yok sayılır (belge).
        $response = $this->get($this->sellerUrl('order', 'claims'), ['claimIds' => $externalClaimId]);
        $response->throw();

        foreach ((array) ($response->json('content') ?? []) as $row) {
            if (is_array($row) && ($parsed = self::parseClaim($row)) !== null && $parsed->externalClaimId === $externalClaimId) {
                return $parsed;
            }
        }

        return null;
    }

    public function approveReturnItems(string $externalClaimId, array $externalItemIds): void
    {
        $response = $this->client->put(
            $this->sellerUrl('order', "claims/{$externalClaimId}/items/approve"),
            ['claimLineItemIdList' => array_values($externalItemIds), 'params' => (object) []],
            headers: $this->defaultHeaders(),
        );

        self::throwReturnDecisionFailure($response);
    }

    public function rejectReturnItem(string $externalClaimId, string $externalItemId, string $issueReasonId, string $description): void
    {
        // Parametreler belgedeki gibi SORGU DİZESİNDE; dosya eki yalnız
        // ek gerektirmeyen sebeplerde atlanabilir (bkz. returnRejectionReasons).
        $response = $this->client->request(
            'POST',
            $this->sellerUrl('order', "claims/{$externalClaimId}/issue"),
            body: ['claimIssueReasonId' => $issueReasonId, 'claimItemIdList' => $externalItemId],
            query: [
                'claimIssueReasonId' => $issueReasonId,
                'claimItemIdList' => $externalItemId,
                'description' => mb_substr($description, 0, 500),
            ],
            headers: $this->defaultHeaders(),
        );

        self::throwReturnDecisionFailure($response);
    }

    /**
     * Dosya eki GEREKTİRMEYEN red sebepleri (belge: createClaimIssue).
     * 2101 bilinçli olarak YOK: o kod reddi değişim ONAYINA çevirir.
     * 1651 talebin "Aksiyon Bekliyor"a düşmesinden sonraki ilk 24 saatte
     * seçilemez (kanal reddeder).
     */
    public function returnRejectionReasons(): array
    {
        return [
            ['id' => '1651', 'label' => 'İade paketi tarafıma teslim edilmedi'],
            ['id' => '451', 'label' => 'Ürünü analize alacağım'],
        ];
    }

    public function returnItemStatusHistory(string $externalItemId): array
    {
        $response = $this->get($this->sellerUrl('order', "claims/items/{$externalItemId}/audit"));
        $response->throw();

        $statuses = [];

        foreach ((array) ($response->json() ?? []) as $row) {
            if (is_array($row) && isset($row['newStatus']) && is_string($row['newStatus'])) {
                $statuses[] = $row['newStatus'];
            }
        }

        return $statuses;
    }

    /** Belge: tarihli sorguda en uzun aralık 2 hafta. */
    public function questionWindowDays(): int
    {
        return 14;
    }

    public function fetchQuestionPage(CarbonInterface $from, CarbonInterface $to, int $page): RemoteQuestionPage
    {
        $response = $this->get($this->sellerUrl('qna', 'questions/filter'), [
            'startDate' => $from->getTimestampMs(),
            'endDate' => $to->getTimestampMs(),
            'page' => $page,
            'size' => 50,
        ]);

        $response->throw();

        $questions = [];

        foreach ((array) ($response->json('content') ?? []) as $row) {
            if (is_array($row) && ($parsed = self::parseQuestion($row)) !== null) {
                $questions[] = $parsed;
            }
        }

        $totalPages = (int) ($response->json('totalPages') ?? 0);

        return new RemoteQuestionPage($questions, $page, $page + 1 < $totalPages);
    }

    public function fetchQuestion(string $externalQuestionId): ?RemoteQuestion
    {
        $response = $this->get($this->sellerUrl('qna', "questions/{$externalQuestionId}"));

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        $row = $response->json();

        return is_array($row) ? self::parseQuestion($row) : null;
    }

    public function answerQuestion(string $externalQuestionId, string $text): ?string
    {
        $response = $this->client->post(
            $this->sellerUrl('qna', "questions/{$externalQuestionId}/answers"),
            ['text' => $text],
            headers: $this->defaultHeaders(),
        );

        if ($response->clientError() && $response->status() !== 429) {
            $body = mb_strtolower($response->body());

            throw new QuestionAnswerRejected(
                "HTTP {$response->status()}: ".mb_substr($response->body(), 0, 300),
                str_contains($body, 'already answered') ? QuestionReason::QUESTION_ALREADY_ANSWERED : QuestionReason::QUESTION_ANSWER_REJECTED,
            );
        }

        $response->throw();

        $answerId = $response->json('answerId');

        return is_scalar($answerId) && (string) $answerId !== '' && (string) $answerId !== '0' ? (string) $answerId : null;
    }

    /** @param  array<string, mixed>  $row */
    private static function parseClaim(array $row): ?RemoteReturn
    {
        $claimId = (string) ($row['claimId'] ?? $row['id'] ?? '');

        if ($claimId === '') {
            return null;
        }

        $items = [];

        foreach ((array) ($row['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $line = is_array($item['orderLine'] ?? null) ? $item['orderLine'] : [];

            foreach ((array) ($item['claimItems'] ?? []) as $claimItem) {
                if (! is_array($claimItem) || ! isset($claimItem['id'])) {
                    continue;
                }

                $reason = is_array($claimItem['customerClaimItemReason'] ?? null) ? $claimItem['customerClaimItemReason'] : [];

                $items[] = new RemoteReturnItem(
                    externalItemId: (string) $claimItem['id'],
                    // claimItem = tek birim (orderLineItemId birim kimliği).
                    quantity: 1,
                    channelStatus: self::claimText($claimItem['claimItemStatus']['name'] ?? null),
                    externalOrderLineId: self::claimText($line['id'] ?? null),
                    sku: self::claimText($line['merchantSku'] ?? null),
                    barcode: self::claimText($line['barcode'] ?? null),
                    productName: self::claimText($line['productName'] ?? null, 255),
                    reasonCode: self::claimText($reason['code'] ?? ($reason['externalReasonId'] ?? null)),
                    reasonText: self::claimText($reason['name'] ?? null, 255),
                    customerNote: self::claimText($claimItem['customerNote'] ?? null, 2000),
                    acceptDetail: ($claimItem['autoAccepted'] ?? false) === true
                        ? 'AUTO'
                        : self::claimText($claimItem['acceptDetail'] ?? null),
                );
            }
        }

        return new RemoteReturn(
            externalClaimId: $claimId,
            items: $items,
            orderNumber: self::claimText($row['orderNumber'] ?? null),
            packageId: self::claimText($row['orderOutboundPackageId'] ?? null),
            claimType: null,
            claimedAt: self::epochMs($row['claimDate'] ?? null),
            modifiedAt: self::epochMs($row['lastModifiedDate'] ?? null),
        );
    }

    /** @param  array<string, mixed>  $row */
    private static function parseQuestion(array $row): ?RemoteQuestion
    {
        if (! isset($row['id']) || ! is_scalar($row['id'])) {
            return null;
        }

        $answer = is_array($row['answer'] ?? null) ? $row['answer'] : [];
        $rejected = is_array($row['rejectedAnswer'] ?? null) ? $row['rejectedAnswer'] : [];

        return new RemoteQuestion(
            externalId: (string) $row['id'],
            body: (string) ($row['text'] ?? ''),
            channelStatus: self::claimText($row['status'] ?? null),
            productRef: self::claimText($row['productMainId'] ?? null),
            productName: self::claimText($row['productName'] ?? null, 255),
            askedAt: self::epochMs($row['creationDate'] ?? null),
            answerBody: self::claimText($answer['text'] ?? null, 5000),
            answerExternalId: isset($answer['id']) && is_scalar($answer['id']) && (string) $answer['id'] !== '0' ? (string) $answer['id'] : null,
            answeredAt: self::epochMs($answer['creationDate'] ?? null),
            rejectedAnswerExternalId: isset($rejected['id']) && is_scalar($rejected['id']) && (string) $rejected['id'] !== '0' ? (string) $rejected['id'] : null,
            rejectedAnswerReason: self::claimText($rejected['reason'] ?? null, 255),
        );
    }

    private static function claimText(mixed $value, int $max = 64): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** 4xx (429 hariç) KESİN ret; 429/5xx geçici — HTTP istisnası. */
    private static function throwReturnDecisionFailure(Response $response): void
    {
        if ($response->clientError() && $response->status() !== 429) {
            throw new ReturnActionRejected("HTTP {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }

        $response->throw();
    }
}
