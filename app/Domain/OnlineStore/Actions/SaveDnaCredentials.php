<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Payments\DnaCredentials;
use App\Domain\OnlineStore\Support\PaymentOptions;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * The store's own DNA Payments account (OS-9, the hospitality OnlineOrderingController's updateDna / deleteDna): client
 * id, client secret and terminal id from the DNA merchant portal, and test mode (DNA's test environment, no real
 * money). Stored with Laravel's encrypted casts; a blank secret keeps the saved one. Never logged, audited only as
 * "changed" with the last 4 characters. Removing the details takes card online out of the ways to pay (a collection
 * falls back to pay at the shop, a delivery to pay on delivery).
 */
final class SaveDnaCredentials
{
    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array<string, mixed>  $input  client_id, client_secret (blank = keep), terminal_id, test_mode
     *
     * @throws ValidationException
     */
    public function handle(Company $company, #[SensitiveParameter] array $input): OnlineStore
    {
        return $this->tenancy->runAs($company, function () use ($company, $input): OnlineStore {
            $store = StoreScope::storeOf($company);
            $hasSecret = trim((string) $store->dna_client_secret) !== '';
            $data = Validator::make($input, [
                'client_id' => ['required', 'string', 'max:191'],
                'client_secret' => [$hasSecret ? 'nullable' : 'required', 'string', 'max:500'],
                'terminal_id' => ['required', 'string', 'max:191'],
                'test_mode' => ['required', 'boolean'],
            ], [
                'client_id.required' => 'Enter the client ID from your DNA Payments portal.',
                'client_secret.required' => 'Enter the client secret from your DNA Payments portal.',
                'terminal_id.required' => 'Enter the terminal ID from your DNA Payments portal.',
            ])->validate();

            $secret = trim((string) ($data['client_secret'] ?? ''));
            $store->forceFill([
                'dna_client_id' => trim((string) $data['client_id']),
                'dna_terminal_id' => trim((string) $data['terminal_id']),
                'dna_test_mode' => (bool) $data['test_mode'],
                ...($secret !== '' ? ['dna_client_secret' => $secret] : []),
            ])->save();

            $this->audit->handle('online_store.dna_saved', $store, null, [
                'client_id' => DnaCredentials::last4($store->dna_client_id), 'terminal_id' => DnaCredentials::last4($store->dna_terminal_id),
                'secret_changed' => $secret !== '', 'test_mode' => (bool) $store->dna_test_mode,
            ], companyId: $company->id);

            return $store->refresh();
        });
    }

    public function remove(Company $company): OnlineStore
    {
        return $this->tenancy->runAs($company, function () use ($company): OnlineStore {
            $store = StoreScope::storeOf($company);
            $without = fn (FulfilmentMethod $method): array => array_values(array_map(fn (OnlinePaymentMethod $p) => $p->value,
                array_filter(PaymentOptions::chosen($store, $method), fn (OnlinePaymentMethod $p) => $p !== OnlinePaymentMethod::CardOnline)))
                ?: [OnlinePaymentMethod::defaultFor($method)->value];

            $store->forceFill([
                'dna_client_id' => null, 'dna_client_secret' => null, 'dna_terminal_id' => null, 'dna_test_mode' => true,
                'collection_payments' => $without(FulfilmentMethod::Collection), 'delivery_payments' => $without(FulfilmentMethod::Delivery),
            ])->save();

            $this->audit->handle('online_store.dna_removed', $store, null, null, companyId: $company->id);

            return $store->refresh();
        });
    }
}
