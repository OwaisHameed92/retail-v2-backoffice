<?php

namespace Tests\Fixtures;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only tenant model used to prove tenant isolation.
 *
 * @property string $id
 * @property string $company_id
 * @property string $body
 */
class Note extends Model
{
    use BelongsToCompany, HasUlids;

    protected $table = 'test_notes';

    /**
     * @var list<string>
     */
    protected $fillable = ['company_id', 'body'];

    public static function createTable(): void
    {
        Schema::create('test_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('body');
            $table->timestamps();
        });
    }
}
