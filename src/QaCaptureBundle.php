<?php

namespace QaTracking\CaptureBundle;

use QaTracking\CaptureBundle\Collector\ExceptionNormalizer;
use QaTracking\CaptureBundle\Collector\RequestContextCollector;
use QaTracking\CaptureBundle\Collector\ValueNormalizer;
use QaTracking\CaptureBundle\Context\ContextAssembler;
use QaTracking\CaptureBundle\Doctrine\EntityDescriber;
use QaTracking\CaptureBundle\Doctrine\EntityTracker;
use QaTracking\CaptureBundle\Doctrine\RowSnapshotter;
use QaTracking\CaptureBundle\EventListener\ContextListener;
use QaTracking\CaptureBundle\EventListener\EndpointListener;
use QaTracking\CaptureBundle\EventListener\TraceListener;
use QaTracking\CaptureBundle\Masking\FieldMasker;
use QaTracking\CaptureBundle\Monolog\LogCollectorProcessor;
use QaTracking\CaptureBundle\Storage\ContextStore;
use QaTracking\CaptureBundle\Tracker\TrackerClient;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * QA Tracking capture bundle.
 *
 * It is enabled at two levels: the bundle is registered only in the environments
 * chosen in `bundles.php`, and on top of that `enabled` works as a runtime
 * switch. With `enabled: false` it adds no headers, stores nothing and its
 * endpoint does not exist.
 */
