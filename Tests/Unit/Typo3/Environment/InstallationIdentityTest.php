<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Typo3\Environment;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use Vtinnovations\GuardianTypo3\Infrastructure\Configuration\SealedRecordStore;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\InMemoryLockFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\RecordPackageFactory;
use Vtinnovations\GuardianTypo3\Tests\Unit\Support\TempWorkingDirectory;
use Vtinnovations\GuardianTypo3\Typo3\Environment\InstallationIdentity;

/**
 * Where the installation's own name is allowed to come from.
 *
 * The point of these cases is that a value a client can set — the `Host` header
 * and its forwarding variants — must never be able to select which entitlement
 * this installation runs under.
 */
final class InstallationIdentityTest extends TestCase
{
    private string $base;
    private SealedRecordStore $store;
    private InstallationIdentity $identity;
    /** @var array<string, mixed> */
    private array $originalSys;

    protected function setUp(): void
    {
        $this->originalSys = $GLOBALS['TYPO3_CONF_VARS']['SYS'] ?? [];
        $this->base = sys_get_temp_dir() . '/guardian-identity-' . bin2hex(random_bytes(6));
        $this->store = new SealedRecordStore(
            new TempWorkingDirectory($this->base),
            (new RecordPackageFactory())->sealedPackage(),
            new InMemoryLockFactory(),
        );
        $this->identity = new InstallationIdentity($this->store);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS'] = $this->originalSys;
        unset($GLOBALS['TYPO3_REQUEST']);
        if (is_dir($this->base)) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * @param array<string, mixed> $serverParams
     */
    private function request(string $host, array $serverParams = []): ServerRequestInterface
    {
        $params = ['HTTP_HOST' => $host, 'HTTPS' => 'on', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php'] + $serverParams;

        // The URI is only ever a fallback; the value under test travels in the
        // header, which is what a client actually controls.
        return (new ServerRequest('https://uri-fallback.invalid/', 'GET', null, [], $params))
            ->withAttribute('normalizedParams', new NormalizedParams(
                $params,
                $GLOBALS['TYPO3_CONF_VARS']['SYS'] ?? [],
                '',
                ''
            ));
    }

    #[Test]
    public function aTrustedHostIsAcceptedAndCanonicalised(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';

        self::assertSame('example.com', $this->identity->resolveFrom($this->request('EXAMPLE.com')));
        self::assertSame('example.com', $this->identity->resolveFrom($this->request('example.com:8443')));
    }

    #[Test]
    public function aHostOutsideTheTrustedPatternYieldsNoIdentity(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = 'example\\.com';

        self::assertSame('example.com', $this->identity->resolveFrom($this->request('example.com')));
        self::assertSame('', $this->identity->resolveFrom($this->request('attacker.test')));
        self::assertSame('', $this->identity->resolveFrom($this->request('www.example.com')));
    }

    #[Test]
    public function anUnsetTrustedPatternDeniesEverythingRatherThanAcceptingAnything(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '';

        self::assertSame('', $this->identity->resolveFrom($this->request('example.com')));

        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern']);
        self::assertSame('', $this->identity->resolveFrom($this->request('example.com')));
    }

    #[Test]
    public function theServerNamePolicyRequiresTheHeaderToMatchTheServerItself(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = 'SERVER_NAME';

        self::assertSame('example.com', $this->identity->resolveFrom($this->request('example.com', [
            'SERVER_NAME' => 'example.com',
            'SERVER_PORT' => '443',
        ])));
        self::assertSame('', $this->identity->resolveFrom($this->request('attacker.test', [
            'SERVER_NAME' => 'example.com',
            'SERVER_PORT' => '443',
        ])));
    }

    #[Test]
    public function aForwardedHostHeaderCannotSelectAnotherIdentityOnItsOwn(): void
    {
        // TYPO3 only honours forwarding headers for configured proxies; with none
        // configured, the header is ignored and the real host stands.
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP'] = '';

        $request = $this->request('example.com', [
            'HTTP_X_FORWARDED_HOST' => 'attacker.test',
            'HTTP_FORWARDED' => 'host=attacker.test',
            'REMOTE_ADDR' => '203.0.113.9',
        ]);

        self::assertSame('example.com', $this->identity->resolveFrom($request));
    }

    #[Test]
    public function aHostThatIsNotAHostYieldsNoIdentity(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';

        // An empty header falls back to the URI host, so it is covered by the
        // "no request" case instead; these are values a client could actually set.
        foreach (['*', '*.example.com', '-bad.example.com', 'exa_mple.com'] as $candidate) {
            self::assertSame('', $this->identity->resolveFrom($this->request($candidate)), $candidate);
        }
    }

    #[Test]
    public function anEstablishedIdentityIsRememberedForLaterNonWebExecution(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';
        $GLOBALS['TYPO3_REQUEST'] = $this->request('example.com');

        // Under the CLI SAPI there is no ambient request, so the value falls back
        // to whatever a previous web request recorded.
        $this->store->rememberVerifiedHost('example.com');
        $fresh = new InstallationIdentity($this->store);

        self::assertSame('example.com', $fresh->current());
    }

    #[Test]
    public function withNoRequestAndNothingRememberedThereIsNoIdentity(): void
    {
        $identity = new InstallationIdentity($this->store);

        self::assertSame('', $identity->current());
        self::assertFalse($identity->isLive());
    }

    #[Test]
    public function theAnswerIsResolvedOncePerExecution(): void
    {
        $this->store->rememberVerifiedHost('example.com');
        $identity = new InstallationIdentity($this->store);

        self::assertSame($identity->current(), $identity->current());
    }
}
