<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\Auth;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use InterServer\Mcp\Core\Auth\IntrospectionClient;
use InterServer\Mcp\Core\Auth\IntrospectionTokenValidator;
use InterServer\Mcp\Core\Profile\Profile;
use PHPUnit\Framework\TestCase;

/**
 * @covers \InterServer\Mcp\Core\Auth\IntrospectionTokenValidator
 * @covers \InterServer\Mcp\Core\Auth\AuthContext
 */
final class IntrospectionTokenValidatorTest extends TestCase
{
    private const CLIENT_RESOURCE = 'https://mcp.interserver.net/client';
    private const PUBLIC_RESOURCE = 'https://mcp.interserver.net/public';
    private const ADMIN_RESOURCE = 'https://adminmcp.interserver.net/';

    /**
     * @param array<string, mixed> $introspection
     */
    private function validator(Profile $profile, array $introspection, int $status = 200): IntrospectionTokenValidator
    {
        // Every call re-introspects, and the validator introspects twice when a
        // test asks for both validate() and contextFor(). Queue enough responses.
        $responses = array_fill(0, 8, new Response($status, [], (string) json_encode($introspection)));

        return new IntrospectionTokenValidator(
            new IntrospectionClient(
                endpoint: 'https://my.test/introspect',
                clientId: 'app',
                clientSecret: 'secret',
                http: new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]),
            ),
            $profile,
        );
    }

    private static function clientProfile(): Profile
    {
        return new Profile(
            name: 'client',
            specSource: '/dev/null',
            upstreamBaseUrl: 'https://my.test/apiv2',
            serverName: 'InterServer Client API',
            resourceIdentifiers: [self::CLIENT_RESOURCE, self::PUBLIC_RESOURCE],
        );
    }

    private static function adminProfile(): Profile
    {
        return new Profile(
            name: 'admin',
            specSource: '/dev/null',
            upstreamBaseUrl: 'https://my.test/apiv2',
            serverName: 'InterServer Admin API',
            requiredScope: 'admin',
            requiresAdminTier: true,
            resourceIdentifiers: [self::ADMIN_RESOURCE],
        );
    }

    // ------------------------------------------------------------------ happy

    public function testAValidClientTokenIsAllowed(): void
    {
        $result = $this->validator(self::clientProfile(), [
            'active' => true, 'scope' => 'vps billing', 'sub' => '42', 'aud' => self::CLIENT_RESOURCE,
        ])->validate('tok');

        self::assertTrue($result->isAllowed());
        self::assertSame(['vps', 'billing'], $result->getAttributes()['oauth.scopes']);
        self::assertSame('42', $result->getAttributes()['oauth.subject']);
    }

    public function testAValidAdminTokenIsAllowed(): void
    {
        $result = $this->validator(self::adminProfile(), [
            'active' => true, 'scope' => 'admin', 'sub' => '1',
            'aud' => self::ADMIN_RESOURCE, 'ext' => ['ima' => 'admin'],
        ])->validate('tok');

        self::assertTrue($result->isAllowed());
        self::assertSame('admin', $result->getAttributes()['oauth.ima']);
    }

    public function testAnInactiveTokenIsUnauthorized(): void
    {
        $result = $this->validator(self::clientProfile(), ['active' => false])->validate('tok');

        self::assertFalse($result->isAllowed());
        self::assertSame(401, $result->getStatusCode());
        self::assertSame('invalid_token', $result->getError());
    }

    // ------------------------------------------------- cross-surface rejection

    /**
     * The test the migration plan singles out. Two resource servers on two hosts
     * share one authorization server; RFC 8707 audience binding is the only thing
     * that stops a token minted for one being replayed at the other. Getting this
     * wrong hands ~445 admin tools to a client-tier credential, silently.
     */
    public function testAnAdminAudienceTokenIsRejectedByTheClientServer(): void
    {
        $result = $this->validator(self::clientProfile(), [
            'active' => true, 'scope' => 'admin', 'sub' => '1',
            'aud' => self::ADMIN_RESOURCE, 'ext' => ['ima' => 'admin'],
        ])->validate('tok');

        self::assertFalse($result->isAllowed(), 'an admin-audience token must not be accepted by the client server');
        self::assertSame(401, $result->getStatusCode());
    }

    public function testAClientAudienceTokenIsRejectedByTheAdminServer(): void
    {
        $result = $this->validator(self::adminProfile(), [
            'active' => true, 'scope' => 'admin', 'sub' => '42',
            'aud' => self::CLIENT_RESOURCE, 'ext' => ['ima' => 'admin'],
        ])->validate('tok');

        self::assertFalse($result->isAllowed(), 'a client-audience token must not be accepted by the admin server');
        self::assertSame(401, $result->getStatusCode());
    }

    /**
     * A rejected audience must be indistinguishable from an invalid token. Saying
     * "right token, wrong server" tells a caller holding an admin token that an
     * admin server exists and that this is not it.
     */
    public function testAWrongAudienceIsIndistinguishableFromAnInvalidToken(): void
    {
        $wrongAudience = $this->validator(self::clientProfile(), [
            'active' => true, 'scope' => 'admin', 'aud' => self::ADMIN_RESOURCE,
        ])->validate('tok');

        $inactive = $this->validator(self::clientProfile(), ['active' => false])->validate('tok');

        self::assertSame($inactive->getStatusCode(), $wrongAudience->getStatusCode());
        self::assertSame($inactive->getError(), $wrongAudience->getError());
    }

    public function testAudienceIsCheckedBeforeScope(): void
    {
        // Scope would pass; audience must still refuse, and with a 401 rather than
        // the 403 an insufficient-scope refusal would produce.
        $result = $this->validator(self::adminProfile(), [
            'active' => true, 'scope' => 'admin', 'aud' => self::CLIENT_RESOURCE, 'ext' => ['ima' => 'admin'],
        ])->validate('tok');

        self::assertSame(401, $result->getStatusCode());
    }

    public function testATokenWithNoAudienceIsRejectedWhenIdentifiersAreDeclared(): void
    {
        $result = $this->validator(self::clientProfile(), ['active' => true, 'scope' => 'vps'])->validate('tok');

        self::assertFalse($result->isAllowed());
    }

    public function testAnArrayAudienceIsAcceptedIfAnyEntryMatches(): void
    {
        $result = $this->validator(self::clientProfile(), [
            'active' => true, 'scope' => 'vps', 'aud' => ['https://elsewhere.test/', self::CLIENT_RESOURCE],
        ])->validate('tok');

        self::assertTrue($result->isAllowed());
    }

    public function testAnArrayAudienceWithNoMatchIsRejected(): void
    {
        $result = $this->validator(self::clientProfile(), [
            'active' => true, 'scope' => 'vps', 'aud' => ['https://elsewhere.test/', self::ADMIN_RESOURCE],
        ])->validate('tok');

        self::assertFalse($result->isAllowed());
    }

    public function testEitherOfAProfilesIdentifiersIsAccepted(): void
    {
        foreach ([self::CLIENT_RESOURCE, self::PUBLIC_RESOURCE] as $audience) {
            $result = $this->validator(self::clientProfile(), [
                'active' => true, 'scope' => 'vps', 'aud' => $audience,
            ])->validate('tok');

            self::assertTrue($result->isAllowed(), "audience {$audience} should be accepted");
        }
    }

    /**
     * An audience that merely shares a prefix is a different resource. Claude
     * matches resource identifiers literally and so must we.
     */
    public function testAudienceMatchingIsExactNotPrefixBased(): void
    {
        $result = $this->validator(self::clientProfile(), [
            'active' => true, 'scope' => 'vps', 'aud' => self::CLIENT_RESOURCE.'/extra',
        ])->validate('tok');

        self::assertFalse($result->isAllowed());
    }

    // ---------------------------------------------------------- scope + tier

    public function testAMissingRequiredScopeIsForbiddenWithAStepUpChallenge(): void
    {
        $result = $this->validator(self::adminProfile(), [
            'active' => true, 'scope' => 'vps billing', 'aud' => self::ADMIN_RESOURCE, 'ext' => ['ima' => 'admin'],
        ])->validate('tok');

        self::assertFalse($result->isAllowed());
        self::assertSame(403, $result->getStatusCode());
        self::assertSame('insufficient_scope', $result->getError());
        self::assertSame(['admin'], $result->getScopes());
    }

    /**
     * A token can carry the `admin` scope without the account behind it being an
     * administrator, so the tier is checked against the account — not the grant.
     * This is the check the current admin server does with two hand-written
     * queries against `account_security` and `sessions`.
     */
    public function testAnAdminScopedTokenOnANonAdminAccountIsRefused(): void
    {
        $result = $this->validator(self::adminProfile(), [
            'active' => true, 'scope' => 'admin', 'aud' => self::ADMIN_RESOURCE, 'ext' => ['ima' => 'client'],
        ])->validate('tok');

        self::assertFalse($result->isAllowed());
        self::assertSame(403, $result->getStatusCode());
    }

    public function testAMissingImaIsTreatedAsNotAdmin(): void
    {
        $result = $this->validator(self::adminProfile(), [
            'active' => true, 'scope' => 'admin', 'aud' => self::ADMIN_RESOURCE,
        ])->validate('tok');

        self::assertFalse($result->isAllowed());
        self::assertSame(403, $result->getStatusCode());
    }

    /**
     * The client server must never receive an admin flag — the authorization
     * server gates `ext.ima` on the introspecting client id. Even if one leaked
     * through, the client profile does not gate on the tier, so it changes nothing.
     */
    public function testTheClientProfileDoesNotGateOnTier(): void
    {
        $result = $this->validator(self::clientProfile(), [
            'active' => true, 'scope' => 'vps', 'aud' => self::CLIENT_RESOURCE,
        ])->validate('tok');

        self::assertTrue($result->isAllowed());
    }

    public function testAProfileWithNoIdentifiersAcceptsAnyAudience(): void
    {
        $local = new Profile(
            name: 'local', specSource: '/dev/null',
            upstreamBaseUrl: 'https://my.test/apiv2', serverName: 'local',
        );

        $result = $this->validator($local, ['active' => true, 'scope' => 'vps'])->validate('tok');

        self::assertTrue($result->isAllowed());
    }

    // ------------------------------------------------------------ contextFor

    public function testContextForCarriesTheCallersOwnToken(): void
    {
        $context = $this->validator(self::clientProfile(), [
            'active' => true, 'scope' => 'vps billing', 'sub' => '42',
            'aud' => self::CLIENT_RESOURCE, 'client_id' => 'claude',
        ])->contextFor('the-token');

        self::assertTrue($context->authenticated);
        self::assertSame(['vps', 'billing'], $context->scopes);
        self::assertSame('42', $context->subject);
        self::assertSame('claude', $context->clientId);
        self::assertSame('Bearer the-token', $context->upstreamHeaders()['Authorization']);
    }

    public function testContextForAWrongAudienceIsAnonymous(): void
    {
        $context = $this->validator(self::clientProfile(), [
            'active' => true, 'scope' => 'admin', 'aud' => self::ADMIN_RESOURCE,
        ])->contextFor('tok');

        self::assertFalse($context->authenticated);
        self::assertSame([], $context->scopes);
        self::assertNull($context->bearerToken);
    }

    public function testContextForAnInactiveTokenIsAnonymous(): void
    {
        $context = $this->validator(self::clientProfile(), ['active' => false])->contextFor('tok');

        self::assertFalse($context->authenticated);
    }

    public function testContextCarriesTheAdminTier(): void
    {
        $context = $this->validator(self::adminProfile(), [
            'active' => true, 'scope' => 'admin', 'aud' => self::ADMIN_RESOURCE, 'ext' => ['ima' => 'admin'],
        ])->contextFor('tok');

        self::assertTrue($context->isAdmin());
    }

    public function testAClientContextIsNeverAdmin(): void
    {
        $context = $this->validator(self::clientProfile(), [
            'active' => true, 'scope' => 'vps', 'aud' => self::CLIENT_RESOURCE,
        ])->contextFor('tok');

        self::assertFalse($context->isAdmin());
        self::assertNull($context->ima);
    }

    // ------------------------------------------------------- scope parsing

    public function testAnArrayScopeClaimIsAccepted(): void
    {
        $result = $this->validator(self::clientProfile(), [
            'active' => true, 'scope' => ['vps', 'billing'], 'aud' => self::CLIENT_RESOURCE,
        ])->validate('tok');

        self::assertSame(['vps', 'billing'], $result->getAttributes()['oauth.scopes']);
    }

    public function testAnAbsentScopeClaimYieldsNoScopes(): void
    {
        $context = $this->validator(self::clientProfile(), [
            'active' => true, 'aud' => self::CLIENT_RESOURCE,
        ])->contextFor('tok');

        self::assertSame([], $context->scopes);
        self::assertSame('', $context->scopeString());
    }
}
