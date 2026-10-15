/**
 * WOAIBench.si brand mark geometry — "Calibrated W".
 * A precision ring with reticle ticks and a fine graduation scale, enclosing an ascending W trace
 * that ends on a locked measurement point. Pure functions returning SVG markup (viewBox 0 0 64 64).
 */
const C = 32;
const f = (n) => +n.toFixed(2);
const polar = (r, deg) => [C + r * Math.sin(deg * Math.PI / 180), C - r * Math.cos(deg * Math.PI / 180)];

export const PALETTES = {
  dark: { a: '#F6E4B5', b: '#D9B26A', c: '#A9803B', tick: '#D9B26A', bg: '#08090C', bg2: '#16171D', text: '#F3EEE4' },
  light: { a: '#B8904A', b: '#8E6A2B', c: '#5F4416', tick: '#8E6A2B', bg: '#F6F2E9', bg2: '#FFFDF8', text: '#17140F' },
};

/** W trace points (ascending: the last stroke rises above the first). */
export const TRACE = [[16.6, 25.2], [23.6, 41.6], [31.6, 30.4], [39.6, 41.6], [47.2, 19.4]];

/** Inner geometry (ring, dial ticks, trace, point) drawn with the given stroke/fill references. */
export function markBody({ stroke, tick, detail = 'full' }) {
  const tiny = detail === 'tiny';
  const ringR = tiny ? 26.6 : 27.4;
  const out = [];
  out.push(`<circle cx="${C}" cy="${C}" r="${ringR}" fill="none" stroke="${stroke}" stroke-width="${tiny ? 4 : 2.3}"/>`);
  const [ex, ey] = TRACE[TRACE.length - 1];
  const pointDeg = Math.atan2(ex - C, C - ey) * 180 / Math.PI;
  if (!tiny) {
    // Dial: bold markers at the four cardinal points (like a chronometer bezel).
    const card = [0, 90, 180, 270].map((deg) => {
      const [x0, y0] = polar(20.6, deg); const [x1, y1] = polar(24.6, deg);
      return `M${f(x0)} ${f(y0)}L${f(x1)} ${f(y1)}`;
    }).join('');
    out.push(`<path d="${card}" stroke="${stroke}" stroke-width="2" stroke-linecap="round" fill="none"/>`);
  }
  if (detail === 'full') {
    // Fine graduation every 6deg (longer every 30deg); it opens where the measured point breaks through.
    let d = '';
    for (let deg = 0; deg < 360; deg += 6) {
      if (deg % 90 === 0 || Math.abs(deg - pointDeg) < 11) continue;
      const major = deg % 30 === 0;
      const [x0, y0] = polar(major ? 22.2 : 23.3, deg); const [x1, y1] = polar(24.6, deg);
      d += `M${f(x0)} ${f(y0)}L${f(x1)} ${f(y1)}`;
    }
    out.push(`<path d="${d}" stroke="${tick}" stroke-width="0.75" stroke-linecap="round" opacity="0.7" fill="none"/>`);
  }
  const pts = TRACE.map(([x, y]) => `${x} ${y}`).join(' ');
  out.push(`<polyline points="${pts}" fill="none" stroke="${stroke}" stroke-width="${tiny ? 5 : 3.1}" stroke-linecap="round" stroke-linejoin="round"/>`);
  if (detail === 'full') {
    out.push(`<circle cx="${ex}" cy="${ey}" r="5.2" fill="none" stroke="${stroke}" stroke-width="0.9" opacity="0.8"/>`);
  }
  out.push(`<circle cx="${ex}" cy="${ey}" r="${tiny ? 4.6 : 3.2}" fill="${stroke}"/>`);
  return out.join('');
}

/** Gradient defs for a palette. */
export function markDefs(p, id = 'wb') {
  return `<linearGradient id="${id}-g" x1="10" y1="6" x2="54" y2="58" gradientUnits="userSpaceOnUse">`
    + `<stop offset="0" stop-color="${p.a}"/><stop offset=".48" stop-color="${p.b}"/><stop offset="1" stop-color="${p.c}"/></linearGradient>`;
}

/** Standalone square mark (transparent background). */
export function markSvg(variant = 'dark', size = 64, detail = 'full', id = 'wbm') {
  const p = PALETTES[variant];
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="${size}" height="${size}" role="img" aria-label="WOAIBench.si">`
    + `<defs>${markDefs(p, id)}</defs>${markBody({ stroke: `url(#${id}-g)`, tick: p.tick, detail })}</svg>`;
}

/** Favicon / app tile: mark on an ink tile. */
export function tileSvg(size = 64, { radius = 14, detail = 'simple', scale = 0.84, id = 'wbt' } = {}) {
  const p = PALETTES.dark;
  const s = scale; const off = (64 - 64 * s) / 2;
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="${size}" height="${size}">`
    + `<defs>${markDefs(p, id)}<radialGradient id="${id}-bg" cx="32" cy="22" r="46" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#1B1C23"/><stop offset="1" stop-color="#07080B"/></radialGradient></defs>`
    + `<rect width="64" height="64" rx="${radius}" fill="url(#${id}-bg)"/>`
    + `<g transform="translate(${f(off)} ${f(off)}) scale(${s})">${markBody({ stroke: `url(#${id}-g)`, tick: p.tick, detail })}</g></svg>`;
}
