# Open Web Analytics SDK for PHP

The OWA Software Development Kit for PHP adds [Open Web Analytics](https://github.com/Open-Web-Analytics/Open-Web-Analytics) tracking to any PHP application and talks to OWA's REST API. The SDK is licensed under GPL v2.0.

## Resources

- [User Guide](https://github.com/Open-Web-Analytics/owa-php-sdk/wiki)
- [Issues](https://github.com/Open-Web-Analytics/owa-php-sdk/issues)

## Getting Help

We use Github for managing bugs and feature requests and have limited bandwidth to provide general support. If you do require support [please consider sponsoring the project](https://github.com/sponsors/padams).

## Opening Issues

If you find a bug in the SDK, please let us know about it. Before you create the ticket please search the existing issues. Include the SDK version, the PHP version, the OWA version and your operating system, plus a stack trace and steps to reproduce when appropriate.

## Requirements

- **OWA 1.14** or later.
- **PHP 8.2** or later, with ext-json and ext-iconv. ext-curl is used when it is installed; otherwise the SDK uses PHP streams, which need `allow_url_fopen`.

The SDK has no other runtime dependencies.

## Installation

```
composer require open-web-analytics/owa-php-sdk
```

Without Composer, download a release and `require 'owa-autoloader.php';`.

## Tracking

```php
$sdk = new OwaSdk\sdk( [
    'instance_url'  => 'https://owa.example.com/',   // where OWA is installed
    'cookie_domain' => 'example.com',
] );

$tracker = $sdk->createTracker();
$tracker->setSiteId( '9ceefbab8a804bc03cb0be196abe12f8' );   // Administration > Sites
$tracker->setPageTitle( 'Home' );
$tracker->trackPageView();
```

Call the tracker **before any output is sent**: it sets the same `owa_*` cookies as OWA's JavaScript tracker, so a visitor tracked by both keeps one visitor id and one session.

Tracking methods return `true` when OWA received the request. On failure `$tracker->getLastErrors()` says why, and the failure is written to PHP's error log without credentials or visitor data.

Other events: `trackAction()`, `trackSyndicationFeed()`, and ecommerce with `addTransaction()`, `addTransactionLineItem()` and `trackTransaction()`. Custom variables take a `page`, `session` or `visitor` scope: `setCustomVar( 1, 'plan', 'pro', 'session' )`.

### The visitor's IP address and user agent

OWA reads the IP address, user agent and language from the HTTP request it receives, never from event properties. The SDK therefore forwards them as request headers:

| Header | Sent from |
|---|---|
| `User-Agent` | `setUserAgent()`, else the visitor's `User-Agent` |
| `X-Forwarded-For` | `setIpAddress()`, else the incoming `X-Forwarded-For` chain plus `REMOTE_ADDR` |
| `Accept-Language` | the visitor's `Accept-Language` |

OWA takes the leftmost public address in `X-Forwarded-For`, so a visitor can put any address there, as they can when the JavaScript tracker reports to OWA directly. If your application sits behind a proxy and knows the real client address, pass it explicitly:

```php
$tracker->setIpAddress( $trustedClientIp );
```

`trackStatelessEvent()` (and `trackTransaction()` with a session id) is for events sent later, from a cron job or a payment webhook, so it forwards neither the current request's IP nor its user agent. Set them with `setIpAddress()` / `setUserAgent()` if you stored them.

### Geolocation

OWA resolves location from the IP address above. Turn on the **MaxMind GeoIP** module in OWA under Administration > Modules; the SDK needs no configuration.

### Do Not Track

Like the JavaScript tracker, the SDK does not track a browser that sends `DNT: 1`. Set `'respect_do_not_track' => false` to change that.

## REST API

```php
$sites = $sdk->createSites( [
    'credentials' => [
        'api_key'  => '...',   // the user's API key: their profile screen in OWA
        'auth_key' => '...',   // OWA_AUTH_KEY from the install's owa-config.php
    ],
] );

$list = $sites->listSites();
$site = $sites->addSite( [ 'domain' => 'https://shop.example.com', 'name' => 'Shop' ] );
```

Both methods return the response's `data`, or `null` with the reasons in `getLastErrors()`.

Instead of passing `credentials`, you can set the `OWA_API_KEY` and `OWA_AUTH_KEY` environment variables or constants, or create `~/.owa/credentials`:

```ini
[defaults]
api_key = 0123456789abcdef0123456789abcdef
auth_key = your-owa-auth-key
```

Requests are signed with the full URL, so `instance_url` must use the scheme, host and port that OWA itself sees.

## Configuration

| Key | Default | |
|---|---|---|
| `instance_url` | | URL of the OWA install |
| `cookie_domain` | the request host, `www.` removed | domain for the `owa_*` cookies |
| `session_length` | `1800` | seconds of inactivity that end a session |
| `trafficAttributionMode` | `direct` | `direct` or `original` |
| `campaignAttributionWindow` | `60` | days a campaign touch is kept |
| `cookie_persistence` | `true` | `false` makes every cookie a session cookie |
| `respect_do_not_track` | `true` | |
| `timeout` / `connect_timeout` | `5` / `2` | seconds |
| `transport` | curl or streams | an `OwaSdk\Http\Transport` |
| `debug` / `debug_log` | `false` / PHP's error log | debug messages and where they go |

## Upgrading from 1.0.x

- PHP 8.2 and OWA 1.14 are required.
- Guzzle is no longer a dependency. `getHttpClient()` returns an `OwaSdk\Http\Transport`, and tracking methods return `bool` instead of a response object.
- Cookies follow OWA 1.14's tracker: the session lives in `owa_s_<siteId>`, and every cookie is JSON. Existing `owa_s` and `owa_b` cookies are migrated on the visitor's next request.
- `addTransaction()`'s country, state and city are sent as `ct_country`, `ct_state` and `ct_city`. OWA 1.14 discards the old names.
- The SDK no longer computes `dsfs`, `dsps` or referrer-based attribution; OWA derives them on the server. `addOrganicSearchEngine()` does nothing.
- `debug` no longer turns on `display_errors`.

## Development

```
composer install
composer test
composer phpstan
```

## Related OWA Products

- [Open Web Analytics Core](https://github.com/Open-Web-Analytics/open-web-analytics)
- [WordPress Integration Plugin](https://github.com/Open-Web-Analytics/owa-wordpress-plugin)
- [MediaWiki Integration Extension](https://github.com/Open-Web-Analytics/owa-mediawiki-extension/)
