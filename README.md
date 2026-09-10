# AirNow API

A small PHP **^8.5**, Symfony **^8.1** service using the published
`survos/airnow-bundle` (and its kit/fetch bundle dependencies). Intended deployment:
**https://airnow-api.survos.com**. No application database, UI, or desktop runtime.
SQLite is used only by fetch-bundle's internal response cache.

## Development

```bash
composer install
# Put AIRNOW_API_KEY=your-key in .env.local (never committed).
symfony server:start
```

One catchall controller handles the supported AirNow paths:

```text
GET /aq/observation/current/ziplatlong/?zipcode=20002
GET /aq/forecast/current/?zipCode=20008
```

`format=application/json` is optional. ZIP parameter spelling follows AirNow.
The root route lists available endpoints; `/health` checks application liveness without
calling AirNow. Missing server credentials produce 503 on data routes.

The paths mirror AirNow; the **JSON is our version-1 normalized contract**, not a
byte-for-byte upstream proxy. Responses contain `data` (the bundle's camelCase DTOs)
and `meta` (`zipCode`, `fetchedAt`, `expiresAt`, `preliminary`, `source`). Observation
measurement times and reporting agencies remain in each DTO. Empty upstream arrays
are successful responses. Failures return sanitized JSON and are not cached.

## Routing and caching

`config/packages/gateway.yaml` is the endpoint allowlist. Each entry specifies a bundle
method, ZIP parameter and TTL. Add a supported bundle method here to expose it through
the same catchall. Unknown paths return 404; unexpected query parameters, caller keys,
force-refresh flags, alternate formats and arbitrary URLs return 400. Only GET is accepted.

Symfony Cache shares results across callers: observations for one hour, forecasts for one
day. Cache-Control and ETag support downstream caches. The cache callback coalesces misses
for the same endpoint/ZIP. The bundle's secondary fetch cache has a one-second TTL so it
does not extend the gateway's refresh window. No client can bypass either cache.

Symfony RateLimiter defaults to 60 requests/minute per client and 60 cache misses/hour
per endpoint across all clients. Configure `AIRNOW_CLIENT_LIMIT` and `AIRNOW_UPSTREAM_LIMIT`
to fit usage and the actual AirNow quotas. Limits are local to one server with shared
filesystem storage; use Redis/shared lock storage before scaling to multiple hosts.
No stale fallback is served after an upstream failure; the desktop can retain its own history.

`var/data` holds the caches and limiter state and should persist across deploys. These are
private server files (fetch-bundle's internal cache can contain upstream credential URLs).
Expose only `public/`. The Dokku proxy is trusted via private network ranges; constrain
`TRUSTED_PROXIES` to the actual proxy when deploying on different infrastructure.

## Verification

```bash
php bin/phpunit
php bin/console lint:container
php bin/console lint:yaml config
composer validate --strict
```

Tests exercise both endpoints through HTTP with mocked upstream responses, normalized data,
shared caching, conditional GET, input rejection, upstream errors, and both rate limits.
They require no real API key and make no network requests.

## Deployment

This follows the other Survos apps: Dokku on `fsn1.survos.com`, FrankenPHP/PHP 8.5 Docker
image, port 80 inside the container, TLS terminated upstream. Symfony versions are locked
in composer.lock; composer.json permits compatible Symfony 8.x updates from 8.1 onward.
The Docker image excludes local secrets, tests, local vendor files and writable caches.

```bash
ssh dokku@fsn1.survos.com apps:create airnow-api
ssh dokku@fsn1.survos.com domains:set airnow-api airnow-api.survos.com
ssh dokku@fsn1.survos.com storage:create airnow-api /var/lib/dokku/data/storage/airnow-api
ssh dokku@fsn1.survos.com storage:mount airnow-api /var/lib/dokku/data/storage/airnow-api:/app/var/data
# Set AIRNOW_API_KEY securely via Dokku config; do not put it in git or this README.
git remote add dokku dokku@fsn1.survos.com:airnow-api
git push dokku main
ssh dokku@fsn1.survos.com letsencrypt:enable airnow-api
```

Ensure the hostname's DNS reaches that server before certificate issuance. Check `/health`
and a real observations request over HTTPS after deployment; check that a second request
has the same `meta.fetchedAt`. The API key stays on the server, never in response JSON.
The desktop hosted-source adapter is a separate change in `survos-sites/airnow`.

## Data attribution

Data are preliminary and supplied by the reporting agencies and EPA AirNow. Preserve
upstream values, advisories, observation times, and agency attribution when displaying them.
AirNow recommends hourly observation and daily forecast caching. Before public promotion,
follow its data-exchange guidelines, including informing the relevant agencies/EPA of use.

- https://docs.airnowapi.org/faq
- https://docs.airnowapi.org/docs/DataUseGuidelines.pdf

The companion desktop app was inspired by https://github.com/breadthe/aqi-desktop.
