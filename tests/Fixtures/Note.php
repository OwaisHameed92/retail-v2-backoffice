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
        // MySQL commits the test's transaction on CREATE TABLE, so RefreshDatabase would migrate again after every
        // test and the rows would stay. A TEMPORARY table commits nothing (and goes with the connection), but cannot
        // have foreign keys or a separate index there (ALTER TABLE commits as well).
        $temporary = Schema::getConnection()->getDriverName() === 'mysql';

        Schema::create('test_notes', function (Blueprint $table) use ($temporary) {
            if ($temporary) {
                $table->temporary();
            }

            $table->ulid('id')->primary();

            if ($temporary) {
                $table->ulid('company_id');
            } else {
                $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            }

            $table->string('body');
            $table->timestamps();
        });
    }
}
