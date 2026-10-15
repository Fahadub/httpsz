/**
 * Builds the WOAIBench.si brand files from code (dev only; the outputs are committed in assets/img).
 *
 *   NODE_PATH=/path/to/node_modules node tools/dev/brand/build.mjs [--png]
 *
 * NODE_PATH must contain opentype.js@1.3.4 and wawoff2@2.0.1 (wordmark outlines from the self-hosted
 * Instrument Serif woff2) and, for --png, playwright (Chromium) to render the raster icons and og.png.
 *
 * Writes: assets/img/logo-mark.svg, logo.svg, logo-light.svg, favicon.svg
 *         with --png: apple-touch-icon.png (180), icon-192.png, icon-512.png, og.png (1200×630)
 */
import { createRequire } from 'module';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { fileURLToPath } from 'url';
import { PALETTES, markBody, markDefs, tileSvg } from './mark.mjs';

const require = createRequire(import.meta.url);
const here = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(here, '../../..');
const IMG = path.join(ROOT, 'assets/img');
const FONTS = path.join(ROOT, 'assets/fonts');
const f = (n) => +n.toFixed(2);

async function loadFont(file) {
  const wawoff = require('wawoff2');
  const opentype = require('opentype.js');
  const ttf = await wawoff.decompress(fs.readFileSync(path.join(FONTS, file)));
  return opentype.parse(Uint8Array.from(ttf).buffer);
}

/** Outline a run of text; returns { d, width } in user units at the given size and baseline. */
function outline(font, text, x, baseline, size, tracking = 0) {
  let d = '';
  let cursor = x;
  const glyphs = font.stringToGlyphs(text);
  glyphs.forEach((g, i) => {
    d += g.getPath(cursor, baseline, size).toPathData(2);
    let adv = g.advanceWidth * size / font.unitsPerEm;
    if (i < glyphs.length - 1) {
      adv += font.getKerningValue(g, glyphs[i + 1]) * size / font.unitsPerEm;
    }
    cursor += adv + tracking * size;
  });
  return { d, width: cursor - x - tracking * size };
}

/** Responsive square mark: hides fine detail and thickens strokes at favicon sizes (SVG media queries). */
function markFile() {
  const d = PALETTES.dark;
  const l = PALETTES.light;
  const css = '.fine,.halo{opacity:.72}'
    + '@media (max-width:40px){.fine,.halo{display:none}}'
    + '@media (max-width:22px){.card{display:none}.ring{stroke-width:4}.trace{stroke-width:5}.pt{r:4.6px}}'
    + `@media (prefers-color-scheme:light){.s0{stop-color:${l.a}}.s1{stop-color:${l.b}}.s2{stop-color:${l.c}}.fine{stroke:${l.tick}}}`;
  const body = markBody({ stroke: 'url(#wbm-g)', tick: d.tick, detail: 'full' })
    .replace('<circle cx="32" cy="32" r="27.4" fill="none"', '<circle class="ring" cx="32" cy="32" r="27.4" fill="none"')
    .replace('<path d="M32 11.4', '<path class="card" d="M32 11.4')
    .replace('opacity="0.7" fill="none"/>', 'fill="none" class="fine"/>')
    .replace('<polyline ', '<polyline class="trace" ')
    .replace('r="5.2" fill="none"', 'r="5.2" class="halo" fill="none"')
    .replace(/<circle cx="([\d.]+)" cy="([\d.]+)" r="3.2"/, '<circle class="pt" cx="$1" cy="$2" r="3.2"');
  const defs = `<linearGradient id="wbm-g" x1="10" y1="6" x2="54" y2="58" gradientUnits="userSpaceOnUse">`
    + `<stop class="s0" offset="0" stop-color="${d.a}"/><stop class="s1" offset=".48" stop-color="${d.b}"/><stop class="s2" offset="1" stop-color="${d.c}"/></linearGradient>`;
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="WOAIBench.si"><title>WOAIBench.si</title><style>${css}</style><defs>${defs}</defs>${body}</svg>\n`;
}

/** Horizontal lockup: mark + "WOAI" (roman) + "Bench" (italic) + ".si" (gold). */
async function lockup(variant) {
  const p = PALETTES[variant];
  const roman = await loadFont('instrument-serif-latin-400-normal.woff2');
  const italic = await loadFont('instrument-serif-latin-400-italic.woff2');
  const size = 46;
  const baseline = 32 + 0.34 * size; // optically centres the cap height on the mark
  let x = 64 + 18;
  const a = outline(roman, 'WOAI', x, baseline, size, 0.03);
  x += a.width + 0.06 * size;
  const b = outline(italic, 'Bench', x, baseline, size, 0.005);
  x += b.width + 0.02 * size;
  const c = outline(roman, '.si', x, baseline, size, 0.02);
  x += c.width;
  const w = Math.ceil(x + 2);
  const id = 'wbl-' + variant;
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} 64" width="${w * 2}" height="128" role="img" aria-label="WOAIBench.si"><title>WOAIBench.si</title>`
    + `<defs>${markDefs(p, id)}<linearGradient id="${id}-t" x1="0" y1="0" x2="0" y2="64" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="${p.a}"/><stop offset="1" stop-color="${p.c}"/></linearGradient></defs>`
    + markBody({ stroke: `url(#${id}-g)`, tick: p.tick, detail: 'full' })
    + `<path fill="${p.text}" d="${a.d}${b.d}"/><path fill="url(#${id}-t)" d="${c.d}"/></svg>\n`;
}

function write(name, content) {
  fs.writeFileSync(path.join(IMG, name), content);
  console.log('wrote', name, content.length, 'bytes');
}

