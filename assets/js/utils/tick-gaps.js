/**
 * Widest gap between two points of one series that is filled.
 *
 * A bond is on the wire only on history bars, so its points skip the ticks between bars: one tick in three at
 * 3,600 ticks a year, 44 in 45 at 108,000, the fastest rate the configuration lists. Anything wider is a
 * reconnect or a restarted ticker, whose missing ticks were never drawn, and is joined as if consecutive.
 */
export const MAX_FILLED_TICK_GAP = 64;

/**
 * Restores the ticks a series skipped, at the price it last had.
 *
 * The live chart advances its clock one tick per point, so a series sent on bars only ran slow by the ratio of
 * bars to ticks. The skipped ticks are the bond desk quoting the price it already had (see BondTracker), so a
 * flat fill is the series the chart buffer holds, not an interpolation.
 *
 * @param {Array<[number, number, (number|null)]>} points `[price, volume, tick]` in tick order (tickPoints()).
 * @param {{tick: number, price: number}|null} last The series' last point before these, or null at its start.
 * @returns {{points: Array<[number, number, (number|null)]>, last: ({tick: number, price: number}|null)}}
 */
export function fillTickGaps(points, last) {
    const filled = [];
    let previous = last;

    for (const point of points) {
        const [price, , tick] = point;

        if (!Number.isFinite(tick)) {
            filled.push(point);
            continue;
        }

        const gap = previous === null ? 1 : tick - previous.tick;
        if (gap > 1 && gap <= MAX_FILLED_TICK_GAP) {
            for (let missing = previous.tick + 1; missing < tick; missing++) {
                filled.push([previous.price, 0, missing]);
            }
        }

        filled.push(point);
        previous = { tick, price };
    }

    return { points: filled, last: previous };
}
