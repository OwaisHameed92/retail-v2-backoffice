<?php

use App\Domain\Admin\Models\Admin;
use App\Models\User;

beforeEach(fn () => $this->withoutVite());

test('the root sends everyone to the sign-in; the trial form stays public', function () {
    $this->get('/')->assertRedirect('/login');
    $this->get('/trial')->assertOk();
    expect(file_exists(resource_path('js/pages/welcome.tsx')))->toBeFalse();
});

test('with an admin signed in, /login shows the customer sign-in and / goes to the admin area: no /login ⇄ / loop', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');

    $this->get('/login')->assertOk();
    $this->get('/')->assertRedirect(route('admin.dashboard'));
    $this->get('/forgot-password')->assertOk();
});

test('a signed-in customer on /login or / goes to their portal, never back to the sign-in', function () {
    $this->actingAs(User::factory()->create(), 'web');

    $this->get('/login')->assertRedirect(route('app.dashboard'));
    $this->get('/')->assertRedirect(route('app.dashboard'));
    $this->get('/forgot-password')->assertRedirect(route('app.dashboard'));
});

test('with both sessions (an admin logged in as a customer) the customer portal wins', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->actingAs(User::factory()->create(), 'web');

    $this->get('/login')->assertRedirect(route('app.dashboard'));
    $this->get('/')->assertRedirect(route('app.dashboard'));
});
