<?php

namespace Tests\Support;

/**
 * Module 2.6: where every contract sample file (docs/web-portal-api/samples/** and licensing/samples/*) is replayed.
 * `tests/Feature/Contract/ContractSamplesTest.php` fails when a sample file is not listed here, when a listed test
 * does not exist, or when a "pending" endpoint gets a route (then its samples must be replayed).
 *
 * - `tests`: [test file under tests/Feature, the start of the test's name].
 * - `pending`: the endpoint is a later module; `route` must not exist yet. Schema-validated meanwhile.
 * - `notApplicable`: a sample of a model the contract has replaced; schema-validated only.
 */
final class ContractSampleCoverage
{
    private const PUSH = ['Sync/SyncPushApiTest.php', 'the three push samples replay into the right company'];

    private const STORE = ['TillData/SampleReplayTest.php', 'stores every sample entity with every field exactly as sent'];

    private const DEVICES_ACTIVATE = 'devices/activate is deprecated and never called by the till (contract §17.4, PHASES 2.1): not built';

    private const REDEEM = 'Licensing/Api/RedeemLicenceTest.php';

    private const MIGRATE = 'Sync/CloudMigrationTest.php';

    /**
     * @return array<string, array{tests?: list<array{0: string, 1: string}>, pending?: string, route?: string, notApplicable?: string}>
     */
    public static function map(): array
    {
        $licence = 'Contract/LicenceSampleReplayTest.php';
        $sync = 'Contract/SyncSampleReplayTest.php';

        return [
            // Sync (§4–§13)
            'samples/enums.json' => ['tests' => [['TillData/EntityStoreGeneratorTest.php', 'generates PHP backed enums with exactly the values of samples/enums.json']]],
            'samples/ownership.json' => ['tests' => [['TillData/EntityStoreGeneratorTest.php', 'uses exactly the ownership in samples/ownership.json'], ['Sync/ResolveSyncConflictTest.php', 'ownership rules match samples/ownership.json']]],
            'samples/settings-local-only.json' => ['tests' => [['TillData/KeyedRowsTest.php', 'keeps SettingSyncPolicy equal to samples/settings-local-only.json']]],
            'samples/error-reply.401.json' => ['tests' => [[$sync, 'error-reply.401.json']]],
            'samples/error-reply.422.json' => ['tests' => [[$sync, 'error-reply.422.json']]],
            'samples/hello-reply.json' => ['tests' => [['Sync/SyncHelloApiTest.php', 'hello answers the key']]],
            'samples/pull-reply.empty.json' => ['tests' => [['Sync/SyncPullApiTest.php', 'portal creates, updates and deletes a category, product and barcode']]],
            'samples/pull-reply.json' => ['tests' => [['TillData/SampleReplayTest.php', 'applies the rows of pull-reply.json'], ['Sync/SyncPullApiTest.php', 'never echoed: a row Leeds pushed goes to Bradford']]],
            'samples/pull-reply.head-office.json' => ['tests' => [['Sync/HeadOfficeOrderTest.php', 'replays pull-reply.head-office.json']]],
            'samples/pull-reply.relay.json' => ['tests' => [['Sync/RelayPullTest.php', 'replays pull-reply.relay.json']]],
            'samples/push-reply.json' => ['tests' => [['TillData/SampleReplayTest.php', 'replays push-request.json and replies exactly like push-reply.json'], self::PUSH]],
            'samples/push-request.json' => ['tests' => [self::PUSH, ['TillData/SampleReplayTest.php', 'replays push-request.json']]],
            'samples/push-request.second-branch.json' => ['tests' => [self::PUSH, ['TillData/SampleReplayTest.php', 'keys the second branch by its own branch and seq']]],
            'samples/push-request.second-till.json' => ['tests' => [self::PUSH, ['TillData/SampleReplayTest.php', 'gives a second till']]],
            'samples/push-request.settings.json' => ['tests' => [['Sync/SyncPushApiTest.php', 'push-request.settings.json replays over HTTP'], ['TillData/KeyedRowsTest.php', 'replays push-request.settings.json']]],
            'samples/web-order.json' => ['pending' => 'Phase 8 Web orders / click and collect (§12 is a proposal; the portal creates no WebOrder yet)'],
            'samples/entities/AccountPayDate.json' => ['tests' => [self::STORE, ['Sync/RelayPullTest.php', 'replays pull-reply.relay.json']]],
            'samples/entities/BranchPrice.json' => ['tests' => [self::STORE, ['Sync/BranchPriceSyncTest.php', 'a shop']]],
            'samples/entities/Category.json' => ['tests' => [self::STORE]],
            'samples/entities/Customer.json' => ['tests' => [self::STORE]],
            'samples/entities/CustomerOrder.json' => ['tests' => [self::STORE, ['Contract/SyncTestListTest.php', '19.4 #14']]],
            'samples/entities/CustomerTransaction.json' => ['tests' => [self::STORE]],
            'samples/entities/Department.json' => ['tests' => [self::STORE]],
            'samples/entities/Product.json' => ['tests' => [self::STORE, ['TillData/SyncRulesTest.php', '19.4 #3']]],
            'samples/entities/ProductBarcode.json' => ['tests' => [self::STORE]],
            'samples/entities/PurchaseOrder.json' => ['tests' => [self::STORE]],
            'samples/entities/PurchaseOrderLine.json' => ['tests' => [self::STORE]],
            'samples/entities/RolePermission.json' => ['tests' => [['TillData/KeyedRowsTest.php', 'derives the till']]],
            'samples/entities/Sale.json' => ['tests' => [self::STORE]],
            'samples/entities/SaleLine.json' => ['tests' => [self::STORE]],
            'samples/entities/SalePayment.json' => ['tests' => [self::STORE]],
            'samples/entities/SaleVat.json' => ['tests' => [self::STORE]],
            'samples/entities/Setting.json' => ['tests' => [['TillData/KeyedRowsTest.php', 'derives the till']]],
            'samples/entities/StockMovement.json' => ['tests' => [self::STORE]],
            'samples/entities/StockTransfer.json' => ['tests' => [self::STORE]],
            'samples/entities/Supplier.json' => ['tests' => [self::STORE]],
            'samples/entities/VatRate.json' => ['tests' => [self::STORE]],

            // Licensing (§17)
            'licensing/samples/activate-request.json' => ['notApplicable' => self::DEVICES_ACTIVATE],
            'licensing/samples/activate-request.replacement-pc.json' => ['notApplicable' => self::DEVICES_ACTIVATE],
            'licensing/samples/activate-reply.json' => ['notApplicable' => self::DEVICES_ACTIVATE],
            'licensing/samples/error.activation-code-not-found.404.json' => ['tests' => [[self::MIGRATE, 'code errors']]],
            'licensing/samples/error.use-migrate.409.json' => ['notApplicable' => self::DEVICES_ACTIVATE],
            'licensing/samples/validate-request.json' => ['tests' => [[$licence, 'validate-request.json']]],
            'licensing/samples/validate-request.per-till.json' => ['tests' => [['Licensing/Api/LicenceApiContractTest.php', 'the request samples replay against the API'], [$licence, 'the validate-reply samples']]],
            'licensing/samples/validate-reply.per-till.json' => ['tests' => [[$licence, 'the validate-reply samples']]],
            'licensing/samples/validate-reply.renewed.json' => ['tests' => [[$licence, 'the validate-reply samples']]],
            'licensing/samples/validate-reply.released.json' => ['tests' => [[$licence, 'validate-reply.released.json']]],
            'licensing/samples/validate-reply.revoked.json' => ['tests' => [[$licence, 'the validate-reply samples']]],
            'licensing/samples/validate-reply.trial-expiring.json' => ['tests' => [[$licence, 'the validate-reply samples']]],
            'licensing/samples/validate-reply.seat-limit.json' => ['notApplicable' => 'status seatLimit and registers[].seat are the branch model (§17.9); per-till licences (§17.15) refuse a till over the limit at licence/activate: error.seat-limit.403.json'],
            'licensing/samples/licence-activate-request.json' => ['tests' => [['Licensing/Api/LicenceApiContractTest.php', 'the request samples replay against the API'], [$licence, 'licence-activate-reply.json']]],
            'licensing/samples/licence-activate-reply.json' => ['tests' => [[$licence, 'licence-activate-reply.json'], ['Licensing/Api/LicenceApiContractTest.php', 'the sample activate reply token verifies']]],
            'licensing/samples/deactivate-request.json' => ['tests' => [['Licensing/Api/LicenceApiContractTest.php', 'the request samples replay against the API'], [$licence, 'deactivate-request.json']]],
            'licensing/samples/deactivate-reply.json' => ['tests' => [[$licence, 'deactivate-request.json']]],
            'licensing/samples/deactivate-request.main-till.json' => ['tests' => [[$licence, 'deactivate-request.main-till.json']]],
            'licensing/samples/deactivate-reply.main-till.json' => ['tests' => [[$licence, 'deactivate-request.main-till.json']]],
            'licensing/samples/deactivate-reply.main-till.same-key.json' => ['tests' => [[$licence, 'deactivate-reply.main-till.same-key.json']]],
            'licensing/samples/error.migrate-activate-first.409.json' => ['tests' => [[self::MIGRATE, 'ANSWERS-2026-09-30-portal points 4 and 5']]],
            'licensing/samples/error-codes.json' => ['tests' => [['Contract/ErrorCodesTest.php', 'every error code the portal emits']]],
            'licensing/samples/error.activation-too-many-attempts.429.json' => ['tests' => [[$licence, 'the error samples we emit']]],
            'licensing/samples/error.key-already-used.409.json' => ['tests' => [[$licence, 'the error samples we emit']]],
            'licensing/samples/error.key-not-found.404.json' => ['tests' => [[$licence, 'the error samples we emit']]],
            'licensing/samples/error.seat-limit.403.json' => ['tests' => [[$licence, 'the error samples we emit']]],
            'licensing/samples/error.update-required.426.json' => ['tests' => [[$licence, 'the error samples we emit']]],
            'licensing/samples/error.branch-already-linked.409.json' => ['tests' => [[self::MIGRATE, 'code errors']]],
            'licensing/samples/error.key-already-redeemed.409.json' => ['tests' => [[self::REDEEM, 'redeem-request.portal-key.json']]],
            'licensing/samples/error.key-used-on-another-install.409.json' => ['tests' => [[self::REDEEM, 'redeem-request.local-report.json']]],
            'licensing/samples/error.licence-bad-signature.422.json' => ['tests' => [[self::REDEEM, 'a token that is not genuine'], [self::MIGRATE, 'a till holding another business']]],
            'licensing/samples/licence-token.payload.full.json' => ['tests' => [['Licensing/Signing/SsposTokenTest.php', 'produces payloads that validate against licence-token-payload.schema.json']]],
            'licensing/samples/licence-token.payload.per-till.json' => ['tests' => [['Licensing/Signing/SsposTokenTest.php', 'reproduces the per-till and migrated sample tokens byte for byte']]],
            'licensing/samples/licence-token.payload.trial.json' => ['tests' => [['Licensing/Signing/SsposTokenTest.php', 'reproduces the contract worked example byte for byte']]],
            'licensing/samples/licence-token.payload.local.json' => ['tests' => [['Licensing/Signing/SsposTokenTest.php', 'produces payloads that validate against licence-token-payload.schema.json']]],
            'licensing/samples/licence-token.payload.local-open.json' => ['tests' => [['Licensing/Signing/SsposTokenTest.php', 'produces payloads that validate against licence-token-payload.schema.json']]],
            'licensing/samples/licence-token.worked-example.json' => ['tests' => [['Licensing/Signing/SsposTokenTest.php', 'reproduces the contract worked example byte for byte']]],
            'licensing/samples/signer-certificate.worked-example.json' => ['tests' => [['Licensing/Signing/SignerCertificateTest.php', 'verifies the contract signer-certificate worked example']]],
            'licensing/samples/public-key-handover.json' => ['tests' => [['Licensing/Signing/SignerCertificateTest.php', 'prints the public-key hand-over exactly shaped like the contract sample']]],
            'licensing/samples/push-request.initial.json' => ['tests' => [[$sync, 'push-request.initial.json'], [self::MIGRATE, 'initial upload']]],
            'licensing/samples/migrate-request.json' => ['tests' => [[self::MIGRATE, 'migrate-request.json with the shop']]],
            'licensing/samples/migrate-reply.adopted.json' => ['tests' => [[self::MIGRATE, 'migrate-request.json with the shop']]],
            'licensing/samples/migrate-reply.aliased.json' => ['tests' => [[self::MIGRATE, 'a second branch of a business']]],
            'licensing/samples/migrate-complete-request.json' => ['tests' => [[self::MIGRATE, 'migrate-complete-request.json replays']]],
            'licensing/samples/migrate-complete-reply.json' => ['tests' => [[self::MIGRATE, 'initial upload']]],
            'licensing/samples/migrate-complete-reply.incomplete.json' => ['tests' => [[self::MIGRATE, 'initial upload']]],
            'licensing/samples/redeem-request.portal-key.json' => ['tests' => [[self::REDEEM, 'redeem-request.portal-key.json']]],
            'licensing/samples/redeem-request.local-token.json' => ['tests' => [[self::REDEEM, 'redeem-request.local-token.json']]],
            'licensing/samples/redeem-request.local-report.json' => ['tests' => [[self::REDEEM, 'redeem-request.local-report.json']]],
            'licensing/samples/redeem-reply.applied.json' => ['tests' => [[self::REDEEM, 'redeem-request.portal-key.json']]],
            'licensing/samples/redeem-reply.recorded.json' => ['tests' => [[self::REDEEM, 'redeem-request.local-token.json']]],
            'licensing/samples/redeem-reply.local-report.json' => ['tests' => [[self::REDEEM, 'redeem-request.local-report.json']]],
        ];
    }

