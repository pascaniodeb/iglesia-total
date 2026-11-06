<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

test('uses Spanish as the application language', function () {
    expect(app()->getLocale())->toBe('es');
    expect(app()->getFallbackLocale())->toBe('es');
});

test('rejects invalid credentials with the failed login message in Spanish', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors(['email' => 'Estas credenciales no coinciden con nuestros registros.']);
});

test('rejects a request without email with a validation message in Spanish', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Nueva Persona',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors(['email' => 'El campo correo electrónico es obligatorio.']);
});

test('rejects a mismatched password confirmation in Spanish', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Nueva Persona',
        'email' => 'nueva@example.com',
        'password' => 'password',
        'password_confirmation' => 'different',
    ]);

    $response->assertSessionHasErrors(['password' => 'La confirmación del campo contraseña no coincide.']);
});

test('flashes the profile updated toast in Spanish', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
    ]);

    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Perfil actualizado.']);
});

test('flashes the password updated toast in Spanish', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => 'a-new-Passw0rd-here',
        'password_confirmation' => 'a-new-Passw0rd-here',
    ]);

    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Contraseña actualizada.']);
});

test('sends the password reset email in Spanish', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $mail = $notification->toMail($user);

        expect($mail->subject)->toBe('Restablezca su contraseña');
        expect($mail->actionText)->toBe('Restablecer contraseña');

        return true;
    });
});

test('sends the email verification message in Spanish', function () {
    $user = User::factory()->unverified()->create();

    $mail = (new VerifyEmail)->toMail($user);

    expect($mail->subject)->toBe('Verifique su correo electrónico');
    expect($mail->actionText)->toBe('Verificar correo electrónico');
});

test('renders the login page in a Spanish document', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSee('<html lang="es"', false);
});
