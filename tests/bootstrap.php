<?php
/**
 * PHPUnit bootstrap: load the module's classes (no WHMCS install needed) and define test doubles.
 */

if (!defined('WHMCS')) {
    define('WHMCS', true);
}
if (!function_exists('logModuleCall')) {
    function logModuleCall() {} // no-op stub for tests
}

$lib = getenv('CS_LIB') ?: dirname(__DIR__) . '/modules/servers/chainstack/lib';
require_once $lib . '/ChainstackClient.php';
require_once $lib . '/Helpers.php';
require_once $lib . '/Provisioner.php';

use WHMCS\Module\Server\Chainstack\ChainstackClient;
use WHMCS\Module\Server\Chainstack\ChainstackApiException;

/**
 * In-memory stand-in for the WHMCS service model's serviceProperties.
 */
class FakeServiceProperties
{
    private array $data = [];
    /** If set, save() throws when this key is written (to simulate a persistence failure). */
    public ?string $throwOnSaveKey = null;
    public function get($key) { return $this->data[$key] ?? ''; }
    public function save(array $kv)
    {
        foreach ($kv as $k => $v) {
            if ($this->throwOnSaveKey !== null && $k === $this->throwOnSaveKey) {
                throw new \RuntimeException('save failed for ' . $k);
            }
            $this->data[$k] = $v;
        }
    }
}

class FakeServiceModel
{
    public FakeServiceProperties $serviceProperties;
    public function __construct() { $this->serviceProperties = new FakeServiceProperties(); }
}

/**
 * ChainstackClient test double: records calls, returns canned data, no HTTP.
 */
class FakeChainstackClient extends ChainstackClient
{
    /** @var array<int,array{name:string,arg:mixed}> */
    public array $calls = [];
    public array $deploymentOptions = ['options' => []];
    public ?ChainstackApiException $failNodeCreate = null;
    /** Node ids that getNode() should report as 404 (deleted out-of-band). */
    public array $missingNodeIds = [];
    /** When true, createNode() returns a body with no id. */
    public bool $createNodeWithoutId = false;

    public function __construct() { parent::__construct('https://api.test', 'key'); }

    public function getDeploymentOptions()
    {
        $this->calls[] = ['name' => 'getDeploymentOptions', 'arg' => null];
        return $this->deploymentOptions;
    }

    public function createProject(array $body)
    {
        $this->calls[] = ['name' => 'createProject', 'arg' => $body];
        return array_merge(['id' => 'PR-TEST-1'], $body);
    }

    public function deleteProject($id)
    {
        $this->calls[] = ['name' => 'deleteProject', 'arg' => $id];
        return [];
    }

    public function createNode(array $body)
    {
        $this->calls[] = ['name' => 'createNode', 'arg' => $body];
        if ($this->failNodeCreate) {
            throw $this->failNodeCreate;
        }
        $node = [
            'id' => 'ND-TEST-1', 'name' => 'test-node', 'protocol' => 'ethereum', 'status' => 'running',
            'details' => [
                'https_endpoint' => 'https://e.example/k',
                'wss_endpoint' => 'wss://e.example/k',
                'api_namespaces' => ['eth'],
            ],
        ];
        if ($this->createNodeWithoutId) {
            unset($node['id']);
        }
        return $node;
    }

    public function getNode($id)
    {
        $this->calls[] = ['name' => 'getNode', 'arg' => $id];
        if (in_array($id, $this->missingNodeIds, true)) {
            throw new ChainstackApiException('not found', 404);
        }
        return [
            'id' => $id, 'name' => 'test-node', 'protocol' => 'ethereum', 'status' => 'running',
            'details' => ['https_endpoint' => 'https://e.example/k'],
        ];
    }

    public function deleteNode($id)
    {
        $this->calls[] = ['name' => 'deleteNode', 'arg' => $id];
        return [];
    }

    public function listNodes(array $query = [])
    {
        $this->calls[] = ['name' => 'listNodes', 'arg' => $query];
        return [];
    }

    /** First recorded arg for a call name, or null. */
    public function firstCall(string $name)
    {
        foreach ($this->calls as $c) {
            if ($c['name'] === $name) {
                return $c['arg'];
            }
        }
        return null;
    }

    /** All recorded args for a call name. */
    public function callsNamed(string $name): array
    {
        return array_values(array_map(
            fn ($c) => $c['arg'],
            array_filter($this->calls, fn ($c) => $c['name'] === $name)
        ));
    }
}
