<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Chainstack\Helpers;
use WHMCS\Module\Server\Chainstack\ChainstackApiException;

final class HelpersTest extends TestCase
{
    public function testFriendlyErrorMapsQuotaExceeded(): void
    {
        $e = new ChainstackApiException('raw api message', 403, null, 'quota_exceeded');
        $this->assertStringContainsString('node limit', Helpers::friendlyError($e));
    }

    public function testFriendlyErrorFallsBackToRawMessage(): void
    {
        $e = new ChainstackApiException('some other error', 500, null, null);
        $this->assertSame('some other error', Helpers::friendlyError($e));
    }

    public function testProjectNameIsDeterministic(): void
    {
        $this->assertSame('whmcs-svc-7', Helpers::projectName(['serviceid' => 7]));
    }

    public function testNodeNameDefault(): void
    {
        $this->assertSame('node-svc-42', Helpers::nodeName(['serviceid' => 42]));
    }

    public function testNodeNameTemplateIsSanitized(): void
    {
        $name = Helpers::nodeName([
            'serviceid' => 42,
            'configoption2' => '{serviceid}-{domain}',
            'domain' => 'a.b',
        ]);
        $this->assertSame('42-a-b', $name); // dots sanitized to hyphens
    }

    public function testBaseUrlSchemeFollowsSslToggle(): void
    {
        $this->assertSame('https://api.chainstack.com', Helpers::baseUrl(['serversecure' => true]));
        $this->assertSame('http://api.chainstack.com', Helpers::baseUrl([]));
    }

    public function testBaseUrlUsesConfiguredHost(): void
    {
        $this->assertSame(
            'https://my.host',
            Helpers::baseUrl(['serverhostname' => 'my.host/', 'serversecure' => true])
        );
    }
}
