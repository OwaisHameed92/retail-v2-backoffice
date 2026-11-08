<?php

namespace App\Http\Controllers\Api\Workshop;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Customer;
use App\Domain\Workshop\Actions\SaveWorkshopCustomer;
use App\Domain\Workshop\Queries\ConnectorCustomers;
use App\Domain\Workshop\Support\ConnectorException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BikeBook connector customers (WORKSHOP-BIKEBOOK.md §3.5): list (delta), get, create (de-duplicated by phone or
 * e-mail), update, address. Never balance, points, credit limit, card, date of birth or notes.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request, ConnectorCustomers $customers): JsonResponse
    {
        $paging = ConnectorPaging::from($request);
        [$items, $total] = $customers->page($paging['since'], $paging['page'], $paging['size'], $paging['light']);

        return ConnectorPaging::reply($request, $items, $total, $paging);
    }

    public function show(string $id): JsonResponse
    {
        $customer = Customer::query()->whereKey($id)->first();

        return $customer === null ? throw ConnectorException::notFound('No customer with this id in this shop\'s business.') : response()->json(ConnectorCustomers::model($customer));
    }

    public function store(Request $request, SaveWorkshopCustomer $save): JsonResponse
    {
        $details = self::details($request->json()->all(), true);
        [$customer, $created] = $save->create($this->tenancy->require(), $details);

        return response()->json(ConnectorCustomers::model($customer), $created ? 201 : 200);
    }

    public function update(Request $request, SaveWorkshopCustomer $save): JsonResponse
    {
        $body = $request->json()->all();
        $id = trim((string) ($body['id'] ?? ''));

        if ($id === '') {
            throw ConnectorException::invalid('id is required.');
        }

        return response()->json(ConnectorCustomers::model($save->update($this->tenancy->require(), $id, self::details($body, false))));
    }

    public function address(Request $request, string $id, SaveWorkshopCustomer $save): JsonResponse
    {
        $body = $request->json()->all();
        $parts = array_map(fn ($key) => trim((string) ($body[$key] ?? '')), ['line1', 'line2', 'line3', 'town', 'county', 'postcode']);
        $address = implode(', ', array_filter($parts, fn ($p) => $p !== ''));

        if (mb_strlen($address) > 500) {
            throw ConnectorException::invalid('The address is longer than 500 characters.');
        }

        return response()->json(ConnectorCustomers::model($save->update($this->tenancy->require(), $id, ['address' => $address])));
    }

    /**
     * @param  array<mixed>  $body
     * @return array<string, string>
     */
    private static function details(array $body, bool $creating): array
    {
        $details = [];
        $first = trim((string) ($body['firstName'] ?? ''));
        $last = trim((string) ($body['lastName'] ?? ''));

        if (array_key_exists('firstName', $body) || array_key_exists('lastName', $body) || $creating) {
            $details['name'] = trim("{$first} {$last}");
        }

        foreach (['phone' => 'phone', 'emailAddress' => 'email'] as $from => $to) {
            if (array_key_exists($from, $body) || $creating) {
                $details[$to] = trim((string) ($body[$from] ?? ''));
            }
        }

        if (isset($details['email']) && $details['email'] !== '' && filter_var($details['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw ConnectorException::invalid('emailAddress is not a valid e-mail address.');
        }

        if ($creating && $details['name'] === '' && $details['phone'] === '' && $details['email'] === '') {
            throw ConnectorException::invalid('Send at least a name, phone or emailAddress.');
        }

        if ($creating && $details['name'] === '') {
            $details['name'] = $details['email'] !== '' ? $details['email'] : $details['phone'];
        }

        foreach (['name' => 200, 'phone' => 60, 'email' => 255] as $key => $max) {
            if (isset($details[$key]) && mb_strlen($details[$key]) > $max) {
                throw ConnectorException::invalid("{$key} is longer than {$max} characters.");
            }
        }

        $address = $body['addresses'][0] ?? null;

        if ($creating && is_array($address)) {
            $details['address'] = implode(', ', array_filter(array_map(fn ($k) => trim((string) ($address[$k] ?? '')), ['line1', 'line2', 'town', 'postcode'])));
        }

        return $details;
    }
}
