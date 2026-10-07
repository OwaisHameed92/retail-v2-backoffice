<?php

namespace App\Domain\Mail\Actions;

use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Models\EmailSetting;
use App\Domain\Mail\Support\EmailControl;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;

/**
 * Saves "Send automatically" per email category (P11, Admin → Settings → Emails, or `emails:auto`). Only changed
 * categories are written and audited (`email.settings_updated`, before/after). Emails already held stay held.
 */
class UpdateEmailSettings
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, bool>  $settings  category value => send automatically (unknown keys ignored)
     * @return array<string, bool> the settings after saving
     */
    public function handle(array $settings, ?string $adminId = null): array
    {
        return DB::transaction(function () use ($settings, $adminId) {
            $current = EmailControl::all();
            $before = [];
            $after = [];

            foreach (EmailCategory::cases() as $category) {
                if (! array_key_exists($category->value, $settings) || $settings[$category->value] === $current[$category->value]) {
                    continue;
                }

                EmailSetting::query()->updateOrCreate(['category' => $category->value], [
                    'send_automatically' => $settings[$category->value],
                    'updated_by_admin_id' => $adminId,
                ]);

                $before[$category->value] = $current[$category->value];
                $after[$category->value] = $settings[$category->value];
            }

            if ($after !== []) {
                $this->audit->handle('email.settings_updated', null, $before, $after);
            }

            return EmailControl::all();
        });
    }
}
