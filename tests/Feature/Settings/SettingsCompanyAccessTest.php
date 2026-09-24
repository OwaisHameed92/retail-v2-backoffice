<?php

use App\Models\User;

it('logs out a user with no company when they open settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertRedirect('/login');

    $this->assertGuest();
});

it('shows settings to a company member', function () {
    $user = User::factory()->withCompany()->create();

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertOk();
});
