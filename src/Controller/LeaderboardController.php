<?php

namespace App\Controller;

use App\Data\District\DistrictCalendar;
use App\Entity\User;
use App\Repository\SeasonEntryRepository;
use App\Repository\SeasonRepository;
use App\Service\Season\SeasonService;
use App\Service\Season\SeasonStanding;
use App\Service\View\PlayerPanelBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The league tables: the open season ranked by return, every account by net worth, and the podiums of seasons past.
 */
class LeaderboardController extends AbstractController
{
    // --- Display ---
    /** Rows a table shows; an account further down sees its own row pinned under them. */
    private const TABLE_ROWS = 100;
    /** Seconds a computed table is reused; it is one query per account plus the net worth statement. */
    private const CACHE_SECONDS = 5;
    /** Closed seasons whose podium the history view lists. */
    private const PAST_SEASONS = 12;

    private const VIEWS = ['season' => 'This season', 'networth' => 'Net worth', 'past' => 'Past seasons'];

    #[Route('/leaderboard', name: 'app_leaderboard')]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        CacheInterface $cache,
        SeasonRepository $seasons,
        SeasonEntryRepository $entries,
        SeasonService $seasonService,
        PlayerPanelBuilder $panels,
        \App\Service\Macro\MacroStateProvider $macroStates,
    ): Response {
        $view = (string) $request->query->get('view', 'season');
        if (!isset(self::VIEWS[$view])) {
            $view = 'season';
        }

        $user = $this->getUser();
        $userId = $user instanceof User ? $user->getId() : null;
        $data = ['view' => $view, 'views' => self::VIEWS, 'myId' => $userId];

        if ($view === 'networth') {
            $data['leaders'] = $cache->get('leaderboard_top_100', function (ItemInterface $item) use ($entityManager) {
                $item->expiresAfter(self::CACHE_SECONDS);

                // Value committed to open limit orders counts: it has left the cash balance (a BUY) or the holdings
                // table (a SELL), so leaving it out ranked traders by how few orders they had working.
                return $entityManager->getConnection()->fetchAllAssociative(
                    \App\Service\User\Portfolio::NET_WORTH_SQL . ' ORDER BY total_value DESC LIMIT ' . self::TABLE_ROWS
                );
            });

            return $this->render('leaderboard/index.html.twig', $data);
        }

        if ($view === 'past') {
            $podiums = [];
            foreach ($entries->podiums(3, self::PAST_SEASONS) as $entry) {
                $season = $entry->getSeason();
                $podiums[$season->getNumber()] ??= [
                    'number' => $season->getNumber(),
                    'span' => DistrictCalendar::quarter($season->getStartTime()) . ' to ' . DistrictCalendar::quarter($season->getEndTime()),
                    'places' => [],
                ];
                $podiums[$season->getNumber()]['places'][] = [
                    'rank' => $entry->getFinalRank(),
                    'username' => $entry->getUser()->getUsername() ?? 'Anonymous Trader',
                    'return' => $entry->getFinalReturn(),
                    'benchmarkReturn' => $entry->getFinalBenchmarkReturn(),
                ];
            }
            $data['podiums'] = array_values($podiums);
            $data['myHistory'] = $user instanceof User ? $entries->history($user, self::PAST_SEASONS) : [];

            return $this->render('leaderboard/index.html.twig', $data);
        }

        $season = $seasons->findOpen();
        $data['season'] = null;
        if ($season !== null) {
            $rows = $cache->get('leaderboard_season_' . $season->getNumber(), function (ItemInterface $item) use ($seasonService, $season) {
                $item->expiresAfter(self::CACHE_SECONDS);

                return array_map(self::rowArray(...), $seasonService->liveTable($season));
            });

            $ranked = array_values(array_filter($rows, static fn (array $r): bool => $r['rank'] !== null));
            $unranked = array_values(array_filter($rows, static fn (array $r): bool => $r['rank'] === null && !$r['forfeited']));
            $mine = null;
            foreach ($rows as $row) {
                if ($row['userId'] === $userId) {
                    $mine = $row;
                }
            }

            $yearsLeft = SeasonService::yearsLeft($season, $macroStates->liveState()->totalTime);
            $data['season'] = [
                'number' => $season->getNumber(),
                'span' => DistrictCalendar::quarter($season->getStartTime()) . ' to ' . DistrictCalendar::quarter($season->getEndTime()),
                'realSecondsLeft' => $yearsLeft * $panels->realSecondsPerYear(),
                'qualifyingWeeks' => \App\Entity\Season::MIN_QUALIFYING_WEEKS,
                'ranked' => array_slice($ranked, 0, self::TABLE_ROWS),
                'rankedCount' => count($ranked),
                'unranked' => array_slice($unranked, 0, self::TABLE_ROWS),
                'mine' => $mine,
                'mineShown' => $mine !== null && $mine['rank'] !== null && $mine['rank'] <= self::TABLE_ROWS,
            ];
        }

        return $this->render('leaderboard/index.html.twig', $data);
    }

    /** @return array<string, mixed> A standing as plain data, so the table can be cached. */
    private static function rowArray(SeasonStanding $s): array
    {
        return [
            'rank' => $s->rank,
            'userId' => $s->userId,
            'username' => $s->username,
            'value' => $s->value,
            'return' => $s->return,
            'benchmarkReturn' => $s->benchmarkReturn,
            'excess' => $s->excess(),
            'sharpe' => $s->sharpe,
            'beta' => $s->beta,
            'maxDrawdown' => $s->maxDrawdown,
            'weeks' => $s->weeks,
            'forfeited' => $s->forfeited,
        ];
    }
}
