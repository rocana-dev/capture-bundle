<?php

namespace QaTracking\CaptureBundle\Tests;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use QaTracking\CaptureBundle\QaCaptureBundle;
use QaTracking\CaptureBundle\Tests\Fixtures\DemoController;
use QaTracking\CaptureBundle\Tests\Fixtures\Entity\Customer;
use QaTracking\CaptureBundle\Tests\Fixtures\Entity\Invoice;
use QaTracking\CaptureBundle\Tests\Fixtures\Entity\InvoiceLine;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimal application for testing the bundle. Each distinct configuration uses its
 * own cache folder, so tests don't step on each other.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const CORS_ORIGIN = 'https://app-qa.example.com';
    public const USER_AGENT = 'Mozilla/5.0 (tests)';
    public const SECRET = 'sk_test_secret';

    /** Response the simulated tracker will return: [status code, body]. */
    public static array $trackerResponse = [201, ['contextToken' => 'ctx_test_token']];
    /** @var list<array{method: string, url: string, headers: list<string>, body: array<string, mixed>}> */
    public static array $trackerRequests = [];

    /** @param array<string, mixed> $qaConfig */
    public function __construct(
        private readonly array $qaConfig = [],
        string $environment = 'test',
        private readonly bool $withDoctrine = false,
    ) {
        parent::__construct($environment, true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new MonologBundle();
        if ($this->withDoctrine) {
            yield new DoctrineBundle();
        }
        yield new QaCaptureBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/qa-capture-bundle/'.$this->environment.'-'.md5(serialize([$this->qaConfig, $this->withDoctrine])).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/qa-capture-bundle/logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);
        $container->extension('monolog', [
            'channels' => ['doctrine'],
            'handlers' => ['main' => ['type' => 'null', 'level' => 'debug']],
        ]);
        $container->extension('qa_capture', $this->qaConfig);

        if ($this->withDoctrine) {
            $container->extension('doctrine', [
                'dbal' => ['url' => 'sqlite:///'.$this->databasePath()],
                'orm' => [
                    'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                    'mappings' => [
                        'Tests' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => __DIR__.'/Fixtures/Entity',
                            'prefix' => 'QaTracking\\CaptureBundle\\Tests\\Fixtures\\Entity',
                        ],
                    ],
                ],
            ]);
        }

        $services = $container->services();

        $services->set('test.controller', DemoController::class)
            ->args([
                new Reference('logger'),
                new Reference('monolog.logger.doctrine'),
                $this->withDoctrine ? new Reference('doctrine') : null,
            ])
            ->public();

        $services->set('test.cors_listener', \stdClass::class)
            ->factory([self::class, 'corsListener'])
            ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => '__invoke', 'priority' => 0]);

        // The tracker is simulated: the application's definitions override the bundle's.
        $services->set('qa_capture.http_client', MockHttpClient::class)
            ->factory([self::class, 'trackerClient']);

        $services->alias('test.store', 'qa_capture.store')->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        foreach (['ok', 'log', 'sql', 'boom', 'spam'] as $action) {
            $routes->add($action, '/'.$action)->controller('test.controller::'.$action);
        }

        if ($this->withDoctrine) {
            $routes->add('invoices', '/invoices')->controller('test.controller::invoices');
            $routes->add('invoice', '/invoices/{id}')->controller('test.controller::invoice');
            $routes->add('pay', '/invoices/{id}/pay')->controller('test.controller::pay');
            $routes->add('remove_line', '/invoices/{id}/remove-line')->controller('test.controller::removeLine');
            $routes->add('new_customer', '/customers/new')->controller('test.controller::newCustomer');
        }
    }

    private function databasePath(): string
    {
        return \dirname($this->getCacheDir()).'/test.db';
    }

    /** Creates the test application's database: one customer and invoice 1 with two lines. */
    public function createDatabase(): void
    {
        @unlink($this->databasePath());
        $this->boot();

        /** @var EntityManagerInterface $em */
        $em = $this->getContainer()->get('doctrine')->getManager();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        $customer = new Customer('Paper and Ink Bookshop', '10407654321');
        $invoice = new Invoice('F001-0023', $customer);
        new InvoiceLine($invoice, 'Annual license');
        new InvoiceLine($invoice, 'Technical support');
        $em->persist($customer);
        $em->persist($invoice);
        $em->flush();
        $em->clear();
    }

    /** Mimics NelmioCorsBundle: writes the CORS headers with priority 0 for a known origin. */
    public static function corsListener(): object
    {
        return new class {
            public function __invoke(ResponseEvent $event): void
            {
                if (TestKernel::CORS_ORIGIN === $event->getRequest()->headers->get('Origin')) {
                    $event->getResponse()->headers->set('Access-Control-Allow-Origin', TestKernel::CORS_ORIGIN);
                    $event->getResponse()->headers->set('Access-Control-Expose-Headers', 'Link');
                }
            }
        };
    }

    public static function trackerClient(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::$trackerRequests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'] ?? [],
                'body' => json_decode((string) ($options['body'] ?? ''), true) ?? [],
            ];

            [$status, $body] = self::$trackerResponse;

            return new MockResponse(json_encode($body), ['http_code' => $status, 'response_headers' => ['Content-Type: application/json']]);
        });
    }

    public static function resetTracker(): void
    {
        self::$trackerResponse = [201, ['contextToken' => 'ctx_test_token']];
        self::$trackerRequests = [];
    }

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = []): Response
    {
        return $this->request('GET', $path, $headers);
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $path, array $headers = [], ?string $content = null): Response
    {
        $request = Request::create($path, $method, [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], $content);
        $request->headers->add(['User-Agent' => self::USER_AGENT, ...$headers]);

        $response = $this->handle($request);
        $this->terminate($request, $response);

        return $response;
    }

    /**
     * Handles a request without reaching `kernel.terminate`, as when the process
     * dies from a fatal error or a time limit after failing.
     *
     * @param array<string, string> $headers
     */
    public function handleWithoutTerminating(string $path, array $headers = []): Response
    {
        $request = Request::create($path, 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']);
        $request->headers->add(['User-Agent' => self::USER_AGENT, ...$headers]);

        return $this->handle($request);
    }

    /** Fingerprint the bundle computes for requests made through `request()`. */
    public static function fingerprint(string $userAgent = self::USER_AGENT): string
    {
        return hash('sha256', '203.0.113.7|'.$userAgent);
    }
}
