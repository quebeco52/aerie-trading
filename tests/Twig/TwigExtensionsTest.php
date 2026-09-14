<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\StockEvent;
use App\Entity\User;
use App\Service\Event\EventPresenter;
use App\Twig\Extension\EventExtension;
use App\Twig\Extension\NumberFormatExtension;
use App\Twig\WebSocketTicketExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[AllowMockObjectsWithoutExpectations]
class TwigExtensionsTest extends TestCase
{
    /** HS256 signing key; the JWT library refuses anything shorter than the hash it signs with. */
    private const TEST_SECRET = 'test-secret-long-enough-for-hs256-signing';

    public function testNumberFormatExtensionFormatsNumbers(): void
    {
        $ext = new NumberFormatExtension();
        $this->assertCount(1, $ext->getFilters());

        $this->assertSame('1.50T', $ext->formatLargeNumber(1_500_000_000_000));
        $this->assertSame('2.50B', $ext->formatLargeNumber(2_500_000_000));
        $this->assertSame('45.20M', $ext->formatLargeNumber(45_200_000));
        $this->assertSame('500.00', $ext->formatLargeNumber(500));
    }

    public function testEventExtensionDelegatesToPresenter(): void
    {
        $presenterMock = $this->createMock(EventPresenter::class);
        $event = new StockEvent();

        $presenterMock->expects($this->once())
            ->method('present')
            ->with($event)
            ->willReturn(['isEarnings' => true, 'badge' => 'EARNINGS BEAT']);

        $ext = new EventExtension($presenterMock);
        $this->assertCount(1, $ext->getFilters());
        $this->assertCount(1, $ext->getFunctions());

        $result = $ext->presentEvent($event);
        $this->assertSame('EARNINGS BEAT', $result['badge']);
    }

    /**
     * The live ticket is a JWT, because bin/websocket-server.php validates it as one. An earlier
     * Redis-ticket extension sat alongside this and returned an empty string for a logged-out
     * visitor, which is what left guests unable to open the stream at all.
     */
    public function testWebSocketTicketIsAJwtCarryingTheUserId(): void
    {
        $user = new User();
        $securityStub = $this->createStub(Security::class);
        $securityStub->method('getUser')->willReturn($user);

        $ticket = (new WebSocketTicketExtension($securityStub, self::TEST_SECRET))->getWsTicket();

        $claims = \Firebase\JWT\JWT::decode($ticket, new \Firebase\JWT\Key(self::TEST_SECRET, 'HS256'));
        $this->assertSame((string) $user->getId(), $claims->uid);
        $this->assertGreaterThan(time(), $claims->exp);
    }

    /**
     * A logged-out visitor still gets a signed ticket, under the "guest" subject. Handing them an
     * empty one closes the stream and the page reads as a dead market rather than a public one.
     */
    public function testGuestsStillReceiveASignedTicket(): void
    {
        $securityStub = $this->createStub(Security::class);
        $securityStub->method('getUser')->willReturn(null);

        $extension = new WebSocketTicketExtension($securityStub, self::TEST_SECRET);
        $ticket = $extension->getWsTicket();

        $this->assertNotSame('', $ticket);
        $this->assertCount(1, $extension->getFunctions());

        $claims = \Firebase\JWT\JWT::decode($ticket, new \Firebase\JWT\Key(self::TEST_SECRET, 'HS256'));
        $this->assertSame('guest', $claims->uid);
    }
}
