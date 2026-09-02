/**
 * Pindrop Mobile Engine v1.0.0
 * Transforms a Pindrop PHP app into a native-feeling mobile experience.
 * On desktop: lightweight SPA with prefetch + subtle transitions.
 * On mobile: full native app feel — slides, gestures, skeleton, bottom nav.
 *
 * Reads config from: window.__PINDROP_MOBILE__
 */

(function (global) {
  "use strict";

  // ─────────────────────────────────────────────
  // 0. BOOTSTRAP
  // ─────────────────────────────────────────────

  const settings = global.__PINDROP_MOBILE__ || {};
  const engine = settings.engine || {};
  const theme = settings.theme || {};
  const transitionConfig = settings.transitions || {};
  const bottomNavConfig = settings.bottom_navigation || {};
  const gestureConfig = settings.gestures || {};
  const skeletonConfig = settings.skeleton || {};

  const CONTENT_SELECTOR = engine.content_selector || "#app-content";
  const TRANSITION_DURATION = transitionConfig.duration || 300;
  const TRANSITION_TYPE = transitionConfig.type || "slide";

  // History stack for back gesture
  const historyStack = [];
  let isNavigating = false;
  let currentPath = location.pathname;

  // ─────────────────────────────────────────────
  // 1. DEVICE DETECTION
  // ─────────────────────────────────────────────

  function detectDevice() {
    const ua = navigator.userAgent;
    const touch = navigator.maxTouchPoints > 1;
    const width = window.innerWidth;
    if (/Mobi|Android|iPhone/i.test(ua)) return "mobile";
    if (/iPad|Tablet/i.test(ua) || (touch && width < 1024)) return "tablet";
    return "desktop";
  }

  const DEVICE = detectDevice();
  const IS_MOBILE = DEVICE === "mobile" || DEVICE === "tablet";

  // ─────────────────────────────────────────────
  // 2. UTILITIES
  // ─────────────────────────────────────────────

  function $(selector, root = document) {
    return root.querySelector(selector);
  }

  function $$(selector, root = document) {
    return Array.from(root.querySelectorAll(selector));
  }

  function isSameOrigin(url) {
    try {
      return new URL(url, location.origin).origin === location.origin;
    } catch {
      return false;
    }
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

  // Simple dot-notation getter
  function deepGet(obj, path, fallback = null) {
    return path.split(".").reduce((acc, k) => (acc && acc[k] !== undefined ? acc[k] : fallback), obj);
  }

  // ─────────────────────────────────────────────
  // 3. STYLES INJECTION
  // ─────────────────────────────────────────────

  function injectStyles() {
    const primaryColor = theme.primary_color || "#6200ea";
    const bgColor = theme.background_color || "#ffffff";
    const skeletonColor = skeletonConfig.color || "#e0e0e0";

    const css = `
      /* ── Pindrop Engine Base ── */
      :root {
        --pd-primary: ${primaryColor};
        --pd-bg: ${bgColor};
        --pd-skeleton: ${skeletonColor};
        --pd-duration: ${TRANSITION_DURATION}ms;
        --pd-bottom-nav-height: 60px;
        --pd-header-height: 56px;
      }

      * {
        -webkit-tap-highlight-color: transparent;
        -webkit-touch-callout: none;
        box-sizing: border-box;
      }

      html, body {
        overscroll-behavior: none;
      }

      /* ── Progress Bar ── */
      #pd-progress {
        position: fixed;
        top: 0; left: 0;
        width: 0%;
        height: 3px;
        background: var(--pd-primary);
        z-index: 9999;
        transition: width 0.2s ease, opacity 0.3s ease;
        opacity: 0;
        pointer-events: none;
      }
      #pd-progress.active {
        opacity: 1;
      }
      #pd-progress.complete {
        width: 100% !important;
        opacity: 0;
        transition: width 0.1s ease, opacity 0.4s ease 0.1s;
      }

      /* ── Page Wrapper ── */
      .pd-page-wrapper {
        position: relative;
        overflow: hidden;
        min-height: 100vh;
      }

      /* ── Content Panels ── */
      .pd-content {
        width: 100%;
        will-change: transform, opacity;
        background: var(--pd-bg);
      }

      /* ── Slide Transitions ── */
      .pd-slide-out-left {
        animation: pdSlideOutLeft var(--pd-duration) cubic-bezier(0.4,0,0.2,1) forwards;
      }
      .pd-slide-in-right {
        animation: pdSlideInRight var(--pd-duration) cubic-bezier(0.4,0,0.2,1) forwards;
      }
      .pd-slide-out-right {
        animation: pdSlideOutRight var(--pd-duration) cubic-bezier(0.4,0,0.2,1) forwards;
      }
      .pd-slide-in-left {
        animation: pdSlideInLeft var(--pd-duration) cubic-bezier(0.4,0,0.2,1) forwards;
      }

      /* ── Fade Transitions ── */
      .pd-fade-out {
        animation: pdFadeOut var(--pd-duration) ease forwards;
      }
      .pd-fade-in {
        animation: pdFadeIn var(--pd-duration) ease forwards;
      }

      @keyframes pdSlideOutLeft {
        from { transform: translateX(0); }
        to   { transform: translateX(-30%); opacity: 0.4; }
      }
      @keyframes pdSlideInRight {
        from { transform: translateX(100%); }
        to   { transform: translateX(0); }
      }
      @keyframes pdSlideOutRight {
        from { transform: translateX(0); }
        to   { transform: translateX(100%); }
      }
      @keyframes pdSlideInLeft {
        from { transform: translateX(-30%); opacity: 0.4; }
        to   { transform: translateX(0); opacity: 1; }
      }
      @keyframes pdFadeOut {
        from { opacity: 1; }
        to   { opacity: 0; }
      }
      @keyframes pdFadeIn {
        from { opacity: 0; }
        to   { opacity: 1; }
      }

      /* ── Skeleton ── */
      .pd-skeleton {
        padding: 20px;
      }
      .pd-skeleton-line {
        height: 16px;
        background: var(--pd-skeleton);
        border-radius: 8px;
        margin-bottom: 12px;
      }
      .pd-skeleton-line.short  { width: 40%; }
      .pd-skeleton-line.medium { width: 70%; }
      .pd-skeleton-line.long   { width: 90%; }
      .pd-skeleton-block {
        height: 160px;
        background: var(--pd-skeleton);
        border-radius: 12px;
        margin-bottom: 16px;
      }

      ${skeletonConfig.shimmer ? `
      .pd-skeleton-line,
      .pd-skeleton-block {
        background: linear-gradient(
          90deg,
          var(--pd-skeleton) 25%,
          #f0f0f0 50%,
          var(--pd-skeleton) 75%
        );
        background-size: 200% 100%;
        animation: pdShimmer 1.4s infinite;
      }
      @keyframes pdShimmer {
        0%   { background-position: 200% 0; }
        100% { background-position: -200% 0; }
      }
      ` : ""}

      /* ── Bottom Navigation (mobile only) ── */
      ${IS_MOBILE && bottomNavConfig.enabled ? `
      #pd-bottom-nav {
        position: fixed;
        bottom: 0; left: 0; right: 0;
        height: var(--pd-bottom-nav-height);
        background: var(--pd-bg);
        display: flex;
        align-items: center;
        justify-content: space-around;
        border-top: 1px solid rgba(0,0,0,0.08);
        z-index: 1000;
        -webkit-backdrop-filter: blur(12px);
        backdrop-filter: blur(12px);
        background: rgba(255,255,255,0.92);
        padding-bottom: env(safe-area-inset-bottom);
      }
      .pd-nav-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        flex: 1;
        height: 100%;
        cursor: pointer;
        color: #999;
        font-size: 10px;
        font-family: -apple-system, sans-serif;
        gap: 3px;
        transition: color 0.2s ease;
        text-decoration: none;
        -webkit-user-select: none;
        user-select: none;
      }
      .pd-nav-item.active {
        color: var(--pd-primary);
      }
      .pd-nav-item svg {
        width: 24px; height: 24px;
        stroke: currentColor;
        fill: none;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
        transition: transform 0.2s cubic-bezier(0.34,1.56,0.64,1);
      }
      .pd-nav-item.active svg {
        transform: scale(1.15);
      }
      /* push body content up for bottom nav */
      body {
        padding-bottom: calc(var(--pd-bottom-nav-height) + env(safe-area-inset-bottom));
      }
      ` : ""}

      /* ── Pull to Refresh ── */
      #pd-pull-indicator {
        position: fixed;
        top: -60px;
        left: 50%;
        transform: translateX(-50%);
        width: 40px; height: 40px;
        border-radius: 50%;
        background: var(--pd-primary);
        display: flex; align-items: center; justify-content: center;
        color: white;
        font-size: 20px;
        z-index: 9998;
        transition: top 0.2s ease, opacity 0.2s ease;
        opacity: 0;
        box-shadow: 0 2px 12px rgba(0,0,0,0.2);
      }
      #pd-pull-indicator.visible {
        top: 16px;
        opacity: 1;
      }
      #pd-pull-indicator.refreshing svg {
        animation: pdSpin 0.8s linear infinite;
      }
      @keyframes pdSpin {
        from { transform: rotate(0deg); }
        to   { transform: rotate(360deg); }
      }

      /* ── Desktop subtle enhancements ── */
      ${!IS_MOBILE ? `
      a { transition: opacity 0.15s ease; }
      a:hover { opacity: 0.75; }
      ` : ""}
    `;

    const style = document.createElement("style");
    style.id = "pd-engine-styles";
    style.textContent = css;
    document.head.appendChild(style);
  }

  // ─────────────────────────────────────────────
  // 4. PROGRESS BAR
  // ─────────────────────────────────────────────

  const Progress = {
    el: null,
    timer: null,

    init() {
      this.el = document.createElement("div");
      this.el.id = "pd-progress";
      document.body.prepend(this.el);
    },

    start() {
      clearTimeout(this.timer);
      this.el.className = "active";
      this.el.style.width = "0%";
      // simulate incremental progress
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
      setTimeout(() => {
        this.el.className = "";
        this.el.style.width = "0%";
      }, 600);
    },

    fail() {
      clearTimeout(this.timer);
      this.el.style.background = "#e53935";
      this.done();
      setTimeout(() => {
        this.el.style.background = "";
      }, 800);
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
  // 6. DOM MORPHING (lightweight, no deps)
  // ─────────────────────────────────────────────

  /**
   * Simple DOM morph: walks old and new trees,
   * patches only what changed. Falls back to innerHTML
   * if trees diverge too much.
   */
  function morphDOM(oldEl, newEl) {
    // different tag → replace entirely
    if (oldEl.tagName !== newEl.tagName) {
      oldEl.replaceWith(newEl.cloneNode(true));
      return;
    }

    // sync attributes
    const oldAttrs = oldEl.attributes;
    const newAttrs = newEl.attributes;
    for (let i = newAttrs.length - 1; i >= 0; i--) {
      const { name, value } = newAttrs[i];
      if (oldEl.getAttribute(name) !== value) oldEl.setAttribute(name, value);
    }
    for (let i = oldAttrs.length - 1; i >= 0; i--) {
      const name = oldAttrs[i].name;
      if (!newEl.hasAttribute(name)) oldEl.removeAttribute(name);
    }

    // text node
    if (newEl.children.length === 0 && oldEl.children.length === 0) {
      if (oldEl.textContent !== newEl.textContent) {
        oldEl.textContent = newEl.textContent;
      }
      return;
    }

    // build map of keyed children (by id)
    const oldById = {};
    for (const child of oldEl.children) {
      if (child.id) oldById[child.id] = child;
    }

    const newChildren = Array.from(newEl.childNodes);
    const oldChildren = Array.from(oldEl.childNodes);

    let i = 0;
    for (const newChild of newChildren) {
      const oldChild = oldChildren[i];

      if (!oldChild) {
        // append new
        oldEl.appendChild(newChild.cloneNode(true));
      } else if (newChild.nodeType === Node.TEXT_NODE) {
        if (oldChild.nodeType === Node.TEXT_NODE) {
          if (oldChild.textContent !== newChild.textContent) {
            oldChild.textContent = newChild.textContent;
          }
        } else {
          oldEl.insertBefore(newChild.cloneNode(true), oldChild);
        }
      } else if (newChild.nodeType === Node.ELEMENT_NODE) {
        // try to match by id first
        if (newChild.id && oldById[newChild.id]) {
          const matched = oldById[newChild.id];
          if (matched !== oldChild) oldEl.insertBefore(matched, oldChild);
          morphDOM(matched, newChild);
        } else if (oldChild.nodeType === Node.ELEMENT_NODE && oldChild.tagName === newChild.tagName) {
          morphDOM(oldChild, newChild);
        } else {
          oldEl.insertBefore(newChild.cloneNode(true), oldChild);
        }
      }
      i++;
    }

    // remove leftover old children
    while (oldEl.childNodes.length > newChildren.length) {
      oldEl.removeChild(oldEl.lastChild);
    }
  }

  // ─────────────────────────────────────────────
  // 7. PAGE FETCHER
  // ─────────────────────────────────────────────

  const fetchCache = new Map(); // simple in-memory cache

  async function fetchPage(url) {
    if (fetchCache.has(url)) return fetchCache.get(url);

    const res = await fetch(url, {
      headers: { "X-Pindrop-Engine": "1" }, // backend can detect engine requests
      credentials: "same-origin",
    });

    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const html = await res.text();
    fetchCache.set(url, html);
    // evict cache after 5 min
    setTimeout(() => fetchCache.delete(url), 5 * 60 * 1000);
    return html;
  }

  function parsePageContent(html) {
    const doc = new DOMParser().parseFromString(html, "text/html");
    const content = $(CONTENT_SELECTOR, doc);
    const title = doc.title;
    return { content, title, doc };
  }

  // ─────────────────────────────────────────────
  // 8. TRANSITIONS
  // ─────────────────────────────────────────────

  function runTransition(contentEl, direction, callback) {
    if (TRANSITION_TYPE === "none" || !IS_MOBILE) {
      callback();
      return;
    }

    const outClass = direction === "forward" ? "pd-slide-out-left" : "pd-slide-out-right";
    const inClass  = direction === "forward" ? "pd-slide-in-right" : "pd-slide-in-left";

    if (TRANSITION_TYPE === "fade") {
      contentEl.classList.add("pd-fade-out");
      setTimeout(() => {
        contentEl.classList.remove("pd-fade-out");
        callback();
        contentEl.classList.add("pd-fade-in");
        contentEl.addEventListener("animationend", () => {
          contentEl.classList.remove("pd-fade-in");
        }, { once: true });
      }, TRANSITION_DURATION);
      return;
    }

    // slide
    contentEl.classList.add(outClass);
    contentEl.addEventListener("animationend", () => {
      contentEl.classList.remove(outClass);
      callback();
      contentEl.classList.add(inClass);
      contentEl.addEventListener("animationend", () => {
        contentEl.classList.remove(inClass);
      }, { once: true });
    }, { once: true });
  }

  // ─────────────────────────────────────────────
  // 9. NAVIGATION CORE
  // ─────────────────────────────────────────────

  async function navigate(url, direction = "forward", pushState = true) {
    if (isNavigating) return;
    if (url === location.href && direction === "forward") return;

    isNavigating = true;
    Progress.start();

    const contentEl = $(CONTENT_SELECTOR);
    if (!contentEl) {
      // no content selector found — do normal navigation
      location.href = url;
      return;
    }

    // show skeleton immediately
    if (IS_MOBILE && skeletonConfig.enabled) {
      runTransition(contentEl, direction, () => {
        contentEl.innerHTML = buildSkeleton();
      });
    }

    // haptic feedback on mobile
    if (IS_MOBILE && navigator.vibrate) navigator.vibrate(8);

    try {
      const html = await fetchPage(url);
      const { content, title } = parsePageContent(html);

      if (!content) {
        // page doesn't use the content selector — hard navigate
        location.href = url;
        return;
      }

      // if skeleton wasn't shown yet, run transition now
      if (!IS_MOBILE || !skeletonConfig.enabled) {
        await new Promise((resolve) => {
          runTransition(contentEl, direction, () => {
            morphDOM(contentEl, content);
            resolve();
          });
        });
      } else {
        // skeleton is already shown, just morph in
        contentEl.classList.add(direction === "forward" ? "pd-slide-in-right" : "pd-slide-in-left");
        morphDOM(contentEl, content);
        contentEl.addEventListener("animationend", () => {
          contentEl.classList.remove("pd-slide-in-right", "pd-slide-in-left");
        }, { once: true });
      }

      // update page title
      document.title = title;

      // update history
      if (pushState) {
        if (direction === "forward") {
          historyStack.push(currentPath);
          history.pushState({ pdStack: historyStack.length }, title, url);
        } else {
          history.replaceState({ pdStack: historyStack.length }, title, url);
        }
        currentPath = url;
      }

      // re-run any inline scripts in new content
      reinitScripts(contentEl);

      // update bottom nav active state
      updateBottomNav(url);

      // scroll to top
      contentEl.scrollTo?.({ top: 0, behavior: "instant" });
      window.scrollTo({ top: 0, behavior: "instant" });

      Progress.done();
    } catch (err) {
      console.error("[Pindrop Engine] Navigation failed:", err);
      Progress.fail();
      // fallback to hard navigation
      location.href = url;
    } finally {
      isNavigating = false;
    }
  }

  function reinitScripts(container) {
    // re-execute inline scripts added by the new page
    $$("script", container).forEach((oldScript) => {
      if (oldScript.src) return; // external scripts skip
      const newScript = document.createElement("script");
      newScript.textContent = oldScript.textContent;
      oldScript.replaceWith(newScript);
    });
  }

  // ─────────────────────────────────────────────
  // 10. LINK INTERCEPTION
  // ─────────────────────────────────────────────

  function initLinkInterception() {
    document.addEventListener("click", (e) => {
      const link = isNavigableLink(e.target);
      if (!link) return;
      e.preventDefault();
      navigate(link.href, "forward");
    });

    // browser back/forward buttons
    window.addEventListener("popstate", (e) => {
      const direction = (e.state?.pdStack || 0) < historyStack.length ? "back" : "forward";
      navigate(location.href, direction, false);
      if (direction === "back") historyStack.pop();
    });
  }

  // ─────────────────────────────────────────────
  // 11. BOTTOM NAVIGATION (mobile)
  // ─────────────────────────────────────────────

  // SVG icons map (Feather icons subset)
  const ICONS = {
    home: `<svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`,
    search: `<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>`,
    person: `<svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
    settings: `<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>`,
    heart: `<svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`,
    bell: `<svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>`,
    grid: `<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>`,
    menu: `<svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>`,
  };

  function getIcon(name) {
    return ICONS[name] || ICONS.menu;
  }

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

      // check if active
      if (location.pathname === item.route || location.pathname.startsWith(item.route + "/")) {
        if (item.route !== "/" || location.pathname === "/") {
          a.classList.add("active");
        }
      }

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
    const path = new URL(url, location.origin).pathname;
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
  // 12. SWIPE BACK GESTURE (mobile)
  // ─────────────────────────────────────────────

  function initSwipeBack() {
    if (!IS_MOBILE || !gestureConfig.swipe_back) return;

    let startX = 0;
    let startY = 0;
    let tracking = false;

    document.addEventListener("touchstart", (e) => {
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      tracking = startX < 30; // only from left edge
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
  // 13. PULL TO REFRESH (mobile)
  // ─────────────────────────────────────────────

  function initPullToRefresh() {
    if (!IS_MOBILE || !gestureConfig.pull_to_refresh) return;

    const indicator = document.createElement("div");
    indicator.id = "pd-pull-indicator";
    indicator.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>`;
    document.body.appendChild(indicator);

    let startY = 0;
    let pulling = false;
    let refreshing = false;

    document.addEventListener("touchstart", (e) => {
      if (window.scrollY === 0) {
        startY = e.touches[0].clientY;
        pulling = true;
      }
    }, { passive: true });

    document.addEventListener("touchmove", (e) => {
      if (!pulling || refreshing) return;
      const dy = e.touches[0].clientY - startY;
      if (dy > 50) indicator.classList.add("visible");
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
  // 14. PREFETCH ON HOVER (desktop)
  // ─────────────────────────────────────────────

  function initPrefetch() {
    if (IS_MOBILE) return; // mobile uses fetch-on-click
    const prefetched = new Set();

    document.addEventListener("mouseover", (e) => {
      const link = isNavigableLink(e.target);
      if (!link || prefetched.has(link.href)) return;
      prefetched.add(link.href);
      // warm the cache silently
      fetchPage(link.href).catch(() => {});
    });
  }

  // ─────────────────────────────────────────────
  // 15. THEME COLOR META
  // ─────────────────────────────────────────────

  function applyThemeColor() {
    if (!theme.status_bar_color) return;
    let meta = $('meta[name="theme-color"]');
    if (!meta) {
      meta = document.createElement("meta");
      meta.name = "theme-color";
      document.head.appendChild(meta);
    }
    meta.content = theme.status_bar_color;
  }

  // ─────────────────────────────────────────────
  // 16. DARK MODE
  // ─────────────────────────────────────────────

  function initDarkMode() {
    if (theme.dark_mode === "auto") {
      const mq = window.matchMedia("(prefers-color-scheme: dark)");
      document.documentElement.dataset.pdTheme = mq.matches ? "dark" : "light";
      mq.addEventListener("change", (e) => {
        document.documentElement.dataset.pdTheme = e.matches ? "dark" : "light";
      });
    } else if (theme.dark_mode === "dark") {
      document.documentElement.dataset.pdTheme = "dark";
    } else {
      document.documentElement.dataset.pdTheme = "light";
    }
  }

  // ─────────────────────────────────────────────
  // 17. DESKTOP ENGINE (lightweight)
  // ─────────────────────────────────────────────

  function initDesktopEngine() {
    initLinkInterception();
    initPrefetch();

    // override transition type for desktop — always subtle fade
    Object.defineProperty(transitionConfig, "type", { value: "fade", writable: false });
  }

  // ─────────────────────────────────────────────
  // 18. MOBILE ENGINE (full native feel)
  // ─────────────────────────────────────────────

  function initMobileEngine() {
    buildBottomNav();
    initLinkInterception();
    initSwipeBack();
    initPullToRefresh();

    // ensure content area is scrollable
    const contentEl = $(CONTENT_SELECTOR);
    if (contentEl) {
      contentEl.style.overflowY = "auto";
      contentEl.style.webkitOverflowScrolling = "touch";
      contentEl.style.overscrollBehavior = "contain";
    }
  }

  // ─────────────────────────────────────────────
  // 19. PUBLIC API
  // ─────────────────────────────────────────────

  global.PindropEngine = {
    navigate,
    device: DEVICE,
    isMobile: IS_MOBILE,
    clearCache: () => fetchCache.clear(),
    version: "1.0.0",
  };

  // ─────────────────────────────────────────────
  // 20. BOOT
  // ─────────────────────────────────────────────

  function boot() {
    injectStyles();
    Progress.init();
    applyThemeColor();
    initDarkMode();

    if (IS_MOBILE) {
      console.log("[Pindrop Engine] Mobile mode activated");
      initMobileEngine();
    } else {
      console.log("[Pindrop Engine] Desktop mode activated");
      initDesktopEngine();
    }

    // mark initial history entry
    history.replaceState({ pdStack: 0 }, document.title, location.href);
    historyStack.push(location.pathname);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

})(window);