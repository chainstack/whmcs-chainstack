<?php
/**
 * Chainstack module hooks.
 *
 * Swaps the product-details header icon (drawn by the theme) for the deployed blockchain's icon.
 * WHMCS auto-loads modules/servers/<module>/hooks.php.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/ChainstackClient.php';
require_once __DIR__ . '/lib/Helpers.php';
require_once __DIR__ . '/lib/Provisioner.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Chainstack\Helpers;

/**
 * On the product-details page, replace the theme's generic `.product-icon` with the protocol
 * icon from Chainstack's CDN. Injected via footer output so no theme files are modified.
 */
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (($_REQUEST['action'] ?? '') !== 'productdetails') {
        return '';
    }
    $serviceId = (int) ($_REQUEST['id'] ?? 0);
    if ($serviceId <= 0) {
        return '';
    }

    try {
        $servertype = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->where('tblhosting.id', $serviceId)
            ->value('tblproducts.servertype');
        if ($servertype !== 'chainstack') {
            return '';
        }
        $service = \WHMCS\Service\Service::find($serviceId);
        $protocol = $service ? (string) $service->serviceProperties->get(Helpers::KEY_PROTOCOL) : '';
    } catch (\Throwable $e) {
        return '';
    }
    if ($protocol === '') {
        return '';
    }

    $genericSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
        . '<circle cx="12" cy="12" r="9" fill="#bbb"/></svg>';
    $u = json_encode('https://static.chainstack.dev/' . $protocol . '.svg');
    $f = json_encode('data:image/svg+xml,' . rawurlencode($genericSvg));
    $a = json_encode($protocol);

    return "<script>(function(){function swap(){var el=document.querySelector('.product-icon');"
        . "if(!el){return;}var img=document.createElement('img');img.src=$u;img.alt=$a;"
        . "img.style.height='64px';img.style.width='64px';"
        . "img.onerror=function(){this.onerror=null;this.src=$f;};"
        . "el.innerHTML='';el.appendChild(img);}"
        . "if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',swap);}"
        . "else{swap();}})();</script>";
});
