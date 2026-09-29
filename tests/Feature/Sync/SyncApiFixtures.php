<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\IssueSyncKey;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Enums\IdMapAction;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Models\IdMapping;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Testing\TestResponse;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\JsonSchemaSubset;
use Tests\TestCase;

/**
 * Module 2.2: a portal tenant with OUR ids (Leeds with two tills, Bradford with one) whose tills keep the contract
 * samples' ids (C001, B001/B002, R001–R003) through id_map, a sync key per branch, and HTTP helpers for
 * `sync/hello` and `sync/push`.
 */
final class SyncApiFixtures
{
    public Company $company;

    public Branch $leeds;

    public Branch $bradford;

    /** @var array<string, Register> till id → our register */
    public array $tills = [];

    public string $leedsKey;

    public string $bradfordKey;

    public function __construct(private readonly TestCase $test, bool $mapTillIds = true)
    {
        $this->company = Company::factory()->create(['name' => 'Kirkgate Stores', 'multi_branch' => true, 'max_branches' => 3]);
        $this->leeds = Branch::factory()->forCompany($this->company)->create(['code' => 'LDS', 'name' => 'Leeds Kirkgate']);
        $this->bradford = Branch::factory()->forCompany($this->company)->create(['code' => 'BRD', 'name' => 'Bradford']);
        $this->tills = [
            TillFixtures::TILL_1 => Register::factory()->forBranch($this->leeds)->create(['code' => '01', 'is_main_till' => true]),
            TillFixtures::TILL_2 => Register::factory()->forBranch($this->leeds)->create(['code' => '02']),
            TillFixtures::BRADFORD_TILL => Register::factory()->forBranch($this->bradford)->create(['code' => '01', 'is_main_till' => true]),
        ];

        $map = [
            [IdKind::Company, TillFixtures::COMPANY, $this->company->id, $this->leeds->id, IdMapAction::Adopted],
            [IdKind::Branch, TillFixtures::LEEDS, $this->leeds->id, $this->leeds->id, IdMapAction::Adopted],
            [IdKind::Branch, TillFixtures::BRADFORD, $this->bradford->id, $this->bradford->id, IdMapAction::Adopted],
        ];

        foreach ($this->tills as $tillId => $register) {
            $map[] = [IdKind::Register, $tillId, $register->id, $register->branch_id, IdMapAction::Adopted];
        }

        foreach ($mapTillIds ? $map : [] as [$kind, $tillId, $portalId, $branchId, $action]) {
            IdMapping::withoutCompanyScope()->create([
                'kind' => $kind, 'till_id' => $tillId, 'portal_id' => $portalId, 'company_id' => $this->company->id,
                'branch_id' => $branchId, 'action' => $action,
            ]);
        }

        $this->leedsKey = app(IssueSyncKey::class)->handle($this->leeds, SyncKeySource::Admin);
        $this->bradfordKey = app(IssueSyncKey::class)->handle($this->bradford, SyncKeySource::Admin);
    }

    /**
     * The §3 headers of a Leeds till 1 request (or Bradford's with `$bradford`).
     *
     * @return array<string, string>
     */
    public function headers(bool $bradford = false): array
    {
        return [
            'Authorization' => 'Bearer '.($bradford ? $this->bradfordKey : $this->leedsKey),
            'X-SSPOS-Company-Id' => TillFixtures::COMPANY,
            'X-SSPOS-Branch-Id' => $bradford ? TillFixtures::BRADFORD : TillFixtures::LEEDS,
            'X-SSPOS-Register-Id' => $bradford ? TillFixtures::BRADFORD_TILL : TillFixtures::TILL_1,
            'X-SSPOS-Contract' => '1',
            'X-SSPOS-Store-Protocol' => '1',
            'X-SSPOS-App-Version' => '3.0.412',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param  array<string, string|null>  $headers  merged over headers(); null removes one
     */
    public function hello(array $headers = [], bool $bradford = false): TestResponse
    {
        return $this->test->call('GET', '/api/v1/sync/hello', [], [], [], $this->server([...$this->headers($bradford), ...$headers]));
    }

    /**
     * POST sync/push. `$body` is encoded as JSON (unless already a string) and gzipped unless `$gzip` is false.
     *
     * @param  array<mixed>|string  $body
     * @param  array<string, string|null>  $headers
     */
    public function push(array|string $body, array $headers = [], bool $gzip = true, bool $bradford = false): TestResponse
    {
        $json = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $content = $gzip ? (string) gzencode($json) : $json;
        $headers = [...$this->headers($bradford), 'Content-Type' => 'application/json; charset=utf-8', ...($gzip ? ['Content-Encoding' => 'gzip'] : []), ...$headers];

        return $this->test->call('POST', '/api/v1/sync/push', [], [], [], $this->server($headers), $content);
    }

    /**
     * @return list<string> schema errors of a reply (empty when valid)
     */
    public static function schemaErrors(TestResponse $response, string $schema): array
    {
        return (new JsonSchemaSubset(base_path(TillFixtures::CONTRACT.'/schemas')))
            ->validate(json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR), $schema);
    }

    /**
     * @param  array<string, string|null>  $headers
     * @return array<string, string>
     */
    private function server(array $headers): array
    {
        $server = [];

        foreach (array_filter($headers, fn ($value) => $value !== null) as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }
}
