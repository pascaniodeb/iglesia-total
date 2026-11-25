<?php

namespace App\Modules\Treasury\Domain\Receipts;

use App\Modules\Integrity\Domain\Ledger\Canonical;

/**
 * What a receipt says on paper: number, type, date, account, parties, amounts, concept, payment
 * and signers. It is what the monthly seal binds to the receipt's entry.
 *
 * Its fingerprint never includes the receipt's status or void data: voiding a receipt of a
 * closed month is recorded forward, as a reversal in an open month, and must not change that
 * month's sealed payload (it would raise drift on a period nobody touched, for good). Do not add
 * the status "for consistency".
 */
final readonly class ReceiptContent
{
    public function __construct(
        public int $number,
        public ReceiptType $type,
        public string $issuedOn,
        public string $accountId,
        public ?string $holder,
        public ?string $counterparty,
        public string $amountVes,
        public string $amountUsd,
        public string $amountCop,
        public ?string $concept,
        public PaymentMethod $paymentMethod,
        public ?string $bank,
        public ?string $paymentReference,
        public ?string $paymentDate,
        public ?string $receivedByName,
        public ?string $receivedByIdNumber,
        public ?string $deliveredByName,
        public ?string $deliveredByIdNumber,
    ) {}

    /**
     * The SHA-256 of the canonical form of the content (amounts as text, as everywhere in the seal).
     */
    public function hash(): string
    {
        return Canonical::hash([
            'number' => $this->number,
            'type' => $this->type->value,
            'issuedOn' => $this->issuedOn,
            'accountId' => $this->accountId,
            'holder' => $this->holder,
            'counterparty' => $this->counterparty,
            'ves' => $this->amountVes,
            'usd' => $this->amountUsd,
            'cop' => $this->amountCop,
            'concept' => $this->concept,
            'paymentMethod' => $this->paymentMethod->value,
            'bank' => $this->bank,
            'paymentReference' => $this->paymentReference,
            'paymentDate' => $this->paymentDate,
            'receivedByName' => $this->receivedByName,
            'receivedByIdNumber' => $this->receivedByIdNumber,
            'deliveredByName' => $this->deliveredByName,
            'deliveredByIdNumber' => $this->deliveredByIdNumber,
        ]);
    }
}
