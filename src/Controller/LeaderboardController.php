<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class LeaderboardController extends AbstractController
{
    #[Route('/leaderboard', name: 'app_leaderboard')]
    public function index(EntityManagerInterface $entityManager, CacheInterface $cache): Response
    {
        // Cache the aggregation query for 5 seconds for fast reloads without DB throttling
        $leaders = $cache->get('leaderboard_top_100', function (ItemInterface $item) use ($entityManager) {
            $item->expiresAfter(5);
            
            $conn = $entityManager->getConnection();
            
            // Value committed to open limit orders counts: it has left the cash balance (a BUY) or the
            // holdings table (a SELL), so leaving it out ranked traders by how few orders they had working.
            $sql = "
            SELECT COALESCE(u.username, 'Anonymous Trader') as username,
                   u.cash_balance,
                   COALESCE(stock_totals.stock_val, 0) as stock_value,
                   COALESCE(etf_totals.etf_val, 0) as etf_value,
                   COALESCE(bond_totals.bond_val, 0) as bond_value,
                   COALESCE(option_totals.option_val, 0) as option_value,
                   COALESCE(escrow.escrow_val, 0) as escrow_value,
                   u.margin_debit,
                   (u.cash_balance - u.margin_debit + COALESCE(stock_totals.stock_val, 0) + COALESCE(etf_totals.etf_val, 0) + COALESCE(bond_totals.bond_val, 0) + COALESCE(option_totals.option_val, 0) + COALESCE(escrow.escrow_val, 0)) as total_value
            FROM users u
            LEFT JOIN (
                -- A short's quantity is negative, so this one SUM marks longs and shorts alike.
                SELECT us.user_id, SUM(us.quantity * s.price) as stock_val
                FROM user_stocks us
                JOIN stocks s ON us.stock_id = s.id
                GROUP BY us.user_id
            ) stock_totals ON stock_totals.user_id = u.id
            LEFT JOIN (
                SELECT ue.user_id, SUM(ue.quantity * e.price) as etf_val
                FROM user_etfs ue
                JOIN etfs e ON ue.etf_id = e.id
                GROUP BY ue.user_id
            ) etf_totals ON etf_totals.user_id = u.id
            LEFT JOIN (
                SELECT ub.user_id, SUM(ub.quantity * b.price) as bond_val
                FROM user_bonds ub
                JOIN bonds b ON ub.bond_id = b.id
                GROUP BY ub.user_id
            ) bond_totals ON bond_totals.user_id = u.id
            LEFT JOIN (" . \App\Service\User\Portfolio::OPTION_VALUE_SQL . ") option_totals ON option_totals.user_id = u.id
            LEFT JOIN (" . \App\Service\User\Portfolio::OPEN_ORDER_ESCROW_SQL . ") escrow ON escrow.user_id = u.id
            ORDER BY total_value DESC
            LIMIT 100
        ";
            
            return $conn->fetchAllAssociative($sql);
        });

        return $this->render('leaderboard/index.html.twig', [
            'leaders' => $leaders,
        ]);
    }
}