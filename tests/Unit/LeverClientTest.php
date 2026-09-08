<?php

declare(strict_types=1);

use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Lever\LeverClient;
use PlinCode\JobBoards\Testing\FakePsrClient;
use PlinCode\JobBoards\Testing\RecordingLogger;

const US = 'https://api.lever.co/v0/postings';

const EU = 'https://api.eu.lever.co/v0/postings';

const CAREERS = 'https://jobs.lever.co';

function leverClient(FakePsrClient $fake, ?RecordingLogger $logger = null): LeverClient
{
    return new LeverClient($fake->asHttpClient(), logger: $logger);
}

/**
 * Lever answers with a bare JSON list, not an object with a jobs key.
 *
 * @param  array<array-key, mixed>  $postings
 */
function withPostings(array $postings): FakePsrClient
{
    return (new FakePsrClient)->respondWithJson($postings);
}

it('fetches jobs for a valid company slug', function (): void {
    $fake = withPostings([
        [
            'id' => 'abc-123',
            'text' => 'Backend Engineer',
            'categories' => [
                'location' => 'Paris',
                'department' => 'Engineering',
            ],
            'hostedUrl' => 'https://jobs.lever.co/scaleway/abc-123',
        ],
        [
            'id' => 'def-456',
            'text' => 'Frontend Engineer',
            'categories' => [
                'location' => 'Amsterdam',
                'department' => 'Engineering',
            ],
            'hostedUrl' => 'https://jobs.lever.co/scaleway/def-456',
        ],
    ]);

    $jobs = leverClient($fake)->fetchJobsForCompany('scaleway');

    expect($jobs)->toHaveCount(2)
        ->and($jobs[0])->toBeInstanceOf(JobPostingDTO::class)
        ->and($jobs[0]->externalId)->toBe('abc-123')
        ->and($jobs[0]->title)->toBe('Backend Engineer')
        ->and($jobs[0]->location)->toBe('Paris')
        ->and($jobs[0]->url)->toBe('https://jobs.lever.co/scaleway/abc-123')
        ->and($jobs[0]->department)->toBe('Engineering')
        ->and($jobs[1]->externalId)->toBe('def-456')
        ->and($fake->lastUri())->toBe(US.'/scaleway');
});

it('returns an empty list for an empty api response', function (): void {
    $fake = withPostings([]);
    $logger = new RecordingLogger;

    expect(leverClient($fake, $logger)->fetchJobsForCompany('nonexistent'))->toBe([])
        // An empty board is an answer, not a fault: nothing to log.
        ->and($logger->records)->toBe([])
        ->and($fake->uris())->toBe([US.'/nonexistent']);
});

it('returns an empty list on a failed http response', function (): void {
    // Both regions have to answer before Lever gives up, and neither response
    // is "the" failure, so the log names the slug and nothing else.
    $fake = (new FakePsrClient)
        ->respondWith(500, 'Server Error')
        ->respondWith(500, 'EU Server Error');
    $logger = new RecordingLogger;

    expect(leverClient($fake, $logger)->fetchJobsForCompany('broken'))->toBe([])
        ->and($logger->messages())->toBe(['Lever API request failed on all regions'])
        ->and($logger->levels())->toBe(['warning'])
        ->and($logger->records[0]['context'])->toBe(['company_slug' => 'broken'])
        ->and($fake->uris())->toBe([US.'/broken', EU.'/broken']);
});

it('returns an empty list on a connection error', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();
    $logger = new RecordingLogger;

    expect(leverClient($fake, $logger)->fetchJobsForCompany('timeout'))->toBe([])
        ->and($logger->messages())->toBe(['Lever API connection error'])
        ->and($logger->levels())->toBe(['error'])
        ->and($logger->records[0]['context']['company_slug'])->toBe('timeout')
        ->and($logger->records[0]['context']['error'])->toContain('connection refused');
});

