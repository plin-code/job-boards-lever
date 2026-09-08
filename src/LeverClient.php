<?php

declare(strict_types=1);

namespace PlinCode\JobBoards\Lever;

use PlinCode\JobBoards\Contracts\JobBoardClient;
use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Exceptions\InvalidResponseException;
use PlinCode\JobBoards\Exceptions\TransportException;
use PlinCode\JobBoards\Http\HttpClient;
use PlinCode\JobBoards\Http\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Reads the public Lever postings API:
 *
 *   GET https://api.lever.co/v0/postings/{slug}
 *   [ { "id": "abc-123", "text": "...", "categories": { "location": "..." } } ]
 *
 * No credentials, no pagination, and the payload is a bare JSON list rather
 * than an object. Boards live in one of two regions and the slug does not say
 * which, so every API call asks the US host first and falls back to the EU one.
 *
 * Lever has no company endpoint at all, so the two lookups improvise:
 * validateSlug() titles the slug itself once the board is known to resolve, and
 * fetchCompanyDescription() scrapes the meta description off the public careers
 * page at https://jobs.lever.co/{slug}.
 *
 * Nothing here knows about Laravel. It is handed core's HttpClient and an
 * optional PSR-3 logger, both of which a Symfony or plain PHP consumer can
 * build by hand. {@see LeverServiceProvider} is the only Laravel aware file.
 */
final class LeverClient implements JobBoardClient
{
    public const string API_BASE_URL = 'https://api.lever.co/v0/postings';

    public const string API_BASE_URL_EU = 'https://api.eu.lever.co/v0/postings';

    /**
     * The public careers page, which is HTML and not part of the API.
     */
    public const string CAREERS_BASE_URL = 'https://jobs.lever.co';

    /**
     * Listing a whole board can be slow, so it gets a longer budget than the
     * cheap name and description lookups below.
     */
    public const float TIMEOUT_SECONDS = 30.0;

