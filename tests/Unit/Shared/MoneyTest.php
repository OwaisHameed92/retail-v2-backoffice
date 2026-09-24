<?php

namespace Tests\Unit\Shared;

use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Shared\Casts\QuantityCast;
use App\Domain\Shared\Support\Money;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    private function model(): Model
    {
        return new class extends Model
        {
            protected $guarded = [];

            protected function casts(): array
            {
                return ['price' => MoneyCast::class, 'qty' => QuantityCast::class];
            }
        };
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function moneyCases(): array
    {
        return [
            'float half up' => [1.005, '1.01'],
            'string half up' => ['1.005', '1.01'],
            'trailing zeros' => ['1.4500', '1.45'],
            'integer' => [5, '5.00'],
            'numeric string' => ['5.15', '5.15'],
            'rounds down' => ['2.344', '2.34'],
            'negative half away from zero' => [-1.005, '-1.01'],
            'negative string' => ['-2.345', '-2.35'],
            'negative zero' => ['-0.001', '0.00'],
            'leading dot' => ['.5', '0.50'],
            'plus sign' => ['+3', '3.00'],
            'large' => ['9999999999.995', '10000000000.00'],
        ];
    }

    #[DataProvider('moneyCases')]
    public function test_money_cast_rounds_half_away_from_zero(mixed $input, string $expected): void
    {
        $model = $this->model();
        $model->price = $input;

        $this->assertSame($expected, $model->price);
        $this->assertSame($expected, $model->getAttributes()['price']);
    }

    public function test_quantity_cast_uses_four_places(): void
    {
        $model = $this->model();

        $model->qty = '1.23455';
        $this->assertSame('1.2346', $model->qty);

        $model->qty = -0.00005;
        $this->assertSame('-0.0001', $model->qty);

        $model->qty = '2';
        $this->assertSame('2.0000', $model->qty);
    }

    public function test_casts_keep_null(): void
    {
        $model = $this->model();
        $model->price = null;

        $this->assertNull($model->price);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidCases(): array
    {
        return [
            'letters' => ['abc'],
            'empty' => [''],
            'mixed' => ['1.2.3'],
            'exponent string' => ['1e3'],
            'array' => [[1]],
            'bool' => [true],
            'infinite' => [INF],
        ];
    }

    #[DataProvider('invalidCases')]
    public function test_casts_reject_non_numeric_input(mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->model()->price = $input;
    }

    public function test_money_helper_maths(): void
    {
        $this->assertSame('3.31', Money::add('1.10', '2.205'));
        $this->assertSame('0.30', Money::add(0.1, 0.2));
        $this->assertSame('-1.00', Money::sub('1.5', '2.5'));
        $this->assertSame('3.09', Money::mul('1.03', 3));
        $this->assertSame('6.60', Money::sum(['1.10', 2.2, '3.3']));
        $this->assertSame(0, Money::compare('1.5', '1.50'));
        $this->assertSame(-1, Money::compare('1.49', '1.5'));
        $this->assertTrue(Money::equals(1.45, '1.4500'));
        $this->assertTrue(Money::isZero('-0.00'));
        $this->assertTrue(Money::isNegative('-0.01'));
        $this->assertSame('0.0000', Money::normalise(1.0E-7, 4));
    }
}
