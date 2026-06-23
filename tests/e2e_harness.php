<?php
/**
 * End-to-end harness for the Chainstack WHMCS module — runs the real module functions against the
 * live API using an in-memory service model, then cleans up. No WHMCS bootstrap required.
 *
 * Tests: resolveDeployment (network slug -> blockchain + DERIVED cloud), createProject(type=public),
 *        createNode, ClientArea fetch, and TerminateAccount cleanup.
 *
 * Usage (run with your WHMCS PHP binary, from the repo root):
 *   CHAINSTACK_API_KEY=xxx [NET=ethereum-sepolia-testnet] php tests/e2e_harness.php
 *
 * Creates and then deletes one real node + project. Use a testnet slug to avoid mainnet cost.
 */

define('WHMCS', true);
if (!function_exists('logModuleCall')) { function logModuleCall() {} }

// Resolve the module entry point relative to this file (tests/ -> repo root).
require dirname(__DIR__) . '/modules/servers/chainstack/chainstack.php';

// --- minimal in-memory stand-in for WHMCS service model serviceProperties ---
class FakeServiceProperties
{
    private $data = [];
    public function get($key) { return $this->data[$key] ?? ''; }
    public function save(array $kv) { foreach ($kv as $k => $v) { $this->data[$k] = $v; } }
}
class FakeServiceModel
{
    public $serviceProperties;
    public function __construct() { $this->serviceProperties = new FakeServiceProperties(); }
}

$key = getenv('CHAINSTACK_API_KEY');
if (!$key) { fwrite(STDERR, "ERROR: set CHAINSTACK_API_KEY\n"); exit(1); }
$net = getenv('NET') ?: 'ethereum-sepolia-testnet';

$model = new FakeServiceModel();
$params = [
    'serviceid'      => 999001,
    'model'          => $model,
    'serverhostname' => 'api.chainstack.com',
    'serversecure'   => true,
    'serverpassword' => $key,
    'domain'         => 'harness.test',
    'configoption1'  => $net,   // "Default network" (slug) -> resolved live via deployment-options
    'configoption2'  => '',     // node name template (blank -> default)
    'configoptions'  => [],     // no customer Configurable Option in this harness
];

$line = str_repeat('-', 60);
echo "Network slug: $net\n$line\n";

try {
    echo "CreateAccount -> " . chainstack_CreateAccount($params) . "\n";
    echo "  stored project = " . $model->serviceProperties->get('chainstack_project_id') . "\n";
    echo "  stored nodes   = " . $model->serviceProperties->get('chainstack_node_ids') . "\n$line\n";

    $ca = chainstack_ClientArea($params);
    $urlBefore = $ca['vars']['nodes'][0]['https'] ?? null;
    echo "ClientArea https (before) = $urlBefore\n$line\n";

    echo "SuspendAccount -> " . chainstack_SuspendAccount($params) . "\n";
    $caSusp = chainstack_ClientArea($params);
    echo "  nodes after suspend = " . count($caSusp['vars']['nodes'] ?? []) . " (expect 0)\n";
    echo "  project after suspend = '" . $model->serviceProperties->get('chainstack_project_id') . "'\n$line\n";

    echo "UnsuspendAccount -> " . chainstack_UnsuspendAccount($params) . "\n";
    $caUns = chainstack_ClientArea($params);
    $urlAfter = $caUns['vars']['nodes'][0]['https'] ?? null;
    echo "  ClientArea https (after) = $urlAfter\n";
    echo "  URL changed across suspend/unsuspend? " . ($urlBefore !== $urlAfter ? 'YES (expected)' : 'no') . "\n$line\n";
} finally {
    // Always attempt teardown so a failure mid-run can't leave billable resources behind.
    echo "TerminateAccount -> " . chainstack_TerminateAccount($params) . "\n";
    echo "  project after terminate = '" . $model->serviceProperties->get('chainstack_project_id') . "'\n";
    echo "  nodes after terminate   = '" . $model->serviceProperties->get('chainstack_node_ids') . "'\n$line\n";
}
echo "Done. (If terminate succeeded, no resources remain.)\n";
