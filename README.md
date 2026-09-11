<p align="center">
  <img src="https://raw.githubusercontent.com/plin-code/job-boards-lever/main/art/banner.png" alt="Job Boards Lever">
</p>

# Job Boards Lever

<p align="center">
    <a href="https://packagist.org/packages/plin-code/job-boards-lever"><img src="https://img.shields.io/packagist/v/plin-code/job-boards-lever.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/plin-code/job-boards-lever"><img src="https://img.shields.io/packagist/php-v/plin-code/job-boards-lever.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/plin-code/job-boards-lever"><img src="https://badge.laravel.cloud/badge/plin-code/job-boards-lever?style=flat" alt="Laravel versions"></a>
    <a href="https://packagist.org/packages/plin-code/job-boards-lever"><img src="https://img.shields.io/packagist/dt/plin-code/job-boards-lever.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Lever connector for the [plin-code](https://github.com/plin-code) job boards family. It reads the public Lever postings API, which needs no credentials and returns a whole board in one request as a bare JSON list:

```
GET https://api.lever.co/v0/postings/{slug}
[ { "id": "abc-123", "text": "Backend Engineer", "categories": { "location": "Paris", "department": "Engineering" }, "hostedUrl": "..." } ]
```

Lever hosts boards in two regions and the slug does not say which, so every API call asks `api.lever.co` first and falls back to `api.eu.lever.co`.

It implements `PlinCode\JobBoards\Contracts\JobBoardClient` from [`plin-code/job-boards-core`](https://github.com/plin-code/job-boards-core), so it is interchangeable with every other connector in the family. Generated from [`plin-code/job-boards-skeleton`](https://github.com/plin-code/job-boards-skeleton).

## Installation

```bash
composer require plin-code/job-boards-lever
```

## Framework agnostic on purpose

`LeverClient` takes core's `HttpClient` and an optional PSR-3 logger. It imports nothing from Laravel, so a Symfony or plain PHP consumer builds it directly:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PlinCode\JobBoards\Http\HttpClient;
use PlinCode\JobBoards\Lever\LeverClient;

$http = new HttpClient(new Client, new HttpFactory);

$client = new LeverClient($http);

$jobs = $client->fetchJobsForCompany('acme');       // list<JobPostingDTO>
$name = $client->validateSlug('acme');              // ?string, the titled slug
$about = $client->fetchCompanyDescription('acme');  // ?string
```

`LeverServiceProvider` is the only Laravel aware file in the package, and all it does is that same wiring out of the container.

## Laravel usage

The provider is auto discovered.

```php
use PlinCode\JobBoards\Lever\LeverClient;

$client = app(LeverClient::class);

foreach ($client->fetchJobsForCompany('acme') as $job) {
    JobPosting::updateOrCreate(
        ['external_id' => $job->externalId],
        $job->toArray(),
    );
}
```

Publish the config to change the base URLs, the timeouts or the request headers:

```bash
php artisan vendor:publish --tag=job-boards-lever-config
```

```php
'base_url'         => env('JOB_BOARDS_LEVER_BASE_URL', LeverClient::API_BASE_URL),
'eu_base_url'      => env('JOB_BOARDS_LEVER_EU_BASE_URL', LeverClient::API_BASE_URL_EU),
'careers_base_url' => env('JOB_BOARDS_LEVER_CAREERS_BASE_URL', LeverClient::CAREERS_BASE_URL),
'timeout'          => env('JOB_BOARDS_LEVER_TIMEOUT', 30),
'lookup_timeout'   => env('JOB_BOARDS_LEVER_LOOKUP_TIMEOUT', 15),
'headers'          => ['Accept' => 'application/json'],
```

The provider binds a PSR-18 client and a PSR-17 factory with `bindIf`, so an application that already binds its own keeps it. It deliberately does **not** bind `JobBoardClient` itself: several connectors implement that interface and would fight over the binding. Bind the one you want in your own application service provider.

## Mapping

| `JobPostingDTO` | Lever field |
| --- | --- |
| `externalId` | `id` cast to a string, `''` when absent or not scalar |
| `title` | `text`, falling back to `'Untitled Position'` when absent, empty or not a string |
| `location` | `categories.location`, or `null` when absent, empty or not a string |
| `url` | `hostedUrl`, or `''` when absent or not a string |
| `department` | `categories.department`, or `null` when absent, empty or not a string |
| `rawPayload` | the untouched posting object |

## Regions

Both `fetchJobsForCompany()` and `validateSlug()` try the US host and, on any non 2xx answer, the EU host. When neither answers 2xx there is no failed response to report, only the fact that no region had the slug.

## No company endpoint

Lever publishes no company object anywhere, so the two lookups improvise.

`validateSlug()` calls a slug valid when the board resolves to a non empty list, and answers with the slug itself, titled: `acme-corp` becomes `Acme Corp`. A board with nothing open is indistinguishable from a slug that does not exist, so it does not validate.

`fetchCompanyDescription()` scrapes the `<meta name="description">` tag off the public careers page at `https://jobs.lever.co/{slug}`. That is HTML rather than the API, which is why it has a base URL of its own and sends `Accept: text/html`.

## Error handling

`fetchJobsForCompany()` never throws at the caller. Everything is logged through the injected PSR-3 logger and an empty list comes back, so one broken company cannot abort a sync over hundreds of them:

| Situation | Level | Message |
| --- | --- | --- |
| non 2xx status from both regions | `warning` | `Lever API request failed on all regions` |
| payload is not a JSON list | `warning` | `Lever API response is not a list of postings` |
| DNS failure, refused connection, timeout | `error` | `Lever API connection error` |
| unreadable body, unexpected shape | `error` | `Unexpected error fetching Lever jobs` |

Every record carries `company_slug`. An empty list is an answer rather than a fault, so a board with nothing open logs nothing. With no logger passed, a `NullLogger` is used and everything is silent.

`validateSlug()` and `fetchCompanyDescription()` return `null` for every failure and log nothing. That is intentional: neither a 404 nor a dropped connection proves a slug is good, and callers use these to validate user input.

## Timeouts

PSR-18 has no notion of a timeout, so core's `HttpClient::withTimeout()` is only honoured by clients implementing `PlinCode\JobBoards\Http\SupportsTimeout`. Guzzle's PSR-18 client does not, so the configured 30 and 15 seconds are a request the transport may ignore. If timeouts matter to you, build the Guzzle client with `['timeout' => 30]` and bind it yourself, or wrap it in a small `SupportsTimeout` adapter.

## Depending on core

```json
"require": {
    "plin-code/job-boards-core": "^0.2||^0.3"
}
```

Core is on Packagist, so that constraint is all this package needs: there is no `repositories` block to carry. Do **not** commit a `path` repository pointing at a sibling checkout of core. It resolves against the layout of one machine, and the package then fails to install from a fresh clone anywhere else.

This connector also requires `symfony/dom-crawler`, which is what parses the careers page. It is the only connector in the family that needs a package core does not already provide.

## Development

```bash
composer install
composer lint          # pint, writes
composer lint:check    # pint, read only
composer analyse       # phpstan level 10, matching core
composer test:unit     # pest
composer test          # analyse + lint:check + test:unit
```

`tests/Unit` builds the client against core's `PlinCode\JobBoards\Testing\FakePsrClient` and boots no framework. `tests/Feature` boots Testbench and covers the service provider only.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
