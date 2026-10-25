#!/bin/bash
# make_ovr.sh: SKYL seed x1.3 (treasury, equity, debt, retained, sam) as ovr/scale130. Edits SKYL's block (lines 605-640).
D=/home/quebeco/Projects/Code/Private/aerie-trading
mkdir -p $D/var/harness/space/ovr/scale130/Data/Company
sed "605,640{s/'corporate_treasury' => 2_500_000_000.00/'corporate_treasury' => 3_250_000_000.00/;s/'total_equity'      => 7_000_000_000.00/'total_equity'      => 9_100_000_000.00/;s/'wholesale_debt'    => 1_500_000_000.00/'wholesale_debt'    => 1_950_000_000.00/;s/'retained_earnings' => 500_000_000.00/'retained_earnings' => 650_000_000.00/;s/'sam_ratio' => 0.02,/'sam_ratio' => 0.026,/}" $D/src/Data/Company/InitialMarket.php > $D/var/harness/space/ovr/scale130/Data/Company/InitialMarket.php
