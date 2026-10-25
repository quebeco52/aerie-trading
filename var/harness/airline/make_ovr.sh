#!/bin/bash
# make_ovr.sh: TERN seed variant x1.8 (treasury, equity, debt, retained earnings) for the opening probe, as ovr/scale18.
D=/home/quebeco/Projects/Code/Private/aerie-trading
mkdir -p $D/var/harness/airline/ovr/scale18/Data/Company
sed "1002,1032{s/'corporate_treasury' => 6_000_000_000.00/'corporate_treasury' => 10_800_000_000.00/;s/'total_equity'      => 24_000_000_000.00/'total_equity'      => 43_200_000_000.00/;s/'wholesale_debt'    => 19_000_000_000.00/'wholesale_debt'    => 34_200_000_000.00/;s/'retained_earnings' => 10_000_000_000.00/'retained_earnings' => 18_000_000_000.00/}" $D/src/Data/Company/InitialMarket.php > $D/var/harness/airline/ovr/scale18/Data/Company/InitialMarket.php
