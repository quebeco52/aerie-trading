/**
 * Canvas and SVG chart libraries can't resolve CSS custom properties, so the font stacks
 * are duplicated here. Keep in step with --font-mono / --font-body in styles/app.css.
 */

/** Figures: axis ticks, tooltips, data labels. */
export const CHART_FONT_MONO = '"IBM Plex Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';

/** Prose: titles, legends, annotations. */
export const CHART_FONT_SANS = '"IBM Plex Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif';
