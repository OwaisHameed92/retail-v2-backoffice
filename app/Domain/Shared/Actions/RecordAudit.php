<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\Redactor;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes one audit log entry: who did what to which record, with before/after values.
 *
 * The actor is taken from the `admin` guard, then the `web` guard. Jobs and till APIs pass `$actor`
 * explicitly (or leave it null for "system"). Secrets are redacted from before/after/meta.
 */
final class RecordAudit
{
    /** Guards checked in order when no actor is passed. */
    private const GUARDS = ['admin', 'web'];

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Request $request,
    ) {}

    /**
     * @param  string  $action  Dotted verb, e.g. `licence.suspended`.
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $meta
     * @param  Model|null  $actor  Explicit actor for jobs/APIs; auto-detected when null.
     * @param  string|null  $companyId  Defaults to the subject's `company_id` when it has one.
     */
    public function handle(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        array $meta = [],
        ?Model $actor = null,
        ?string $companyId = null,
    ): AuditLog {
        $actor ??= $this->detectActor();

        return AuditLog::query()->create([
            'company_id' => $companyId ?? $this->companyIdOf($subject),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor !== null ? (string) $actor->getKey() : null,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject !== null ? (string) $subject->getKey() : null,
            'before' => Redactor::redact($before),
            'after' => Redactor::redact($after),
            'meta' => $meta === [] ? null : Redactor::redact($meta),
            'ip' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, '') ?: null,
        ]);
    }

    private function detectActor(): ?Model
    {
        foreach (self::GUARDS as $guard) {
            if (config("auth.guards.{$guard}") === null) {
                continue;
            }

            $user = $this->auth->guard($guard)->user();

            if ($user instanceof Model) {
                return $user;
            }
        }

        return null;
    }

    private function companyIdOf(?Model $subject): ?string
    {
        if ($subject === null || ! array_key_exists('company_id', $subject->getAttributes())) {
            return null;
        }

        $companyId = $subject->getAttribute('company_id');

        return $companyId === null ? null : (string) $companyId;
    }
}
