<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

it('mantiene el estado http y entrega codigo detalle explicacion y referencia', function (int $status) {
    Route::middleware('web')->get('/_test/system-error', fn () => abort($status, 'Detalle técnico de prueba.'));

    $response = $this->getJson('/_test/system-error');
    $response->assertStatus($status)
        ->assertHeader('X-System-Error-Code', 'HTTP_'.$status)
        ->assertJsonPath('system_error.code', 'HTTP_'.$status)
        ->assertJsonPath('system_error.http_status', $status)
        ->assertJsonPath('system_error.technical_message', 'Detalle técnico de prueba.')
        ->assertJsonStructure(['system_error' => ['id', 'explanation', 'exception_type', 'native_code']]);
    expect($response->json('system_error.id'))->toBe($response->headers->get('X-Error-Reference'));
})->with([401, 403, 404, 405, 409, 419, 423, 429, 503]);

it('conserva la validacion json y explica el bloqueo de inventario', function () {
    Route::middleware('web')->post('/_test/system-error', function () {
        throw ValidationException::withMessages(['purchase' => 'La bobina 13 tiene movimientos de consumo y no puede anularse.']);
    });

    $this->postJson('/_test/system-error')
        ->assertUnprocessable()->assertJsonValidationErrors('purchase')
        ->assertJsonPath('system_error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('system_error.technical_message', 'La bobina 13 tiene movimientos de consumo y no puede anularse.')
        ->assertJsonPath('system_error.explanation', 'La mercadería necesaria para revertir la compra ya se usó, está reservada o no alcanza. Revisa sus movimientos y reservas.');
});

it('conserva el formulario y envia el diagnostico en la redireccion inertia', function () {
    Route::middleware('web')->post('/_test/system-error', function () {
        throw ValidationException::withMessages(['purchase' => 'Los pagos y el saldo pagado de la compra no son consistentes.']);
    });

    $this->from('/login')->post('/_test/system-error')
        ->assertRedirect('/login')->assertSessionHasErrors('purchase')
        ->assertSessionHas('system_error.code', 'VALIDATION_FAILED');

    $version = app(HandleInertiaRequests::class)->version(Request::create('/login'));
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->get('/login')
        ->assertOk()->assertJsonPath('props.systemError.code', 'VALIDATION_FAILED')
        ->assertJsonPath('props.systemError.technical_message', 'Los pagos y el saldo pagado de la compra no son consistentes.');
});

it('expone sqlstate y codigo nativo sin sql ni bindings', function () {
    Route::middleware('web')->get('/_test/system-error', function () {
        $previous = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'valor-confidencial' for key 'unique_test'");
        $previous->errorInfo = ['23000', 1062, 'Duplicate entry'];
        throw new QueryException('mysql', 'insert into users (password) values (?)', ['binding-secreto'], $previous);
    });

    $response = $this->getJson('/_test/system-error');
    $response->assertStatus(500)->assertJsonPath('system_error.code', 'DB_23000_1062')
        ->assertJsonPath('system_error.sql_state', '23000')->assertJsonPath('system_error.native_code', '1062');
    expect($response->getContent())->not->toContain('binding-secreto', 'valor-confidencial', 'insert into users', 'unique_test');
});

it('oculta credenciales y trazas incluso con debug activo', function () {
    config(['app.debug' => true]);
    Route::middleware('web')->get('/_test/system-error', fn () => throw new RuntimeException('Fallo password=clave-privada token=token-privado mysql://usuario:conexion-secreta@servidor/base'));

    $response = $this->getJson('/_test/system-error');
    $response->assertStatus(500)->assertJsonPath('system_error.exception_type', RuntimeException::class);
    expect($response->getContent())->not->toContain('clave-privada', 'token-privado', 'conexion-secreta', '"trace"', '"file"');
});

it('muestra una pagina de emergencia escapada sin necesitar inertia', function () {
    Route::middleware('web')->get('/_test/system-error', fn () => throw new RuntimeException('<script>alert(1)</script>'));

    $this->get('/_test/system-error')->assertStatus(500)
        ->assertSee('HTTP_500')->assertSee('Referencia')
        ->assertDontSee('<script>', false)->assertSee('&lt;script&gt;', false);
});
