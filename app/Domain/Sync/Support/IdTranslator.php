<?php

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Enums\IdMapAction;
use App\Domain\Sync\Models\IdMapping;

/**
 * Translates a till's company / branch / register ids to ours and back at the edge (module 2.1, contract §17.3
 * step 3, §17.8 "translate at your edge"), from one company's `id_map` rows only (so another business's ids never
 * translate). An id without a mapping is left as it is (a till that already uses our ids).
 *
 * - In (push, 2.2): `change()` rewrites an envelope's companyId/branchId/registerId, the entityId and payload id
 *   of Company/Branch/Register rows, and payload members `companyId`, `…branchId`, `…registerId`, before the
 *   tenancy checks — so rows land in the right company.
 * - Out (pull, 2.5): `toTill()` gives the ids the till knows. A company id goes back as the alias the pulling
 *   branch uses (else the adopted one); branch and register as their latest till id. 2.5 must run every outgoing
 *   envelope and payload through it (the till ignores rows naming ids it does not have).
 */
final class IdTranslator
{
    private const TENANCY_ENTITIES = ['Company' => IdKind::Company, 'Branch' => IdKind::Branch, 'Register' => IdKind::Register];

    /**
     * @param  array<string, array<string, string>>  $toPortal  kind => till id => our id
     * @param  list<IdMapping>  $rows
     */
    private function __construct(
        private readonly array $toPortal,
        private readonly array $rows,
        /** @var list<string> the calling till's own ids (sync headers): echoed back when they are one of the aliases */
        private readonly array $preferred = [],
    ) {}

    /**
     * The same map, answering with the calling till's own ids where several tills mapped the same shop (a second
     * till installed on its own, not joined to the main till, brings its own company/branch ids): the main till
     * must get its own ids back from hello and pull, or it treats the key as another shop's and stops syncing.
     *
     * @param  list<string>  $tillIds
     */
    public function preferring(array $tillIds): self
    {
        return new self($this->toPortal, $this->rows, array_values(array_filter($tillIds, fn (string $id) => $id !== '')));
    }

    public static function forCompany(string $companyId): self
    {
        $rows = IdMapping::withoutCompanyScope()->where('company_id', $companyId)->orderBy('id')->get()->all();
        $toPortal = [];

        foreach ($rows as $row) {
            $toPortal[$row->kind->value][$row->till_id] = $row->portal_id;
        }

        return new self($toPortal, array_values($rows));
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function toPortal(IdKind $kind, string $id): string
    {
        return $this->toPortal[$kind->value][$id] ?? $id;
    }

    /** Our id → the till's; `$branchId` = our branch the reply goes to (it picks the company alias). */
    public function toTill(IdKind $kind, string $portalId, ?string $branchId = null): string
    {
        $candidates = array_values(array_filter($this->rows, fn (IdMapping $row) => $row->kind === $kind && $row->portal_id === $portalId));

        if ($candidates === []) {
            return $portalId;
        }

        // Only where the answer is ambiguous (two installs mapped the same shop) does the caller's own id win;
        // a company alias still follows the branch the reply goes to.
        $scoped = $kind === IdKind::Company && $branchId !== null
            ? array_values(array_filter($candidates, fn (IdMapping $row) => $row->branch_id === $branchId))
            : $candidates;

        if (count($scoped) > 1) {
            foreach ($scoped as $row) {
                if (in_array($row->till_id, $this->preferred, true)) {
                    return $row->till_id;
                }
            }
        }

        if ($kind === IdKind::Company) {
            foreach ($candidates as $row) {
                if ($branchId !== null && $row->branch_id === $branchId) {
                    return $row->till_id;
                }
            }

            foreach ($candidates as $row) {
                if ($row->action === IdMapAction::Adopted) {
                    return $row->till_id;
                }
            }
        }

        return $candidates[count($candidates) - 1]->till_id;
    }

    /**
     * One raw sync envelope with the till's ids replaced by ours. Anything that is not an envelope is returned
     * unchanged (EnvelopeReader rejects it).
     */
    public function change(mixed $raw): mixed
    {
        if (! is_array($raw) || $this->rows === []) {
            return $raw;
        }

        foreach (['companyId' => IdKind::Company, 'branchId' => IdKind::Branch, 'registerId' => IdKind::Register] as $member => $kind) {
            if (is_string($raw[$member] ?? null) && $raw[$member] !== '') {
                $raw[$member] = $this->toPortal($kind, $raw[$member]);
            }
        }

        $entityKind = is_string($raw['entity'] ?? null) ? (self::TENANCY_ENTITIES[$raw['entity']] ?? null) : null;

        if ($entityKind !== null && is_string($raw['entityId'] ?? null)) {
            $portalId = $this->toPortal($entityKind, $raw['entityId']);

            // The till finds a refused row by its own key, so keep the key it would have had.
            if ($portalId !== $raw['entityId'] && ! array_key_exists('key', $raw)) {
                $raw['key'] = $raw['entity'].':'.$raw['entityId'].':'.(is_scalar($raw['version'] ?? null) ? $raw['version'] : '');
            }

            $raw['entityId'] = $portalId;
        }

        if (is_array($raw['payload'] ?? null)) {
            $raw['payload'] = $this->payload($raw['payload'], $entityKind);

            // A setting's scopeId is the till's company or branch id (contract §10.3).
            $scopeKind = ($raw['entity'] ?? null) === 'Setting' ? match ($raw['payload']['scope'] ?? null) {
                'company' => IdKind::Company,
                'branch' => IdKind::Branch,
                default => null,
            } : null;

            if ($scopeKind !== null && is_string($raw['payload']['scopeId'] ?? null) && $raw['payload']['scopeId'] !== '') {
                $raw['payload']['scopeId'] = $this->toPortal($scopeKind, $raw['payload']['scopeId']);
            }
        }

        return $raw;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function payload(array $payload, ?IdKind $entityKind): array
    {
        foreach ($payload as $member => $value) {
            if (! is_string($value) || $value === '' || ! is_string($member)) {
                continue;
            }

            $kind = match (true) {
                $member === 'id' => $entityKind,
                $member === 'companyId' => IdKind::Company,
                $member === 'branchId' || str_ends_with($member, 'BranchId') => IdKind::Branch,
                $member === 'registerId' || str_ends_with($member, 'RegisterId') => IdKind::Register,
                default => null,
            };

            if ($kind !== null) {
                $payload[$member] = $this->toPortal($kind, $value);
            }
        }

        return $payload;
    }
}
