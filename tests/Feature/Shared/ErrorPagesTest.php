<?php

use App\Http\Support\InertiaErrorPages;

beforeEach(function () {
    InertiaErrorPages::$inTests = true;
    config(['app.debug' => false]);
});

afterEach(function () {
    InertiaErrorPages::$inTests = false;
});

it('renders the branded page for a missing portal page', function () {
    $this->get('/app/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->component('error')->where('status', 404)->where('home', '/app'));
});

it('points admin errors back to the admin dashboard', function () {
    $this->get('/admin/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->component('error')->where('home', '/admin'));
});

it('keeps JSON errors for the till APIs and JSON callers', function () {
    $this->getJson('/api/v1/no-such-endpoint')->assertNotFound()->assertJsonStructure(['code', 'message', 'traceId']);
    $this->getJson('/app/no-such-page')->assertNotFound()->assertJsonMissingPath('component');
});

it('shows the framework page while debugging', function () {
    config(['app.debug' => true]);

    $response = $this->get('/app/no-such-page')->assertNotFound();

    expect($response->getContent())->not->toContain('&quot;component&quot;:&quot;error&quot;');
});

it('sends an expired page back with a toast', function () {
    $response = InertiaErrorPages::render(response('', 419), request()->create('/app/customers', 'POST'));

    expect($response->getStatusCode())->toBe(302)
        ->and(session('error'))->toBe('The page expired. Try again.');
});
