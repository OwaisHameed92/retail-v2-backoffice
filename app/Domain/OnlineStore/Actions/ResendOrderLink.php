<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Mail\OnlineOrderReceivedMail;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\OnlineOrderMessages;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Sends the customer their order email again, with a fresh order link (audit OSA-22: links expire; the shop can send
 * a new one), from the portal's order page. Uses the existing "We've got your order" email (OS-10's). Refused for an
 * order whose details were anonymised or whose card payment did not go through. Audited.
 */
final class ResendOrderLink
{
    public function __construct(private readonly OnlineOrderMessages $messages, private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, string $orderId): OnlineOrder
    {
        return $this->tenancy->runAs($company, function () use ($company, $orderId): OnlineOrder {
            $order = OnlineOrder::query()->with(['lines', 'branch:id,name,address,town,postcode'])->where('company_id', $company->id)->find($orderId)
                ?? throw ValidationException::withMessages(['order' => 'That order was not found.']);
            $store = OnlineStore::query()->where('company_id', $company->id)->find($order->online_store_id);

            if ($store === null || $order->anonymised_at !== null || $order->status->isPaymentPending() || ! filter_var($order->customer_email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['order' => 'This order\'s link cannot be sent.']);
            }

            Mail::to($order->customer_email, $order->customer_name)
                ->queue(new OnlineOrderReceivedMail($this->messages->mailData($order, $store, $company, $this->messages->shopAddress($store, $company), forCustomer: true)));
            $this->audit->handle('online_order.link_resent', $order, null, null, ['number' => $order->reference], companyId: $company->id);

            return $order;
        });
    }
}
