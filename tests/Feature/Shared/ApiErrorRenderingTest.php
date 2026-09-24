<?php

namespace Tests\Feature\Shared;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\Ulid;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class ApiErrorRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/test-errors')->group(function () {
            Route::get('ok', fn () => ['ok' => true]);
            Route::get('api-exception', fn () => throw ApiException::rowRejected('Sale:01K5VB000000000SR001000482:1', 'Sale 01K5VB000000000SR001000482 has a total with more than 2 decimal places.'));
            Route::post('validate', function (Request $request) {
                $request->validate(['deviceId' => ['required', 'string']]);

                return ['ok' => true];
            });
            Route::get('missing', fn () => abort(404));
            Route::get('throttled', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 42]));
            Route::get('crash', fn () => throw new RuntimeException('SQLSTATE secret-db-password at /var/www/app.php'));
        });
    }

    private function assertErrorShape(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)
            ->assertExactJsonStructure(['code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey'])
            ->assertJsonPath('code', $code);

        $traceId = $response->json('traceId');
        $this->assertTrue(Ulid::isValid($traceId));
        $this->assertSame($traceId, $response->headers->get('X-Trace-Id'));
        $this->assertNotSame('', $response->json('message'));

        $body = (string) $response->getContent();
        foreach (['exception', 'trace', 'file', 'line', 'SQLSTATE', '.php'] as $leak) {
            $this->assertStringNotContainsString('"'.$leak.'"', $body);
        }
    }

    public function test_successful_api_replies_carry_a_trace_id(): void
    {
        $response = $this->getJson('/api/test-errors/ok')->assertOk();

        $this->assertTrue(Ulid::isValid($response->headers->get('X-Trace-Id')));
    }

    public function test_api_exception(): void
    {
        $response = $this->getJson('/api/test-errors/api-exception');

        $this->assertErrorShape($response, 422, 'row.invalid');
        $response->assertJsonPath('rejectedKey', 'Sale:01K5VB000000000SR001000482:1')
            ->assertJsonPath('retryAfterSeconds', null);
    }

    public function test_validation_errors_become_400(): void
    {
        $response = $this->postJson('/api/test-errors/validate', []);

        $this->assertErrorShape($response, 400, 'request.invalid');
        $this->assertStringContainsString('device id', (string) $response->json('message'));
    }

    public function test_not_found(): void
    {
        $this->assertErrorShape($this->getJson('/api/test-errors/missing'), 404, 'not_found');
        // Unknown route: the api middleware never ran, the renderer still adds a trace id.
        $this->assertErrorShape($this->getJson('/api/does-not-exist'), 404, 'not_found');
    }

    public function test_rate_limited(): void
    {
        $response = $this->getJson('/api/test-errors/throttled');

        $this->assertErrorShape($response, 429, 'rate_limited');
        $response->assertJsonPath('retryAfterSeconds', 42)->assertHeader('Retry-After', '42');
    }

    public function test_server_errors_do_not_leak_details(): void
    {
        config(['app.debug' => true]);

        $response = $this->getJson('/api/test-errors/crash');

        $this->assertErrorShape($response, 500, 'server.error');
        $this->assertStringNotContainsString('secret-db-password', (string) $response->getContent());
    }

    public function test_non_api_routes_are_untouched(): void
    {
        $this->getJson('/definitely-not-a-page')->assertNotFound()->assertJsonMissingPath('traceId');
    }
}
