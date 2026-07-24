<?php

use App\Models\User;
use Illuminate\Support\Facades\Password;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('reset password request shows administrator contact message', function () {
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHas('status', 'Contacta al administrador para restablecer tu contrasena.');
});

test('reset password screen can be rendered', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->get('/reset-password/'.$token)
        ->assertStatus(200);
});

test('password can be reset with valid token', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));
});
