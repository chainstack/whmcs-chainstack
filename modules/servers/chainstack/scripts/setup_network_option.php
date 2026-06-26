<?php
/**
 * setup_network_option.php — create/sync the "Network" Configurable Option in WHMCS from the live
 * Chainstack deployment-options list, so a single "Chainstack Node" product can offer a network
 * dropdown. Option values are network SLUGS (matched by the module's live resolution at provision
 * time), priced free (0.00) on creation.
 *
 * Idempotent: safe to re-run. Adds new networks, keeps existing (and their prices) untouched.
 * Does NOT delete networks that disappear from the API — it reports them so you can hide manually.
 *
 * RUN AS YOUR WHMCS WEB/PHP USER (not root) so WHMCS bootstrap does not create root-owned cache
 * files. Example:
 *   [CHAINSTACK_API_KEY=xxx] [PRODUCT_ID=NN] php \
 *     modules/servers/chainstack/scripts/setup_network_option.php
 *
 * Env:
 *   WHMCS_ROOT         WHMCS install root; auto-detected relative to this script if unset
 *   CHAINSTACK_API_KEY optional; auto-detected from the chainstack server if omitted
 *   PRODUCT_ID         optional; links the group to this product id
 */

// This script lives at <whmcs>/modules/servers/chainstack/scripts/, so the WHMCS root is four
// directories up. Override with WHMCS_ROOT if your layout differs.
$whmcsRoot = rtrim(getenv('WHMCS_ROOT') ?: dirname(__DIR__, 4), '/');
require $whmcsRoot . '/init.php';

