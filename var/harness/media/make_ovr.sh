#!/bin/bash
# make_ovr.sh: seed-data variants of BELL for the opening-value probe, as OVR trees under var/harness/media/ovr/<name>.
# Each variant edits one line inside BELL's InitialMarket block (lines 1212-1242).
D=/home/quebeco/Projects/Code/Private/aerie-trading
mk() { mkdir -p $D/var/harness/media/ovr/$1/Data/Company; sed "1212,1242{$2}" $D/src/Data/Company/InitialMarket.php > $D/var/harness/media/ovr/$1/Data/Company/InitialMarket.php; }
mk roic10 "s/'baseline_roic' => 0.14/'baseline_roic' => 0.10/"
mk roic12 "s/'baseline_roic' => 0.14/'baseline_roic' => 0.12/"
mk capex30 "s/'capex_ratio' => 0.15/'capex_ratio' => 0.30/"
mk pay60 "s/'target_payout_ratio' => 0.35/'target_payout_ratio' => 0.60/"
mk om16 "s/'operating_margin' => 0.20/'operating_margin' => 0.16/"
