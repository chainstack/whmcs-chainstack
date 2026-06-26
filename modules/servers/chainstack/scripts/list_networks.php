<?php
/**
 * List Chainstack networks available for deployment (slug, blockchain, cloud, provider) from the
 * LIVE API. Use the "network" slug as the product's "Default network" config option.
 *
 * Usage (run with your WHMCS PHP binary):
 *   CHAINSTACK_API_KEY=xxx php modules/servers/chainstack/scripts/list_networks.php
 */

define('WHMCS', true);
if (!function_exists('logModuleCall')) { function logModuleCall() {} }

// Module lib lives one directory up (scripts/ -> chainstack/).
require dirname(__DIR__) . '/lib/ChainstackClient.php';

use WHMCS\Module\Server\Chainstack\ChainstackClient;

$key = getenv('CHAINSTACK_API_KEY');
if (!$key) { fwrite(STDERR, "ERROR: set CHAINSTACK_API_KEY\n"); exit(1); }

$client = new ChainstackClient('https://api.chainstack.com', $key);
$resp = $client->getDeploymentOptions();
$opts = isset($resp['options']) && is_array($resp['options']) ? $resp['options'] : [];

usort($opts, function ($a, $b) {
    return strcmp((string) ($a['network'] ?? ''), (string) ($b['network'] ?? ''));
});

printf("%-34s %-16s %-8s %-8s %s\n", 'NETWORK (slug)', 'BLOCKCHAIN', 'CLOUD', 'REGION', 'PROVIDER');
printf("%s\n", str_repeat('-', 90));
foreach ($opts as $o) {
    printf(
        "%-34s %-16s %-8s %-8s %s\n",
        $o['network'] ?? '?',
        $o['blockchain'] ?? '?',
        $o['cloud'] ?? '?',
        $o['region'] ?? '?',
        $o['provider'] ?? '?'
    );
}
echo count($opts) . " networks available\n";
