<?php

namespace WHMCS\Module\Server\Chainstack;

/**
 * Orchestrates the provisioning lifecycle for a service.
 *
 * One service maps to one project containing one node (endpoint). Operations are idempotent
 * where practical so a retry resumes rather than duplicates.
 */
class Provisioner
{
    /** @var ChainstackClient */
    private $client;

    /** @var array WHMCS $params */
    private $params;

    public function __construct(ChainstackClient $client, array $params)
    {
        $this->client = $client;
        $this->params = $params;
    }

    public static function fromParams(array $params)
    {
        return new self(ChainstackClient::fromParams($params), $params);
    }

    /** Create the project (if missing) and one node. Returns the created node. */
    public function provision()
    {
        // Idempotent re-run: trust a stored node id only if the node still exists on the server.
        // A stale id (node deleted out-of-band) is pruned so we re-create instead of reporting done.
        foreach (Helpers::getNodeIds($this->params) as $nodeId) {
            try {
                $this->client->getNode($nodeId);
                return ['id' => $nodeId, 'status' => 'running', 'already_provisioned' => true];
            } catch (ChainstackApiException $e) {
                if ($e->httpStatus !== 404) {
                    throw $e;
                }
                Helpers::removeNodeId($this->params, $nodeId);
            }
        }

        $projectId = Helpers::getProjectId($this->params);
        $createdProjectThisCall = false;
        if (!$projectId) {
            $project = $this->client->createProject([
                'name' => Helpers::projectName($this->params),
                'description' => 'Provisioned via WHMCS service #' . (int) $this->params['serviceid'],
                'type' => 'public', // the only project type the API accepts
            ]);
            if (empty($project['id'])) {
                throw new ChainstackApiException('Project creation returned no id.');
            }
            $projectId = $project['id'];
            Helpers::setProjectId($this->params, $projectId);
            $createdProjectThisCall = true;
        }

        // On any failure, roll back the node and/or project created in this call so a partial
        // provision can't orphan a billable node or duplicate it on the next retry.
        $node = null;
        try {
            [$blockchain, $cloud] = $this->resolveDeployment();
            $node = $this->client->createNode([
                'name' => Helpers::nodeName($this->params),
                'blockchain' => $blockchain,
                'cloud' => $cloud,
                'project' => $projectId,
            ]);
            if (empty($node['id'])) {
                throw new ChainstackApiException('Node creation returned no id.');
            }
            Helpers::appendNodeId($this->params, $node['id']); // persist the id before anything else
        } catch (\Throwable $e) {
            if (!empty($node['id'])) {
                $this->safeDeleteNode($node['id']);
            }
            if ($createdProjectThisCall) {
                $this->safeDeleteProject($projectId);
                Helpers::setProjectId($this->params, '');
            }
            throw $e;
        }

        // Id is safely recorded; status/protocol are non-critical metadata.
        Helpers::setStatus($this->params, $node['status'] ?? 'pending');
        Helpers::setProtocol($this->params, $node['protocol'] ?? '');

        return $node;
    }

    /** Create an additional node in the existing project. */
    public function addEndpoint()
    {
        $projectId = Helpers::getProjectId($this->params);
        if (!$projectId) {
            throw new ChainstackApiException('No Chainstack project associated with this service.');
        }
        [$blockchain, $cloud] = $this->resolveDeployment();
        $node = $this->client->createNode([
            'name' => Helpers::nodeName($this->params) . '-' . substr(uniqid(), -4),
            'blockchain' => $blockchain,
            'cloud' => $cloud,
            'project' => $projectId,
        ]);
        if (empty($node['id'])) {
            throw new ChainstackApiException('Node creation returned no id.');
        }
        try {
            Helpers::appendNodeId($this->params, $node['id']);
        } catch (\Throwable $e) {
            $this->safeDeleteNode($node['id']); // don't orphan a node we can't record
            throw $e;
        }
        return $node;
    }

    /** Best-effort node delete used for rollback (ignores errors). */
    private function safeDeleteNode($nodeId)
    {
        try {
            $this->client->deleteNode($nodeId);
        } catch (\Throwable $ignore) {
            // best effort; surface the original error to the caller
        }
    }

