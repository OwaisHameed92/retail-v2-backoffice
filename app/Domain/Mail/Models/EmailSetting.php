<?php

namespace App\Domain\Mail\Models;

use App\Domain\Mail\Enums\EmailCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "Send automatically" for one email category (P11). No row = on. Global (not tenant-owned).
 *
 * @property EmailCategory $category
 * @property bool $send_automatically
 * @property string|null $updated_by_admin_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EmailSetting extends Model
{
    protected $table = 'email_settings';

    protected $primaryKey = 'category';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['category', 'send_automatically', 'updated_by_admin_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => EmailCategory::class,
            'send_automatically' => 'boolean',
        ];
    }
}
