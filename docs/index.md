# QA Capture Bundle

This bundle is installed in the Symfony API of the application your QA team tests. It records what
happens on the server during each request and, when a tester files a report from the browser, sends
the context of the requests involved to the tracker.

- [How it works](#how-it-works)
- [Installation](#installation)
- [Configuration reference](#configuration-reference)
- [What is captured](#what-is-captured)
- [Keeping sensitive data out](#keeping-sensitive-data-out)
- [Security model](#security-model)
- [Several nodes and long-running runtimes](#several-nodes-and-long-running-runtimes)
- [Troubleshooting](#troubleshooting)
- [Known limitations](#known-limitations)
- [Running the tests](#running-the-tests)

## How it works

1. **Every request gets a trace ID.** The bundle adds an `X-QA-Trace-Id` header to each response and
   keeps what happened in that request (for two hours by default) in a cache pool.
2. **The capture bar remembers the trace IDs it saw.** The bar runs in the browser, loaded from the
   tracker; it is not part of this package.
3. **When the tester sends a report**, the bar posts those trace IDs to `POST /_qa/context` on your
   API. The bundle assembles the context of those requests and sends it **from your server to the
   tracker**.
4. **The browser only gets a token back**, which the bar attaches to the report so the tracker can
   join both halves.

Nothing captured on the server ever travels through the browser.

## Installation

```bash
composer require qa-tracking/capture-bundle
```

Register the bundle for the environments QA uses, never for `prod`:

```php
// config/bundles.php
return [
    // ...
    QaTracking\CaptureBundle\QaCaptureBundle::class => ['dev' => true, 'qa' => true],
];
```

Symfony Flex registers bundles for all environments: if you use Flex, edit the line it added.

Then configure it:

```yaml
# config/packages/qa_capture.yaml
when@qa: &qa_capture
    qa_capture:
        enabled: '%env(bool:QA_CAPTURE_ENABLED)%'
        tracker_url: '%env(QA_TRACKER_URL)%'
        project_secret: '%env(QA_PROJECT_SECRET)%'
        allowed_origins: ['https://app-qa.example.com']

when@dev: *qa_capture
```

```dotenv
# .env.local on the QA server
QA_CAPTURE_ENABLED=1
QA_TRACKER_URL=https://qa-tracker.example.com
QA_PROJECT_SECRET=sk_...
```

`tracker_url` is the tracker as **this server** reaches it. `project_secret` is the secret key of the
project in the tracker; it stays on the server. `allowed_origins` are the origins of the front-end
applications under test.

If your QA server runs with `APP_ENV=prod`, register the bundle for `prod` and add
`allow_prod_env: true`. Without that line the bundle refuses to load in `prod`, so it cannot reach
production by accident.

To verify the installation, check that any response of the API carries the trace header:

```bash
curl -si https://api-qa.example.com/api/anything | grep -i x-qa-trace-id
```

## Configuration reference

All options with their defaults:

```yaml
qa_capture:
    # Runtime switch. Accepts an environment variable.
    enabled: false
    # Allows loading the bundle with APP_ENV=prod. Only for QA servers that run in prod mode.
    allow_prod_env: false
    # URL of the tracker as this server reaches it.
    tracker_url: null
    # Secret key of the project in the tracker.
    project_secret: null
    # Origins of the applications under test.
    allowed_origins: []
    # Response header that carries the request identifier.
    trace_header: X-QA-Trace-Id
    # Prefix of the endpoint the capture bar calls.
    path_prefix: /_qa
    # Id of a PSR-6 pool shared between nodes. By default, files in the cache directory.
    cache_pool: null
    # Seconds the context of each request is kept (minimum 60).
    ttl: 7200
    # Hands the context only to the browser (IP and user agent) that made the original request.
    bind_to_client: true
    # Maximum number of requests whose context is attached to one report.
    max_trace_ids: 50
    # Times the context of one request can be attached (several reports from the same screen).
    max_claims_per_trace: 20

    logs:
        enabled: true
        # Log records kept per request. When full, the lowest levels are dropped first.
        max_records: 300
        # Monolog channels that are not captured.
        exclude_channels: [event]
        # Includes the parameters of the SQL queries Doctrine logs. They are unmasked database data.
        sql_params: false

    doctrine:
        # Tracks the Doctrine entities involved in each request and attaches their rows.
        enabled: true
        # Maximum rows per report: main rows first, then modified ones, then the ones only read.
        max_rows: 60
        # Also attaches the rows of entities that were only read.
        include_read_rows: true
        # Entities tracked per request; past the limit, the ones only read are no longer tracked.
        max_entities_per_request: 300

    masking:
        # Key name fragments to mask, in addition to the built-in ones.
        fields: []
        # Per entity, fields or columns to mask.
        entities: {}
        # Per entity, the only fields or columns that are sent; everything else is masked.
        only: {}
        # Entities whose data is never sent; the report keeps only a reference to them.
        exclude_entities: []
```

`php bin/console config:dump-reference qa_capture` prints the same tree from the installed version.

## What is captured

For each HTTP request, while `enabled` is on:

- **The request itself:** route, controller, authenticated user and response status.
- **The exception**, if there was one, with its stack trace.
- **Log records** from every Monolog channel except the excluded ones (requires Monolog).
- **Doctrine entities** that were read or modified, with their rows and, for changes, the values
  before and after (requires Doctrine ORM).

Monolog and Doctrine are optional: without them the bundle captures what it can.

## Keeping sensitive data out

Database rows end up stored in the tracker. If your QA database is a copy of production, decide
what may leave before turning the bundle on:

```yaml
qa_capture:
    masking:
        entities:
            App\Entity\Customer: [taxId, email, phone]   # these fields are masked
        only:
            App\Entity\User: [id, name, roles]            # only these leave for User
        exclude_entities:
            - App\Entity\RefreshToken                      # its data is never sent
```

Fields whose name contains `password`, `passwd`, `pwd`, `secret`, `token`, `api_key`, `apikey`,
`authorization`, `credential`, `private_key` or `salt` are always masked; `masking.fields` can only
add more.

An entity that shows up in almost every report without adding anything, such as the `User` the
firewall loads on each request, can be excluded or limited with `only`.

`logs.sql_params` is off by default because query parameters are database data that does not go
through entity masking.

## Security model

The endpoint is an early listener rather than a route, so installing the bundle does not require
changes to your firewall or CORS configuration. In exchange it brings its own controls:

- It only answers the origins listed in `allowed_origins`.
- Each trace ID is unpredictable, expires after `ttl` seconds and, with `bind_to_client`, can only
  be used by the browser that made the original request.
- It never returns captured data to the browser: data goes from your server to the tracker, and the
  response only carries a token and counts.
- It is rate limited to 30 calls per minute per client, and rejects request bodies over 16 KB.
- The call to the tracker is authenticated with the project secret (sent as a bearer token from
  your server), which never reaches the browser.

## Several nodes and long-running runtimes

If the API runs on **several nodes**, set `cache_pool` to a pool shared between them (Redis, for
example): the original request and the context request may land on different nodes.

```yaml
qa_capture:
    cache_pool: cache.qa_capture   # a pool you define under framework.cache.pools
```

The collector is reset between requests (`kernel.reset`), so nothing leaks from one request to the
next when the container is reused, as long-running runtimes do.

## Troubleshooting

| Symptom | Cause |
|---|---|
| Responses have no `X-QA-Trace-Id` header | The bundle is not registered for the current environment, or `enabled` is false. |
| The application fails to boot in `prod` with a `LogicException` from `QaCaptureBundle` | The bundle is registered for `prod`. Remove it from that environment, or set `allow_prod_env: true` on a QA server that runs in prod mode. |
| The context endpoint answers `origin_not_allowed` | The origin of the front-end application is missing from `allowed_origins`. |
| The context endpoint answers `tracker_unavailable` | The server cannot reach `tracker_url`, or `tracker_url` / `project_secret` are missing. |
| The context endpoint answers `too_many_requests` | More than 30 calls in a minute from the same client. |
| Reports arrive without backend context on a multi-node setup | `cache_pool` is not shared between nodes. |

## Known limitations

- Only HTTP requests are captured: Messenger handlers and console commands are not.
- Rows reflect the state at the moment of the report. What changed during the requests is kept
  separately, with its before and after.

## Running the tests

```bash
composer update && vendor/bin/phpunit
bin/test-matrix.sh "7.4.*"     # against a specific Symfony version
```

The suite runs against PHP 8.1 with the lowest supported dependencies, and against Symfony 7.4
and 8.x.