final class QaCaptureBundle extends AbstractBundle
{
    /** Key names that are always masked; the configuration can only add more. */
    public const SENSITIVE_PATTERNS = ['password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'credential', 'private_key', 'salt'];

    protected string $extensionAlias = 'qa_capture';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('Runtime switch. Accepts an environment variable: %env(bool:QA_CAPTURE_ENABLED)%.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('allow_prod_env')
                    ->info('Allows loading the bundle with APP_ENV=prod. Only for QA servers that run in prod mode.')
                    ->defaultFalse()
                ->end()
                ->scalarNode('tracker_url')
                    ->info('URL of the tracker as this server reaches it, for example https://tracker.example.com.')
                    ->defaultNull()
                ->end()
                ->scalarNode('project_secret')
                    ->info('Secret key of the project in the tracker. It stays on the server.')
                    ->defaultNull()
                ->end()
                ->arrayNode('allowed_origins')
                    ->info('Origins of the applications under test, for example https://app-qa.example.com.')
                    ->scalarPrototype()->end()
                ->end()
                ->scalarNode('trace_header')
                    ->info('Response header that carries the request identifier.')
                    ->defaultValue('X-QA-Trace-Id')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('path_prefix')
                    ->info('Prefix of the endpoint the capture bar calls to request the context.')
                    ->defaultValue('/_qa')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('cache_pool')
                    ->info('Id of a PSR-6 pool shared between nodes (a Redis one, for example). By default, files in the cache directory.')
                    ->defaultNull()
                ->end()
                ->integerNode('ttl')
                    ->info('Seconds the context of each request is kept.')
                    ->defaultValue(7200)
                    ->min(60)
                ->end()
                ->booleanNode('bind_to_client')
                    ->info('Hands the context only to the browser (IP and user agent) that made the original request.')
                    ->defaultTrue()
                ->end()
                ->integerNode('max_trace_ids')
                    ->info('Maximum number of requests whose context is attached to one report.')
                    ->defaultValue(50)
                    ->min(1)
                ->end()
                ->integerNode('max_claims_per_trace')
                    ->info('Times the context of one request can be attached (several reports from the same screen).')
                    ->defaultValue(20)
                    ->min(1)
                ->end()
                ->arrayNode('logs')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('max_records')
                            ->info('Log records kept per request. When full, the lowest levels are dropped first.')
                            ->defaultValue(300)
                            ->min(1)
                        ->end()
                        ->arrayNode('exclude_channels')
                            ->info('Monolog channels that are not captured.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['event'])
                        ->end()
                        ->booleanNode('sql_params')
                            ->info('Includes the parameters of the SQL queries Doctrine logs. They are unmasked database data.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Tracks the Doctrine entities involved in each request and attaches their rows to the report.')
                            ->defaultTrue()
                        ->end()
                        ->integerNode('max_rows')
                            ->info('Maximum rows per report. Main rows first, then the modified ones, then the ones only read.')
                            ->defaultValue(60)
                            ->min(0)
                        ->end()
                        ->booleanNode('include_read_rows')
                            ->info('Also attaches the rows of entities that were only read (the lines of an invoice, for example).')
                            ->defaultTrue()
                        ->end()
                        ->integerNode('max_entities_per_request')
                            ->info('Entities tracked per request; past the limit, the ones that were only read are no longer tracked.')
                            ->defaultValue(300)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('masking')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('fields')
                            ->info('Key name fragments to mask, in addition to the built-in ones (password, token, secret…).')
                            ->scalarPrototype()->end()
                        ->end()
                        ->arrayNode('entities')
                            ->info('Per entity, fields or columns to mask. Example: App\\Entity\\Customer: [taxId, email].')
                            ->normalizeKeys(false)
                            ->useAttributeAsKey('class')
                            ->arrayPrototype()->scalarPrototype()->end()->end()
                        ->end()
                        ->arrayNode('only')
                            ->info('Per entity, the only fields or columns that are sent; everything else is masked.')
                            ->normalizeKeys(false)
                            ->useAttributeAsKey('class')
                            ->arrayPrototype()->scalarPrototype()->end()->end()
                        ->end()
                        ->arrayNode('exclude_entities')
                            ->info('Entities whose data is never sent; the report keeps only a reference to them.')
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ('prod' === $builder->getParameter('kernel.environment') && true !== $config['allow_prod_env']) {
            throw new \LogicException('QaCaptureBundle exposes debugging data and must not be loaded with APP_ENV=prod. Register it only for your QA environments in config/bundles.php or, if your QA server runs in prod mode, set "qa_capture.allow_prod_env: true".');
        }

        $enabled = $config['enabled'];
        $patterns = array_values(array_unique([...self::SENSITIVE_PATTERNS, ...$config['masking']['fields']]));

        // A bundle must not depend on the application's autowiring or autoconfiguration:
        // each service is defined with its arguments and tags.
        $services = $container->services();

        $services->set('qa_capture.value_normalizer', ValueNormalizer::class)
            ->args([$patterns]);

        $services->set('qa_capture.exception_normalizer', ExceptionNormalizer::class)
            ->args([new Reference('qa_capture.value_normalizer'), '%kernel.project_dir%']);

        $services->set('qa_capture.collector', RequestContextCollector::class)
            ->args([
                new Reference('qa_capture.value_normalizer'),
                new Reference('qa_capture.exception_normalizer'),
                $config['logs']['max_records'],
                $config['doctrine']['max_entities_per_request'],
            ])
            // Essential in long-lived runtimes: the container is reused between requests.
            ->tag('kernel.reset', ['method' => 'reset']);

        if ($config['logs']['enabled']) {
            // With no channel or handler in the tag, MonologBundle applies it to every channel.
            $services->set('qa_capture.log_processor', LogCollectorProcessor::class)
                ->args([new Reference('qa_capture.collector'), $enabled, $config['logs']['exclude_channels'], $config['logs']['sql_params']])
                ->tag('monolog.processor');
        }

        if (null === $config['cache_pool']) {
            $services->set('qa_capture.cache', FilesystemAdapter::class)
                ->args(['qa_capture', 0, '%kernel.cache_dir%/qa_capture']);
        } else {
            $services->alias('qa_capture.cache', $config['cache_pool']);
        }

        $services->set('qa_capture.store', ContextStore::class)
            ->args([new Reference('qa_capture.cache'), $config['ttl'], $config['max_claims_per_trace']]);

        // Its own HTTP client: it does not depend on the application having one configured.
        $services->set('qa_capture.http_client')
            ->class(\Symfony\Contracts\HttpClient\HttpClientInterface::class)
            ->factory([HttpClient::class, 'create']);

        $services->set('qa_capture.tracker_client', TrackerClient::class)
            ->args([new Reference('qa_capture.http_client'), $config['tracker_url'], $config['project_secret']]);

        $services->set('qa_capture.field_masker', FieldMasker::class)
            ->args([
                new Reference('qa_capture.value_normalizer'),
                $config['masking']['entities'],
                $config['masking']['only'],
                $config['masking']['exclude_entities'],
            ]);

        // Doctrine is optional: without DoctrineBundle the report carries the exception and logs, no rows.
        $withDoctrine = $config['doctrine']['enabled'] && \array_key_exists('DoctrineBundle', (array) $builder->getParameter('kernel.bundles'));
        if ($withDoctrine) {
            $services->set('qa_capture.entity_describer', EntityDescriber::class)
                ->args([new Reference('qa_capture.value_normalizer')]);

            $services->set('qa_capture.entity_tracker', EntityTracker::class)
                ->args([
                    new Reference('qa_capture.collector'),
                    new Reference('qa_capture.entity_describer'),
                    new Reference('qa_capture.field_masker'),
                    $enabled,
                    new Reference('doctrine'),
                ])
                ->tag('doctrine.event_listener', ['event' => 'postLoad'])
                ->tag('doctrine.event_listener', ['event' => 'onFlush'])
                ->tag('doctrine.event_listener', ['event' => 'postFlush'])
                ->tag('kernel.event_listener', ['event' => KernelEvents::CONTROLLER_ARGUMENTS, 'method' => 'onControllerArguments', 'priority' => -1024])
                ->tag('kernel.reset', ['method' => 'reset']);

            $services->set('qa_capture.row_snapshotter', RowSnapshotter::class)
                ->args([
                    new Reference('doctrine'),
                    new Reference('qa_capture.field_masker'),
                    $config['doctrine']['max_rows'],
                    $config['doctrine']['include_read_rows'],
                ]);
        }

        $services->set('qa_capture.assembler', ContextAssembler::class)
            ->args([
                new Reference('qa_capture.store'),
                $config['max_trace_ids'],
                $withDoctrine ? new Reference('qa_capture.row_snapshotter') : null,
            ]);

        $services->set('qa_capture.endpoint_listener', EndpointListener::class)
            ->args([
                new Reference('qa_capture.assembler'),
                new Reference('qa_capture.tracker_client'),
                new Reference('qa_capture.store'),
                $enabled,
                $config['path_prefix'],
                $config['allowed_origins'],
                $config['bind_to_client'],
            ])
            // Before everything else (CORS, router, firewall): the endpoint handles itself.
            ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => 8192]);

        $services->set('qa_capture.trace_listener', TraceListener::class)
            ->args([$enabled, $config['trace_header'], $config['allowed_origins']])
            ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => 4096])
            // After NelmioCorsBundle (priority 0), which writes the CORS headers.
            ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'onResponse', 'priority' => -1024]);

        $services->set('qa_capture.context_listener', ContextListener::class)
            ->args([
                new Reference('qa_capture.collector'),
                new Reference('qa_capture.store'),
                $enabled,
                new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => 4095])
            ->tag('kernel.event_listener', ['event' => KernelEvents::EXCEPTION, 'method' => 'onException', 'priority' => 2048])
            ->tag('kernel.event_listener', ['event' => KernelEvents::TERMINATE, 'method' => 'onTerminate', 'priority' => -1024]);
    }
}
