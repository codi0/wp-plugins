<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Adapter\CodiServer;
use CodiMcp\Adapter\DirectMcpPresentation;
use CodiMcp\Core\Abilities\AbilityCatalogue;
use CodiMcp\Core\Abilities\SchemaPresentation;
use CodiMcp\Exposure\ExposurePolicy;
use CodiMcp\Core\Uploads\UploadCapabilityService;
use CodiMcp\Packages\Content\Package as ContentPackage;
use CodiMcp\Packages\Media\Package as MediaPackage;
use CodiMcp\Packages\Database\Package as DatabasePackage;
use CodiMcp\Packages\Diagnostics\Package as DiagnosticsPackage;
use CodiMcp\Packages\Jobs\Package as JobsPackage;
use CodiMcp\Packages\Plugins\Package as PluginsPackage;
use CodiMcp\Packages\Presentation\Package as PresentationPackage;
use CodiMcp\Packages\Site\Package as SitePackage;
use CodiMcp\Packages\System\SystemInspector;
use CodiMcp\Packages\System\SiteHealthTestScope;
use CodiMcp\Packages\System\Package as SystemPackage;
use CodiMcp\Packages\Themes\Package as ThemesPackage;
use CodiMcpTest\Framework\TestCase;

final class DevelopmentAbilityPackagesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('CODI_MCP_ABILITY_PREFIX')) {
            define('CODI_MCP_ABILITY_PREFIX', 'codi');
        }
    }

    public function test_packages_register_only_their_declared_bounded_abilities(): void
    {
        $packages = $this->packages();
        foreach ($packages as $package) {
            $before = array_keys((array) $GLOBALS['codi_mcp_test_registered_abilities']);
            $package->registerCategories();
            $package->registerAbilities();
            $after = array_keys((array) $GLOBALS['codi_mcp_test_registered_abilities']);
            $registered = array_values(array_diff($after, $before));
            $this->assertSame($package->abilityNames(), $registered, get_class($package) . ' declaration must match its registered abilities.');
        }
        $registeredNames = array_keys((array) $GLOBALS['codi_mcp_test_registered_abilities']);
        $this->assertSame(count($registeredNames), count(array_unique($registeredNames)), 'Ability names must be globally unique.');

        foreach ($GLOBALS['codi_mcp_test_registered_abilities'] as $name => $descriptor) {
            $this->assertTrue(is_callable($descriptor['execute_callback'] ?? null), $name . ' must have an execute callback.');
            $this->assertTrue(is_callable($descriptor['permission_callback'] ?? null), $name . ' must have a permission callback.');
            $this->assertSame('object', (string) ($descriptor['input_schema']['type'] ?? ''), $name . ' must have an object input schema.');
            $this->assertArrayHasKey('output_schema', $descriptor, $name . ' must have an output schema.');
            $this->assertWordPressRestSchema((array) $descriptor['input_schema'], $name . ' input');
            $this->assertWordPressRestSchema((array) $descriptor['output_schema'], $name . ' output');
            $meta = is_array($descriptor['meta'] ?? null) ? $descriptor['meta'] : array();
            $this->assertSame(false, $meta['public'] ?? null, $name . ' must explicitly opt out of general public exposure.');
            $this->assertSame(false, $meta['mcp']['public'] ?? null, $name . ' must explicitly opt out of generic MCP exposure.');
            $this->assertSame(false, $meta['show_in_rest'] ?? null, $name . ' must explicitly opt out of REST exposure.');
            $this->assertSame(true, $meta['codi_mcp']['owned'] ?? null, $name . ' must identify itself as Codi-owned.');
            $this->assertTrue((string) ($meta['codi_mcp']['package'] ?? '') !== '', $name . ' must identify its Codi package.');
            foreach (array('readonly', 'destructive', 'idempotent', 'openWorldHint') as $annotation) {
                $this->assertArrayHasKey($annotation, (array) ($meta['annotations'] ?? array()), $name . ' must declare ' . $annotation . '.');
            }
        }

        $modelSurfaceChars = 0;
        foreach ($GLOBALS['codi_mcp_test_registered_abilities'] as $name => $descriptor) {
            $description = trim((string) ($descriptor['description'] ?? ''));
            $this->assertTrue($description !== '', $name . ' must have a model-useful description.');
            $this->assertTrue(strlen($description) <= 220, $name . ' description is too verbose for the advertised tool catalogue.');

            $meta = is_array($descriptor['meta']['codi_mcp'] ?? null) ? $descriptor['meta']['codi_mcp'] : array();
            $modelInput = is_array($meta['model_input_schema'] ?? null)
                ? $meta['model_input_schema']
                : (array) ($descriptor['input_schema'] ?? array());
            $presented = SchemaPresentation::normalize($modelInput);
            $encoded = json_encode($presented, JSON_UNESCAPED_SLASHES);
            $this->assertTrue(is_string($encoded), $name . ' model input schema must serialize.');
            $this->assertFalse(str_contains($encoded, '"pattern":'), $name . ' must not advertise regex validators.');
            $this->assertFalse(str_contains($encoded, '"minLength":'), $name . ' must not advertise lexical length validators.');
            $this->assertFalse(str_contains($encoded, '"maxLength":'), $name . ' must not advertise lexical length validators.');

            $modelSurfaceChars += strlen((string) $name)
                + strlen((string) ($descriptor['label'] ?? ''))
                + strlen($description)
                + strlen($encoded);
        }
        $this->assertTrue(
            $modelSurfaceChars <= 36000,
            'The advertised Codi MCP tool catalogue exceeds the 36k-character model-surface budget.'
        );

        $uploadDescriptor = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/plugin-upload'] ?? array());
        $uploadSchema = (array) ($uploadDescriptor['input_schema'] ?? array());
        $uploadProperties = (array) ($uploadSchema['properties'] ?? array());
        foreach (array('plugin_slug', 'size', 'sha256', 'replace', 'folder') as $field) {
            $this->assertArrayHasKey($field, $uploadProperties);
        }
        $this->assertSame(false, $uploadSchema['additionalProperties']);
        $this->assertSame(array('entire', 'folder', 'file'), $uploadProperties['replace']['enum']);
        $this->assertSame(array('plugin_slug', 'size', 'sha256'), array_values((array) ($uploadSchema['required'] ?? array())));
        $this->assertSame(['upload_id'], $GLOBALS['codi_mcp_test_registered_abilities']['codi/plugin-install']['input_schema']['required']);
        $diagnosticRunInput = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/diagnostic-run']['input_schema']['properties'] ?? array());
        $this->assertArrayHasKey('actor_user_id', $diagnosticRunInput);
        $this->assertArrayHasKey('explain_slow_queries', $diagnosticRunInput);
        $this->assertArrayHasKey('callback_timing', $diagnosticRunInput);
        $diagnosticRunSummary = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/diagnostic-run']['output_schema']['properties']['summary']['properties'] ?? array());
        $this->assertArrayHasKey('correlations', $diagnosticRunSummary);
        $diagnosticInspectSections = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/diagnostic-inspect']['input_schema']['properties']['section']['enum'] ?? array());
        $this->assertTrue(in_array('correlations', $diagnosticInspectSections, true));
        $diagnosticArmInput = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/diagnostic-arm']['input_schema']['properties'] ?? array());
        $this->assertSame(array('GET', 'POST', 'PUT', 'PATCH', 'DELETE'), array_values((array) ($diagnosticArmInput['method']['enum'] ?? array())));
        $this->assertArrayHasKey('explain_slow_queries', $diagnosticArmInput);
        $this->assertArrayHasKey('callback_timing', $diagnosticArmInput);
        $this->assertTrue(isset($GLOBALS['codi_mcp_test_registered_abilities']['codi/jobs-list']), 'Read-only Jobs discovery must not depend on Action Scheduler load order.');
        $this->assertTrue(isset($GLOBALS['codi_mcp_test_registered_abilities']['codi/job-inspect']));
        $this->assertFalse(isset($GLOBALS['codi_mcp_test_registered_abilities']['codi/job-run']));
        $this->assertFalse(isset($GLOBALS['codi_mcp_test_registered_abilities']['codi/job-cancel']));
        $this->assertFalse(isset($uploadProperties['data']));

        $pluginList = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/plugins-list'] ?? array());
        $this->assertSame(array('summary', 'full'), (array) ($pluginList['input_schema']['properties']['detail']['enum'] ?? array()));
        $pluginListItem = (array) ($pluginList['output_schema']['properties']['items']['items'] ?? array());
        $this->assertNotContains('description', (array) ($pluginListItem['required'] ?? array()));
        $this->assertArrayHasKey('description', (array) ($pluginListItem['properties'] ?? array()));

        $themeList = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/themes-list'] ?? array());
        $this->assertSame(array('summary', 'full'), (array) ($themeList['input_schema']['properties']['detail']['enum'] ?? array()));
        $themeListItem = (array) ($themeList['output_schema']['properties']['items']['items'] ?? array());
        $this->assertNotContains('description', (array) ($themeListItem['required'] ?? array()));
        $this->assertArrayHasKey('description', (array) ($themeListItem['properties'] ?? array()));

        $blocksList = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/registered-blocks-list'] ?? array());
        $this->assertSame(array('summary', 'schema'), (array) ($blocksList['input_schema']['properties']['detail']['enum'] ?? array()));
        $blockItem = (array) ($blocksList['output_schema']['properties']['items']['items'] ?? array());
        $this->assertNotContains('attributes', (array) ($blockItem['required'] ?? array()));
        $this->assertArrayHasKey('attributes', (array) ($blockItem['properties'] ?? array()));

        $rolesList = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/roles-list'] ?? array());
        $this->assertArrayHasKey('include_capabilities', (array) ($rolesList['input_schema']['properties'] ?? array()));
        $roleItem = (array) ($rolesList['output_schema']['properties']['items']['items'] ?? array());
        $this->assertContains('capability_count', (array) ($roleItem['required'] ?? array()));
        $this->assertNotContains('capabilities', (array) ($roleItem['required'] ?? array()));

        $cacheInfo = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/cache-info'] ?? array());
        $cacheProperties = (array) ($cacheInfo['output_schema']['properties'] ?? array());
        $this->assertArrayHasKey('dropin_name', $cacheProperties);
        $this->assertArrayHasKey('dropin_version', $cacheProperties);

        $siteHealth = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/site-health'] ?? array());
        $siteHealthInput = (array) ($siteHealth['input_schema']['properties'] ?? array());
        $this->assertArrayHasKey('include_third_party', $siteHealthInput);
        $this->assertSame(false, (bool) ($siteHealthInput['include_good']['default'] ?? true));
        $this->assertSame(false, (bool) ($siteHealthInput['include_third_party']['default'] ?? true));
        $siteHealthProperties = (array) ($siteHealth['output_schema']['properties'] ?? array());
        $this->assertArrayHasKey('unexpected_output', $siteHealthProperties);
        $this->assertSame('array', (string) ($siteHealthProperties['unexpected_output']['type'] ?? ''));
        $this->assertArrayHasKey('third_party_tests_run', $siteHealthProperties);
        $this->assertArrayHasKey('third_party_tests_omitted', $siteHealthProperties);

        $uploadOutput = (array) ($uploadDescriptor['output_schema'] ?? array());
        $uploadOutputProperties = (array) ($uploadOutput['properties'] ?? array());
        foreach (array('upload_id', 'complete', 'upload', 'instruction', 'replace', 'folder') as $field) {
            $this->assertArrayHasKey($field, $uploadOutputProperties);
        }
        $transferProperties = (array) ($uploadOutputProperties['upload']['properties'] ?? array());
        $this->assertSame(array('PUT'), array_values((array) ($transferProperties['method']['enum'] ?? array())));
        $this->assertArrayHasKey('url', $transferProperties);

        $themeUpload = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/theme-upload'] ?? array());
        $themeInput = (array) ($themeUpload['input_schema']['properties'] ?? array());
        foreach (array('stylesheet', 'size', 'sha256', 'replace', 'folder') as $field) {
            $this->assertArrayHasKey($field, $themeInput);
        }
        $this->assertSame(array('entire', 'folder', 'file'), $themeInput['replace']['enum']);
        $themeTransfer = (array) (($themeUpload['output_schema']['properties']['upload']['properties'] ?? array()));
        $this->assertSame(array('PUT'), array_values((array) ($themeTransfer['method']['enum'] ?? array())));
        $this->assertArrayHasKey('url', $themeTransfer);

        $mediaUpload = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/media-upload'] ?? array());
        $mediaInput = (array) ($mediaUpload['input_schema']['properties'] ?? array());
        $this->assertSame(array('filename', 'mime_type', 'size', 'sha256'), array_keys($mediaInput));
        $mediaTransfer = (array) (($mediaUpload['output_schema']['properties']['upload']['properties'] ?? array()));
        $this->assertSame(array('PUT'), array_values((array) ($mediaTransfer['method']['enum'] ?? array())));
        $this->assertArrayHasKey('url', $mediaTransfer);

        $cancelDescriptor = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/upload-cancel'] ?? array());
        $cancelInput = (array) ($cancelDescriptor['input_schema']['properties'] ?? array());
        $this->assertSame(array('upload_id', 'purpose'), array_keys($cancelInput));
        $this->assertSame(array('plugins.install', 'themes.install', 'media.library'), array_values((array) ($cancelInput['purpose']['enum'] ?? array())));

        UploadCapabilityService::registerRoute();
        $this->assertArrayHasKey('codi-mcp/v1/uploads/(?P<purpose>[a-z0-9][a-z0-9._-]{0,99})/(?P<upload_id>[a-f0-9]{32})/(?P<token>[a-f0-9]{64})', (array) $GLOBALS['codi_mcp_test_rest_routes']);

        foreach (array(
            'codi/plugins-list',
            'codi/plugin-info',
            'codi/runtime-info',
            'codi/rest-routes-list',
            'codi/cron-list',
            'codi/rewrite-rules',
            'codi/site-health',
            'codi/error-log-tail',
            'codi/cache-info',
            'codi/roles-list',
            'codi/audit-log',
            'codi/site-config',
            'codi/themes-list',
            'codi/theme-info',
            'codi/post-types-list',
            'codi/taxonomies-list',
            'codi/registered-blocks-list',
            'codi/registered-meta-list',
            'codi/posts-list',
            'codi/registered-meta-get',
            'codi/media-config',
            'codi/media-find',
            'codi/media-inspect',
            'codi/presentation-help',
            'codi/presentation-find',
            'codi/presentation-inspect',
            'codi/presentation-verify',
            'codi/presentation-history',
            'codi/db-tables',
            'codi/db-schema',
            'codi/db-query',
            'codi/options-autoload',
            'codi/diagnostic-inspect',
            'codi/diagnostic-compare',
            'codi/jobs-list',
            'codi/job-inspect',
        ) as $name) {
            $annotations = (array) ($GLOBALS['codi_mcp_test_registered_abilities'][$name]['meta']['annotations'] ?? array());
            $this->assertTrue((bool) ($annotations['readonly'] ?? false), $name . ' must be readonly.');
            $this->assertFalse((bool) ($annotations['destructive'] ?? true), $name . ' must be non-destructive.');
            $this->assertTrue((bool) ($annotations['idempotent'] ?? false), $name . ' must be idempotent.');
        }

        foreach (array(
            'codi/plugin-delete' => false,
            'codi/plugin-update' => false,
            'codi/plugin-restore' => false,
            'codi/cron-run' => false,
            'codi/rewrite-flush' => true,
            'codi/cache-flush' => true,
            'codi/upload-cancel' => true,
            'codi/site-config-update' => true,
            'codi/theme-install' => false,
            'codi/theme-activate' => true,
            'codi/theme-delete' => false,
            'codi/theme-update' => false,
            'codi/theme-restore' => false,
            'codi/term-update' => true,
            'codi/term-delete' => false,
            'codi/post-terms-update' => true,
            'codi/registered-meta-set' => true,
            'codi/registered-meta-delete' => true,
            'codi/post-author-set' => true,
            'codi/post-attributes-update' => true,
            'codi/post-identity-update' => true,
            'codi/post-status-update' => true,
            'codi/post-featured-media-set' => true,
            'codi/post-trash' => true,
            'codi/post-restore' => true,
            'codi/post-delete' => false,
            'codi/media-update' => true,
            'codi/media-delete' => false,
            'codi/presentation-commit' => false,
            'codi/presentation-rollback' => false,
            'codi/diagnostic-run' => false,
            'codi/db-index' => true,
            'codi/options-autoload-set' => true,
        ) as $name => $idempotent) {
            $annotations = (array) ($GLOBALS['codi_mcp_test_registered_abilities'][$name]['meta']['annotations'] ?? array());
            $this->assertFalse((bool) ($annotations['readonly'] ?? true), $name . ' must be mutating.');
            $this->assertTrue((bool) ($annotations['destructive'] ?? false), $name . ' must be destructive.');
            $this->assertSame($idempotent, (bool) ($annotations['idempotent'] ?? false), $name . ' idempotence annotation mismatch.');
        }

        foreach (array('codi/plugin-upload', 'codi/plugin-export', 'codi/theme-upload', 'codi/theme-export', 'codi/media-upload', 'codi/presentation-preview', 'codi/diagnostic-arm') as $name) {
            $annotations = (array) ($GLOBALS['codi_mcp_test_registered_abilities'][$name]['meta']['annotations'] ?? array());
            $this->assertFalse((bool) ($annotations['readonly'] ?? true), $name . ' must create only temporary transfer state.');
            $this->assertFalse((bool) ($annotations['destructive'] ?? true), $name . ' must not mutate installed WordPress state.');
            $this->assertFalse((bool) ($annotations['idempotent'] ?? true), $name . ' creates temporary transfer state and is not idempotent.');
        }

        $termCreateAnnotations = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/term-create']['meta']['annotations'] ?? array());
        $this->assertFalse((bool) ($termCreateAnnotations['readonly'] ?? true), 'codi/term-create must be mutating.');
        $this->assertFalse((bool) ($termCreateAnnotations['destructive'] ?? true), 'codi/term-create must be additive.');
        $this->assertFalse((bool) ($termCreateAnnotations['idempotent'] ?? true), 'codi/term-create is not idempotent.');

        foreach (array('codi/post-create', 'codi/post-duplicate', 'codi/media-create') as $name) {
            $annotations = (array) ($GLOBALS['codi_mcp_test_registered_abilities'][$name]['meta']['annotations'] ?? array());
            $this->assertFalse((bool) ($annotations['readonly'] ?? true), $name . ' must be mutating.');
            $this->assertFalse((bool) ($annotations['destructive'] ?? true), $name . ' must be additive.');
            $this->assertFalse((bool) ($annotations['idempotent'] ?? true), $name . ' is not idempotent.');
        }
    }

    public function test_rest_routes_list_initializes_rest_api_once_when_needed(): void
    {
        $server = new class {
            public array $routes = array();

            public function get_routes(): array
            {
                return $this->routes;
            }

            public function get_namespaces(): array
            {
                return array('wp/v2');
            }
        };
        $GLOBALS['codi_mcp_test_rest_server'] = $server;

        add_action('rest_api_init', static function ($restServer): void {
            $restServer->routes['/wp/v2/codi-live-test'] = array(
                array(
                    'methods' => array('GET' => true),
                    'permission_callback' => static fn (): bool => true,
                ),
            );
        }, 10, 1);

        $inspector = new SystemInspector();
        $first = $inspector->restRoutesList(array('namespace' => 'wp/v2', 'per_page' => 20));

        $this->assertSame(1, did_action('rest_api_init'));
        $this->assertSame(1, (int) ($first['total'] ?? 0));
        $this->assertSame('/wp/v2/codi-live-test', (string) ($first['items'][0]['route'] ?? ''));
        $this->assertTrue((bool) ($first['items'][0]['permission_callbacks_present'] ?? false));

        $second = $inspector->restRoutesList(array('namespace' => 'wp/v2', 'per_page' => 20));

        $this->assertSame(1, did_action('rest_api_init'));
        $this->assertSame(1, (int) ($second['total'] ?? 0));
    }

    public function test_all_new_abilities_are_disabled_until_explicitly_exposed(): void
    {
        foreach ($this->packages() as $package) {
            $package->registerAbilities();
        }

        $catalogue = new AbilityCatalogue();
        $policy = new ExposurePolicy($catalogue);
        $rows = $policy->rows();
        $this->assertCount(count((array) $GLOBALS['codi_mcp_test_registered_abilities']), $rows);
        foreach ($rows as $row) {
            $this->assertSame('codi', (string) ($row['origin'] ?? ''), (string) ($row['name'] ?? '') . ' must be Codi-owned.');
            $this->assertFalse((bool) ($row['enabled'] ?? true), (string) ($row['name'] ?? '') . ' must default disabled.');
        }
        $this->assertSame(array(), $policy->enabledNames());

        $policy->saveSelection(array('codi/plugins-list', 'codi/runtime-info'));
        $selectedPolicy = new ExposurePolicy($catalogue);
        $this->assertSame(array('codi/plugins-list', 'codi/runtime-info'), $selectedPolicy->enabledNames());

        foreach (array(
            'CODI_MCP_SERVER_ID' => 'codi-mcp',
            'CODI_MCP_SERVER_REST_NAMESPACE' => 'mcp',
            'CODI_MCP_SERVER_REST_ROUTE' => 'codi',
            'CODI_MCP_VERSION' => 'test',
        ) as $constant => $value) {
            if (!defined($constant)) {
                define($constant, $value);
            }
        }

        update_option('codi_mcp_exposure_overrides', array(), false);
        $serverPolicy = new ExposurePolicy($catalogue);
        $adapter = new class {
            public array $createArgs = array();
            public function get_server(string $id): mixed { return null; }
            public function create_server(...$args): object { $this->createArgs = $args; return (object) array(); }
        };
        (new CodiServer(new DirectMcpPresentation($catalogue, $serverPolicy)))->register($adapter);
        $this->assertSame(array(), (array) ($adapter->createArgs[9] ?? array()));
        $this->assertSame(array(), (array) ($adapter->createArgs[10] ?? array()));
        $this->assertSame(array(), (array) ($adapter->createArgs[11] ?? array()));
    }

    /** @param array<string,mixed> $schema */
    private function assertWordPressRestSchema(array $schema, string $path): void
    {
        $this->assertFalse(array_key_exists('const', $schema), $path . ' must not use unsupported JSON Schema const.');
        $this->assertArrayHasKey('type', $schema, $path . ' schema node must declare type for WordPress REST validation.');

        foreach ((array) ($schema['properties'] ?? array()) as $name => $child) {
            if (is_array($child)) {
                $this->assertWordPressRestSchema($child, $path . '.properties.' . (string) $name);
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $this->assertWordPressRestSchema($schema['items'], $path . '.items');
        }
        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            $this->assertWordPressRestSchema($schema['additionalProperties'], $path . '.additionalProperties');
        }
        foreach (array('oneOf', 'anyOf', 'allOf') as $keyword) {
            foreach ((array) ($schema[$keyword] ?? array()) as $index => $child) {
                if (is_array($child)) {
                    $this->assertWordPressRestSchema($child, $path . '.' . $keyword . '.' . (string) $index);
                }
            }
        }
    }

    /** @return array<int,object> */
    public function test_site_health_scope_only_treats_current_core_instance_methods_as_core(): void
    {
        $instance = new class {
            public function get_test_core(): array
            {
                return array();
            }

            public function plugin_like_callback(): array
            {
                return array();
            }
        };
        $other = clone $instance;

        $core = SiteHealthTestScope::resolve('core', $instance);
        $this->assertTrue(is_callable($core));
        $this->assertTrue(SiteHealthTestScope::isCore($core, $instance));
        $this->assertFalse(SiteHealthTestScope::isCore(array($other, 'get_test_core'), $instance));
        $this->assertFalse(SiteHealthTestScope::isCore(array($instance, 'plugin_like_callback'), $instance));
        $this->assertFalse(SiteHealthTestScope::isCore(static fn (): array => array(), $instance));
    }

    private function packages(): array
    {
        return array(
            new PluginsPackage(),
            new SystemPackage(),
            new SitePackage(),
            new ThemesPackage(),
            new ContentPackage(),
            new MediaPackage(),
            new PresentationPackage(),
            new DatabasePackage(),
            new DiagnosticsPackage(),
            new JobsPackage(),
        );
    }
}
