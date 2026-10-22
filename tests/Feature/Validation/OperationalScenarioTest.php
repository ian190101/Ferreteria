<?php

declare(strict_types=1);

use Tests\Support\OperationalScenario;

it('prepara compras con pagos parciales bobina reservada y una orden con dos recepciones', function () {
    $scenario = OperationalScenario::create();
    $purchase = $scenario['purchases'][0];

    expect($purchase->payments()->count())->toBe(2)
        ->and((float) $purchase->payments()->sum('amount'))->toBe(300.0)
        ->and((float) $purchase->balance_due)->toBe(1100.0)
        ->and($scenario['reservation']->product_coil_id)->toBe($scenario['coil']->id)
        ->and($scenario['reservation']->status)->toBe('active')
        ->and($scenario['order']->receipts()->count())->toBe(2)
        ->and((float) $scenario['orderItem']->received_meters)->toBe(100.0)
        ->and($scenario['sequence']->preview())->toBe('QA-NV-000002');

    $this->assertDatabaseHas('purchases', ['document_number' => 'QA-COMP-001']);
    $this->assertDatabaseHas('purchase_order_receipts', ['receipt_number' => 'QA-REC-002']);
});