async function renderPngs() {
  const { chromium } = require('playwright');
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wb-brand-'));
  const browser = await chromium.launch();
  const shot = async (html, w, h, out, transparent = false) => {
    const file = path.join(tmp, out + '.html');
    fs.writeFileSync(file, html);
    const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
    await page.goto('file://' + file);
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(150);
    await page.screenshot({ path: path.join(IMG, out), omitBackground: transparent });
    await page.close();
    console.log('rendered', out);
  };
  const tile = (size, scale) => `<!doctype html><html><body style="margin:0">${tileSvg(size, { radius: 0, detail: 'full', scale })}</body></html>`;
  await shot(tile(180, 0.66), 180, 180, 'apple-touch-icon.png');
  await shot(tile(192, 0.62), 192, 192, 'icon-192.png');
  await shot(tile(512, 0.62), 512, 512, 'icon-512.png');
  await shot(ogHtml(), 1200, 630, 'og.png');
  await browser.close();
  fs.rmSync(tmp, { recursive: true, force: true });
}

/** Social card: mark, name, Arabic + English tagline, and the method in one line. */
function ogHtml() {
  const font = (fam, file, extra = '') => `@font-face{font-family:"${fam}";src:url("file://${path.join(FONTS, file)}");${extra}}`;
  const p = PALETTES.dark;
  let dial = '';
  for (let deg = 0; deg < 360; deg += 2) {
    const major = deg % 30 === 0; const mid = deg % 10 === 0;
    const r0 = major ? 262 : mid ? 272 : 280; const r1 = 290;
    const s = Math.sin(deg * Math.PI / 180); const c = Math.cos(deg * Math.PI / 180);
    dial += `M${f(300 + r0 * s)} ${f(300 - r0 * c)}L${f(300 + r1 * s)} ${f(300 - r1 * c)}`;
  }
  return `<!doctype html><html><head><meta charset="utf-8"><style>
${font('Disp', 'instrument-serif-latin-400-normal.woff2')}
${font('Disp', 'instrument-serif-latin-400-italic.woff2', 'font-style:italic;')}
${font('Kufi', 'noto-kufi-arabic-arabic-wght-normal.woff2', 'font-weight:100 900;')}
${font('Sans', 'inter-latin-wght-normal.woff2', 'font-weight:100 900;')}
${font('Mono', 'jetbrains-mono-latin-wght-normal.woff2', 'font-weight:100 800;')}
html,body{margin:0;width:1200px;height:630px;overflow:hidden;background:#07080B;color:${p.text}}
body{position:relative;background:radial-gradient(900px 520px at 88% 30%,rgba(217,178,106,.16),transparent 60%),radial-gradient(700px 500px at 0% 100%,rgba(217,178,106,.07),transparent 60%),#07080B}
.dial{position:absolute;right:-170px;top:-150px;width:860px;height:860px;opacity:.9}
.frame{position:absolute;inset:28px;border:1px solid rgba(236,228,210,.10);border-radius:28px}
.wrap{position:absolute;left:84px;top:92px;right:200px}
.row{display:flex;align-items:center;gap:26px}
.name{font-family:Disp;font-size:96px;line-height:1;letter-spacing:-.5px}
.name i{font-style:italic}.name b{font-weight:400;background:linear-gradient(180deg,${p.a},${p.c});-webkit-background-clip:text;color:transparent}
.ar{font-family:Kufi;font-weight:380;font-size:35px;line-height:1.6;margin-top:50px;direction:rtl;text-align:left;white-space:nowrap}
.en{font-family:Sans;font-weight:300;font-size:28px;color:#B8B2A6;margin-top:6px;letter-spacing:.2px}
.rule{height:1px;width:520px;margin:40px 0 22px;background:linear-gradient(90deg,rgba(217,178,106,.7),rgba(217,178,106,0))}
.method{font-family:Mono;font-size:19px;letter-spacing:.5px;color:#D9B26A;text-transform:uppercase}
.method span{color:#77736B;margin:0 10px}
.url{position:absolute;right:84px;bottom:70px;font-family:Mono;font-size:20px;color:#8A857A;letter-spacing:1px}
</style></head><body>
<svg class="dial" viewBox="0 0 600 600"><defs><linearGradient id="dg" x1="0" y1="0" x2="600" y2="600" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="${p.a}" stop-opacity=".55"/><stop offset="1" stop-color="${p.c}" stop-opacity=".12"/></linearGradient></defs>
<circle cx="300" cy="300" r="296" fill="none" stroke="url(#dg)" stroke-width="2"/><circle cx="300" cy="300" r="246" fill="none" stroke="url(#dg)" stroke-width="1" opacity=".5"/>
<path d="${dial}" stroke="url(#dg)" stroke-width="1.6" fill="none"/></svg>
<div class="frame"></div>
<div class="wrap">
  <div class="row">${tileSvg(112, { radius: 26, detail: 'full', scale: 0.8, id: 'og' })}<div class="name">WOAI<i>Bench</i><b>.si</b></div></div>
  <div class="ar">المؤشر العلمي لقياس نماذج الذكاء الاصطناعي في البرمجة</div>
  <div class="en">The scientific index of AI coding models</div>
  <div class="rule"></div>
  <div class="method">3× runs<span>/</span>blind judge panel<span>/</span>95% CI<span>/</span>real browser</div>
</div>
<div class="url">woaibench.si</div>
</body></html>`;
}

const png = process.argv.includes('--png');
write('logo-mark.svg', markFile());
write('logo.svg', await lockup('dark'));
write('logo-light.svg', await lockup('light'));
write('favicon.svg', tileSvg(32, { radius: 14, detail: 'tiny', scale: 0.86, id: 'fav' }).replace('width="32" height="32"', '') + '\n');
if (png) {
  await renderPngs();
}