    /**
     * The schema a sample validates against (path under docs/web-portal-api), `push`/`pull` for the sync feeds (the
     * envelope and every payload), or null for a sample that is not a message (lists, worked examples).
     */
    public static function schemaFor(string $sample): ?string
    {
        $name = basename($sample, '.json');

        if (str_starts_with($sample, 'samples/entities/')) {
            return "schemas/entities/{$name}.schema.json";
        }

        if (str_starts_with($sample, 'samples/')) {
            return match (true) {
                str_starts_with($name, 'error-reply') => 'schemas/error-reply.schema.json',
                str_starts_with($name, 'push-request') => 'push',
                str_starts_with($name, 'pull-reply') => 'pull',
                in_array($name, ['hello-reply', 'push-reply', 'web-order'], true) => "schemas/{$name}.schema.json",
                default => null,
            };
        }

        $base = explode('.', $name)[0];

        return match (true) {
            $name === 'push-request.initial' => 'push',
            str_starts_with($name, 'error.') => 'licensing/schemas/error-reply.schema.json',
            str_starts_with($name, 'licence-token.payload') => 'licensing/schemas/licence-token-payload.schema.json',
            is_file(ContractSchema::dir("licensing/schemas/{$base}.schema.json")) => "licensing/schemas/{$base}.schema.json",
            default => null,
        };
    }
}
