/**
 * Pindrop Mobile Engine v1.2.0
 * Transforms a Pindrop PHP app into a native-feeling mobile experience.
 * On desktop: lightweight SPA with prefetch + subtle transitions.
 * On mobile: full native app feel — slides, gestures, skeleton, bottom nav.
 *
 * Reads config from: window.__PINDROP_MOBILE__
 * DOM morphing:      idiomorph (bundled or loaded from CDN)
 */

(function (global) {
  "use strict";

  // ─────────────────────────────────────────────
  // 0. BOOTSTRAP
  // ─────────────────────────────────────────────

  const settings         = global.__PINDROP_MOBILE__ || {};
  const engine           = settings.engine || {};
  const theme            = settings.theme || {};
  const transitionConfig = settings.transitions || {};
  const bottomNavConfig  = settings.bottom_navigation || {};
  const gestureConfig    = settings.gestures || {};
  const skeletonConfig   = settings.skeleton || {};

  const CONTENT_SELECTOR    = engine.content_selector || "#app-content";
  const TRANSITION_DURATION = transitionConfig.duration || 300;
  const TRANSITION_TYPE     = transitionConfig.type || "slide";

  const historyStack = [];
  let isNavigating   = false;
  let currentPath    = location.pathname;

  // ─────────────────────────────────────────────
  // 1. IDIOMORPH LOADER
  // ─────────────────────────────────────────────

  const idiomorphReady = (function () {
    if (global.Idiomorph) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const script    = document.createElement("script");
      script.src      = "https://cdn.jsdelivr.net/npm/idiomorph@0.3.0/dist/idiomorph.min.js";
      script.crossOrigin = "anonymous";
      script.onload   = () => resolve();
      script.onerror  = () => {
        const fallback    = document.createElement("script");
        fallback.src      = "https://unpkg.com/idiomorph@0.3.0/dist/idiomorph.min.js";
        fallback.onload   = () => resolve();
        fallback.onerror  = () => reject(new Error("Failed to load idiomorph"));
        document.head.appendChild(fallback);
      };
      document.head.appendChild(script);
    });
  })();

  async function morphContent(oldEl, newEl) {
    await idiomorphReady;
    if (global.Idiomorph) {
      global.Idiomorph.morph(oldEl, newEl, {
        morphStyle: "innerHTML",
        callbacks: {
          beforeNodeMorphed(oldNode) {
            if (
              oldNode === document.activeElement &&
              (oldNode.tagName === "INPUT" || oldNode.tagName === "TEXTAREA")
            ) return false;
          },
        },
      });
    } else {
      oldEl.innerHTML = newEl.innerHTML;
    }
  }

  // ─────────────────────────────────────────────
  // 2. DEVICE DETECTION
  // ─────────────────────────────────────────────

  function detectDevice() {
    const ua    = navigator.userAgent;
    const touch = navigator.maxTouchPoints > 1;
    const width = window.innerWidth;
    if (/Mobi|Android|iPhone/i.test(ua)) return "mobile";
    if (/iPad|Tablet/i.test(ua) || (touch && width < 1024)) return "tablet";
    return "desktop";
  }

  const DEVICE    = detectDevice();
  const IS_MOBILE = DEVICE === "mobile" || DEVICE === "tablet";

  // ─────────────────────────────────────────────
  // 3. UTILITIES
  // ─────────────────────────────────────────────

  function $(selector, root = document) {
    return root.querySelector(selector);
  }

  function $$(selector, root = document) {
    return Array.from(root.querySelectorAll(selector));
  }

  function isSameOrigin(url) {
    try { return new URL(url, location.origin).origin === location.origin; }
    catch { return false; }
  }

  function isNavigableLink(el) {
    const link = el.closest("a[href]");
    if (!link) return null;
    const href = link.getAttribute("href");
    if (!href || href.startsWith("#") || href.startsWith("mailto:") || href.startsWith("tel:")) return null;
    if (link.target === "_blank") return null;
    if (!isSameOrigin(link.href)) return null;
    return link;
  }

  // ─────────────────────────────────────────────
  // 4. PROGRESS BAR
  // ─────────────────────────────────────────────

  const Progress = {
    el: null, timer: null,

    init() {
      this.el    = document.createElement("div");
      this.el.id = "pd-progress";
      document.body.prepend(this.el);
    },

    start() {
      clearTimeout(this.timer);
      this.el.className    = "active";
      this.el.style.width  = "0%";
      let width = 0;
      const tick = () => {
        if (width < 85) {
          width += Math.random() * 15;
          this.el.style.width = Math.min(width, 85) + "%";
          this.timer = setTimeout(tick, 200 + Math.random() * 200);
        }
      };
      setTimeout(tick, 50);
    },

    done() {
      clearTimeout(this.timer);
      this.el.style.width = "100%";
      this.el.classList.add("complete");
      setTimeout(() => { this.el.className = ""; this.el.style.width = "0%"; }, 600);
    },

    fail() {
      clearTimeout(this.timer);
      this.el.style.background = "#e53935";
      this.done();
      setTimeout(() => { this.el.style.background = ""; }, 800);
    },
  };

  // ─────────────────────────────────────────────
  // 5. SKELETON SCREEN
  // ─────────────────────────────────────────────

  function buildSkeleton() {
    if (!skeletonConfig.enabled) return "<div style='padding:20px'>Loading...</div>";
    return `
      <div class="pd-skeleton">
        <div class="pd-skeleton-line short"></div>
        <div class="pd-skeleton-block"></div>
        <div class="pd-skeleton-line long"></div>
        <div class="pd-skeleton-line medium"></div>
        <div class="pd-skeleton-line long"></div>
        <div class="pd-skeleton-line short"></div>
        <div class="pd-skeleton-block" style="height:100px"></div>
        <div class="pd-skeleton-line medium"></div>
        <div class="pd-skeleton-line long"></div>
      </div>
    `;
  }

  // ─────────────────────────────────────────────
  // 6. PAGE FETCHER
  // ─────────────────────────────────────────────

  const fetchCache = new Map();

  async function fetchPage(url) {
    if (fetchCache.has(url)) return fetchCache.get(url);
    const res = await fetch(url, {
      headers: { "X-Pindrop-Engine": "1" },
      credentials: "same-origin",
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const html = await res.text();
    fetchCache.set(url, html);
    setTimeout(() => fetchCache.delete(url), 5 * 60 * 1000);
    return html;
  }

  function parsePageContent(html) {
    const doc     = new DOMParser().parseFromString(html, "text/html");
    const content = $(CONTENT_SELECTOR, doc);
    const title   = doc.title;
    return { content, title, doc };
  }

  // ─────────────────────────────────────────────
  // 7. TRANSITIONS
  // ─────────────────────────────────────────────

  /**
   * FIX: All transitions now use Web Animations API (element.animate())
   * instead of CSS animation classes.
   *
   * CSS classes with `animation-fill-mode: forwards` were the root cause
   * of the blink — after the OUT animation the element stayed at
   * translateX(-30%) / opacity:0.4 via fill-mode, so after morphing the
   * content appeared then immediately "disappeared" (it was just held
   * off-screen by the stale fill).
   *
   * Web Animations API lets us await the animation and it automatically
   * cleans up when done — no fill-mode residue, no class collision.
   */

  function animateEl(el, keyframes, duration) {
    return el.animate(keyframes, {
      duration,
      easing: "cubic-bezier(0.4, 0, 0.2, 1)",
      fill: "none",   // ← critical: NO fill-mode, element always returns to natural state
    }).finished;
  }

  async function transitionOut(contentEl, direction) {
    if (!IS_MOBILE || TRANSITION_TYPE === "none") return;

    if (TRANSITION_TYPE === "fade") {
      await animateEl(contentEl,
        [{ opacity: 1 }, { opacity: 0 }],
        TRANSITION_DURATION
      );
      return;
    }

    // slide
    const toX = direction === "forward" ? "-30%" : "100%";
    await animateEl(contentEl,
      [
        { transform: "translateX(0)",     opacity: 1   },
        { transform: `translateX(${toX})`, opacity: direction === "forward" ? 0.4 : 1 },
      ],
      TRANSITION_DURATION
    );
  }

  async function transitionIn(contentEl, direction) {
    if (!IS_MOBILE || TRANSITION_TYPE === "none") return;

    if (TRANSITION_TYPE === "fade") {
      await animateEl(contentEl,
        [{ opacity: 0 }, { opacity: 1 }],
        TRANSITION_DURATION
      );
      return;
    }

    // slide — start from off-screen, animate to natural position
    const fromX = direction === "forward" ? "100%" : "-30%";
    const fromOpacity = direction === "forward" ? 1 : 0.4;
    await animateEl(contentEl,
      [
        { transform: `translateX(${fromX})`, opacity: fromOpacity },
        { transform: "translateX(0)",        opacity: 1           },
      ],
      TRANSITION_DURATION
    );
  }

  // ─────────────────────────────────────────────
  // 8. NAVIGATION CORE
  // ─────────────────────────────────────────────

  async function navigate(url, direction = "forward", pushState = true) {
    if (isNavigating) return;
    if (url === location.href && direction === "forward") return;

    isNavigating = true;
    Progress.start();

    const contentEl = $(CONTENT_SELECTOR);
    if (!contentEl) { location.href = url; return; }

    // Haptic feedback
    if (IS_MOBILE && navigator.vibrate) navigator.vibrate(8);

    try {
      // ── Fetch the new page (overlaps with OUT animation) ──────────
      const fetchPromise = fetchPage(url);

      if (IS_MOBILE && skeletonConfig.enabled) {
        // Show skeleton: animate out → show skeleton → fetch resolves → morph in
        await transitionOut(contentEl, direction);
        contentEl.innerHTML = buildSkeleton();

        const html = await fetchPromise;
        const { content, title } = parsePageContent(html);
        if (!content) { location.href = url; return; }

        await morphContent(contentEl, content);
        document.title = title;
        await transitionIn(contentEl, direction);

      } else {
        // No skeleton: animate out → morph → animate in
        const [html] = await Promise.all([fetchPromise, transitionOut(contentEl, direction)]);
        const { content, title } = parsePageContent(html);
        if (!content) { location.href = url; return; }

        await morphContent(contentEl, content);
        document.title = title;
        await transitionIn(contentEl, direction);
      }

      // Update history
      if (pushState) {
        if (direction === "forward") {
          historyStack.push(currentPath);
          history.pushState({ pdStack: historyStack.length }, document.title, url);
        } else {
          history.replaceState({ pdStack: historyStack.length }, document.title, url);
        }
        currentPath = url;
      }

      // Re-execute inline scripts
      reinitScripts(contentEl);

      // Sync bottom nav
      updateBottomNav(url);

      // Scroll to top
      contentEl.scrollTo?.({ top: 0, behavior: "instant" });
      window.scrollTo({ top: 0, behavior: "instant" });

      Progress.done();

    } catch (err) {
      console.error("[Pindrop Engine] Navigation failed:", err);
      Progress.fail();
      location.href = url;
    } finally {
      isNavigating = false;
    }
  }

  function reinitScripts(container) {
    $$("script", container).forEach((old) => {
      if (old.src) return;
      const s = document.createElement("script");
      s.textContent = old.textContent;
      old.replaceWith(s);
    });
  }

  // ─────────────────────────────────────────────
  // 9. LINK INTERCEPTION
  // ─────────────────────────────────────────────

  function initLinkInterception() {
    document.addEventListener("click", (e) => {
      const link = isNavigableLink(e.target);
      if (!link) return;
      e.preventDefault();
      navigate(link.href, "forward");
    });

    window.addEventListener("popstate", (e) => {
      const direction = (e.state?.pdStack || 0) < historyStack.length ? "back" : "forward";
      navigate(location.href, direction, false);
      if (direction === "back") historyStack.pop();
    });
  }

  // ─────────────────────────────────────────────
  // 10. BOTTOM NAVIGATION (mobile)
  // ─────────────────────────────────────────────

  const ICONS = {
    home:     `<svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`,
    search:   `<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>`,
    person:   `<svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
    settings: `<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>`,
    heart:    `<svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`,
    bell:     `<svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>`,
    grid:     `<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>`,
    menu:     `<svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>`,
  };

  function getIcon(name) { return ICONS[name] || ICONS.menu; }

  function buildBottomNav() {
    if (!IS_MOBILE || !bottomNavConfig.enabled) return;
    const items = bottomNavConfig.items || [];
    if (!items.length) return;

    const nav = document.createElement("nav");
    nav.id = "pd-bottom-nav";
    nav.setAttribute("role", "navigation");
    nav.setAttribute("aria-label", "Main navigation");

    items.forEach((item) => {
      const a = document.createElement("a");
      a.className = "pd-nav-item";
      a.href = item.route;
      a.setAttribute("aria-label", item.label);
      a.innerHTML = `${getIcon(item.icon)}<span>${item.label}</span>`;

      if (
        location.pathname === item.route ||
        (item.route !== "/" && location.pathname.startsWith(item.route + "/"))
      ) a.classList.add("active");

      a.addEventListener("click", (e) => {
        e.preventDefault();
        if (navigator.vibrate) navigator.vibrate(8);
        navigate(item.route, "forward");
      });

      nav.appendChild(a);
    });

    document.body.appendChild(nav);
  }

  function updateBottomNav(url) {
    if (!IS_MOBILE || !bottomNavConfig.enabled) return;
    const path  = new URL(url, location.origin).pathname;
    const items = bottomNavConfig.items || [];

    $$(".pd-nav-item").forEach((el, i) => {
      el.classList.remove("active");
      const route = items[i]?.route;
      if (!route) return;
      if (path === route || (route !== "/" && path.startsWith(route + "/"))) {
        el.classList.add("active");
      }
    });
  }

  // ─────────────────────────────────────────────
  // 11. SWIPE BACK GESTURE (mobile)
  // ─────────────────────────────────────────────

  function initSwipeBack() {
    if (!IS_MOBILE || !gestureConfig.swipe_back) return;
    let startX = 0, startY = 0, tracking = false;

    document.addEventListener("touchstart", (e) => {
      startX   = e.touches[0].clientX;
      startY   = e.touches[0].clientY;
      tracking = startX < 30;
    }, { passive: true });

    document.addEventListener("touchend", (e) => {
      if (!tracking) return;
      const dx = e.changedTouches[0].clientX - startX;
      const dy = Math.abs(e.changedTouches[0].clientY - startY);
      if (dx > 60 && dy < 60 && historyStack.length > 0) {
        if (navigator.vibrate) navigator.vibrate(10);
        historyStack.pop();
        history.back();
      }
      tracking = false;
    }, { passive: true });
  }

  // ─────────────────────────────────────────────
  // 12. PULL TO REFRESH (mobile)
  // ─────────────────────────────────────────────

  function initPullToRefresh() {
    if (!IS_MOBILE || !gestureConfig.pull_to_refresh) return;

    const indicator    = document.createElement("div");
    indicator.id       = "pd-pull-indicator";
    indicator.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none"
      stroke="white" stroke-width="2.5" stroke-linecap="round">
      <path d="M23 4v6h-6"/><path d="M1 20v-6h6"/>
      <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
    </svg>`;
    document.body.appendChild(indicator);

    let startY = 0, pulling = false, refreshing = false;

    document.addEventListener("touchstart", (e) => {
      if (window.scrollY === 0) { startY = e.touches[0].clientY; pulling = true; }
    }, { passive: true });

    document.addEventListener("touchmove", (e) => {
      if (!pulling || refreshing) return;
      if (e.touches[0].clientY - startY > 50) indicator.classList.add("visible");
    }, { passive: true });

    document.addEventListener("touchend", (e) => {
      if (!pulling || refreshing) return;
      const dy = e.changedTouches[0].clientY - startY;
      if (dy > 80) {
        refreshing = true;
        indicator.classList.add("refreshing");
        if (navigator.vibrate) navigator.vibrate(15);
        fetchCache.delete(location.href);
        navigate(location.href, "forward", false).then(() => {
          indicator.classList.remove("visible", "refreshing");
          refreshing = false;
        });
      } else {
        indicator.classList.remove("visible");
      }
      pulling = false;
    }, { passive: true });
  }

  // ─────────────────────────────────────────────
  // 13. PREFETCH ON HOVER (desktop)
  // ─────────────────────────────────────────────

  function initPrefetch() {
    if (IS_MOBILE) return;
    const prefetched = new Set();
    document.addEventListener("mouseover", (e) => {
      const link = isNavigableLink(e.target);
      if (!link || prefetched.has(link.href)) return;
      prefetched.add(link.href);
      fetchPage(link.href).catch(() => {});
    });
  }

  // ─────────────────────────────────────────────
  // 14. THEME COLOR META
  // ─────────────────────────────────────────────

  function applyThemeColor() {
    if (!theme.status_bar_color) return;
    let meta = $('meta[name="theme-color"]');
    if (!meta) {
      meta      = document.createElement("meta");
      meta.name = "theme-color";
      document.head.appendChild(meta);
    }
    meta.content = theme.status_bar_color;
  }

  // ─────────────────────────────────────────────
  // 15. DARK MODE
  // ─────────────────────────────────────────────

  function initDarkMode() {
    if (theme.dark_mode === "auto") {
      const mq = window.matchMedia("(prefers-color-scheme: dark)");
      document.documentElement.dataset.pdTheme = mq.matches ? "dark" : "light";
      mq.addEventListener("change", (e) => {
        document.documentElement.dataset.pdTheme = e.matches ? "dark" : "light";
      });
    } else {
      document.documentElement.dataset.pdTheme = theme.dark_mode === "dark" ? "dark" : "light";
    }
  }

  // ─────────────────────────────────────────────
  // 16. DESKTOP ENGINE
  // ─────────────────────────────────────────────

  function initDesktopEngine() {
    initLinkInterception();
    initPrefetch();
    // Desktop always fades
    Object.defineProperty(transitionConfig, "type", { value: "fade", writable: false });
  }

  // ─────────────────────────────────────────────
  // 17. MOBILE ENGINE
  // ─────────────────────────────────────────────

  function initMobileEngine() {
    buildBottomNav();
    initLinkInterception();
    initSwipeBack();
    initPullToRefresh();

    const contentEl = $(CONTENT_SELECTOR);
    if (contentEl) {
      contentEl.style.overflowY              = "auto";
      contentEl.style.webkitOverflowScrolling = "touch";
      contentEl.style.overscrollBehavior     = "contain";
    }
  }

  // ─────────────────────────────────────────────
  // 18. PUBLIC API
  // ─────────────────────────────────────────────

  global.PindropEngine = {
    navigate,
    device:      DEVICE,
    isMobile:    IS_MOBILE,
    clearCache:  () => fetchCache.clear(),
    historyStack,
    version:     "1.2.0",
  };

  // ─────────────────────────────────────────────
  // 19. BOOT
  // ─────────────────────────────────────────────

  function boot() {
    Progress.init();
    applyThemeColor();
    initDarkMode();

    // Start loading idiomorph in background immediately
    idiomorphReady.catch((err) => {
      console.warn("[Pindrop Engine] idiomorph load failed, using innerHTML fallback:", err.message);
    });

    if (IS_MOBILE) {
      console.log("[Pindrop Engine] v1.2.0 — mobile (idiomorph)");
      initMobileEngine();
    } else {
      console.log("[Pindrop Engine] v1.2.0 — desktop (idiomorph)");
      initDesktopEngine();
    }

    history.replaceState({ pdStack: 0 }, document.title, location.href);
    historyStack.push(location.pathname);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

})(window);