// WHMCS may already have loaded the module's classes during bootstrap; guard against re-declaring.
if (!class_exists('WHMCS\\Module\\Server\\Chainstack\\ChainstackClient')) {
    require_once dirname(__DIR__) . '/lib/ChainstackClient.php';
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Chainstack\ChainstackClient;

const GROUP_NAME = 'Chainstack Networks';
const OPTION_NAME = 'Network';   // module reads $params['configoptions']['Network']

function out($m) { echo $m . "\n"; }

/**
 * Human-friendly label for a network slug, e.g. ethereum-sepolia-testnet -> "Ethereum Sepolia
 * Testnet", bsc-mainnet -> "BNB Smart Chain Mainnet". Per-token dictionary + title-case fallback.
 */
function labelFor($slug)
{
    static $tokens = [
        'bsc' => 'BNB Smart Chain', 'pos' => 'PoS', 'zkevm' => 'zkEVM', 'zksync' => 'zkSync',
        'opbnb' => 'opBNB', 'ton' => 'TON', 'evm' => 'EVM',
        'mainnet' => 'Mainnet', 'testnet' => 'Testnet', 'sepolia' => 'Sepolia',
        'devnet' => 'Devnet', 'signet' => 'Signet', 'saigon' => 'Saigon', 'nile' => 'Nile',
        'amoy' => 'Amoy', 'chiado' => 'Chiado', 'hoodi' => 'Hoodi',
    ];
    $parts = [];
    foreach (explode('-', $slug) as $t) {
        $parts[] = $tokens[$t] ?? ucfirst($t);
    }
    return implode(' ', $parts);
}

// --- resolve API key (env, else decrypt the chainstack server's stored key) ---
$apiKey = getenv('CHAINSTACK_API_KEY') ?: '';
if ($apiKey === '') {
    $server = Capsule::table('tblservers')->where('type', 'chainstack')->where('disabled', 0)->first();
    if ($server) {
        $dec = localAPI('DecryptPassword', ['password2' => $server->password]);
        $apiKey = $dec['password'] ?? '';
    }
}
if ($apiKey === '') {
    fwrite(STDERR, "ERROR: no API key (set CHAINSTACK_API_KEY or configure a chainstack server)\n");
    exit(1);
}

// --- fetch network slugs from the live API ---
$client = new ChainstackClient('https://api.chainstack.com', $apiKey);
$resp = $client->getDeploymentOptions();
$opts = isset($resp['options']) && is_array($resp['options']) ? $resp['options'] : [];
$slugs = [];
foreach ($opts as $o) {
    if (!empty($o['network'])) { $slugs[(string) $o['network']] = true; }
}
$slugs = array_keys($slugs);
sort($slugs);
out(count($slugs) . ' networks from API');

// --- upsert group ---
$gid = Capsule::table('tblproductconfiggroups')->where('name', GROUP_NAME)->value('id');
if (!$gid) {
    $gid = Capsule::table('tblproductconfiggroups')->insertGetId([
        'name' => GROUP_NAME,
        'description' => 'Chainstack network selection (auto-synced from deployment-options).',
    ]);
    out("created group #$gid");
} else {
    out("group #$gid exists");
}

// --- upsert option (dropdown) ---
$configid = Capsule::table('tblproductconfigoptions')
    ->where('gid', $gid)->where('optionname', OPTION_NAME)->value('id');
if (!$configid) {
    $configid = Capsule::table('tblproductconfigoptions')->insertGetId([
        'gid' => $gid,
        'optionname' => OPTION_NAME,
        'optiontype' => 1,   // 1 = dropdown
        'qtyminimum' => 0,
        'qtymaximum' => 0,
        'order' => 0,
        'hidden' => 0,
    ]);
    out("created option '" . OPTION_NAME . "' #$configid");
} else {
    out("option #$configid exists");
}

// --- currencies ---
$currencies = Capsule::table('tblcurrencies')->pluck('id')->all();

// --- upsert sub-options (one per slug) + free pricing ---
// Map existing sub-options by their SLUG (the part before any "|Friendly Name"), so re-runs
// match and migrate values rather than duplicating them.
$existing = [];
foreach (Capsule::table('tblproductconfigoptionssub')->where('configid', $configid)->get(['id', 'optionname']) as $row) {
    $existing[trim(explode('|', $row->optionname, 2)[0])] = $row->id;
}

$added = 0; $updated = 0; $sort = 0;
foreach ($slugs as $slug) {
    $sort++;
    // WHMCS shows the friendly name; passes the value (slug) before the pipe to the module.
    $value = $slug . '|' . labelFor($slug);
    if (isset($existing[$slug])) {
        $subid = $existing[$slug];
        Capsule::table('tblproductconfigoptionssub')->where('id', $subid)
            ->update(['optionname' => $value, 'hidden' => 0, 'sortorder' => $sort]);
        $updated++;
    } else {
        $subid = Capsule::table('tblproductconfigoptionssub')->insertGetId([
            'configid' => $configid, 'optionname' => $value, 'sortorder' => $sort, 'hidden' => 0,
        ]);
        $added++;
    }
    // Free pricing per currency (only create if missing — never overwrite operator-set prices).
    foreach ($currencies as $cur) {
        $has = Capsule::table('tblpricing')->where('type', 'configoptions')
            ->where('currency', $cur)->where('relid', $subid)->exists();
        if (!$has) {
            Capsule::table('tblpricing')->insert([
                'type' => 'configoptions', 'currency' => $cur, 'relid' => $subid,
                'msetupfee' => 0, 'qsetupfee' => 0, 'ssetupfee' => 0,
                'asetupfee' => 0, 'bsetupfee' => 0, 'tsetupfee' => 0,
                'monthly' => 0, 'quarterly' => 0, 'semiannually' => 0,
                'annually' => 0, 'biennially' => 0, 'triennially' => 0,
            ]);
        }
    }
}
out("sub-options: +$added added, $updated updated");

// --- report stale (present in WHMCS but no longer offered by the API) ---
$stale = array_diff(array_keys($existing), $slugs);
if ($stale) {
    out('NOTE: ' . count($stale) . ' option(s) no longer offered by the API (left untouched; hide '
        . 'manually if unwanted): ' . implode(', ', $stale));
}

// --- optional: link the group to a product ---
$pid = (int) getenv('PRODUCT_ID');
if ($pid > 0) {
    $linked = Capsule::table('tblproductconfiglinks')->where('gid', $gid)->where('pid', $pid)->exists();
    if (!$linked) {
        Capsule::table('tblproductconfiglinks')->insert(['gid' => $gid, 'pid' => $pid]);
        out("linked group #$gid to product #$pid");
    } else {
        out("group already linked to product #$pid");
    }
} else {
    out("To use it: attach the '" . GROUP_NAME . "' configurable option group to your 'Chainstack "
        . "Node' product (Products/Services > edit > Configurable Options), or re-run with PRODUCT_ID=<id>.");
}

out('Done.');
