// Interpolation and smoothing for map selections. Both respect the real axis values, so maps with
// uneven breakpoints (e.g. rpm 800, 1250, 1750 ...) are filled and smoothed by distance, not by
// cell index.

/** Position of axis[i] between axis[a] and axis[b] (0..1); falls back to index spacing for flat or missing axes. */
function fraction(axis, i, a, b) {
    if (b === a) return 0;
    const span = axis?.[b] - axis?.[a];
    if (Number.isFinite(span) && span !== 0) return (axis[i] - axis[a]) / span;
    return (i - a) / (b - a);
}

/**
 * New value for cell (r, c) when interpolating the selection box from its edges.
 * mode 'h' between left/right edge, 'v' between top/bottom edge, 'b' bilinear from the corners.
 */
export function interpolateCell(grid, box, xAxis, yAxis, mode, r, c) {
    const { r0, r1, c0, c1 } = box;
    const tc = fraction(xAxis, c, c0, c1);
    const tr = fraction(yAxis, r, r0, r1);
    if (mode === 'h') return c1 > c0 ? grid[r][c0] + (grid[r][c1] - grid[r][c0]) * tc : grid[r][c];
    if (mode === 'v') return r1 > r0 ? grid[r0][c] + (grid[r1][c] - grid[r0][c]) * tr : grid[r][c];
    const top = grid[r0][c0] + (grid[r0][c1] - grid[r0][c0]) * tc;
    const bottom = grid[r1][c0] + (grid[r1][c1] - grid[r1][c0]) * tc;
    return top + (bottom - top) * tr;
}

/** Typical spacing of an axis, used to turn breakpoint distances into "cells". */
function step(axis, n) {
    if (!axis || n < 2) return 1;
    const span = Math.abs(axis[n - 1] - axis[0]);
    return Number.isFinite(span) && span > 0 ? span / (n - 1) : 1;
}

/**
 * One Gaussian pass over `field` (rows x cols). Neighbours are weighted by their real axis
 * distance, so a closely spaced breakpoint counts more than a far one. Returns a new grid.
 */
function gaussianPass(field, xAxis, yAxis) {
    const rows = field.length;
    const cols = field[0]?.length ?? 0;
    const sx = step(xAxis, cols);
    const sy = step(yAxis, rows);
    const dist = (axis, s, i, j) => (axis && Number.isFinite(axis[i]) && Number.isFinite(axis[j]) ? (axis[i] - axis[j]) / s : i - j);
    const out = [];
    for (let r = 0; r < rows; r++) {
        const row = [];
        for (let c = 0; c < cols; c++) {
            let sum = 0;
            let weights = 0;
            for (let dr = -1; dr <= 1; dr++) {
                for (let dc = -1; dc <= 1; dc++) {
                    const v = field[r + dr]?.[c + dc];
                    if (!Number.isFinite(v)) continue;
                    const dy = dr ? dist(yAxis, sy, r + dr, r) : 0;
                    const dxx = dc ? dist(xAxis, sx, c + dc, c) : 0;
                    const w = Math.exp(-(dxx * dxx + dy * dy) / 1.4);
                    sum += v * w;
                    weights += w;
                }
            }
            row.push(weights ? sum / weights : field[r][c]);
        }
        out.push(row);
    }
    return out;
}

/** True when a cell is on the outer edge of a selection that has inner cells in that direction. */
function onSelectionEdge(box, r, c) {
    const tall = box.r1 - box.r0 >= 2;
    const wide = box.c1 - box.c0 >= 2;
    return (tall && (r === box.r0 || r === box.r1)) || (wide && (c === box.c0 || c === box.c1));
}

/**
 * Smooths the values of the selection. The selection's outer edge stays put (when it has inner
 * cells) so the smoothed area still meets the rest of the map.
 *
 * @returns (r, c) => new value
 */
export function smoothValues(grid, box, xAxis, yAxis, passes = 1) {
    let field = grid;
    for (let i = 0; i < passes; i++) field = gaussianPass(field, xAxis, yAxis);
    return (r, c) => (onSelectionEdge(box, r, c) ? grid[r][c] : field[r][c]);
}

/**
 * Smooths only the change against the original: the stock shape stays, steps and spikes in the
 * modification are evened out and its edges feathered into the unchanged cells around it.
 *
 * @returns (r, c) => new value
 */
export function smoothChanges(grid, original, xAxis, yAxis, passes = 1) {
    let delta = grid.map((row, r) => row.map((v, c) => v - original[r][c]));
    for (let i = 0; i < passes; i++) delta = gaussianPass(delta, xAxis, yAxis);
    return (r, c) => original[r][c] + delta[r][c];
}