it('maps lever fields to the dto including nullable ones', function (): void {
    $jobs = leverClient(withPostings([
        [
            'id' => 'uuid-789',
            'text' => 'Designer',
            'categories' => [],
            'hostedUrl' => 'https://jobs.lever.co/testco/uuid-789',
            'workplaceType' => 'remote',
        ],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->externalId)->toBe('uuid-789')
        ->and($jobs[0]->title)->toBe('Designer')
        ->and($jobs[0]->location)->toBeNull()
        ->and($jobs[0]->department)->toBeNull()
        ->and($jobs[0]->rawPayload)->toHaveKey('workplaceType', 'remote');
});

it('drops categories that are absent, empty, or not strings', function (): void {
    $jobs = leverClient(withPostings([
        ['id' => '1', 'text' => 'A'],
        ['id' => '2', 'text' => 'B', 'categories' => 'Paris'],
        ['id' => '3', 'text' => 'C', 'categories' => ['location' => '', 'department' => '']],
        ['id' => '4', 'text' => 'D', 'categories' => ['location' => 42, 'department' => ['nested']]],
        ['id' => '5', 'text' => 'E', 'categories' => ['location' => null, 'department' => 'Sales']],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->location)->toBeNull()
        ->and($jobs[1]->location)->toBeNull()
        ->and($jobs[2]->location)->toBeNull()
        ->and($jobs[2]->department)->toBeNull()
        ->and($jobs[3]->location)->toBeNull()
        ->and($jobs[3]->department)->toBeNull()
        ->and($jobs[4]->location)->toBeNull()
        ->and($jobs[4]->department)->toBe('Sales');
});

it('falls back to an empty id and url and a placeholder title', function (): void {
    $jobs = leverClient(withPostings([
        ['id' => 'abc-123'],
        ['id' => 42, 'text' => '', 'hostedUrl' => ['nested']],
        ['id' => ['nested'], 'text' => 99],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->externalId)->toBe('abc-123')
        ->and($jobs[0]->title)->toBe('Untitled Position')
        ->and($jobs[0]->url)->toBe('')
        ->and($jobs[0]->rawPayload)->toBe(['id' => 'abc-123'])
        ->and($jobs[1]->externalId)->toBe('42')
        ->and($jobs[1]->title)->toBe('Untitled Position')
        ->and($jobs[1]->url)->toBe('')
        ->and($jobs[2]->externalId)->toBe('')
        ->and($jobs[2]->title)->toBe('Untitled Position');
});

it('returns an empty list when the api returns non-list json', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['ok' => false, 'error' => 'Document not found']);
    $logger = new RecordingLogger;

    expect(leverClient($fake, $logger)->fetchJobsForCompany('invalid'))->toBe([])
        ->and($logger->messages())->toBe(['Lever API response is not a list of postings'])
        ->and($logger->levels())->toBe(['warning'])
        ->and($logger->records[0]['context']['response'])->toBe(['ok' => false, 'error' => 'Document not found']);
});

it('falls back to the eu region when the us board 404s', function (): void {
    $fake = (new FakePsrClient)
        ->respondWith(404, 'Not Found')
        ->respondWithJson([['id' => 'eu-1', 'text' => 'EU Engineer']]);

    $jobs = leverClient($fake)->fetchJobsForCompany('euco');

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->title)->toBe('EU Engineer')
        ->and($fake->uris())->toBe([US.'/euco', EU.'/euco']);
});

it('returns an empty list when the body is not json', function (): void {
    $fake = (new FakePsrClient)->respondWith(200, '<html>maintenance</html>');
    $logger = new RecordingLogger;

    expect(leverClient($fake, $logger)->fetchJobsForCompany('testco'))->toBe([])
        ->and($logger->messages())->toBe(['Unexpected error fetching Lever jobs'])
        ->and($logger->levels())->toBe(['error']);
});

it('returns an empty list when a posting is not an object', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['not-an-object']);
    $logger = new RecordingLogger;

    expect(leverClient($fake, $logger)->fetchJobsForCompany('testco'))->toBe([])
        ->and($logger->messages())->toBe(['Unexpected error fetching Lever jobs']);
});

it('validates a valid slug and returns the titled slug', function (): void {
    $fake = withPostings([
        [
            'id' => 'abc-123',
            'text' => 'Backend Engineer',
            'categories' => [],
            'hostedUrl' => 'https://jobs.lever.co/scaleway/abc-123',
        ],
    ]);

    expect(leverClient($fake)->validateSlug('scaleway'))->toBe('Scaleway')
        ->and($fake->lastUri())->toBe(US.'/scaleway');
});

it('validates a multi-word slug and returns a formatted company name', function (): void {
    $fake = withPostings([
        ['id' => 'abc-123', 'text' => 'Engineer', 'categories' => [], 'hostedUrl' => 'https://jobs.lever.co/acme-corp/abc-123'],
    ]);

    expect(leverClient($fake)->validateSlug('acme-corp'))->toBe('Acme Corp');
});

it('returns null for an invalid slug', function (): void {
    // Lever answers an unknown slug with an empty list, which is also what a
    // real board with nothing open returns. Neither validates.
    expect(leverClient(withPostings([]))->validateSlug('nonexistent'))->toBeNull();
});

it('returns null for slug validation when both regions fail', function (): void {
    $fake = (new FakePsrClient)
        ->respondWith(404, 'Not Found')
        ->respondWith(404, 'Not Found');

    expect(leverClient($fake)->validateSlug('nonexistent'))->toBeNull()
        ->and($fake->uris())->toBe([US.'/nonexistent', EU.'/nonexistent']);
});

it('returns null for slug validation on a connection error, silently', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();
    $logger = new RecordingLogger;

    expect(leverClient($fake, $logger)->validateSlug('timeout'))->toBeNull()
        ->and($logger->records)->toBe([]);
});

