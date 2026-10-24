<?php

use Tests\Support\ContractReplyGuard;
use Tests\Support\ContractSchema;
use Tests\Support\ProposalSchema;

/*
 * Contract proposal docs/contracts/proposals/product-images (IMG-1): every sample is valid JSON and matches its
 * schema, the error samples match the v1.4.1 error-reply schema with a code of the proposal (same status), the new
 * codes only add to v1.4.1's error-codes.json, and the reply guard checks the proposal's calls against its schemas.
 */

const IMAGES_PROPOSAL = 'proposals/product-images';

function imagesProposalJson(string $file): mixed
{
    return json_decode((string) file_get_contents(base_path('docs/contracts/'.IMAGES_PROPOSAL.'/'.$file)), true, 512, JSON_THROW_ON_ERROR);
}

test('every sample matches its proposal schema', function () {
    $pairs = [
        'samples/upload-request.headers.json' => 'schemas/product-image-upload-headers.schema.json',
        'samples/upload-reply.stored.json' => 'schemas/product-image-upload-reply.schema.json',
        'samples/upload-reply.replaced.json' => 'schemas/product-image-upload-reply.schema.json',
        'samples/upload-reply.unchanged.json' => 'schemas/product-image-upload-reply.schema.json',
        'samples/delete-reply.deleted.json' => 'schemas/product-image-delete-reply.schema.json',
        'samples/delete-reply.none.json' => 'schemas/product-image-delete-reply.schema.json',
        'samples/list-request.query.json' => 'schemas/product-image-list-query.schema.json',
        'samples/list-reply.json' => 'schemas/product-image-list-reply.schema.json',
        'samples/list-reply.last-page.json' => 'schemas/product-image-list-reply.schema.json',
    ];

    foreach ($pairs as $sample => $schema) {
        expect(ProposalSchema::errorsIn(IMAGES_PROPOSAL, imagesProposalJson($sample), $schema))->toBe([], $sample);
    }

    // Every sample file is covered (requests, replies and errors).
    $files = array_map(fn (string $path) => 'samples/'.basename($path), glob(base_path('docs/contracts/'.IMAGES_PROPOSAL.'/samples/*.json')) ?: []);
    $errors = array_values(array_filter($files, fn (string $file) => str_starts_with(basename($file), 'error-reply.')));
    expect(array_values(array_diff($files, array_keys($pairs), $errors)))->toBe([])->and($errors)->toHaveCount(5);
});

test('the error samples are v1.4.1 error replies with a code of the proposal or of v1.4.1, at its status', function () {
    $known = ContractReplyGuard::knownCodes();
    $added = array_column(imagesProposalJson('error-codes.additions.json'), 'status', 'code');

    foreach (glob(base_path('docs/contracts/'.IMAGES_PROPOSAL.'/samples/error-reply.*.json')) ?: [] as $path) {
        $error = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);

        expect(ContractSchema::errors($error, 'licensing/schemas/error-reply.schema.json'))->toBe([], basename($path))
            ->and($known)->toHaveKey($error->code);
    }

    expect($added)->toBe([
        'image.not_available' => 501, 'product.not_found' => 404, 'image.too_large' => 413, 'image.unsupported_type' => 415,
        'image.hash_mismatch' => 422, 'image.dimensions' => 422, 'image.invalid' => 422,
    ]);
});

test('the error-code additions only add to v1.4.1 and have the error-codes.json shape', function () {
    $contract = array_column(json_decode((string) file_get_contents(ContractSchema::dir('licensing/samples/error-codes.json')), true, 512, JSON_THROW_ON_ERROR), 'code');

    foreach (imagesProposalJson('error-codes.additions.json') as $entry) {
        expect($contract)->not->toContain($entry['code'])
            ->and($entry['code'])->toMatch('/^[a-z]+(_[a-z]+)*\.[a-z]+(_[a-z]+)*$/')
            ->and($entry)->toHaveKeys(['code', 'status', 'endpoints', 'meaning', 'tillDoes', 'details', 'retryable']);
    }
});

test('the schemas refuse what the till must not send or the portal must not answer', function () {
    $headers = imagesProposalJson('samples/upload-request.headers.json');
    $reply = imagesProposalJson('samples/upload-reply.stored.json');

    expect(ProposalSchema::errorsIn(IMAGES_PROPOSAL, [...$headers, 'Content-Type' => 'image/gif'], 'schemas/product-image-upload-headers.schema.json'))->not->toBe([])
        ->and(ProposalSchema::errorsIn(IMAGES_PROPOSAL, [...$headers, 'X-SSPOS-Image-Sha256' => strtoupper($headers['X-SSPOS-Image-Sha256'])], 'schemas/product-image-upload-headers.schema.json'))->not->toBe([])
        ->and(ProposalSchema::errorsIn(IMAGES_PROPOSAL, [...$headers, 'Content-Encoding' => 'gzip'], 'schemas/product-image-upload-headers.schema.json'))->not->toBe([])
        ->and(ProposalSchema::errorsIn(IMAGES_PROPOSAL, [...$reply, 'result' => 'created'], 'schemas/product-image-upload-reply.schema.json'))->not->toBe([])
        ->and(ProposalSchema::errorsIn(IMAGES_PROPOSAL, ['max' => '5001'], 'schemas/product-image-list-query.schema.json'))->not->toBe([])
        ->and(ContractReplyGuard::check('PUT api/v1/sync/product-images/01K5T0Q8C4000000000000P001', 200, '{"productId": "01K5T0Q8C4000000000000P001"}'))->not->toBe([])
        ->and(ContractReplyGuard::check('GET api/v1/sync/product-images', 200, (string) json_encode(imagesProposalJson('samples/list-reply.json'))))->toBe([])
        ->and(ContractReplyGuard::check('DELETE api/v1/sync/product-images/01K5T0Q8C4000000000000P001', 404, (string) json_encode(imagesProposalJson('samples/error-reply.product-not-found.json'))))->toBe([])
        ->and(ContractReplyGuard::check('PUT api/v1/sync/product-images/01K5T0Q8C4000000000000P001', 400, (string) json_encode([...imagesProposalJson('samples/error-reply.too-large.json')])))
        ->toContain('code image.too_large is HTTP 413 in error-codes.json');
});
