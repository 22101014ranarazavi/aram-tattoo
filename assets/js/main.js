/* ============================================================
   Aram's Tattoo — interactions
   ============================================================ */
(() => {
  'use strict';

  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  /* ── current year ─────────────────────────────────────────── */
  $('#year').textContent = new Date().getFullYear();


  /* ── sticky navbar + scroll progress + back-to-top ────────── */
  const nav      = $('#nav');
  const bar      = $('.scroll-progress span');
  const toTop    = $('#toTop');

  const onScroll = () => {
    const y   = window.scrollY;
    const max = document.documentElement.scrollHeight - innerHeight;

    nav.classList.toggle('is-stuck', y > 30);
    toTop.classList.toggle('is-shown', y > 600);
    bar.style.width = max > 0 ? `${(y / max) * 100}%` : '0%';
  };
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();


  /* ── mobile menu ──────────────────────────────────────────── */
  const burger = $('#burger');
  const links  = $('#navLinks');
  const scrim  = $('#navScrim');

  // stagger the slide-in of each item
  $$('.nav__link', links).forEach((a, i) => a.style.setProperty('--i', `${0.06 * i + 0.1}s`));

  const setMenu = (open) => {
    links.classList.toggle('is-open', open);
    burger.classList.toggle('is-open', open);
    burger.setAttribute('aria-expanded', String(open));
    burger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    document.body.classList.toggle('is-locked', open);

    if (open) {
      scrim.hidden = false;
      requestAnimationFrame(() => scrim.classList.add('is-shown'));
    } else {
      scrim.classList.remove('is-shown');
      setTimeout(() => { scrim.hidden = true; }, 350);
    }
  };

  burger.addEventListener('click', () => setMenu(!links.classList.contains('is-open')));
  scrim.addEventListener('click', () => setMenu(false));
  links.addEventListener('click', (e) => { if (e.target.closest('a')) setMenu(false); });
  addEventListener('keydown', (e) => { if (e.key === 'Escape') setMenu(false); });
  // reset when growing back to desktop
  matchMedia('(min-width: 861px)').addEventListener('change', (e) => { if (e.matches) setMenu(false); });


  /* ── scroll reveal ────────────────────────────────────────── */
  const revealIO = new IntersectionObserver((entries) => {
    entries.forEach((en) => {
      if (!en.isIntersecting) return;
      en.target.classList.add('is-in');
      revealIO.unobserve(en.target);
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });

  $$('.reveal').forEach((el) => revealIO.observe(el));


  /* ── active nav link while scrolling ──────────────────────── */
  const navLinks = $$('.nav__link');
  const sections = navLinks
    .map((a) => a.getAttribute('href'))
    .filter((href) => href.startsWith('#'))   // Virtual Camera points at its own page
    .map((href) => $(href))
    .filter(Boolean);

  const spyIO = new IntersectionObserver((entries) => {
    entries.forEach((en) => {
      if (!en.isIntersecting) return;
      navLinks.forEach((a) =>
        a.classList.toggle('is-active', a.getAttribute('href') === `#${en.target.id}`));
    });
    // threshold stays 0: the root margin already narrows this to a thin band
    // near the top of the screen, and a section taller than that band can
    // never reach a higher ratio.
  }, { threshold: 0, rootMargin: '-30% 0px -55% 0px' });

  sections.forEach((s) => spyIO.observe(s));


  /* ── counting stats ───────────────────────────────────────── */
  const countIO = new IntersectionObserver((entries) => {
    entries.forEach((en) => {
      if (!en.isIntersecting) return;
      const el     = en.target;
      const target = +el.dataset.count;
      const suffix = el.dataset.suffix || '';
      countIO.unobserve(el);

      if (reduced) { el.textContent = target + suffix; return; }

      const start = performance.now();
      const tick = (now) => {
        const p = Math.min((now - start) / 1400, 1);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.round(target * eased) + suffix;
        if (p < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    });
  }, { threshold: 0.5 });

  $$('[data-count]').forEach((el) => countIO.observe(el));


  /* ── cursor glow (pointer devices only) ───────────────────── */
  const glow = $('.cursor-glow');
  if (matchMedia('(pointer: fine)').matches && !reduced) {
    let gx = 0, gy = 0, cx = 0, cy = 0;

    addEventListener('mousemove', (e) => {
      gx = e.clientX; gy = e.clientY;
      glow.style.opacity = '1';
    }, { passive: true });

    (function follow() {
      cx += (gx - cx) * 0.12;
      cy += (gy - cy) * 0.12;
      glow.style.transform = `translate3d(${cx}px, ${cy}px, 0)`;
      requestAnimationFrame(follow);
    })();
  }


  /* ── hero parallax ────────────────────────────────────────── */
  const heroLayers = $$('.hero__bg, .hero__video');
  if (heroLayers.length && !reduced) {
    addEventListener('scroll', () => {
      const y = window.scrollY;
      if (y >= innerHeight * 1.2) return;
      const shift = `0 ${y * 0.28}px`;
      heroLayers.forEach((el) => { el.style.translate = shift; });
    }, { passive: true });
  }


  /* ── hero video ───────────────────────────────────────────── */
  /* Held back until we know it is wanted: 3MB is rude to push at someone
     on mobile data, and it is pure decoration — the poster already says
     everything the video does. */
  const heroVideo = $('#heroVideo');
  if (heroVideo) {
    const conn = navigator.connection || {};
    const stingy = conn.saveData === true
      || /^(slow-)?2g$/.test(conn.effectiveType || '');

    if (reduced || stingy) {
      heroVideo.removeAttribute('autoplay');
      heroVideo.remove();                    // poster image carries the hero
    } else {
      heroVideo.preload = 'auto';
      heroVideo.load();

      const reveal = () => heroVideo.classList.add('is-playing');
      heroVideo.addEventListener('playing', reveal, { once: true });

      // Autoplay can still be refused even when muted; if so, the poster
      // simply stays, which is a perfectly good hero.
      heroVideo.play?.().catch(() => {});

      // Stop decoding while the hero is off-screen.
      const vidIO = new IntersectionObserver(([en]) => {
        if (en.isIntersecting) heroVideo.play?.().catch(() => {});
        else heroVideo.pause();
      }, { threshold: 0.01 });
      vidIO.observe(heroVideo);
    }
  }


  /* ── floating ink particles ───────────────────────────────── */
  const canvas = $('#particles');
  if (canvas && !reduced) {
    const ctx = canvas.getContext('2d');
    let w, h, dots = [];

    const build = () => {
      const dpr = Math.min(devicePixelRatio || 1, 2);
      const rect = canvas.getBoundingClientRect();
      w = rect.width; h = rect.height;
      canvas.width  = w * dpr;
      canvas.height = h * dpr;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

      const count = Math.round(Math.min(w * h / 16000, 90));
      dots = Array.from({ length: count }, () => ({
        x: Math.random() * w,
        y: Math.random() * h,
        r: Math.random() * 1.9 + 0.5,
        vx: (Math.random() - 0.5) * 0.22,
        vy: -(Math.random() * 0.3 + 0.06),
        a: Math.random() * 0.45 + 0.12,
        t: Math.random() * Math.PI * 2,
      }));
    };

    const draw = () => {
      ctx.clearRect(0, 0, w, h);

      for (const d of dots) {
        d.t += 0.012;
        d.x += d.vx + Math.sin(d.t) * 0.16;
        d.y += d.vy;

        if (d.y < -12) { d.y = h + 10; d.x = Math.random() * w; }
        if (d.x < -12) d.x = w + 10;
        if (d.x > w + 12) d.x = -10;

        const twinkle = d.a * (0.65 + Math.sin(d.t * 1.7) * 0.35);
        ctx.beginPath();
        ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);
        ctx.fillStyle = `rgba(165, 216, 243, ${twinkle})`;
        ctx.shadowBlur = 8;
        ctx.shadowColor = 'rgba(124, 196, 238, .55)';
        ctx.fill();
      }
      requestAnimationFrame(draw);
    };

    build();
    draw();

    let rt;
    addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(build, 200); });
  }


  /* ── talking to the PHP API ───────────────────────────────── */
  const API = 'api/';

  const say = (el, msg, bad = false) => {
    el.textContent = msg;
    el.style.color = bad ? '#ff9b9b' : '';
  };

  /** POST JSON and always come back with a usable object. */
  async function post(endpoint, data) {
    const res = await fetch(API + endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });

    let body = {};
    try { body = await res.json(); } catch { /* server returned non-JSON */ }

    if (!res.ok || !body.ok) {
      throw Object.assign(
        new Error(body.error || 'Something went wrong. Please try again.'),
        { fields: body.fields || {} }
      );
    }
    return body;
  }


  /* ── booking form ─────────────────────────────────────────── */
  const bookForm = $('#bookForm');
  const formNote = $('#formNote');

  bookForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const missing = ['#bname', '#bmail', '#bdate', '#bidea']
      .map((sel) => $(sel))
      .filter((f) => !f.value.trim());

    if (missing.length) {
      say(formNote, 'Please fill in your name, email, date and idea.', true);
      missing[0].focus();
      return;
    }

    const btn = bookForm.querySelector('button[type="submit"]');
    const label = btn.querySelector('span');
    const original = label.textContent;

    btn.disabled = true;
    label.textContent = 'Sending…';
    say(formNote, '');

    try {
      const body = await post('book.php', {
        name:  $('#bname').value,
        email: $('#bmail').value,
        phone: $('#bphone').value,
        date:  $('#bdate').value,
        style: $('#bstyle').value,
        idea:  $('#bidea').value,
        website: $('#bwebsite')?.value || '',            // honeypot
        preview_token: sessionStorage.getItem('previewToken') || '',
        placement: sessionStorage.getItem('placement') || '',
      });

      say(formNote, body.message || 'Request sent.');
      bookForm.reset();
      sessionStorage.removeItem('previewToken');         // used once
      sessionStorage.removeItem('previewUrl');
      sessionStorage.removeItem('placement');
      $('#previewAttached')?.setAttribute('hidden', '');

    } catch (err) {
      say(formNote, err.message, true);
      const first = Object.keys(err.fields || {})[0];
      if (first) {
        const map = { name: '#bname', email: '#bmail', date: '#bdate', idea: '#bidea' };
        $(map[first] || '')?.focus();
      }
    } finally {
      btn.disabled = false;
      label.textContent = original;
    }
  });


  /* ── newsletter ───────────────────────────────────────────── */
  const subForm = $('#subForm');
  const subNote = $('#subNote');

  subForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = subForm.querySelector('input[type="email"]');

    try {
      const body = await post('subscribe.php', {
        email: input.value,
        website: $('#swebsite')?.value || '',
      });
      say(subNote, body.message);
      subForm.reset();
    } catch (err) {
      say(subNote, err.message, true);
    }
  });


  /* ── show what they attached (camera shot and/or placement) ─── */
  const attached = $('#previewAttached');

  function refreshAttachment() {
    if (!attached) return;

    const url = sessionStorage.getItem('previewUrl');
    const place = sessionStorage.getItem('placement');
    if (!url && !place) { attached.hidden = true; return; }

    const img = attached.querySelector('img');
    if (url) {
      img.src = url;
      img.hidden = false;
    } else {
      img.hidden = true;
    }

    attached.querySelector('strong').textContent =
      place ? `Going on: ${place}` : 'Camera preview attached';
    attached.querySelector('span').textContent =
      url ? "I'll see exactly how you placed it." : 'Picked on the body map.';

    attached.hidden = false;
  }

  refreshAttachment();
  addEventListener('aram:placement', refreshAttachment);

  attached?.querySelector('button')?.addEventListener('click', () => {
    sessionStorage.removeItem('previewToken');
    sessionStorage.removeItem('previewUrl');
    sessionStorage.removeItem('placement');
    attached.hidden = true;
  });


  /* ── live portfolio ───────────────────────────────────────────
     Replaces the four hard-coded cards once designs exist in the
     database. If the API is missing (site opened as a plain file,
     or backend not installed yet) the static cards simply stay.   */
  const cards = $('.cards');
  if (cards) {
    fetch(API + 'designs.php?limit=12')
      .then((r) => (r.ok ? r.json() : null))
      .then((body) => {
        if (!body?.ok || !body.designs?.length) return;

        cards.innerHTML = body.designs.map((d, i) => `
          <article class="card reveal" style="--d:${(i % 4) * 0.1}s">
            <img class="card__shot" src="${escapeAttr(d.image_url)}"
                 alt="${escapeAttr(d.title)}" loading="lazy">
            <h3>${escapeHtml(d.title)}</h3>
            ${d.description ? `<p>${escapeHtml(d.description)}</p>` : ''}
            <span class="card__meta">${escapeHtml(d.style)}${
              d.price_from ? ` · from $${Math.round(d.price_from)}` : ''
            }</span>
          </article>`).join('');

        $$('.reveal', cards).forEach((el) => revealIO.observe(el));
      })
      .catch(() => { /* keep the static cards */ });
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
  }
  const escapeAttr = escapeHtml;

})();