it('returns null for slug validation on a non-list or non-json body', function (): void {
    expect(leverClient((new FakePsrClient)->respondWithJson(['ok' => false]))->validateSlug('testco'))->toBeNull()
        ->and(leverClient((new FakePsrClient)->respondWith(200, 'not json'))->validateSlug('testco'))->toBeNull();
});

it('validates a slug against the eu region when the us board 404s', function (): void {
    $fake = (new FakePsrClient)
        ->respondWith(404, 'Not Found')
        ->respondWithJson([['id' => 'eu-1', 'text' => 'Engineer']]);

    expect(leverClient($fake)->validateSlug('euco'))->toBe('Euco')
        ->and($fake->uris())->toBe([US.'/euco', EU.'/euco']);
});

it('fetches the company description from the careers page meta tag', function (): void {
    $fake = (new FakePsrClient)->respondWith(
        200,
        '<html><head><meta name="description" content="Join our team at Test Co"></head></html>',
    );

    expect(leverClient($fake)->fetchCompanyDescription('test-co'))->toBe('Join our team at Test Co')
        // The careers page, not the API.
        ->and($fake->lastUri())->toBe(CAREERS.'/test-co')
        ->and($fake->requests[0]->getHeaderLine('Accept'))->toBe('text/html');
});

it('returns null from fetchCompanyDescription when the meta tag is missing, empty or contentless', function (): void {
    expect(leverClient((new FakePsrClient)->respondWith(200, '<html><head><title>Test Co</title></head></html>'))->fetchCompanyDescription('test-co'))->toBeNull()
        ->and(leverClient((new FakePsrClient)->respondWith(200, '<html><head><meta name="description" content="   "></head></html>'))->fetchCompanyDescription('test-co'))->toBeNull()
        ->and(leverClient((new FakePsrClient)->respondWith(200, '<html><head><meta name="description"></head></html>'))->fetchCompanyDescription('test-co'))->toBeNull()
        ->and(leverClient((new FakePsrClient)->respondWith(200, ''))->fetchCompanyDescription('test-co'))->toBeNull();
});

it('returns null from fetchCompanyDescription when the careers page is unreachable', function (): void {
    $logger = new RecordingLogger;

    expect(leverClient((new FakePsrClient)->respondWith(404, 'Not Found'))->fetchCompanyDescription('nope'))->toBeNull()
        ->and(leverClient((new FakePsrClient)->throwNetworkError(), $logger)->fetchCompanyDescription('timeout'))->toBeNull()
        ->and($logger->records)->toBe([]);
});

it('asks for 30 seconds when listing and 15 when looking up', function (): void {
    $fake = (new FakePsrClient)
        ->respondWithJson([])
        ->respondWithJson([['id' => '1', 'text' => 'Engineer']])
        ->respondWith(200, '<html><head><meta name="description" content="Hello"></head></html>');

    $client = leverClient($fake);
    $client->fetchJobsForCompany('testco');
    $client->validateSlug('testco');
    $client->fetchCompanyDescription('testco');

    expect($fake->appliedTimeouts)->toBe([30.0, 15.0, 15.0]);
});

it('percent encodes the slug in every url', function (): void {
    $postings = withPostings([]);
    leverClient($postings)->fetchJobsForCompany('a b/../c');

    $careers = (new FakePsrClient)->respondWith(200, '<html></html>');
    leverClient($careers)->fetchCompanyDescription('a b/../c');

    expect($postings->lastUri())->toBe(US.'/a%20b%2F..%2Fc')
        ->and($careers->lastUri())->toBe(CAREERS.'/a%20b%2F..%2Fc');
});

it('is safe with no logger at all', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();

    expect((new LeverClient($fake->asHttpClient()))->fetchJobsForCompany('testco'))->toBe([]);
});

it('accepts custom base urls', function (): void {
    $api = (new FakePsrClient)
        ->respondWith(404, 'Not Found')
        ->respondWithJson([]);

    (new LeverClient($api->asHttpClient(), 'https://us.test/postings/', 'https://eu.test/postings/'))
        ->fetchJobsForCompany('testco');

    $careers = (new FakePsrClient)->respondWith(200, '<html></html>');

    (new LeverClient($careers->asHttpClient(), careersBaseUrl: 'https://careers.test/'))
        ->fetchCompanyDescription('testco');

    expect($api->uris())->toBe(['https://us.test/postings/testco', 'https://eu.test/postings/testco'])
        ->and($careers->lastUri())->toBe('https://careers.test/testco');
});
