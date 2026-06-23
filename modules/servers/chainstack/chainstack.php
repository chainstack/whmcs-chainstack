<?php
/**
 * WHMCS provisioning module — Chainstack.
 *
 * Each service maps to one Chainstack project containing one node (endpoint), provisioned via the
 * Chainstack public API using an admin API key stored in the WHMCS Server config.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/ChainstackClient.php';
require_once __DIR__ . '/lib/Helpers.php';
require_once __DIR__ . '/lib/Provisioner.php';

use WHMCS\Module\Server\Chainstack\Helpers;
use WHMCS\Module\Server\Chainstack\Provisioner;
use WHMCS\Module\Server\Chainstack\ChainstackClient;

function chainstack_MetaData()
{
    return [
        'DisplayName' => 'Chainstack',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOn' => false,
        'AdminSingleSignOn' => false,
    ];
}

/**
 * Product config options. The network slug is resolved live at provision time and the cloud is
 * derived from it. "Default network" is used unless a "Network" configurable option is set.
 */
function chainstack_ConfigOptions()
{
    return [
        'Default network' => [
            'Type' => 'text',
            'Size' => '30',
            'Description' => 'Network slug to deploy, e.g. ethereum-mainnet or '
                . 'ethereum-sepolia-testnet. Run scripts/list_networks.php for the full list.',
        ],
        'Node name template' => [
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Optional. Supports {serviceid} and {domain}. Default: node-svc-{serviceid}.',
        ],
    ];
}

/** Verify API reachability with the configured key. */
function chainstack_TestConnection(array $params)
{
    try {
        ChainstackClient::fromParams($params)->getOrganization();
        return ['success' => true, 'error' => ''];
    } catch (\Throwable $e) {
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), $e->getMessage(), $e->getTraceAsString());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/** Create the project and node. Endpoints are shown by the client area. */
function chainstack_CreateAccount(array $params)
{
    try {
        $node = Provisioner::fromParams($params)->provision();
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), $node);
        return 'success';
    } catch (\Throwable $e) {
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), $e->getMessage(), $e->getTraceAsString());
        return Helpers::friendlyError($e);
    }
}

/** Delete the node(s) and project. */
function chainstack_TerminateAccount(array $params)
{
    try {
        Provisioner::fromParams($params)->terminate();
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), 'terminated');
        return 'success';
    } catch (\Throwable $e) {
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), $e->getMessage(), $e->getTraceAsString());
        return Helpers::friendlyError($e);
    }
}

/**
 * Suspend tears down the resources (no native node pause). Unsuspend re-provisions, so the
 * endpoint URL changes across a suspend/unsuspend cycle.
 */
function chainstack_SuspendAccount(array $params)
{
    try {
        Provisioner::fromParams($params)->terminate();
        Helpers::setStatus($params, 'suspended');
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), 'suspended');
        return 'success';
    } catch (\Throwable $e) {
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), $e->getMessage(), $e->getTraceAsString());
        return Helpers::friendlyError($e);
    }
}

/** Re-provision fresh resources (new endpoint URL). */
function chainstack_UnsuspendAccount(array $params)
{
    try {
        Provisioner::fromParams($params)->provision();
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), 'unsuspended');
        return 'success';
    } catch (\Throwable $e) {
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), $e->getMessage(), $e->getTraceAsString());
        return Helpers::friendlyError($e);
    }
}

/** Client area: show the service's endpoints with per-protocol icons. */
function chainstack_ClientArea(array $params)
{
    try {
        $nodes = Provisioner::fromParams($params)->fetchNodes();
        // Fallback icon for protocols the CDN doesn't have.
        $genericSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
            . '<circle cx="12" cy="12" r="9" fill="#bbb"/></svg>';
        return [
            'templatefile' => 'clientarea',
            'vars' => [
                'nodes' => $nodes,
                'serviceStatus' => $params['status'] ?? '',
                'iconBase' => 'https://static.chainstack.dev',
                'fallbackIcon' => 'data:image/svg+xml,' . rawurlencode($genericSvg),
            ],
        ];
    } catch (\Throwable $e) {
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), $e->getMessage());
        return [
            'templatefile' => 'clientarea',
            'vars' => ['nodes' => [], 'error' => $e->getMessage()],
        ];
    }
}

/** Admin service tab: show the provisioned resources to staff. */
function chainstack_AdminServicesTabFields(array $params)
{
    try {
        $projectId = Helpers::getProjectId($params);
        $nodes = Provisioner::fromParams($params)->fetchNodes();

        $fields = [];
        $fields['Chainstack Project'] = htmlspecialchars($projectId !== '' ? $projectId : '—');
        if (!$nodes) {
            $fields['Nodes'] = 'None';
        }
        foreach ($nodes as $i => $n) {
            $label = 'Node ' . ($i + 1)
                . ($n['protocol'] !== '' ? ' (' . htmlspecialchars($n['protocol']) . ')' : '');
            $value = '<strong>' . htmlspecialchars($n['id']) . '</strong> — '
                . htmlspecialchars($n['status']);
            if (!empty($n['https'])) {
                $value .= '<br><small>' . htmlspecialchars($n['https']) . '</small>';
            }
            $fields[$label] = $value;
        }
        return $fields;
    } catch (\Throwable $e) {
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), $e->getMessage());
        return ['Chainstack' => 'Error: ' . htmlspecialchars($e->getMessage())];
    }
}

/** Client-area buttons (one endpoint per service, so no create here). */
function chainstack_ClientAreaCustomButtonArray()
{
    return [
        'Refresh Status' => 'refreshStatus',
    ];
}

/** Admin-area buttons. "Create Endpoint" is an admin recovery action. */
function chainstack_AdminCustomButtonArray()
{
    return [
        'Create Endpoint' => 'createEndpoint',
        'Refresh Status' => 'refreshStatus',
    ];
}

/** Create an additional endpoint in the existing project. */
function chainstack_createEndpoint(array $params)
{
    try {
        $node = Provisioner::fromParams($params)->addEndpoint();
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), $node);
        return 'success';
    } catch (\Throwable $e) {
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), $e->getMessage());
        return Helpers::friendlyError($e);
    }
}

/** Refresh the stored node status from the API. */
function chainstack_refreshStatus(array $params)
{
    try {
        $nodes = Provisioner::fromParams($params)->fetchNodes();
        Helpers::setStatus($params, $nodes ? $nodes[0]['status'] : 'unknown');
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), $nodes);
        return 'success';
    } catch (\Throwable $e) {
        logModuleCall('chainstack', __FUNCTION__, chainstack_redact($params), $e->getMessage());
        return Helpers::friendlyError($e);
    }
}

/** Redact the API key before logging. */
function chainstack_redact(array $params)
{
    if (isset($params['serverpassword'])) {
        $params['serverpassword'] = '***REDACTED***';
    }
    return $params;
}
