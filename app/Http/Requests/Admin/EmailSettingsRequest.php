<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Mail\Enums\EmailCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * P11: `settings` = {category: bool} for Admin → Settings → Emails. Owner and accounts (billing.manage).
 */
class EmailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->can(AdminRole::BILLING_MANAGE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array']];

        foreach (EmailCategory::values() as $category) {
            $rules["settings.{$category}"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, bool>
     */
    public function settings(): array
    {
        $settings = [];

        foreach (EmailCategory::values() as $category) {
            if ($this->has("settings.{$category}")) {
                $settings[$category] = $this->boolean("settings.{$category}");
            }
        }

        return $settings;
    }
}
