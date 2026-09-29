<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;

/**
 * Audit and telemetry for changes a till makes through the licence API. The actor is always the till (the
 * licence's Register), never a signed-in admin or user, even if one shares the request.
 */
final class TillAudit
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $meta
     */
    public function record(string $action, Licence $licence, ?array $before, ?array $after, TillRequest $request, array $meta = []): void
    {
        $register = Register::withoutCompanyScope()->withTrashed()->find($licence->register_id);

        $this->audit->handle($action, $licence, $before, $after, [
            'source' => 'tillApi',
            'device_name' => $request->deviceName ?? $licence->device_name,
            'app_version' => $request->appVersion,
            ...$meta,
        ], actor: $register, companyId: $licence->company_id);
    }

    /**
     * Last contact from the bound PC: time, IP, app version, contract version, OS, device name, validate
     * diagnostics and till clock skew (tillClockUtc − portal time, seconds). Saved with the licence by the caller.
     */
    public static function touch(Licence $licence, TillRequest $request, CarbonImmutable $now): void
    {
        $licence->last_check_in_at = $now;
        $licence->last_ip = $request->ip !== null ? mb_substr($request->ip, 0, 45) : $licence->last_ip;

        if ($request->appVersion !== null) {
            $licence->last_app_version = mb_substr($request->appVersion, 0, 50);
        }

        if ($request->os !== null) {
            $licence->os = $request->os;
        }

        if ($request->deviceName !== null) {
            $licence->device_name = mb_substr($request->deviceName, 0, 100);
        }

        if ($request->installCode !== null) {
            $licence->install_code = $request->installCode;
        }

        if ($request->contractVersion !== null) {
            $licence->last_contract_version = $request->contractVersion;
        }

        // Module 2.7: validate diagnostics (Till health backlog). An empty object still dates the report.
        if ($request->diagnostics !== null) {
            $licence->diagnostics = $request->diagnostics === [] ? null : $request->diagnostics;
            $licence->diagnostics_at = $now;
        }

        if ($request->tillClockUtc !== null) {
            $skew = $request->tillClockUtc->getTimestamp() - $now->getTimestamp();
            $licence->till_clock_skew_seconds = max(-2147483647, min(2147483647, $skew));
        }
    }
}
