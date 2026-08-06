/* ============================================================
   Aram's Tattoo — Virtual Camera

   Everything is composited into a single <canvas>: the camera
   frame first, then the processed design on top. That way the
   saved photo is guaranteed to match what's on screen.
   ============================================================ */
(() => {
  'use strict';

  const $ = (s) => document.querySelector(s);

  const stage   = $('#stage');
  const video   = $('#video');
  const canvas  = $('#canvas');
  const ctx     = canvas.getContext('2d');

  const CARD_CM = 8.56;   // ISO-7810 bank card, long edge
  const CARD_RATIO = 53.98 / 85.6;

  const st = {
    kind: null,          // 'camera' | 'photo'
    photo: null,         // <img> when using a still instead of the camera
    facing: 'environment',
    mirrored: false,
    stream: null,

    raw: null,           // uploaded design, as an <img>
    design: null,        // processed design, as a canvas
    x: 0.5, y: 0.5,      // design centre, as a fraction of the stage
    size: 32,            // design width, as a % of stage width
    rot: 0,              // degrees
    opacity: 0.9,
    ink: '#141821',
    removeBg: true,
    thresh: 0.5,

    calibrating: false,
    cardPct: 34,
    pxPerCm: null,
  };


  /* ── helpers ──────────────────────────────────────────── */
  const clamp = (v, lo, hi) => (v < lo ? lo : v > hi ? hi : v);

  const hexToRgb = (hex) => ({
    r: parseInt(hex.slice(1, 3), 16),
    g: parseInt(hex.slice(3, 5), 16),
    b: parseInt(hex.slice(5, 7), 16),
  });

  /** Rect that covers dst while preserving the source aspect ratio. */
  const coverRect = (sw, sh, dw, dh) => {
    const s = Math.max(dw / sw, dh / sh);
    const w = sw * s, h = sh * s;
    return { x: (dw - w) / 2, y: (dh - h) / 2, w, h };
  };

  const activeSource = () => {
    if (st.kind === 'camera' && video.videoWidth) return video;
    if (st.kind === 'photo' && st.photo) return st.photo;
    return null;
  };

  const sourceSize = (src) => src === video
    ? { w: video.videoWidth, h: video.videoHeight }
    : { w: src.naturalWidth, h: src.naturalHeight };


  /* ── canvas sizing ────────────────────────────────────── */
  /**
   * Match the canvas backing store to the box it is displayed in.
   * Returns true if anything changed.
   */
  function resizeCanvas() {
    const rect = stage.getBoundingClientRect();
    const dpr  = Math.min(devicePixelRatio || 1, 2);
    const w = Math.max(1, Math.round(rect.width  * dpr));
    const h = Math.max(1, Math.round(rect.height * dpr));

    if (canvas.width !== w || canvas.height !== h) {
      canvas.width = w;
      canvas.height = h;
      return true;
    }
    return false;
  }

  /* Three belts for one pair of trousers, deliberately.
     ResizeObserver is the good path, but it is missing on older Safari and
     can be inert in embedded webviews — and a canvas stuck at the wrong size
     silently ruins every saved photo. The loop below re-checks periodically,
     so the view repairs itself no matter what. */
  if (typeof ResizeObserver === 'function') {
    try {
      new ResizeObserver(() => resizeCanvas()).observe(stage);
    } catch { /* fall through to the listeners below */ }
  }
  addEventListener('resize', resizeCanvas);
  addEventListener('orientationchange', () => setTimeout(resizeCanvas, 200));
  resizeCanvas();


  /* ── design processing ────────────────────────────────── */
  /* The actual pixel work lives in ink.js so the body map on the
     main page uses exactly the same treatment. */
  function processDesign() {
    st.design = AramInk.process(st.raw, {
      removeBg: st.removeBg,
      ink: st.ink,
      thresh: st.thresh,
    });
  }


  /* ── render loop ──────────────────────────────────────── */
  let sizeTick = 0;

  function draw() {
    requestAnimationFrame(draw);

    // Cheap safety net: every ~20 frames confirm the canvas still matches its
    // box. getBoundingClientRect forces layout, so this is not per-frame.
    if (++sizeTick % 20 === 0) {
      resizeCanvas();
    }

    renderFrame();
  }

  /**
   * Paint one frame. Kept separate from the loop so a capture can force a
   * fresh frame instead of trusting whatever was last drawn — browsers pause
   * requestAnimationFrame in hidden/background tabs, and a stale canvas would
   * silently save a blank photo.
   */
  function renderFrame() {
    const src = activeSource();
    const W = canvas.width, H = canvas.height;
    if (!src) { ctx.clearRect(0, 0, W, H); return; }

    const { w: sw, h: sh } = sourceSize(src);
    if (!sw || !sh) return;

    const r = coverRect(sw, sh, W, H);

    ctx.save();
    if (st.mirrored) { ctx.translate(W, 0); ctx.scale(-1, 1); }
    ctx.drawImage(src, r.x, r.y, r.w, r.h);
    ctx.restore();

    if (st.design) {
      const dw = (st.size / 100) * W;
      const dh = dw * (st.design.height / st.design.width);

      ctx.save();
      ctx.globalAlpha = st.opacity;
      ctx.globalCompositeOperation = AramInk.blendFor(st.ink);
      ctx.translate(st.x * W, st.y * H);
      ctx.rotate((st.rot * Math.PI) / 180);
      ctx.drawImage(st.design, -dw / 2, -dh / 2, dw, dh);
      ctx.restore();
    }

    if (st.calibrating) drawCard(W, H);
  }

  function drawCard(W, H) {
    const cw = (st.cardPct / 100) * W;
    const ch = cw * CARD_RATIO;
    const x = (W - cw) / 2, y = (H - ch) / 2;

    ctx.save();
    ctx.lineWidth = Math.max(2, W * 0.004);
    ctx.setLineDash([ctx.lineWidth * 3, ctx.lineWidth * 2]);
    ctx.strokeStyle = '#a5d8f3';
    ctx.shadowColor = 'rgba(0,0,0,.6)';
    ctx.shadowBlur = 8;
    ctx.strokeRect(x, y, cw, ch);
    ctx.restore();
  }

  requestAnimationFrame(draw);


  /* ── camera ───────────────────────────────────────────── */
  const startPane = $('#startPane');
  const paneTitle = $('#paneTitle');
  const paneText  = $('#paneText');
  const paneHint  = $('#paneHint');
  const tools     = $('#stageTools');
  const gestureHint = $('#gestureHint');

  function showPane(title, text, { error = false, hint } = {}) {
    paneTitle.textContent = title;
    paneText.textContent = text;
    if (hint !== undefined) paneHint.textContent = hint;
    startPane.querySelector('.stage__card').classList.toggle('is-error', error);
    startPane.hidden = false;
    tools.hidden = true;
  }

  function hidePane() {
    startPane.hidden = true;
    tools.hidden = false;
    gestureHint.hidden = false;
    clearTimeout(hidePane._t);
    gestureHint.classList.remove('is-faded');
    hidePane._t = setTimeout(() => gestureHint.classList.add('is-faded'), 5000);
  }

  function stopStream() {
    if (st.stream) {
      st.stream.getTracks().forEach((t) => t.stop());
      st.stream = null;
    }
  }

  async function startCamera(facing = st.facing) {
    if (!navigator.mediaDevices?.getUserMedia) {
      showPane('Camera not available',
        'This browser does not support camera access. You can still upload a photo of the spot instead.',
        { error: true, hint: '' });
      return false;
    }

    // getUserMedia only works on https:// or localhost
    if (!window.isSecureContext) {
      showPane('Needs a secure connection',
        'Browsers only allow camera access over https:// (or localhost). Upload a photo instead, or open this page over HTTPS.',
        { error: true, hint: '' });
      return false;
    }

    stopStream();
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: facing, width: { ideal: 1920 }, height: { ideal: 1080 } },
        audio: false,
      });

      st.stream = stream;
      st.kind = 'camera';
      st.facing = facing;
      st.mirrored = facing === 'user';
      st.photo = null;

      video.srcObject = stream;
      await video.play().catch(() => {});
      hidePane();
      return true;

    } catch (err) {
      const map = {
        NotAllowedError: ['Camera blocked',
          'You (or the browser) turned the camera down. Allow camera access in the address bar and try again — or just upload a photo.'],
        NotFoundError: ['No camera found',
          'This device has no camera the browser can reach. Upload a photo of the spot instead.'],
        NotReadableError: ['Camera is busy',
          'Another app is already using the camera. Close it and try again.'],
      };
      const [title, text] = map[err.name] ||
        ['Could not start the camera', err.message || 'Something went wrong. Try again, or upload a photo instead.'];
      showPane(title, text, { error: true, hint: '' });
      return false;
    }
  }

  $('#startCam').addEventListener('click', () => startCamera());

  $('#flipCam').addEventListener('click', async () => {
    if (st.kind === 'photo') { $('#photoInput').click(); return; }
    const next = st.facing === 'environment' ? 'user' : 'environment';
    const ok = await startCamera(next);
    if (!ok) startCamera(st.facing);   // fall back to the one that worked
  });

  addEventListener('pagehide', stopStream);


  /* ── photo fallback ───────────────────────────────────── */
  const photoInput = $('#photoInput');
  $('#usePhoto').addEventListener('click', () => photoInput.click());

  photoInput.addEventListener('change', () => {
    const file = photoInput.files?.[0];
    if (!file) return;
    const img = new Image();
    img.onload = () => {
      stopStream();
      st.photo = img;
      st.kind = 'photo';
      st.mirrored = false;
      hidePane();
    };
    img.src = URL.createObjectURL(file);
  });


  /* ── design upload ────────────────────────────────────── */
  const drop      = $('#drop');
  const fileInput = $('#fileInput');
  const thumb     = $('#thumb');
  const thumbImg  = $('#thumbImg');
  const thumbName = $('#thumbName');
  const thumbSize = $('#thumbSize');
  const sizeBlock = $('#sizeBlock');

  function loadDesign(file) {
    if (!file || !file.type.startsWith('image/')) return;

    const url = URL.createObjectURL(file);
    const img = new Image();

    img.onload = () => {
      st.raw = img;
      st.x = 0.5; st.y = 0.5; st.rot = 0;
      processDesign();

      thumbImg.src = url;
      thumbName.textContent = file.name;
      thumbSize.textContent = `${img.naturalWidth} × ${img.naturalHeight}px`;
      thumb.hidden = false;
      drop.hidden = true;
      sizeBlock.classList.remove('is-off');
      canvas.classList.remove('is-empty');
      rotRange.value = 0;
      syncRange(rotRange); rotOut.textContent = '0°';
      updateSizeOut();
    };

    img.onerror = () => {
      URL.revokeObjectURL(url);
      thumbName.textContent = '';
      alert('That file could not be read as an image. Try a PNG or JPG.');
    };

    img.src = url;
  }

  fileInput.addEventListener('change', () => loadDesign(fileInput.files?.[0]));

  ['dragenter', 'dragover'].forEach((ev) =>
    drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach((ev) =>
    drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
  drop.addEventListener('drop', (e) => loadDesign(e.dataTransfer?.files?.[0]));

  // dropping straight onto the camera view works too
  stage.addEventListener('dragover', (e) => e.preventDefault());
  stage.addEventListener('drop', (e) => {
    e.preventDefault();
    loadDesign(e.dataTransfer?.files?.[0]);
  });

  $('#clearDesign').addEventListener('click', () => {
    st.raw = null; st.design = null;
    thumb.hidden = true;
    drop.hidden = false;
    fileInput.value = '';
    sizeBlock.classList.add('is-off');
    canvas.classList.add('is-empty');
  });

  sizeBlock.classList.add('is-off');
  canvas.classList.add('is-empty');


  /* ── sliders ──────────────────────────────────────────── */
  function syncRange(el) {
    const pct = ((el.value - el.min) / (el.max - el.min)) * 100;
    el.style.setProperty('--fill', `${pct}%`);
  }

  const sizeRange = $('#sizeRange'), sizeOut = $('#sizeOut');
  const rotRange  = $('#rotRange'),  rotOut  = $('#rotOut');
  const opRange   = $('#opacityRange'), opOut = $('#opacityOut');
  const threshRange = $('#threshRange'), threshOut = $('#threshOut');
  const calibRange = $('#calibRange');

  function updateSizeOut() {
    if (st.pxPerCm) {
      const px = (st.size / 100) * canvas.getBoundingClientRect().width;
      sizeOut.textContent = `${(px / st.pxPerCm).toFixed(1)} cm wide`;
    } else {
      sizeOut.textContent = `${Math.round(st.size)}%`;
    }
  }

  sizeRange.addEventListener('input', () => {
    st.size = +sizeRange.value; syncRange(sizeRange); updateSizeOut();
  });

  rotRange.addEventListener('input', () => {
    st.rot = +rotRange.value; syncRange(rotRange);
    rotOut.textContent = `${st.rot}°`;
  });

  opRange.addEventListener('input', () => {
    st.opacity = +opRange.value / 100; syncRange(opRange);
    opOut.textContent = `${opRange.value}%`;
  });

  threshRange.addEventListener('input', () => {
    st.thresh = +threshRange.value / 100; syncRange(threshRange);
    threshOut.textContent = `${threshRange.value}%`;
    processDesign();
  });

  calibRange.addEventListener('input', () => {
    st.cardPct = +calibRange.value; syncRange(calibRange);
  });

  [sizeRange, rotRange, opRange, threshRange, calibRange].forEach(syncRange);
  updateSizeOut();


  /* ── ink + background ─────────────────────────────────── */
  $('#swatches').addEventListener('click', (e) => {
    const sw = e.target.closest('.sw');
    if (!sw) return;
    document.querySelectorAll('.sw').forEach((b) => {
      const on = b === sw;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-checked', String(on));
    });
    st.ink = sw.dataset.ink;
    processDesign();
  });

  const removeBgBox = $('#removeBg');
  const thresholdCtrl = $('#thresholdCtrl');

  removeBgBox.addEventListener('change', () => {
    st.removeBg = removeBgBox.checked;
    thresholdCtrl.style.display = st.removeBg ? '' : 'none';
    processDesign();
  });


  /* ── calibration ──────────────────────────────────────── */
  const calibToggle = $('#calibToggle');
  const calibBox = $('#calibBox');

  calibToggle.addEventListener('click', () => {
    const open = calibBox.hidden;
    calibBox.hidden = !open;
    st.calibrating = open;
    calibToggle.setAttribute('aria-expanded', String(open));
  });

  $('#calibDone').addEventListener('click', () => {
    const px = (st.cardPct / 100) * canvas.getBoundingClientRect().width;
    st.pxPerCm = px / CARD_CM;
    st.calibrating = false;
    calibBox.hidden = true;
    calibToggle.setAttribute('aria-expanded', 'false');
    calibToggle.querySelector('span').textContent = 'Re-measure real size';
    updateSizeOut();
  });


  /* ── drag / pinch / wheel on the stage ────────────────── */
  const pointers = new Map();
  let gesture = null;

  const rectOf = () => canvas.getBoundingClientRect();

  function beginGesture() {
    const pts = [...pointers.values()];
    if (pts.length === 1) {
      gesture = { mode: 'drag', last: { ...pts[0] } };
    } else if (pts.length >= 2) {
      const [a, b] = pts;
      gesture = {
        mode: 'pinch',
        dist: Math.hypot(b.x - a.x, b.y - a.y) || 1,
        ang: Math.atan2(b.y - a.y, b.x - a.x),
        mid: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 },
        size: st.size,
        rot: st.rot,
      };
    }
  }

  canvas.addEventListener('pointerdown', (e) => {
    if (!st.design) return;
    canvas.setPointerCapture(e.pointerId);
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    beginGesture();
    canvas.classList.add('is-dragging');
  });

  canvas.addEventListener('pointermove', (e) => {
    if (!pointers.has(e.pointerId) || !gesture) return;
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    const r = rectOf();
    const pts = [...pointers.values()];

    if (gesture.mode === 'drag' && pts.length === 1) {
      const p = pts[0];
      st.x = clamp(st.x + (p.x - gesture.last.x) / r.width, 0, 1);
      st.y = clamp(st.y + (p.y - gesture.last.y) / r.height, 0, 1);
      gesture.last = { ...p };

    } else if (gesture.mode === 'pinch' && pts.length >= 2) {
      const [a, b] = pts;
      const dist = Math.hypot(b.x - a.x, b.y - a.y) || 1;
      const ang  = Math.atan2(b.y - a.y, b.x - a.x);
      const mid  = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };

      st.size = clamp(gesture.size * (dist / gesture.dist), +sizeRange.min, +sizeRange.max);
      st.rot  = clamp(gesture.rot + ((ang - gesture.ang) * 180) / Math.PI, -180, 180);
      st.x = clamp(st.x + (mid.x - gesture.mid.x) / r.width, 0, 1);
      st.y = clamp(st.y + (mid.y - gesture.mid.y) / r.height, 0, 1);
      gesture.mid = mid;

      sizeRange.value = st.size; syncRange(sizeRange); updateSizeOut();
      rotRange.value = Math.round(st.rot); syncRange(rotRange);
      rotOut.textContent = `${Math.round(st.rot)}°`;
    }
  });

  function endPointer(e) {
    pointers.delete(e.pointerId);
    if (pointers.size === 0) {
      gesture = null;
      canvas.classList.remove('is-dragging');
    } else {
      beginGesture();
    }
  }
  canvas.addEventListener('pointerup', endPointer);
  canvas.addEventListener('pointercancel', endPointer);

  canvas.addEventListener('wheel', (e) => {
    if (!st.design) return;
    e.preventDefault();
    st.size = clamp(st.size * (1 - e.deltaY * 0.0015), +sizeRange.min, +sizeRange.max);
    sizeRange.value = st.size; syncRange(sizeRange); updateSizeOut();
  }, { passive: false });


  /* ── reset ────────────────────────────────────────────── */
  $('#resetBtn').addEventListener('click', () => {
    st.x = 0.5; st.y = 0.5; st.rot = 0; st.size = 32;
    sizeRange.value = 32; rotRange.value = 0;
    syncRange(sizeRange); syncRange(rotRange);
    rotOut.textContent = '0°';
    updateSizeOut();
  });


  /* ── snapshot ─────────────────────────────────────────── */
  const shotModal = $('#shotModal');
  const shotImg   = $('#shotImg');
  const shotSave  = $('#shotSave');
  const shotShare = $('#shotShare');

  const MIN_SHOT = 200;   // px — below this the canvas has collapsed

  function takeShot() {
    if (!activeSource()) return;

    // If the stage has no real size (page still laying out, window collapsed,
    // odd mobile chrome) a capture would save a sliver of pixels. Refuse it
    // rather than hand the studio a useless photo.
    if (canvas.width < MIN_SHOT || canvas.height < MIN_SHOT) {
      resizeCanvas();
      if (canvas.width < MIN_SHOT || canvas.height < MIN_SHOT) {
        showPane('Give the window a moment',
          'The camera view has not finished sizing itself. Resize the window or rotate the phone, then try the shutter again.',
          { error: true, hint: '' });
        return;
      }
    }

    stage.classList.add('is-flashing');
    setTimeout(() => stage.classList.remove('is-flashing'), 460);

    renderFrame();                     // guarantee the pixels are current
    const url = canvas.toDataURL('image/png');
    shotImg.src = url;
    shotSave.href = url;
    shotModal.hidden = false;

    canvas.toBlob((blob) => {
      if (!blob) return;
      const file = new File([blob], 'arams-tattoo-preview.png', { type: 'image/png' });
      shotShare.hidden = !(navigator.canShare?.({ files: [file] }));
      shotShare.onclick = () => navigator.share({
        files: [file],
        title: "Aram's Tattoo preview",
      }).catch(() => {});
    }, 'image/png');
  }

  $('#shotBtn').addEventListener('click', takeShot);
  $('#shotBtn2').addEventListener('click', takeShot);
  $('#shotClose').addEventListener('click', () => { shotModal.hidden = true; });


  /* ── send the shot with a booking ─────────────────────────
     Uploads to the API, keeps the returned token in sessionStorage,
     and the booking form on the main page sends it back so the two
     get linked. If the backend isn't installed we just go to the
     booking form without an attachment.                            */
  const shotBook = $('#shotBook');

  shotBook?.addEventListener('click', async () => {
    const label = shotBook.querySelector('span');
    const original = label.textContent;
    label.textContent = 'Attaching…';
    shotBook.disabled = true;

    try {
      renderFrame();                   // same guarantee before uploading
      const res = await fetch('api/upload-preview.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          image: canvas.toDataURL('image/jpeg', 0.86),
          size_cm: st.pxPerCm
            ? +(((st.size / 100) * canvas.getBoundingClientRect().width) / st.pxPerCm).toFixed(2)
            : null,
        }),
      });

      const body = await res.json();
      if (body.ok) {
        sessionStorage.setItem('previewToken', body.token);
        sessionStorage.setItem('previewUrl', body.url);
      }
    } catch {
      /* backend not reachable — carry on without the attachment */
    } finally {
      location.href = 'index.html#book';
    }
  });

  addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !shotModal.hidden) shotModal.hidden = true;
  });

})();
