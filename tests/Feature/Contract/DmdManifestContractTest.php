<?php

use App\Domain\Pharmacy\Support\DmdErrors;
use App\Domain\Tenancy\Enums\BusinessType;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pharmacy\DmdFixtures as Dmd;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Support\ContractReplyGuard;
use Tests\Support\ContractSchema;
use Tests\Support\ProposalSchema;

/*
 * W4: the dm+d manifest against PORTAL-CHANGES-pharmacy.md item 12 (till 0.1.85 pack), turned into files in
 * docs/contracts/proposals/pharmacy-dmd (the pack has no schema). The sample is the one in item 12, copied as written.
 */

const DMD_PROPOSAL = 'proposals/pharmacy-dmd';

function dmdProposalJson(string $file): mixed
{
    return json_decode((string) file_get_contents(base_path('docs/contracts/'.DMD_PROPOSAL.'/'.$file)), true, 512, JSON_THROW_ON_ERROR);
}

test('the contract\'s sample manifest matches the schema, and the schema holds the contract\'s rules', function () {
    $sample = dmdProposalJson('samples/dmd-manifest-reply.json');
    $schema = 'schemas/dmd-manifest-reply.schema.json';

    expect(ProposalSchema::errorsIn(DMD_PROPOSAL, $sample, $schema))->toBe([])
        // The sample in item 12 is copied as written.
        ->and(file_get_contents(base_path('docs/contracts/portal-api-0.1.85/docs/web-portal-api/PORTAL-CHANGES-pharmacy.md')))->toContain('"archiveFileName": "'.$sample['archiveFileName'].'"')
        // sha256 required, 64 hex in either case; https or relative downloadUrl; archiveFileName required.
        ->and(ProposalSchema::errorsIn(DMD_PROPOSAL, array_diff_key($sample, ['sha256' => 1]), $schema))->not->toBe([])
        ->and(ProposalSchema::errorsIn(DMD_PROPOSAL, [...$sample, 'sha256' => strtolower($sample['sha256'])], $schema))->toBe([])
        ->and(ProposalSchema::errorsIn(DMD_PROPOSAL, [...$sample, 'sha256' => 'abc'], $schema))->not->toBe([])
        ->and(ProposalSchema::errorsIn(DMD_PROPOSAL, [...$sample, 'downloadUrl' => 'http://portal.sspos.co.uk/x.zip'], $schema))->not->toBe([])
        ->and(ProposalSchema::errorsIn(DMD_PROPOSAL, [...$sample, 'downloadUrl' => '/api/v1/pharmacy/dmd/packages/x.zip'], $schema))->toBe([])
        ->and(ProposalSchema::errorsIn(DMD_PROPOSAL, array_diff_key($sample, ['archiveFileName' => 1]), $schema))->not->toBe([]);
});

test('the error codes: dmd.no_release as the contract names it, our 501 / 403 codes listed, each at its status', function () {
    $added = array_column(dmdProposalJson('error-codes.additions.json'), 'status', 'code');

    expect($added)->toBe(['dmd.not_available' => 501, 'dmd.not_pharmacy' => 403, 'dmd.no_release' => 404, 'dmd.link_invalid' => 403])
        ->and(ContractReplyGuard::knownCodes())->toMatchArray($added);

    foreach ([DmdErrors::notAvailable(), DmdErrors::notPharmacy(), DmdErrors::noRelease(), DmdErrors::linkInvalid()] as $error) {
        expect($added[$error->errorCode] ?? null)->toBe($error->httpStatus, $error->errorCode);
    }

    expect(ContractReplyGuard::check('GET api/v1/pharmacy/dmd/latest', 404, (string) json_encode(['code' => 'dmd.no_release', 'message' => 'x', 'traceId' => 't', 'retryAfterSeconds' => null, 'rejectedKey' => null])))->toBe([])
        ->and(ContractReplyGuard::check('GET api/v1/pharmacy/dmd/latest', 200, '{"releaseId": "x"}'))->not->toBe([]);
});

test('live replies: the 200 manifest validates against the schema and every error against the §9 envelope', function () {
    Storage::fake('local');
    $f = new SyncApiFixtures($this);
    $get = fn () => $f->send('GET', '/api/v1/pharmacy/dmd/latest');

    $replies = [$get()];   // 501, off
    Dmd::on();
    $replies[] = $get();   // 403, a grocery
    $f->company->forceFill(['business_type' => BusinessType::Pharmacy])->save();
    $replies[] = $get();   // 404
    Dmd::publish('2026-10-26');
    $replies[] = $ok = $get();

    expect(array_map(fn ($r) => $r->status(), $replies))->toBe([501, 403, 404, 200])
        ->and(ProposalSchema::errorsIn(DMD_PROPOSAL, $ok->json(), 'schemas/dmd-manifest-reply.schema.json'))->toBe([]);

    foreach (array_slice($replies, 0, 3) as $error) {
        expect(ContractSchema::errors($error->json(), 'licensing/schemas/error-reply.schema.json'))->toBe([]);
    }
});