    /** Best-effort project delete used for rollback (ignores errors). */
    private function safeDeleteProject($projectId)
    {
        try {
            $this->client->deleteProject($projectId);
        } catch (\Throwable $ignore) {
            // best effort; surface the original error to the caller
        }
    }

    /** Delete a single node. Tolerates an already-deleted (404) node and still clears local state. */
    public function deleteEndpoint($nodeId)
    {
        try {
            $this->client->deleteNode($nodeId);
        } catch (ChainstackApiException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }
        }
        Helpers::removeNodeId($this->params, $nodeId);
    }

    /** Delete the node(s), then the project, then clear stored state. */
    public function terminate()
    {
        foreach (Helpers::getNodeIds($this->params) as $nodeId) {
            try {
                $this->client->deleteNode($nodeId);
            } catch (ChainstackApiException $e) {
                if ($e->httpStatus !== 404) { // 404 => already gone
                    throw $e;
                }
            }
        }

        $projectId = Helpers::getProjectId($this->params);
        if ($projectId) {
            try {
                $this->client->deleteProject($projectId);
            } catch (ChainstackApiException $e) {
                if ($e->httpStatus !== 404) {
                    throw $e;
                }
            }
        }

        Helpers::clearState($this->params);
    }

    /** Fetch current node view-models for display. */
    public function fetchNodes()
    {
        $out = [];
        foreach (Helpers::getNodeIds($this->params) as $nodeId) {
            try {
                $n = $this->client->getNode($nodeId);
            } catch (ChainstackApiException $e) {
                if ($e->httpStatus === 404) {
                    Helpers::removeNodeId($this->params, $nodeId); // prune a node deleted out-of-band
                    continue;
                }
                throw $e;
            }
            $details = isset($n['details']) && is_array($n['details']) ? $n['details'] : [];
            $out[] = [
                'id' => $n['id'] ?? $nodeId,
                'name' => $n['name'] ?? '',
                'protocol' => $n['protocol'] ?? '', // drives the displayed icon
                'status' => $n['status'] ?? 'unknown',
                'https' => $details['https_endpoint'] ?? null,
                'wss' => $details['wss_endpoint'] ?? null,
                'beacon' => $details['beacon_endpoint'] ?? null,
                'namespaces' => $details['api_namespaces'] ?? [],
            ];
        }
        return $out;
    }

    /**
     * Resolve {blockchain, cloud} for the selected network. The cloud is derived, not entered:
     * the network slug is looked up live against the deployment options.
     *
     * @return array{0:string,1:string} [blockchain_id, cloud_id]
     */
    private function resolveDeployment()
    {
        $slug = $this->selectedNetworkSlug();
        if ($slug === '') {
            throw new ChainstackApiException('No network configured for this product.');
        }

        $options = $this->client->getDeploymentOptions();
        $list = isset($options['options']) && is_array($options['options']) ? $options['options'] : [];
        foreach ($list as $opt) {
            if (isset($opt['network']) && strcasecmp((string) $opt['network'], $slug) === 0) {
                return [$opt['blockchain'], $opt['cloud']];
            }
        }
        throw new ChainstackApiException("Network '{$slug}' is not available for deployment.");
    }

    /** Selected network slug: the "Network" configurable option if set, else "Default network". */
    private function selectedNetworkSlug()
    {
        $configoptions = $this->params['configoptions'] ?? [];
        if (is_array($configoptions)) {
            foreach (['Network', 'network'] as $key) {
                if (!empty($configoptions[$key])) {
                    return $this->normalizeSlug($configoptions[$key]);
                }
            }
        }
        return $this->normalizeSlug($this->params['configoption1'] ?? '');
    }

    /** Trim and keep the value before any "value|Label" pipe. */
    private function normalizeSlug($value)
    {
        $value = trim((string) $value);
        $pipe = strpos($value, '|');
        if ($pipe !== false) {
            $value = trim(substr($value, 0, $pipe));
        }
        return $value;
    }
}
