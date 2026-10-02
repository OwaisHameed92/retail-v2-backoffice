<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('the sessions table stores a ULID user id (admins), not only integers', function () {
    $ulid = (string) Str::ulid();

    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $ulid,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => 'x',
        'last_activity' => time(),
    ]);

    expect(DB::table('sessions')->where('user_id', $ulid)->exists())->toBeTrue();
});
