<?php

namespace App\Command;

use App\Entity\Etf;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Entity\User;
use App\Data\InitialMarket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Service\Market\OpeningBoardBuilder;

#[AsCommand(
    name: 'app:market-seed',
    description: 'Seeds the production database with initial market data.',
)]
class MarketSeedCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private \App\Service\Market\TreasuryAuctionService $treasuryAuction,
        private OpeningBoardBuilder $openingBoard
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding the Aerie Exchange (Production)');

        // The board is priced against the economy the macro engine opens in, so the first tick does not revalue it.
        $openingMacro = new MacroStateDTO();

        // Loop through ETFs
        foreach (InitialMarket::ETFS as $etfData) {
            $etf = $this->entityManager->getRepository(Etf::class)->findOneBy(['ticker' => $etfData['ticker']]);
            if (!$etf) {
                $etf = new Etf();
                $etf->setTicker($etfData['ticker']);
                $etf->setPrice((string) $etfData['price']);
            }
            $etf->setName($etfData['name']);
            $etf->setDescription(\App\Data\StockInfo::DESCRIPTIONS[$etfData['ticker']] ?? null);
            // The fee is a property of the fund, not of a run, so it is re-applied on every seed. Its books
            // — the basket it still owns and the income it is holding — are NOT touched here: those are
            // accumulated history, and a reseed is not a liquidation.
            $etf->setExpenseRatio((float) ($etfData['expense_ratio'] ?? 0.0));
            // Only on a fund that has never had a share count. Creations and redemptions move it after
            // that, and a reseed is not a liquidation — see the note on its other books above.
            if ($etf->getSharesOutstanding() <= 0.0) {
                $etf->setSharesOutstanding(\App\Service\Math\FinancialConstants::ETF_SEED_SHARES_OUTSTANDING);
            }
            $this->entityManager->persist($etf);
        }

        // Every listed firm, kept so the anchor spheres can be marked against the finished board below.
        $seeded = [];
        $spheres = [];

        foreach (InitialMarket::STOCKS as $stockData) {
            $stock = $this->entityManager->getRepository(Stock::class)->findOneBy(['ticker' => $stockData['ticker']]);
            if (!$stock) {
                $stock = new Stock();
                $stock->setTicker($stockData['ticker']);

                $sphere = $this->openingBoard->open($stock, $stockData, $openingMacro);
                if ($sphere !== null) {
                    $spheres[] = $sphere;
                }
            } else {
                // A reseed is not a reset: a firm already trading keeps its books and only has its listing refreshed.
                $this->openingBoard->applyListing($stock, $stockData);
            }

            $seeded[$stockData['ticker']] = $stock;
            $this->entityManager->persist($stock);
        }

        try {
            $this->openingBoard->openSpheres($spheres, $seeded, $openingMacro);
        } catch (\DomainException $misfit) {
            $io->error($misfit->getMessage());

            return Command::FAILURE;
        }

        // Test User
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test.test@test.se']);
        if (!$user) {
            $user = new User();
            $user->setEmail('test.test@test.se');
            $user->setRoles(['ROLE_ADMIN']);
            $user->setCashBalance('10000.00');
            $user->setPassword($this->passwordHasher->hashPassword($user, 'test'));
            $this->entityManager->persist($user);
        }

        $user->setUsername('Test');
        $user->setIsVerified(true);

        $this->entityManager->flush();

        // Open the bond desk with one on-the-run at each tenor. Only the benchmarks are sold here; the rest
        // of the ladder fills in as the quarterly refunding runs, which is also how a real curve gets its
        // off-the-run issues rather than having them appear fully formed.
        $existingBonds = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM bonds');
        if ($existingBonds === 0) {
            $issued = $this->treasuryAuction->conductAuction($openingMacro->sovereignCurve(), 0.0);
            $this->entityManager->flush();
            $io->text(sprintf('Opened the bond desk with %d benchmark issues.', count($issued)));
        }

        $io->success('Database successfully seeded with full fundamental physics!');

        return Command::SUCCESS;
    }
}
