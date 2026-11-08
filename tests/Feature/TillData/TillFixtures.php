<?php

namespace Tests\Feature\TillData;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\Actions\ApplySyncChanges;
use App\Domain\TillData\Sync\Data\ApplyResult;
use Illuminate\Support\Arr;

/**
 * The contract samples' tenant (company C001; Leeds B001 with tills R001, R002; Bradford B002 with till R003) and
 * helpers to build and apply envelopes.
 */
final class TillFixtures
{
    /** The contract pack the entity store is generated from (definitions.php `contract`): till 0.1.85 since W1. */
    public const CONTRACT = 'docs/contracts/portal-api-0.1.85/docs/web-portal-api';

    /**
     * The salon pack (W2, 2026-11-08; the till's unreleased `feat/salon-barber` branch): overlays CONTRACT
     * (definitions.php `overlay`). sample() and schema() read its copy of a file first.
     */
    public const SALON = 'docs/contracts/salon-barber-2026-11-08/docs/web-portal-api';

    /** The v1.4.1 pack (till ≤ 0.1.84 shapes): replayed to prove an older till's push is answered exactly as before. */
    public const LEGACY_CONTRACT = 'docs/contracts/portal-api-v1.4.1/docs/web-portal-api';

    public const COMPANY = '01K5T0Q8C4000000000000C001';

    public const LEEDS = '01K5T0Q8C4000000000000B001';

    public const BRADFORD = '01K5T0Q8C4000000000000B002';

    public const TILL_1 = '01K5T0Q8C4000000000000R001';

    public const TILL_2 = '01K5T0Q8C4000000000000R002';

    public const BRADFORD_TILL = '01K5T0Q8C4000000000000R003';

    /**
     * @return array{0: Company, 1: Branch, 2: Branch}
     */
    public static function tenant(): array
    {
        $company = Company::factory()->create(['id' => self::COMPANY, 'name' => 'Kirkgate Convenience']);
        $leeds = Branch::factory()->forCompany($company)->create(['id' => self::LEEDS, 'code' => 'LDS', 'name' => 'Leeds']);
        $bradford = Branch::factory()->forCompany($company)->create(['id' => self::BRADFORD, 'code' => 'BRD', 'name' => 'Bradford']);
        Register::factory()->forBranch($leeds)->create(['id' => self::TILL_1, 'code' => '01', 'name' => 'Till 1', 'is_main_till' => true]);
        Register::factory()->forBranch($leeds)->create(['id' => self::TILL_2, 'code' => '02', 'name' => 'Till 2']);
        Register::factory()->forBranch($bradford)->create(['id' => self::BRADFORD_TILL, 'code' => '01', 'name' => 'Till 1', 'is_main_till' => true]);

        return [$company, $leeds, $bradford];
    }

    /**
     * @return array<mixed>
     */
    public static function sample(string $file): array
    {
        $overlay = base_path(self::SALON.'/samples/'.$file);
        $path = is_file($overlay) ? $overlay : base_path(self::CONTRACT.'/samples/'.$file);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Every entity schema file (the salon pack's copy wins), entity => path.
     *
     * @return array<string, string>
     */
    public static function schemaFiles(): array
    {
        $files = [];

        foreach ([self::CONTRACT, self::SALON] as $folder) {
            foreach (glob(base_path($folder.'/schemas/entities/*.schema.json')) ?: [] as $file) {
                $files[basename($file, '.schema.json')] = $file;
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * An envelope for a payload, the way the till's ChangeFeed writes it.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function envelope(string $entity, array $payload, int $seq, array $overrides = []): array
    {
        $version = $overrides['version'] ?? (int) ($payload['rowVersion'] ?? 1);

        return [
            'seq' => $seq,
            'entity' => $entity,
            'entityId' => $payload['id'],
            'op' => 'I',
            'version' => $version,
            'companyId' => $payload['companyId'],
            'branchId' => $payload['branchId'] ?? '',
            'registerId' => $payload['registerId'] ?? '',
            'at' => $payload['updatedAt'] ?? '2026-09-23T09:41:12Z',
            'payload' => $payload,
            'key' => "{$entity}:{$payload['id']}:{$version}",
            ...$overrides,
        ];
    }

    /**
     * The push reply without `receivedAt` (a clock time; tests check it on its own).
     *
     * @return array<string, mixed>
     */
    public static function ack(ApplyResult|array $result): array
    {
        return Arr::except($result instanceof ApplyResult ? $result->toPushReply() : $result, 'receivedAt');
    }

    /**
     * @param  iterable<mixed>  $changes
     */
    public static function apply(Company $company, Branch $sender, iterable $changes): ApplyResult
    {
        return app(ApplySyncChanges::class)->handle($company, $sender, $changes);
    }
}
