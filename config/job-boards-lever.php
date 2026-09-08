<?php

declare(strict_types=1);

use PlinCode\JobBoards\Lever\LeverClient;

return [

    /*
    |--------------------------------------------------------------------------
    | API Base URLs
    |--------------------------------------------------------------------------
    |
    | Lever hosts boards in two regions and a slug does not say which one it
    | belongs to, so the connector asks "base_url" first and falls back to
    | "eu_base_url". Override either to point at a recorded fixture server.
    |
    */

    'base_url' => env('JOB_BOARDS_LEVER_BASE_URL', LeverClient::API_BASE_URL),

    'eu_base_url' => env('JOB_BOARDS_LEVER_EU_BASE_URL', LeverClient::API_BASE_URL_EU),

    /*
    |--------------------------------------------------------------------------
    | Careers Page Base URL
    |--------------------------------------------------------------------------
    |
    | Lever publishes no company endpoint, so fetchCompanyDescription() reads
    | the meta description off the public careers page instead. This is HTML,
    | not the API, which is why it has a base URL of its own.
    |
    */

    'careers_base_url' => env('JOB_BOARDS_LEVER_CAREERS_BASE_URL', LeverClient::CAREERS_BASE_URL),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Seconds. "timeout" covers listing a whole board, "lookup_timeout" the
    | cheaper calls behind validateSlug() and fetchCompanyDescription().
    | Honoured only by PSR-18 clients that implement
    | PlinCode\JobBoards\Http\SupportsTimeout; other clients keep the timeout
    | they were built with.
    |
    */

    'timeout' => env('JOB_BOARDS_LEVER_TIMEOUT', 30),

    'lookup_timeout' => env('JOB_BOARDS_LEVER_LOOKUP_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Request Headers
    |--------------------------------------------------------------------------
    |
    | Sent with every request. The public postings endpoint needs no
    | authentication, so Accept is all Lever asks for. The careers page request
    | overrides Accept with text/html, since that one is scraped, not parsed.
    |
    */

    'headers' => [
        'Accept' => 'application/json',
    ],

];
