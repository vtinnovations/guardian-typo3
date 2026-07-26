<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Domain\Process;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Process\CommandRequest;

final class CommandRequestTest extends TestCase
{
    #[Test]
    public function preservesArgvAsAnArrayWithoutShellConcatenation(): void
    {
        $request = CommandRequest::create(
            ['/usr/bin/php', 'vendor/bin/typo3', 'cache:flush', '--no-interaction'],
            '/var/www',
            300,
        );

        self::assertSame('/usr/bin/php', $request->binary());
        self::assertCount(4, $request->arguments);
        self::assertSame('/var/www', $request->workingDirectory);
        self::assertSame(300, $request->timeoutSeconds);
    }

    #[Test]
    public function emptyArgvIsRejected(): void
    {
        $this->expectException(GuardianException::class);
        CommandRequest::create([]);
    }

    #[Test]
    public function nulByteInArgumentIsRejected(): void
    {
        $this->expectException(GuardianException::class);
        CommandRequest::create(['php', "cache:flush\0; rm -rf /"]);
    }

    #[Test]
    public function nonPositiveTimeoutIsRejected(): void
    {
        $this->expectException(GuardianException::class);
        CommandRequest::create(['php', '-v'], null, 0);
    }

    #[Test]
    public function describeQuotesArgumentsContainingSpacesForDisplayOnly(): void
    {
        $request = CommandRequest::create(['php', 'script.php', 'a b']);

        self::assertSame('php script.php "a b"', $request->describe());
    }

    #[Test]
    public function withEnvReturnsImmutableCopy(): void
    {
        $request = CommandRequest::create(['mysql']);
        $withPassword = $request->withEnv('MYSQL_PWD', 'secret');

        self::assertSame([], $request->env, 'original request must not carry the secret');
        self::assertSame('secret', $withPassword->env['MYSQL_PWD']);
    }
}
