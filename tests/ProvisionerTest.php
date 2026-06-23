<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Chainstack\Provisioner;
use WHMCS\Module\Server\Chainstack\ChainstackApiException;

final class ProvisionerTest extends TestCase
{
    private function client(): FakeChainstackClient
    {
        $c = new FakeChainstackClient();
        $c->deploymentOptions = ['options' => [
            ['network' => 'ethereum-mainnet', 'blockchain' => 'BC-ETH', 'cloud' => 'CC-G1'],
            ['network' => 'solana-mainnet', 'blockchain' => 'BC-SOL', 'cloud' => 'CC-G1'],
        ]];
        return $c;
    }

    private function params(array $overrides = []): array
    {
        return array_merge([
            'serviceid' => 1001,
            'model' => new FakeServiceModel(),
            'configoption1' => 'ethereum-mainnet', // Default network
            'configoption2' => '',
            'configoptions' => [],
        ], $overrides);
    }

    public function testProvisionCreatesProjectAndNodeWithDerivedCloud(): void
    {
        $c = $this->client();
        $p = $this->params();
        $node = (new Provisioner($c, $p))->provision();

        $this->assertSame('ND-TEST-1', $node['id']);
        $create = $c->firstCall('createNode');
        $this->assertSame('BC-ETH', $create['blockchain']);
        $this->assertSame('CC-G1', $create['cloud']);      // derived, never entered
        $this->assertSame('PR-TEST-1', $create['project']);

        $props = $p['model']->serviceProperties;
        $this->assertSame('PR-TEST-1', $props->get('chainstack_project_id'));
        $this->assertStringContainsString('ND-TEST-1', $props->get('chainstack_node_ids'));
        $this->assertSame('ethereum', $props->get('chainstack_protocol'));
    }

    public function testProvisionIsIdempotentOnRerun(): void
    {
        $c = $this->client();
        $p = $this->params();
        $p['model']->serviceProperties->save(['chainstack_node_ids' => json_encode(['ND-EXISTING'])]);

        $node = (new Provisioner($c, $p))->provision();

        $this->assertTrue($node['already_provisioned'] ?? false);
        $this->assertNull($c->firstCall('createNode'), 'must not create a second node on re-run');
        $this->assertNull($c->firstCall('createProject'));
    }

    public function testResolvesViaConfigurableOptionWithFriendlyPipe(): void
    {
        $c = $this->client();
        $p = $this->params([
            'configoption1' => '',
            'configoptions' => ['Network' => 'solana-mainnet|Solana Mainnet'], // pipe form
        ]);

        (new Provisioner($c, $p))->provision();

        $create = $c->firstCall('createNode');
        $this->assertSame('BC-SOL', $create['blockchain']); // pipe stripped, slug resolved
    }

    public function testUnknownNetworkThrows(): void
    {
        $c = $this->client();
        $p = $this->params(['configoption1' => 'does-not-exist']);
        $this->expectException(ChainstackApiException::class);
        (new Provisioner($c, $p))->provision();
    }

    public function testNodeCreateFailureRollsBackProject(): void
    {
        $c = $this->client();
        $c->failNodeCreate = new ChainstackApiException('limit reached', 403, null, 'quota_exceeded');
        $p = $this->params();

        try {
            (new Provisioner($c, $p))->provision();
            $this->fail('expected ChainstackApiException');
        } catch (ChainstackApiException $e) {
            $this->assertSame('quota_exceeded', $e->errorCode);
        }

        $this->assertNotNull($c->firstCall('deleteProject'), 'orphaned project must be rolled back');
        $this->assertSame('', $p['model']->serviceProperties->get('chainstack_project_id'));
    }

    public function testTerminateDeletesNodesThenProjectAndClearsState(): void
    {
        $c = $this->client();
        $p = $this->params();
        $p['model']->serviceProperties->save([
            'chainstack_project_id' => 'PR-X',
            'chainstack_node_ids' => json_encode(['ND-A', 'ND-B']),
        ]);

        (new Provisioner($c, $p))->terminate();

        $this->assertCount(2, $c->callsNamed('deleteNode'));
        $this->assertNotNull($c->firstCall('deleteProject'));
        $this->assertSame('', $p['model']->serviceProperties->get('chainstack_project_id'));
        $this->assertSame('', $p['model']->serviceProperties->get('chainstack_node_ids'));
    }

    public function testFetchNodesExposesProtocolAndEndpoints(): void
    {
        $c = $this->client();
        $p = $this->params();
        $p['model']->serviceProperties->save(['chainstack_node_ids' => json_encode(['ND-A'])]);

        $nodes = (new Provisioner($c, $p))->fetchNodes();

        $this->assertCount(1, $nodes);
        $this->assertSame('ethereum', $nodes[0]['protocol']);
        $this->assertSame('https://e.example/k', $nodes[0]['https']);
    }

    public function testFetchNodesPrunesStaleNodeOn404(): void
    {
        $c = $this->client();
        $c->missingNodeIds = ['ND-GONE'];
        $p = $this->params();
        $p['model']->serviceProperties->save(['chainstack_node_ids' => json_encode(['ND-GONE'])]);

        $nodes = (new Provisioner($c, $p))->fetchNodes();

        $this->assertCount(0, $nodes);
        $this->assertSame('[]', $p['model']->serviceProperties->get('chainstack_node_ids'));
    }

    public function testProvisionRecreatesWhenStoredNodeIsGone(): void
    {
        $c = $this->client();
        $c->missingNodeIds = ['ND-STALE']; // stored id no longer exists on the server
        $p = $this->params();
        $p['model']->serviceProperties->save(['chainstack_node_ids' => json_encode(['ND-STALE'])]);

        $node = (new Provisioner($c, $p))->provision();

        $this->assertSame('ND-TEST-1', $node['id']);              // re-created, not "already provisioned"
        $this->assertNotNull($c->firstCall('createNode'));
        $this->assertStringContainsString('ND-TEST-1', $p['model']->serviceProperties->get('chainstack_node_ids'));
        $this->assertStringNotContainsString('ND-STALE', $p['model']->serviceProperties->get('chainstack_node_ids'));
    }

    public function testProvisionThrowsWhenCreateReturnsNoId(): void
    {
        $c = $this->client();
        $c->createNodeWithoutId = true;
        $p = $this->params();

        try {
            (new Provisioner($c, $p))->provision();
            $this->fail('expected ChainstackApiException');
        } catch (ChainstackApiException $e) {
            $this->assertStringContainsString('no id', $e->getMessage());
        }
        // the project created this call is rolled back
        $this->assertNotNull($c->firstCall('deleteProject'));
        $this->assertSame('', $p['model']->serviceProperties->get('chainstack_project_id'));
    }

    public function testProvisionRollsBackNodeWhenPersistFails(): void
    {
        $c = $this->client();
        $p = $this->params();
        $p['model']->serviceProperties->throwOnSaveKey = 'chainstack_node_ids'; // persisting the id fails

        try {
            (new Provisioner($c, $p))->provision();
            $this->fail('expected exception');
        } catch (\Throwable $e) {
            // expected
        }
        // the just-created node is deleted so it isn't orphaned, and the project is rolled back
        $this->assertSame('ND-TEST-1', $c->firstCall('deleteNode'));
        $this->assertNotNull($c->firstCall('deleteProject'));
    }
}
