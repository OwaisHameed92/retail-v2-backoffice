<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Database\Factories\RegisterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A till of a branch. Fields mirror the till's Register entity. `nextSaleNo`/`nextRefundNo` are till-owned
 * counters and are deliberately not stored here. One licence = one register.
 *
 * Invariant (kept by the Register actions): every branch with at least one active register has exactly one
 * active main till; inactive registers are never main.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $code
 * @property string $name
 * @property bool $is_main_till
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Register extends Model
{
    /** @use HasFactory<RegisterFactory> */
    use BelongsToCompany, HasFactory, HasPortalUlid, SoftDeletes;

    public const CODE_PATTERN = '/^(0[1-9]|[1-9][0-9])$/';

    public const MAX_PER_BRANCH = 99;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'code',
        'name',
        'is_main_till',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_main_till' => false,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_main_till' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<Register>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public static function codeFor(int $number): string
    {
        return str_pad((string) $number, 2, '0', STR_PAD_LEFT);
    }

    protected static function newFactory(): RegisterFactory
    {
        return RegisterFactory::new();
    }
}
