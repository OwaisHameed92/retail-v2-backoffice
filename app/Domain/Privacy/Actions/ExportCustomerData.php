<?php

namespace App\Domain\Privacy\Actions;

use App\Domain\Privacy\Enums\DataRequestStatus;
use App\Domain\Privacy\Enums\DataRequestType;
use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Privacy\Queries\CustomerDataExport;
use App\Domain\Privacy\Support\CustomerExportBundle;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use Carbon\CarbonImmutable;

/**
 * A customer's subject access request (module 7.7): builds the ZIP of everything held about them
 * (CustomerDataExport + CustomerExportBundle), records a completed export request and audits it (counts only, no
 * personal details). The caller streams the temporary file and deletes it.
 *
 *     ['path' => $zip, 'filename' => $name] = app(ExportCustomerData::class)->handle($company, $customerId, $user->id);
 */
final class ExportCustomerData
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly CustomerExportBundle $bundle,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return array{path: string, filename: string, request: DataRequest}
     */
    public function handle(Company $company, string $customerId, ?int $userId): array
    {
        return $this->tenancy->runAs($company, function (Company $company) use ($customerId, $userId): array {
            $customer = Customer::query()->findOrFail($customerId);
            $data = CustomerDataExport::for($company, $customer);
            $path = $this->bundle->build($data);
            $now = CarbonImmutable::now('UTC');

            $request = DataRequest::query()->create([
                'customer_id' => $customer->id,
                'type' => DataRequestType::Export,
                'status' => DataRequestStatus::Completed,
                'source' => 'owner',
                'requested_by_user_id' => $userId,
                'completed_at' => $now,
            ]);

            $this->audit->handle('customer.data_exported', $customer, null, null, [
                'request' => $request->id,
                'ledgerRows' => count($data['ledger']),
                'sales' => count($data['sales']),
                'consentEvents' => count($data['consent']['history']),
            ]);

            return [
                'path' => $path,
                'filename' => 'customer-data-'.substr($customer->id, -6).'-'.$now->setTimezone('Europe/London')->format('Y-m-d').'.zip',
                'request' => $request,
            ];
        });
    }
}
