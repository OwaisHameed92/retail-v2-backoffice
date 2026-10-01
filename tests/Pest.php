<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
 * A JSON column read back: MySQL's JSON type stores object members in its own order (SQLite keeps the written one),
 * so compare objects (string-keyed arrays) member by member, still strictly (types and list order count).
 */
expect()->extend('toBeIgnoringKeyOrder', function (array $expected) {
    $sort = function (mixed $value) use (&$sort): mixed {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($sort, $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    };

    return expect($sort($this->value))->toBe($sort($expected));
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
