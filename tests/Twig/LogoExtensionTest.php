<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\Extension\LogoExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;

#[Group('unit')]
class LogoExtensionTest extends TestCase
{
    private string $publicDir;

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir() . '/logo-extension-' . bin2hex(random_bytes(4));
        mkdir($this->publicDir . '/icons', 0o777, true);
        touch($this->publicDir . '/icons/LAKE.svg');
        touch($this->publicDir . '/icons/default.svg');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->publicDir . '/icons/*') ?: []);
        rmdir($this->publicDir . '/icons');
        rmdir($this->publicDir);
    }

    public function testACompanyWithALogoGetsItsOwn(): void
    {
        $this->assertSame('/icons/LAKE.svg', $this->extension()->url('LAKE'));
    }

    public function testACompanyWithoutALogoGetsTheGenericMark(): void
    {
        $this->assertSame('/icons/default.svg', $this->extension()->url('PEAC'));
    }

    public function testATickerThatIsNotAFileNameGetsTheGenericMark(): void
    {
        touch($this->publicDir . '/secret.svg');
        try {
            $this->assertSame('/icons/default.svg', $this->extension()->url('../secret'));
            $this->assertSame('/icons/default.svg', $this->extension()->url(''));
        } finally {
            unlink($this->publicDir . '/secret.svg');
        }
    }

    public function testTheFunctionIsRegisteredAsLogoUrl(): void
    {
        $functions = $this->extension()->getFunctions();
        $this->assertCount(1, $functions);
        $this->assertSame('logo_url', $functions[0]->getName());
    }

    private function extension(): LogoExtension
    {
        return new LogoExtension(new Packages(new PathPackage('/', new EmptyVersionStrategy())), $this->publicDir);
    }
}
