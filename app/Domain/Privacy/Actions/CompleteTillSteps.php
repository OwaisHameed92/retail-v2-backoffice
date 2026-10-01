<?php

namespace App\Domain\Privacy\Actions;

use App\Domain\Privacy\Enums\DataRequestStatus;
use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The owner confirms the till steps of an erasure are done (module 7.7): the request becomes completed. Audited.
 */
final class CompleteTillSteps
{
    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    public function handle(Company $company, string $requestId, ?int $userId): DataRequest
    {
        return $this->tenancy->runAs($company, function () use ($requestId, $userId): DataRequest {
            $request = DataRequest::query()->findOrFail($requestId);

            if ($request->status !== DataRequestStatus::TillPending) {
                throw ValidationException::withMessages(['request' => 'This request has nothing left to do on the tills.']);
            }

            $now = CarbonImmutable::now('UTC');
            $request->forceFill(['status' => DataRequestStatus::Completed, 'till_done_at' => $now, 'till_done_by_user_id' => $userId, 'completed_at' => $now])->save();
            $this->audit->handle('privacy.till_steps_done', $request, ['status' => 'tillPending'], ['status' => 'completed']);

            return $request;
        });
    }
}
