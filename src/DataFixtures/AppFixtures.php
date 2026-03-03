<?php

namespace App\DataFixtures;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\User;
use App\Data\InitialMarket;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        echo "Seeding the Lakebird Exchange...\n";

        // Create the ETF (The Lakebird Index)
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Lakebird Index');
        $etf->setPrice('100.00');
        $manager->persist($etf);

        // Loop through Stocks
        foreach (InitialMarket::STOCKS as $stockData) {
            $stock = new Stock();
            $stock->setTicker($stockData['ticker']);
            $stock->setName($stockData['name']);
            $stock->setSector($stockData['sector']);
            $stock->setPrice((string) $stockData['price']);
            $stock->setEarningsPerShare((string) $stockData['eps']);
            $stock->setSharesOutstanding((string) $stockData['shares_outstanding']);
            $stock->setVolatility((string) $stockData['volatility']);
            $stock->setBeta((string) $stockData['beta']);
            $stock->setJumpIntensity((string) $stockData['jump_intensity']);
            $stock->setJumpMean((string) $stockData['jump_mean']);
            $stock->setJumpVol((string) $stockData['jump_vol']);
            
            $manager->persist($stock);
        }

        // Create a Test User
        $user = new User();
        $user->setEmail('trader@lakebird.com');
        $user->setCashBalance('10000.00');
        
        // Hash the password
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);
        
        $manager->persist($user);

        $manager->flush();

        echo "Database successfully seeded!\n";
    }
}