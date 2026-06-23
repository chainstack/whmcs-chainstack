<?php

namespace WHMCS\Module\Server\Chainstack;

/**
 * Thin HTTP client for the Chainstack public API.
 *
 * Uses cURL directly (no external HTTP dependency). All requests send
 * `Authorization: Bearer <key>`.
 */
class ChainstackClient
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $apiKey;

    /** @var int */
    private $timeout;

    public function __construct($baseUrl, $apiKey, $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = (int) $timeout;
    }

    /** Build a client from a WHMCS $params array (reads base URL + API key from the server config). */
    public static function fromParams(array $params)
    {
        return new self(Helpers::baseUrl($params), Helpers::apiKey($params));
    }

    /** List deployable networks: {blockchain, cloud, region, protocol, network}. */
    public function getDeploymentOptions()
    {
        return $this->request('GET', '/v2/deployment-options/');
    }

    /** Return the API key's organization (used by TestConnection). */
    public function getOrganization()
    {
        return $this->request('GET', '/v1/organization/');
    }

    /** Create a project. Body: {name, description, type}. */
    public function createProject(array $body)
    {
        return $this->request('POST', '/v1/projects/', $body);
    }

    public function deleteProject($id)
    {
        return $this->request('DELETE', '/v1/projects/' . rawurlencode($id) . '/');
    }

    /** Create a node. Body: {name, blockchain, cloud, project}. */
    public function createNode(array $body)
    {
        return $this->request('POST', '/v2/nodes/', $body);
    }

    public function getNode($id)
    {
        return $this->request('GET', '/v2/nodes/' . rawurlencode($id) . '/');
    }

    public function listNodes(array $query = [])
    {
        $qs = $query ? ('?' . http_build_query($query)) : '';
        return $this->request('GET', '/v2/nodes/' . $qs);
    }

    public function deleteNode($id)
    {
        return $this->request('DELETE', '/v2/nodes/' . rawurlencode($id) . '/');
    }

    /**
     * @return array Decoded JSON (empty array for 204 No Content).
     * @throws ChainstackApiException on transport error or HTTP >= 400.
     */
    private function request($method, $path, array $body = null)
    {
        $ch = curl_init($this->baseUrl . $path);

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if ($body !== null) {
            $payload = json_encode($body);
            if ($payload === false) {
                curl_close($ch);
                throw new ChainstackApiException('Failed to encode request body: ' . json_last_error_msg());
            }
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new ChainstackApiException('HTTP transport error: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            // A success response that isn't valid JSON is an error worth surfacing clearly.
            if (json_last_error() !== JSON_ERROR_NONE && $status < 400) {
                throw new ChainstackApiException(
                    'Invalid JSON response (HTTP ' . $status . '): ' . json_last_error_msg(),
                    $status,
                    $raw
                );
            }
        }

        if ($status >= 400) {
            $code = null;
            $message = null;
            if (is_array($decoded)) {
                if (isset($decoded['error']) && is_array($decoded['error'])) {
                    // {"error": {"code": "...", "message": "..."}}
                    $code = $decoded['error']['code'] ?? null;
                    $message = $decoded['error']['message'] ?? null;
                } elseif (isset($decoded['detail'])) {
                    $message = is_array($decoded['detail']) ? json_encode($decoded['detail']) : $decoded['detail'];
                } else {
                    $message = json_encode($decoded);
                }
            }
            if ($message === null || $message === '') {
                $message = 'Chainstack API returned HTTP ' . $status;
            }
            throw new ChainstackApiException($message, $status, $raw, $code);
        }

        return is_array($decoded) ? $decoded : [];
    }
}

/** API exception carrying the HTTP status, raw body, and error code. */
class ChainstackApiException extends \Exception
{
    /** @var int */
    public $httpStatus;

    /** @var string|null */
    public $rawBody;

    /** @var string|null e.g. "quota_exceeded" */
    public $errorCode;

    public function __construct($message, $httpStatus = 0, $rawBody = null, $errorCode = null)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->rawBody = $rawBody;
        $this->errorCode = $errorCode;
    }
}
