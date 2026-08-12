<?php

it('redirige la raiz al login del sistema', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});

it('expone health check publico para render sin redireccion', function () {
    $this->get('/health')
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});
