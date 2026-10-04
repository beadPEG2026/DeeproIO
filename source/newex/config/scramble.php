<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
     * Your API path. By default, all routes starting with this path will be added to the docs.
     * If you need to change this behavior, you can add your custom routes resolver using `Scramble::routes()`.
     */
    'api_path' => 'api/v1',

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain' => null,

    /*
     * The path where your OpenAPI specification will be exported.
     */
    'export_path' => 'api.json',

    'info' => [
        /*
         * API version.
         */
        'version' => env('API_VERSION', '1.2'),

        /*
         * Description rendered on the home page of the API documentation (`/docs/api`).
         */
        'description' => 'Our REST API version 1.2 provides programmatic access to the platform’s trading engine API endpoints. We strongly recommend that our new customers use API version 1.2 to get the best trading experience. We also recommend that our current traders switch to the newest version 1.2. <br><br><h3>DateTime Format</h3> All timestamps are returned in ISO 8601 format or UNIX timestamp in milliseconds (UTC). <p>Example: "2021-06-03T10:20:49.315Z" or "1614815872000".</p><h3>Number Format</h3><p>All currency data, e.g., price, amount, fee, etc., should be precision numbers and have a string representation. Example: "20.4383003".</p><h3>RATE LIMITING</h3><p>The following Rate Limits are applied:</p><p>The default API limit is 200 requests per minute per authenticated user, or per IP for guests. Some endpoints and approved integrations have separate limits. Inspect X-RateLimit-Limit, X-RateLimit-Remaining and Retry-After response headers.</p><br><p>Significantly exceeding the Rate Limits can lead to suspension.</p><h3>BEST PRACTICES</h3><p>The development team does its best to bring the best trading experience to API users. This manual contains a set of best practices for using the API as efficiently as possible.</p><h3>HTTP Persistent Connection</h3><p>The underlying TCP connection is kept active for multiple requests/responses. Subsequent requests will result in reduced latency as the TCP handshaking process is no longer required.</p><p>If you use the HTTP 1.0 client, please ensure it supports the Keep-Alive directive and submit the "Connection: Keep-Alive" header with your request.</p><p>Keep-Alive is a part of the HTTP/1.1 or HTTP/2 protocol and is enabled by default on compliant clients. However, you will have to ensure your implementation does not set other values as the connection header.</p><h3>REST API Reference</h3><p><strong>HTTP Status Codes</strong></p><p>200 OK. Successful request</p><p>422 Bad Request. Returns JSON with the error message</p><p>401 Unauthorized. Authorization is required or has been failed</p><p>403 Forbidden. Action is forbidden</p><p>404 Not Found. Data requested cannot be found</p><p>429 Too Many Requests. Your connection has been rate limited</p><p>500 Internal Server. Internal Server Error</p><p>503 Service Unavailable. Service is down for maintenance</p><p>504 Gateway Timeout. Request timeout expired</p>',
    ],

    /*
     * Customize Stoplight Elements UI
     */
    'ui' => [
        /*
         * Define the title of the documentation's website. App name is used when this config is `null`.
         */
        'title' => 'REST API Documentation',

        /*
         * Define the theme of the documentation. Available options are `light` and `dark`.
         */
        'theme' => 'light',

        /*
         * Hide the `Try It` feature. Enabled by default.
         */
        'hide_try_it' => false,

        /*
         * URL to an image that displays as a small square logo next to the title, above the table of contents.
         */
        'logo' => '',

        /*
         * Use to fetch the credential policy for the Try It feature. Options are: omit, include (default), and same-origin
         */
        'try_it_credentials_policy' => 'include',

        'hide_schemas' => true,
    ],

    /*
     * The list of servers of the API. By default, when `null`, server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     *
     * Example of non-default config (final URLs are generated using Laravel `url` helper):
     *
     * ```php
     * 'servers' => [
     *     'Live' => 'api',
     *     'Prod' => 'https://scramble.dedoc.co/api',
     * ],
     * ```
     */
    'servers' => null,

    'middleware' => [
        'web',
    ],

    'extensions' => [],
];
