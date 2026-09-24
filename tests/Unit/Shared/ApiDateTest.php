<?php

namespace Tests\Unit\Shared;

use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Shared\Support\ApiDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tests\TestCase;

class ApiDateTest extends TestCase
{
    public function test_it_formats_as_utc_with_z(): void
    {
        $london = CarbonImmutable::parse('2026-09-23 10:41:12', 'Europe/London');

        $this->assertSame('2026-09-23T09:41:12Z', ApiDate::format($london));
        $this->assertSame('2026-09-23T09:41:12Z', ApiDate::format('2026-09-23T10:41:12+01:00'));
        $this->assertSame('2026-09-23T09:41:12Z', ApiDate::format('2026-09-23T09:41:12'));
        $this->assertNull(ApiDate::format(null));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', ApiDate::now());
    }

    public function test_it_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApiDate::parse('not a date');
    }

    public function test_the_cast_stores_utc_and_serialises_with_z(): void
    {
        $model = new class extends Model
        {
            protected $guarded = [];

            protected function casts(): array
            {
                return ['at' => UtcDateTimeCast::class];
            }
        };

        $model->at = '2026-09-23T10:41:12+01:00';

        $this->assertSame('2026-09-23 09:41:12', $model->getAttributes()['at']);
        $this->assertSame('UTC', $model->at?->tzName);
        $this->assertSame('2026-09-23T09:41:12Z', $model->toArray()['at']);
    }
}
