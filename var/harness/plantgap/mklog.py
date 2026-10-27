"""mklog.py <src TreasuryEngine.php> <out dir>: writes <out dir>/Service/Corporate/TreasuryEngine.php with the plantgap
logging wrapper around processOrganicCapex (behaviour-neutral) and copies ovrlog's CapitalBudgetEngine beside it.
Logged extra fields: org0 (plant fundPlannedPlant built this quarter), eqr (equity raised before organic capex)."""
import os, shutil, sys
src, out = sys.argv[1], sys.argv[2]
here = os.path.dirname(os.path.abspath(__file__))
code = open(src).read()
anchor = "    private function processOrganicCapex(CapitalAllocationContext $ctx): void\n    {\n"
assert code.count(anchor) == 1, 'anchor not found once'
wrap = anchor + """        $in = $ctx->expansionBudget;
        $org0 = $ctx->organicCapex;
        $this->processOrganicCapexInner($ctx);
        $d = \\App\\Service\\Corporate\\Earnings\\CapitalBudgetEngine::$dbg;
        if (($f = getenv('PGLOG')) && ($d['tk'] ?? null) === $ctx->stock->getTicker() && !($d['fin'] ?? 1)) {
            file_put_contents($f, json_encode($d + ['ind' => $ctx->stock->getIndustry(), 'bin' => $in, 'debt' => $ctx->debtIssued, 'org' => $ctx->organicCapex, 'org0' => $org0, 'eqr' => $ctx->equityRaised, 'bout' => $ctx->expansionBudget, 'pay' => (int) $ctx->health->wantsToPaydownDebt, 'cid' => (int) $ctx->health->canIssueDebt, 'head' => (int) $ctx->health->hasLeverageHeadroom]) . "\\n", FILE_APPEND);
        }
    }

    private function processOrganicCapexInner(CapitalAllocationContext $ctx): void
    {
"""
os.makedirs(f'{out}/Service/Corporate/Earnings', exist_ok=True)
open(f'{out}/Service/Corporate/TreasuryEngine.php', 'w').write(code.replace(anchor, wrap))
shutil.copy(f'{here}/ovrlog/Service/Corporate/Earnings/CapitalBudgetEngine.php', f'{out}/Service/Corporate/Earnings/CapitalBudgetEngine.php')
