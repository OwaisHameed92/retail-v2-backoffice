<?php

test('the root sends everyone to the sign-in; the trial form stays public', function () {
    $this->withoutVite();

    $this->get('/')->assertRedirect('/login');
    $this->get('/trial')->assertOk();
    expect(file_exists(resource_path('js/pages/welcome.tsx')))->toBeFalse();
});
