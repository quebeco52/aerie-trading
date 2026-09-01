<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\StockEvent;
use App\Entity\User;
use App\Service\Event\EventPresenter;
use App\Twig\Extension\EventExtension;
use App\Twig\Extension\NumberFormatExtension;
use App\Twig\Extension\WebsocketExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[AllowMockObjectsWithoutExpectations]
class TwigExtensionsTest extends TestCase
{
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

    public function testWebsocketExtensionGeneratesTicketForLoggedInUser(): void
    {
        $securityStub = $this->createStub(Security::class);
        $redisMock = $this->createMock(\Redis::class);

        $user = new User();
        $securityStub->method('getUser')->willReturn($user);

        $redisMock->expects($this->once())
            ->method('setex')
            ->with($this->stringStartsWith('ws_ticket:'), 30, $this->anything());

        $ext = new WebsocketExtension($securityStub, $redisMock);
        $this->assertCount(1, $ext->getFunctions());

        $ticket = $ext->generateTicket();
        $this->assertNotEmpty($ticket);
        $this->assertSame(32, strlen($ticket)); // 16 bytes in hex = 32 chars
    }

    public function testWebsocketExtensionReturnsEmptyWhenNoUser(): void
    {
        $securityStub = $this->createStub(Security::class);
        $redisMock = $this->createMock(\Redis::class);
        $securityStub->method('getUser')->willReturn(null);

        $redisMock->expects($this->never())->method('setex');

        $ext = new WebsocketExtension($securityStub, $redisMock);
        $this->assertSame('', $ext->generateTicket());
    }
}
