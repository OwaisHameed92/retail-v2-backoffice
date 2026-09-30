<?php

namespace App\Domain\Setup\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Enums\ReasonType;
use App\Domain\TillData\Models\Reason;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a reason code the till asks for (refund, void, discount, no sale, wastage…; the till's hub-owned
 * `Reason`, module 4.5). Saved through its model, so every till receives it at its next pull. The wording is unique
 * within its type; a new reason goes to the end of its type's list.
 */
final class SaveReason
{
    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @param  array{type: string, text: string, position?: int|string|null, is_active?: bool, account_code?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(Company $company, ?string $reasonId, array $data): Reason
    {
        return $this->tenancy->runAs($company, function () use ($reasonId, $data): Reason {
            $reason = $reasonId === null ? new Reason : Reason::query()->findOrFail($reasonId);
            $type = ReasonType::from($data['type']);
            $text = trim($data['text']);

            $clash = Reason::query()->when($reason->exists, fn ($q) => $q->whereKeyNot($reason->id))
                ->where('type', $type->value)->whereRaw('LOWER(text) = ?', [mb_strtolower($text)])->exists();

            if ($clash) {
                throw ValidationException::withMessages(['text' => 'This reason is already in the list for that type.']);
            }

            $before = $reason->exists ? $this->summary($reason) : null;
            $position = isset($data['position']) && $data['position'] !== '' ? (int) $data['position']
                : ($reason->exists && $reason->type === $type ? $reason->position : ((int) Reason::query()->where('type', $type->value)->max('position')) + 1);
            $code = trim((string) ($data['account_code'] ?? ''));

            $reason->forceFill([
                'type' => $type,
                'text' => $text,
                'position' => $position,
                'is_active' => $data['is_active'] ?? true,
                'account_code' => $code === '' ? null : $code,
            ]);

            if (! $reason->exists || $reason->isDirty()) {
                $reason->save();
                $this->audit->handle($before === null ? 'reason.created' : 'reason.updated', $reason, $before, $this->summary($reason));
            }

            return $reason;
        });
    }

    /** @return array<string, mixed> */
    private function summary(Reason $reason): array
    {
        return ['type' => $reason->type?->value, 'text' => $reason->text, 'position' => $reason->position, 'is_active' => $reason->is_active, 'account_code' => $reason->account_code];
    }
}
