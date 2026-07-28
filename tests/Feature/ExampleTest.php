<?php

it('redirige la raiz al login del sistema', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});
