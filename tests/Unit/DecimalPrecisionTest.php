<?php

use App\Support\DecimalPrecision;

it('mantiene los centavos aunque la configuración anterior use otra precisión', function () {
    $precision = DecimalPrecision::normalize([
        'money' => 1,
        'cost' => 1,
        'exchange_rate' => 5,
        'modules' => [
            'sales' => ['money' => 1, 'cost' => 1, 'measure' => 3],
            'purchases' => ['money' => 0, 'cost' => 4],
            'finance' => ['money' => 1],
            'inventory' => ['cost' => 3],
        ],
    ]);

    expect($precision['money'])->toBe(2)
        ->and($precision['modules']['sales']['money'])->toBe(2)
        ->and($precision['modules']['sales']['cost'])->toBe(2)
        ->and($precision['modules']['purchases']['money'])->toBe(2)
        ->and($precision['modules']['purchases']['cost'])->toBe(2)
        ->and($precision['modules']['finance']['money'])->toBe(2)
        ->and($precision['modules']['inventory']['cost'])->toBe(3)
        ->and($precision['modules']['sales']['measure'])->toBe(3)
        ->and($precision['exchange_rate'])->toBe(5);
});
