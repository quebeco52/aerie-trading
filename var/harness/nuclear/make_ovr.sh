#!/bin/bash
# make_ovr.sh: FIRE seed x0.74 (treasury, equity, debt, retained, sam) as ovr/scale74. Edits FIRE's block (lines 2872-2905).
D=/home/quebeco/Projects/Code/Private/aerie-trading
mkdir -p $D/var/harness/nuclear/ovr/scale74/Data/Company
sed "2872,2905{s/'corporate_treasury' => 5_000_000_000.00/'corporate_treasury' => 3_700_000_000.00/;s/'total_equity'      => 30_000_000_000.00/'total_equity'      => 22_200_000_000.00/;s/'wholesale_debt'    => 15_000_000_000.00/'wholesale_debt'    => 11_100_000_000.00/;s/'retained_earnings' => 12_000_000_000.00/'retained_earnings' => 8_880_000_000.00/;s/'sam_ratio' => 0.15/'sam_ratio' => 0.111/}" $D/src/Data/Company/InitialMarket.php > $D/var/harness/nuclear/ovr/scale74/Data/Company/InitialMarket.php
