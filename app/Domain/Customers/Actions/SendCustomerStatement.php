<?php

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Queries\CustomerStatement;
use App\Domain\Customers\Support\StatementPdf;
use App\Domain\Mail\Data\CustomerStatementData;
use App\Domain\Mail\Mailables\CustomerStatementMail;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Emails a customer their account statement for a date range, PDF attached (module 4.4). Only to the customer's own
 * email address on file, never to an address typed in; refused for an anonymised customer or one without an email.
 * A statement is a transactional message, so it does not need marketing consent. Audited `customer.statement_sent`
 * (the address itself is not written to the audit trail).
 *
 *     app(SendCustomerStatement::class)->handle($company, $customerId, '2026-10-01', '2026-10-31');
 */
final class SendCustomerStatement
{
    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, string $customerId, string $from, string $to): Customer
    {
        return $this->tenancy->runAs($company, function (Company $company) use ($customerId, $from, $to): Customer {
            $customer = Customer::query()->findOrFail($customerId);
            $email = trim((string) $customer->email);

            if ($customer->anonymised_at !== null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['email' => 'This customer has no email address. Add one to their details first, or download the PDF.']);
            }

            $statement = CustomerStatement::for($company, $customer, $from, $to);

            Mail::to($email, $customer->name ?: null)->queue(new CustomerStatementMail(new CustomerStatementData(
                businessName: $company->name,
                businessEmail: $company->email,
                businessPhone: $company->phone,
                customerName: (string) $customer->name,
                period: (string) $statement['period'],
                closingBalance: (string) $statement['closing']['balance'],
                closingPoints: (int) $statement['closing']['points'],
                pdfRenderer: StatementPdf::class,
                pdfKey: StatementPdf::key($customer, $from, $to),
                filename: CustomerStatement::filename($customer, $from, $to),
                companyId: $company->id,
            )));

            $this->audit->handle('customer.statement_sent', $customer, null, null, ['name' => $customer->name, 'from' => $from, 'to' => $to]);

            return $customer;
        });
    }
}
