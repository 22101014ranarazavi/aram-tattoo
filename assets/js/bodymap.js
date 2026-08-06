/* ============================================================
   Aram's Tattoo — body map

   Pick a body part, drop a design on it, drag/scale/rotate it.
   The design is clipped to the chosen part and multiplied onto it,
   so it reads as ink rather than a sticker.
   ============================================================ */
(() => {
  'use strict';

  const svg = document.querySelector('#bodySvg');
  if (!svg) return;                       // section not on this page

  const $ = (s) => document.querySelector(s);
  const SVGNS = 'http://www.w3.org/2000/svg';

  const front   = $('#bodyFront');
  const back    = $('#bodyBack');
  const inkLayer = $('#inkLayer');
  const clip     = $('#inkClip');

  const chosenName = $('#chosenName');
  const chosenSub  = $('#chosenSub');
  const controls   = $('#bmapControls');
  const hint       = $('#bmapHint');
  const note       = $('#bmapNote');

  const sizeRange = $('#bmapSize'), sizeOut = $('#bmapSizeOut');
  const rotRange  = $('#bmapRot'),  rotOut  = $('#bmapRotOut');

  const st = {
    view: 'front',
    region: null,        // the selected <use> element
    label: '',
    raw: null,           // uploaded design as an <img>
    ink: '#141821',
    href: null,          // processed design as a data URL
    ratio: 1,            // design height / width
    x: 0, y: 0,          // centre, in SVG units
    w: 0,                // width, in SVG units
    rot: 0,
    basis: 100,          // region width, so the slider means "% of the part"
  };

  let inkImg = null;


  /* ── view toggle ──────────────────────────────────────── */
  document.querySelectorAll('.vbtn').forEach((b) => {
    b.addEventListener('click', () => {
      st.view = b.dataset.view;
      document.querySelectorAll('.vbtn').forEach((o) => {
        const on = o === b;
        o.classList.toggle('is-on', on);
        o.setAttribute('aria-selected', String(on));
      });
      front.hidden = st.view !== 'front';
      back.hidden  = st.view !== 'back';
      clearSelection();
    });
  });


  /* ── choosing a body part ─────────────────────────────── */
  svg.addEventListener('click', (e) => {
    const reg = e.target.closest('.reg');
    if (!reg) return;
    select(reg);
  });

  // keyboard: the regions are focusable via the list below
  svg.querySelectorAll('.reg').forEach((r) => {
    r.setAttribute('tabindex', '0');
    r.setAttribute('role', 'button');
    r.setAttribute('aria-label', r.dataset.label);
    r.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(r); }
    });
  });

  function select(reg) {
    svg.querySelectorAll('.reg').forEach((r) => r.classList.remove('is-on'));
    reg.classList.add('is-on');

    st.region = reg;
    st.label  = reg.dataset.label;

    chosenName.textContent = st.label;

    const box = reg.getBBox();
    const ctm = regionMatrix(reg);

    // Clip the ink to this exact part.
    // The left-hand parts live inside a mirrored <g>. Cloning the <use> on its
    // own leaves that transform behind, so the clip would land on the opposite
    // side of the body and the design would vanish. Bake the matrix in.
    const shape = reg.cloneNode(false);
    shape.setAttribute('transform',
      `matrix(${ctm.a} ${ctm.b} ${ctm.c} ${ctm.d} ${ctm.e} ${ctm.f})`);
    clip.replaceChildren(shape);

    // centre the design in the part and size it relative to the part
    const c = applyMatrix(ctm, box.x + box.width / 2, box.y + box.height / 2);

    st.basis = box.width * Math.abs(ctm.a || 1);
    st.x = c.x;
    st.y = c.y;
    st.w = st.basis * (sizeRange.value / 100);
    st.rot = +rotRange.value;

    if (st.raw) showControls();
    renderInk();
    updateGuide();
  }

  function clearSelection() {
    svg.querySelectorAll('.reg').forEach((r) => r.classList.remove('is-on'));
    st.region = null;
    clip.replaceChildren();
    inkLayer.replaceChildren();
    inkImg = null;
    chosenName.textContent = 'No spot chosen yet';
    controls.hidden = true;
    updateGuide();
  }

  /** A <use> inside the mirrored group carries that transform — honour it. */
  function regionMatrix(el) {
    const own = el.getCTM();
    const root = svg.getCTM();
    return root ? root.inverse().multiply(own) : own;
  }

  function applyMatrix(m, x, y) {
    return { x: m.a * x + m.c * y + m.e, y: m.b * x + m.d * y + m.f };
  }


  /* ── uploading the design ─────────────────────────────── */
  const fileInput = $('#bmapFile');
  const drop      = $('#bmapDrop');
  const stage     = document.querySelector('.bmap__stage');
  const thumb     = $('#bmapThumb');
  const thumbImg  = $('#bmapThumbImg');
  const thumbName = $('#bmapThumbName');

  fileInput.addEventListener('change', () => loadDesign(fileInput.files?.[0]));

  ['dragenter', 'dragover'].forEach((ev) =>
    drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach((ev) =>
    drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
  drop.addEventListener('drop', (e) => loadDesign(e.dataTransfer?.files?.[0]));

  // dropping straight onto the figure works too — that is the obvious
  // gesture once you are looking at a body
  ['dragenter', 'dragover'].forEach((ev) =>
    stage.addEventListener(ev, (e) => { e.preventDefault(); stage.classList.add('is-dragging'); }));
  ['dragleave', 'dragend'].forEach((ev) =>
    stage.addEventListener(ev, (e) => {
      if (e.target === stage || !stage.contains(e.relatedTarget)) {
        stage.classList.remove('is-dragging');
      }
    }));
  stage.addEventListener('drop', (e) => {
    e.preventDefault();
    stage.classList.remove('is-dragging');
    // if they dropped onto a body part, select it as well
    const reg = e.target.closest?.('.reg');
    if (reg) select(reg);
    loadDesign(e.dataTransfer?.files?.[0]);
  });

  function loadDesign(file) {
    if (!file || !file.type.startsWith('image/')) return;

    const url = URL.createObjectURL(file);
    const img = new Image();

    img.onload = () => {
      st.raw = img;
      st.ratio = img.naturalHeight / img.naturalWidth;
      processInk();

      thumbImg.src = url;                 // kept alive; revoked on reset
      thumbName.textContent = file.name;
      thumb.hidden = false;
      drop.hidden = true;

      if (st.region) {
        showControls();
        st.w = st.basis * (sizeRange.value / 100);
      }
      renderInk();
      updateGuide();
    };

    img.onerror = () => {
      URL.revokeObjectURL(url);
      say('That file could not be read as an image.', true);
    };

    img.src = url;
  }

  /**
   * Keep the client pointed at whichever step is still missing, and pulse
   * the body while there is a design but nowhere to put it.
   */
  function updateGuide() {
    const hasDesign = !!st.href;
    const hasRegion = !!st.region;

    svg.querySelectorAll('.reg').forEach((r) => r.classList.remove('is-target'));

    if (!hasDesign && !hasRegion) {
      hint.textContent = 'Step 1 — tap a body part';
      chosenSub.textContent = 'Tap the body to pick one';
    } else if (hasRegion && !hasDesign) {
      hint.textContent = 'Step 2 — now upload your design below';
      chosenSub.textContent = 'Now upload your design';
    } else if (hasDesign && !hasRegion) {
      hint.textContent = 'Step 2 — now tap where it goes';
      chosenSub.textContent = 'Tap the body to place it';
      svg.querySelectorAll('#body' + (st.view === 'front' ? 'Front' : 'Back') + ' .reg')
        .forEach((r) => r.classList.add('is-target'));
    } else {
      hint.textContent = 'Drag it to position · scroll to resize';
      chosenSub.textContent = 'Drag the design to fine-tune it';
    }
    hint.classList.toggle('is-done', hasDesign && hasRegion);
  }

  function processInk() {
    const c = AramInk.process(st.raw, { removeBg: true, ink: st.ink, thresh: 0.5, maxEdge: 900 });
    st.href = c ? c.toDataURL('image/png') : null;
  }

  function showControls() {
    controls.hidden = false;
  }


  /* ── drawing the design onto the body ─────────────────── */
  function renderInk() {
    if (!st.region || !st.href) { inkLayer.replaceChildren(); inkImg = null; return; }

    if (!inkImg) {
      inkImg = document.createElementNS(SVGNS, 'image');
      inkImg.setAttribute('clip-path', 'url(#inkClip)');
      inkImg.setAttribute('preserveAspectRatio', 'none');
      inkImg.style.mixBlendMode = AramInk.blendFor(st.ink);
      inkImg.style.cursor = 'grab';
      inkLayer.replaceChildren(inkImg);
    }

    const w = st.w;
    const h = w * st.ratio;

    inkImg.setAttribute('href', st.href);
    inkImg.setAttribute('x', st.x - w / 2);
    inkImg.setAttribute('y', st.y - h / 2);
    inkImg.setAttribute('width', w);
    inkImg.setAttribute('height', h);
    inkImg.setAttribute('transform', `rotate(${st.rot} ${st.x} ${st.y})`);
    inkImg.style.mixBlendMode = AramInk.blendFor(st.ink);
  }


  /* ── sliders + ink colour ─────────────────────────────── */
  const fill = (el) => {
    const p = ((el.value - el.min) / (el.max - el.min)) * 100;
    el.style.setProperty('--fill', `${p}%`);
  };

  sizeRange.addEventListener('input', () => {
    st.w = st.basis * (sizeRange.value / 100);
    sizeOut.textContent = `${sizeRange.value}%`;
    fill(sizeRange);
    renderInk();
  });

  rotRange.addEventListener('input', () => {
    st.rot = +rotRange.value;
    rotOut.textContent = `${st.rot}°`;
    fill(rotRange);
    renderInk();
  });

  [sizeRange, rotRange].forEach(fill);

  $('#bmapSwatches').addEventListener('click', (e) => {
    const sw = e.target.closest('.sw');
    if (!sw) return;
    $('#bmapSwatches').querySelectorAll('.sw').forEach((b) => {
      const on = b === sw;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-checked', String(on));
    });
    st.ink = sw.dataset.ink;
    processInk();
    renderInk();
  });


  /* ── dragging the design around inside the part ───────── */
  let drag = null;

  const toSvg = (evt) => {
    const pt = svg.createSVGPoint();
    pt.x = evt.clientX;
    pt.y = evt.clientY;
    const m = svg.getScreenCTM();
    return m ? pt.matrixTransform(m.inverse()) : { x: 0, y: 0 };
  };

  svg.addEventListener('pointerdown', (e) => {
    if (!inkImg || !st.href) return;
    // only start a drag when the press lands on the chosen part
    const onRegion = e.target === inkImg || e.target.closest('.reg') === st.region;
    if (!onRegion) return;

    const p = toSvg(e);
    drag = { dx: st.x - p.x, dy: st.y - p.y };
    svg.setPointerCapture(e.pointerId);
    inkImg.style.cursor = 'grabbing';
  });

  svg.addEventListener('pointermove', (e) => {
    if (!drag) return;
    const p = toSvg(e);
    st.x = p.x + drag.dx;
    st.y = p.y + drag.dy;
    renderInk();
  });

  const endDrag = () => {
    drag = null;
    if (inkImg) inkImg.style.cursor = 'grab';
  };
  svg.addEventListener('pointerup', endDrag);
  svg.addEventListener('pointercancel', endDrag);

  svg.addEventListener('wheel', (e) => {
    if (!st.region || !st.href) return;
    e.preventDefault();
    const next = AramInk.clamp(+sizeRange.value * (1 - e.deltaY * 0.0015),
      +sizeRange.min, +sizeRange.max);
    sizeRange.value = next;
    sizeRange.dispatchEvent(new Event('input'));
  }, { passive: false });


  /* ── reset ────────────────────────────────────────────── */
  function resetAll() {
    st.raw = null; st.href = null;
    fileInput.value = '';
    if (thumbImg.src.startsWith('blob:')) URL.revokeObjectURL(thumbImg.src);
    thumb.hidden = true;
    drop.hidden = false;
    sizeRange.value = 45; rotRange.value = 0;
    sizeOut.textContent = '45%'; rotOut.textContent = '0°';
    [sizeRange, rotRange].forEach(fill);
    say('');
    clearSelection();
  }

  $('#bmapReset').addEventListener('click', resetAll);
  $('#bmapThumbClear').addEventListener('click', resetAll);

  updateGuide();          // set the opening instruction

  function say(msg, bad = false) {
    note.textContent = msg;
    note.style.color = bad ? '#ff9b9b' : '';
  }


  /* ── send it with a booking ───────────────────────────── */
  /* The SVG is rendered to a canvas so the studio receives a flat
     picture of the body with the design in place, then uploaded
     through the same endpoint the Virtual Camera uses. */
  $('#bmapSend').addEventListener('click', async () => {
    if (!st.region) { say('Pick a body part first.', true); return; }
    if (!st.href)   { say('Upload a design first.', true); return; }

    const btn = $('#bmapSend');
    const label = btn.querySelector('span');
    const original = label.textContent;
    btn.disabled = true;
    label.textContent = 'Preparing…';

    try {
      const png = await renderToPng();

      const res = await fetch('api/upload-preview.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ image: png }),
      });
      const body = await res.json();

      if (body.ok) {
        sessionStorage.setItem('previewToken', body.token);
        sessionStorage.setItem('previewUrl', body.url);
      }
    } catch {
      /* backend not reachable — still carry the placement across */
    } finally {
      sessionStorage.setItem('placement', st.label);
      btn.disabled = false;
      label.textContent = original;
      location.hash = '#book';
      window.dispatchEvent(new Event('aram:placement'));
    }
  });

  /** Flatten the live SVG (body + design) into a PNG data URL. */
  function renderToPng() {
    return new Promise((resolve, reject) => {
      const clone = svg.cloneNode(true);
      clone.setAttribute('xmlns', SVGNS);
      clone.setAttribute('width', 600);
      clone.setAttribute('height', 1460);

      // hide whichever view is not showing
      const other = clone.querySelector(st.view === 'front' ? '#bodyBack' : '#bodyFront');
      other?.remove();

      // External CSS does not travel with a serialised SVG, so inline it.
      // Keep this in step with bodymap.css — no stroke, or the export comes
      // out looking like a jointed mannequin.
      const style = document.createElementNS(SVGNS, 'style');
      style.textContent = `
        .reg { fill:#e5c7ab; stroke:none; }
        .reg.is-on { fill:#f6e2cf; }
        image { mix-blend-mode:${AramInk.blendFor(st.ink)}; }
      `;
      clone.insertBefore(style, clone.firstChild);

      const svgText = new XMLSerializer().serializeToString(clone);
      const src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svgText)));

      const img = new Image();
      img.onload = () => {
        const c = document.createElement('canvas');
        c.width = 600; c.height = 1460;
        const cx = c.getContext('2d');
        cx.fillStyle = '#0a1c47';
        cx.fillRect(0, 0, c.width, c.height);
        cx.drawImage(img, 0, 0, c.width, c.height);
        resolve(c.toDataURL('image/png'));
      };
      img.onerror = () => reject(new Error('Could not render the body map.'));
      img.src = src;
    });
  }

})();
