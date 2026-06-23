<?php

namespace WHMCS\Module\Server\Chainstack;

/**
 * Server-config parsing, per-service state, naming, and error mapping.
 *
 * Per-service state is stored on the WHMCS service model's serviceProperties:
 *   chainstack_project_id, chainstack_node_ids (JSON array), chainstack_status, chainstack_protocol
 */
class Helpers
{
    const KEY_PROJECT = 'chainstack_project_id';
    const KEY_NODES = 'chainstack_node_ids';
    const KEY_STATUS = 'chainstack_status';
    const KEY_PROTOCOL = 'chainstack_protocol';

    // ----- Server config -----------------------------------------------------------

    /** API base URL from the WHMCS server config: hostname + the "SSL Mode" (serversecure) toggle. */
    public static function baseUrl(array $params)
    {
        $host = trim((string) ($params['serverhostname'] ?? '')) ?: 'api.chainstack.com';
        $scheme = !empty($params['serversecure']) ? 'https' : 'http';
        return $scheme . '://' . rtrim($host, '/');
    }

    /** Admin API key from the WHMCS Server password field. */
    public static function apiKey(array $params)
    {
        return (string) ($params['serverpassword'] ?? '');
    }

    // ----- Per-service state -------------------------------------------------------

    public static function getProjectId(array $params)
    {
        return self::propGet($params, self::KEY_PROJECT);
    }

    public static function setProjectId(array $params, $id)
    {
        self::propSet($params, self::KEY_PROJECT, $id);
    }

    /** @return string[] node ids */
    public static function getNodeIds(array $params)
    {
        $raw = self::propGet($params, self::KEY_NODES);
        if (!$raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function appendNodeId(array $params, $nodeId)
    {
        $ids = self::getNodeIds($params);
        if (!in_array($nodeId, $ids, true)) {
            $ids[] = $nodeId;
        }
        self::propSet($params, self::KEY_NODES, json_encode(array_values($ids)));
    }

    public static function removeNodeId(array $params, $nodeId)
    {
        $ids = array_values(array_filter(self::getNodeIds($params), function ($id) use ($nodeId) {
            return $id !== $nodeId;
        }));
        self::propSet($params, self::KEY_NODES, json_encode($ids));
    }

    public static function setStatus(array $params, $status)
    {
        self::propSet($params, self::KEY_STATUS, (string) $status);
    }

    /** Store the deployed protocol (e.g. ethereum, solana) for icon display. */
    public static function setProtocol(array $params, $protocol)
    {
        self::propSet($params, self::KEY_PROTOCOL, (string) $protocol);
    }

    public static function clearState(array $params)
    {
        self::propSet($params, self::KEY_PROJECT, '');
        self::propSet($params, self::KEY_NODES, '');
        self::propSet($params, self::KEY_STATUS, '');
        self::propSet($params, self::KEY_PROTOCOL, '');
    }

    // ----- Error messaging ---------------------------------------------------------

    /** Map an API error to a user-friendly message; falls back to the raw message. */
    public static function friendlyError(\Throwable $e)
    {
        if ($e instanceof ChainstackApiException) {
            switch ($e->errorCode) {
                case 'quota_exceeded':
                    return 'Your Chainstack account has reached its node limit. '
                        . 'Please contact your administrator to increase the quota.';
            }
        }
        return $e->getMessage();
    }

    // ----- Naming ------------------------------------------------------------------

    /** Deterministic project name for a service, e.g. "whmcs-svc-1024". */
    public static function projectName(array $params)
    {
        return 'whmcs-svc-' . (int) ($params['serviceid'] ?? 0);
    }

    /** Node name from the optional template (config option #2), sanitized. */
    public static function nodeName(array $params)
    {
        $base = trim((string) ($params['configoption2'] ?? ''));
        if ($base === '') {
            $base = 'node-svc-' . (int) ($params['serviceid'] ?? 0);
        } else {
            $base = str_replace(
                ['{serviceid}', '{domain}'],
                [(int) ($params['serviceid'] ?? 0), (string) ($params['domain'] ?? '')],
                $base
            );
        }
        $base = preg_replace('/[^A-Za-z0-9\-_]/', '-', $base);
        return substr($base, 0, 64);
    }

    // ----- serviceProperties bridge ------------------------------------------------

    private static function propGet(array $params, $key)
    {
        if (!isset($params['model']) || !is_object($params['model'])) {
            return '';
        }
        // Let read failures propagate — a silent '' could trigger duplicate provisioning.
        return (string) $params['model']->serviceProperties->get($key);
    }

    private static function propSet(array $params, $key, $value)
    {
        if (!isset($params['model']) || !is_object($params['model'])) {
            return;
        }
        // Let write failures propagate — a silently dropped id would orphan a real resource.
        $params['model']->serviceProperties->save([$key => $value]);
    }
}
