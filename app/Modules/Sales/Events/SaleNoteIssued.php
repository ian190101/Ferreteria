<?php

namespace App\Modules\Sales\Events;

class SaleNoteIssued
{
    public function __construct(
        public readonly int $saleId,
        public readonly int $userId,
        public readonly ?int $sourceQuotationId = null,
        public readonly ?array $posPayment = null,
    ) {
    }
}