    public const float LOOKUP_TIMEOUT_SECONDS = 15.0;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl = self::API_BASE_URL,
        private readonly string $euBaseUrl = self::API_BASE_URL_EU,
        private readonly string $careersBaseUrl = self::CAREERS_BASE_URL,
        private readonly float $timeout = self::TIMEOUT_SECONDS,
        private readonly float $lookupTimeout = self::LOOKUP_TIMEOUT_SECONDS,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Never throws at the caller: whatever goes wrong is logged and an empty
     * list comes back, so one broken company cannot abort a sync over hundreds
     * of them.
     *
     * @return list<JobPostingDTO>
     */
    public function fetchJobsForCompany(string $slug): array
    {
        try {
            // get() rather than tryGet() so the transport error message survives
            // into the log. tryGet() would flatten it to a null.
            $response = $this->listBoard($slug, $this->timeout);

            if ($response === null) {
                $this->logger->warning('Lever API request failed on all regions', [
                    'company_slug' => $slug,
                ]);

                return [];
            }

            $data = $response->json();

            // A bare [] is a board with nothing open, which is an answer and not
            // a problem. Anything that is not a list at all is a problem.
            if ($data === []) {
                return [];
            }

            if (! is_array($data) || ! array_is_list($data)) {
                $this->logger->warning('Lever API response is not a list of postings', [
                    'company_slug' => $slug,
                    'response' => $data,
                ]);

                return [];
            }

            $postings = [];

            foreach ($data as $job) {
                if (! is_array($job)) {
                    throw InvalidResponseException::unexpectedShape(
                        $response->url(),
                        '*',
                        get_debug_type($job),
                    );
                }

                /** @var array<string, mixed> $job */
                $postings[] = $this->mapToDTO($job);
            }

            return $postings;
        } catch (TransportException $e) {
            $this->logger->error('Lever API connection error', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return [];
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error fetching Lever jobs', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Lever exposes no company name anywhere, so a slug that resolves to a non
     * empty board is answered with the slug itself, titled: "acme-corp" becomes
     * "Acme Corp". A board with no open postings cannot be told apart from a
     * slug that does not exist, so it does not validate.
     *
     * A 404 and a dead connection are deliberately indistinguishable here:
     * neither proves the slug is good.
     */
    public function validateSlug(string $slug): ?string
    {
        try {
            // tryGet() here: this one is silent by contract, so there is no
            // message to keep.
            $response = $this->tryListBoard($slug, $this->lookupTimeout);

            if ($response === null) {
                return null;
            }

            $data = $response->json();

            if (! is_array($data) || $data === [] || ! array_is_list($data)) {
                return null;
            }

            $name = mb_convert_case(str_replace('-', ' ', $slug), MB_CASE_TITLE, 'UTF-8');

            return $name === '' ? null : $name;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Scraped, not fetched: the meta description of the public careers page is
     * the only company blurb Lever publishes.
     */
    public function fetchCompanyDescription(string $slug): ?string
    {
        try {
            $response = $this->http
                ->withTimeout($this->lookupTimeout)
                ->withHeaders(['Accept' => 'text/html'])
                ->tryGet($this->careersEndpoint($slug));

            if ($response === null || $response->failed()) {
                return null;
            }

            $body = $response->body();

            if (trim($body) === '') {
                return null;
            }

            $meta = (new Crawler($body))->filterXPath('//meta[@name="description"]');

            if ($meta->count() === 0) {
                return null;
            }

            $content = trim($meta->first()->attr('content') ?? '');

            return $content !== '' ? $content : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * US first, EU second, and null when neither answers 2xx. Unlike a single
     * region board there is no "the failed response" to report, only the fact
     * that no region had the slug.
     *
     * @throws TransportException
     */
    private function listBoard(string $slug, float $timeout): ?Response
    {
        $http = $this->http->withTimeout($timeout);

        foreach ($this->regions() as $baseUrl) {
            $response = $http->get($this->postingsEndpoint($baseUrl, $slug));

            if ($response->successful()) {
                return $response;
            }
        }

        return null;
    }

    /**
     * The same fallback, silently: a transport failure on either host is a null
     * rather than an exception.
     */
    private function tryListBoard(string $slug, float $timeout): ?Response
    {
        $http = $this->http->withTimeout($timeout);

        foreach ($this->regions() as $baseUrl) {
            $response = $http->tryGet($this->postingsEndpoint($baseUrl, $slug));

            if ($response !== null && $response->successful()) {
                return $response;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function regions(): array
    {
        return [$this->baseUrl, $this->euBaseUrl];
    }

    private function postingsEndpoint(string $baseUrl, string $slug): string
    {
        return sprintf('%s/%s', rtrim($baseUrl, '/'), rawurlencode($slug));
    }

    private function careersEndpoint(string $slug): string
    {
        return sprintf('%s/%s', rtrim($this->careersBaseUrl, '/'), rawurlencode($slug));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapToDTO(array $data): JobPostingDTO
    {
        $id = $data['id'] ?? null;
        $text = $data['text'] ?? null;
        $hostedUrl = $data['hostedUrl'] ?? null;

        return new JobPostingDTO(
            externalId: is_scalar($id) ? (string) $id : '',
            title: is_string($text) && $text !== '' ? $text : 'Untitled Position',
            location: $this->category($data, 'location'),
            url: is_string($hostedUrl) ? $hostedUrl : '',
            department: $this->category($data, 'department'),
            rawPayload: $data,
        );
    }

    /**
     * Lever files location, team, department and commitment under "categories",
     * and any of them may be absent, null or empty.
     *
     * @param  array<string, mixed>  $data
     */
    private function category(array $data, string $key): ?string
    {
        $categories = $data['categories'] ?? null;

        if (! is_array($categories)) {
            return null;
        }

        $value = $categories[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
