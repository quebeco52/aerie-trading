<?php

declare(strict_types=1);

namespace App\Service\Corporate\Deal;

use App\Service\Math\MathUtility;

/** The private firm on the far side of a deal, the target bought or the buyer of a division: named, not modelled. */
final class PrivateCompany
{
    /**
     * Procedurally generates a realistic sounding private company.
     *
     * @return array{name: string} An array containing the generated company name.
     */
    public static function procedural(MathUtility $random): array
    {
        $prefixes = ['Apex', 'Horizon', 'Vertex', 'Quantum', 'Cipher', 'Aegis', 'Omni', 'Vanguard', 'Pinnacle', 'Meridian'];
        $suffixes = ['Dynamics', 'Holdings', 'Labs', 'Logistics', 'Networks', 'Heavy Industries', 'Capital', 'Technologies', 'Resources', 'Ventures'];

        $name = $prefixes[$random->generateIndex(count($prefixes))] . ' ' . $suffixes[$random->generateIndex(count($suffixes))];

        return ['name' => $name];
    }
}
