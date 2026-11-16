# QA Capture Bundle

Symfony bundle that adds what happened **on the server** to the bug reports your QA team files with
[QA Tracking](#about-qa-tracking). When a tester reports a problem from the browser, the report
arrives with:

- the exception, with its stack trace;
- the Monolog records of the request, from every channel;
- the Doctrine entities involved, with their database rows and the before/after of every change.

It is meant for QA and development environments. It never runs in production unless you explicitly
allow it.

Requires PHP 8.1+ and Symfony 6.4, 7.x or 8.x. Doctrine and Monolog are optional.

## Installation

### Step 1: Download the bundle

```bash
composer require qa-tracking/capture-bundle
```

### Step 2: Enable the bundle only where QA tests

Register the bundle in `config/bundles.php` for the environments QA uses, and **not** for `prod`:

```php
// config/bundles.php
return [
    // ...
    QaTracking\CaptureBundle\QaCaptureBundle::class => ['dev' => true, 'qa' => true],
];
```

> **Symfony Flex users:** Flex enables the bundle in **all** environments. Edit the line it adds to
> `config/bundles.php` so it looks like the one above. The bundle refuses to boot with
> `APP_ENV=prod` (see [Guarantees](#guarantees)), so a leftover `['all' => true]` breaks a
> production deploy instead of silently exposing debug data.

### Step 3: Configure it

```yaml
# config/packages/qa_capture.yaml
when@qa:
    qa_capture:
        enabled: '%env(bool:QA_CAPTURE_ENABLED)%'
        tracker_url: '%env(QA_TRACKER_URL)%'
        project_secret: '%env(QA_PROJECT_SECRET)%'
        allowed_origins: ['https://app-qa.example.com']
```

You do not need to touch the firewall, the routes or the CORS configuration of your application.

Check that it works: every response of your API now carries an `X-QA-Trace-Id` header.

## Documentation

The full documentation is in [docs/index.md](docs/index.md): how it works, every configuration
option, how to keep sensitive data out of the reports, and troubleshooting.

## Guarantees

- With `enabled: false` it adds no headers, stores nothing, and its endpoint does not exist.
- With `APP_ENV=prod` it refuses to load, unless you set `allow_prod_env: true`.
- It never breaks your application: if one of its collectors fails, the error is discarded.
- Data goes from your server to the tracker. The browser only receives a token.

## About QA Tracking

QA Tracking is a self-hosted bug tracker with a capture bar that testers use inside the application
under test. This bundle is its server-side half: it only does something
useful together with a running tracker.

**The tracker is not publicly released yet.** Until it is, this package is published for the teams
that already run one.

## Tests

```bash
composer update && vendor/bin/phpunit
bin/test-matrix.sh "7.4.*"     # against a specific Symfony version
```

## License

Released under the [MIT License](LICENSE).
