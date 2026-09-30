<?php

namespace App\Domain\Customers\Support;

use App\Domain\Customers\Queries\CustomerStatement;
use App\Domain\Mail\Contracts\RendersAttachment;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * A customer's A4 account statement PDF (dompdf, resources/views/customers/statement-pdf.blade.php), the same approach
 * as the invoice PDF (InvoicePdf): remote resources off, DejaVu Sans. Built from CustomerStatement::for(), like the
 * screen. For the statement email it is rendered in the queue worker from a key "companyId:customerId:from:to".
 */
final class StatementPdf implements RendersAttachment
{
    /**
     * @param  array<string, mixed>  $statement  CustomerStatement::for()
     */
    public function render(array $statement): string
    {
        return Pdf::loadView('customers.statement-pdf', ['s' => $statement])->setPaper('a4')->setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'isFontSubsettingEnabled' => true,
            'dpi' => 144,
        ])->output();
    }

    public static function key(Customer $customer, string $from, string $to): string
    {
        return implode(':', [$customer->company_id, $customer->id, $from, $to]);
    }

    public function renderAttachment(string $key): ?string
    {
        [$companyId, $customerId, $from, $to] = array_pad(explode(':', $key), 4, '');
        $company = Company::query()->find($companyId);

        if ($company === null) {
            return null;
        }

        return app(CurrentCompany::class)->runAs($company, function (Company $company) use ($customerId, $from, $to): ?string {
            $customer = Customer::query()->find($customerId);

            return $customer === null ? null : $this->render(CustomerStatement::for($company, $customer, $from, $to));
        });
    }
}
