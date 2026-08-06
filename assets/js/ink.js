/* ============================================================
   Aram's Tattoo — shared design processing

   Tattoo line art is nearly always black on white paper. Turning
   brightness into transparency makes the paper disappear and leaves
   the lines, which can then be tinted with the chosen ink.

   Used by both the Virtual Camera and the body map so there is one
   implementation to fix, not two.
   ============================================================ */
window.AramInk = (() => {
  'use strict';

  const clamp = (v, lo, hi) => (v < lo ? lo : v > hi ? hi : v);

  const hexToRgb = (hex) => ({
    r: parseInt(hex.slice(1, 3), 16),
    g: parseInt(hex.slice(3, 5), 16),
    b: parseInt(hex.slice(5, 7), 16),
  });

  /** Perceived brightness of a hex colour, 0–1. */
  const luminance = (hex) => {
    const { r, g, b } = hexToRgb(hex);
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  };

  /**
   * White ink would vanish under a multiply blend, so light inks are
   * screened onto the skin instead.
   */
  const blendFor = (hex) => (luminance(hex) > 0.6 ? 'screen' : 'multiply');

  /**
   * @param {HTMLImageElement} img   the uploaded design
   * @param {object} opts
   *   removeBg {boolean} turn white paper into transparency
   *   ink      {string}  hex colour to tint the lines
   *   thresh   {number}  0–1, how eagerly faint lines are picked up
   *   maxEdge  {number}  longest side of the returned canvas
   * @returns {HTMLCanvasElement|null}
   */
  function process(img, { removeBg = true, ink = '#141821', thresh = 0.5, maxEdge = 1024 } = {}) {
    if (!img) return null;

    const iw = img.naturalWidth, ih = img.naturalHeight;
    if (!iw || !ih) return null;

    const scale = Math.min(1, maxEdge / Math.max(iw, ih));
    const w = Math.max(1, Math.round(iw * scale));
    const h = Math.max(1, Math.round(ih * scale));

    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    const cx = c.getContext('2d', { willReadFrequently: true });
    cx.drawImage(img, 0, 0, w, h);

    if (!removeBg) return c;              // keep the artwork exactly as uploaded

    const rgb = hexToRgb(ink);
    const data = cx.getImageData(0, 0, w, h);
    const p = data.data;

    // soft window around the threshold so edges stay smooth
    const lo = clamp(thresh - 0.35, 0, 1);
    const hi = clamp(thresh + 0.35, 0.001, 1);
    const span = hi - lo || 0.001;

    for (let i = 0; i < p.length; i += 4) {
      const lum = (0.299 * p[i] + 0.587 * p[i + 1] + 0.114 * p[i + 2]) / 255;
      const a = clamp((1 - lum - lo) / span, 0, 1) * (p[i + 3] / 255);
      p[i] = rgb.r; p[i + 1] = rgb.g; p[i + 2] = rgb.b;
      p[i + 3] = Math.round(a * 255);
    }

    cx.putImageData(data, 0, 0);
    return c;
  }

  return { process, blendFor, luminance, hexToRgb, clamp };
})();
