<?php

namespace App\Domain\Leads\Data;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Enums\BusinessType;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadNote;
use Carbon\CarbonInterface;

/**
 * Shapes leads for the admin React pages (camelCase keys, ISO-8601 UTC dates).
 */
final class LeadData
{
    /**
     * @return array<string, mixed>
     */
    public static function listRow(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'businessName' => $lead->business_name,
            'contactName' => $lead->contact_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'town' => $lead->town,
            'postcode' => $lead->postcode,
            'shopsCount' => $lead->shops_count,
            'tillsCount' => $lead->tills_count,
            'businessType' => $lead->business_type->value,
            'source' => $lead->source->value,
            'status' => $lead->status->value,
            'assignedAdmin' => self::admin($lead->assignedAdmin),
            'followUpAt' => self::date($lead->follow_up_at),
            'createdAt' => self::date($lead->created_at),
            'archived' => $lead->trashed(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Lead $lead): array
    {
        return self::listRow($lead) + [
            'currentSystem' => $lead->current_system,
            'message' => $lead->message,
            'consentMarketing' => $lead->consent_marketing,
            'utm' => $lead->utm,
            'ip' => $lead->ip,
            'contactedAt' => self::date($lead->contacted_at),
            'lastContactedAt' => self::date($lead->last_contacted_at),
            'rejectionReason' => $lead->rejection_reason,
            'rejectedAt' => self::date($lead->rejected_at),
            'convertedAt' => self::date($lead->converted_at),
            'convertedBy' => self::admin($lead->convertedBy),
            'company' => $lead->company === null ? null : [
                'id' => $lead->company->id,
                'name' => $lead->company->name,
                'status' => $lead->company->status->value,
                'deleted' => $lead->company->trashed(),
            ],
            'updatedAt' => self::date($lead->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function note(LeadNote $note): array
    {
        return [
            'id' => $note->id,
            'kind' => $note->kind->value,
            'system' => $note->kind->isSystem(),
            'body' => $note->body,
            'meta' => $note->meta,
            'author' => self::admin($note->admin),
            'createdAt' => self::date($note->created_at),
        ];
    }

    /**
     * Values for the add/edit form.
     *
     * @return array<string, mixed>
     */
    public static function form(Lead $lead): array
    {
        return [
            'business_name' => $lead->business_name,
            'contact_name' => $lead->contact_name,
            'email' => $lead->email ?? '',
            'phone' => $lead->phone ?? '',
            'town' => $lead->town ?? '',
            'postcode' => $lead->postcode ?? '',
            'shops_count' => $lead->shops_count,
            'tills_count' => $lead->tills_count,
            'business_type' => $lead->business_type->value,
            'current_system' => $lead->current_system ?? '',
            'message' => $lead->message ?? '',
            'source' => $lead->source->value,
            'consent_marketing' => $lead->consent_marketing,
        ];
    }

    /**
     * Select options shared by the lead pages.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'sources' => LeadSource::options(),
            'businessTypes' => BusinessType::options(),
            'admins' => self::assignableAdmins(),
            'maxShops' => Lead::MAX_SHOPS,
            'maxTills' => Lead::MAX_TILLS,
        ];
    }

    /**
     * Active staff who can be given leads (owner and sales).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function assignableAdmins(): array
    {
        return Admin::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (Admin $admin) => $admin->hasAbility(AdminRole::LEADS_MANAGE))
            ->map(fn (Admin $admin) => ['value' => $admin->id, 'label' => $admin->name])
            ->values()
            ->all();
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public static function admin(?Admin $admin): ?array
    {
        return $admin === null ? null : ['id' => $admin->id, 'name' => $admin->name];
    }

    public static function date(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->toIso8601String();
    }
}
