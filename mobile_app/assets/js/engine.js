/**
 * Pindrop Mobile Engine v1.3.0
 * Transforms a Pindrop PHP app into a native-feeling mobile experience.
 *
 * Offline strategy (per web.dev/learn/pwa/offline-data):
 *   - Cache Storage API  → caches full HTML page responses by URL
 *   - IndexedDB          → stores structured page metadata + offline queue
 *   - Storage Manager    → requests persistence, monitors quota
 *   - Offline fallback   → serves cached page when network unavailable
 *
 * DOM morphing: idiomorph (bundled or loaded from CDN)
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
  const offlineConfig    = settings.offline || {};

  const CONTENT_SELECTOR    = engine.content_selector || "#app-content";
  const TRANSITION_DURATION = transitionConfig.duration || 300;
  const TRANSITION_TYPE     = transitionConfig.type || "slide";

  // Cache Storage name — versioned so we can bust on update
  const CACHE_NAME     = "pindrop-pages-v1";
  // IndexedDB database name + version
  const IDB_NAME       = "pindrop-offline";
  const IDB_VERSION    = 1;
  // Max pages to keep in cache
  const CACHE_MAX_PAGES = offlineConfig.max_pages || 30;

  const historyStack = [];
  let isNavigating   = false;
  let currentPath    = location.pathname;

  // ─────────────────────────────────────────────
  // 1. IDIOMORPH LOADER
  // ─────────────────────────────────────────────

  const idiomorphReady = (function () {
    if (global.Idiomorph) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const script       = document.createElement("script");
      script.src         = "https://cdn.jsdelivr.net/npm/idiomorph@0.3.0/dist/idiomorph.min.js";
      script.crossOrigin = "anonymous";
      script.onload      = () => resolve();
      script.onerror     = () => {
        const fallback   = document.createElement("script");
        fallback.src     = "https://unpkg.com/idiomorph@0.3.0/dist/idiomorph.min.js";
        fallback.onload  = () => resolve();
        fallback.onerror = () => reject(new Error("Failed to load idiomorph"));
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

  function isOnline() {
    return navigator.onLine !== false;
  }

  // ─────────────────────────────────────────────
  // 4. INDEXEDDB  (structured offline data)
  // ─────────────────────────────────────────────

  /**
   * Per web.dev: IndexedDB is the right choice for structured data.
   * We use it to store:
   *   - page metadata (url, title, cached_at) in "pages" store
   *   - pending POST/action queue in "queue" store (for offline form subs)
   */

  let _db = null;

  async function openDB() {
    if (_db) return _db;

    return new Promise((resolve, reject) => {
      const req = indexedDB.open(IDB_NAME, IDB_VERSION);

      req.onupgradeneeded = (e) => {
        const db = e.target.result;

        // "pages" store — tracks which pages are cached and when
        if (!db.objectStoreNames.contains("pages")) {
          const pagesStore = db.createObjectStore("pages", { keyPath: "url" });
          pagesStore.createIndex("cached_at", "cached_at");
        }

        // "queue" store — offline action queue (form submissions etc)
        if (!db.objectStoreNames.contains("queue")) {
          db.createObjectStore("queue", {
            keyPath: "id",
            autoIncrement: true,
          });
        }
      };

      req.onsuccess = (e) => {
        _db = e.target.result;
        resolve(_db);
      };

      req.onerror = () => reject(req.error);
    });
  }

  async function idbSet(storeName, data) {
    try {
      const db = await openDB();
      return new Promise((resolve, reject) => {
        const tx    = db.transaction(storeName, "readwrite");
        const store = tx.objectStore(storeName);
        const req   = store.put(data);
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => reject(req.error);
      });
    } catch (e) {
      console.warn("[Pindrop IDB] set failed:", e);
    }
  }

  async function idbGet(storeName, key) {
    try {
      const db = await openDB();
      return new Promise((resolve, reject) => {
        const tx    = db.transaction(storeName, "readonly");
        const store = tx.objectStore(storeName);
        const req   = store.get(key);
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => reject(req.error);
      });
    } catch (e) {
      console.warn("[Pindrop IDB] get failed:", e);
      return null;
    }
  }

  async function idbGetAll(storeName) {
    try {
      const db = await openDB();
      return new Promise((resolve, reject) => {
        const tx    = db.transaction(storeName, "readonly");
        const store = tx.objectStore(storeName);
        const req   = store.getAll();
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => reject(req.error);
      });
    } catch (e) {
      return [];
    }
  }

  async function idbDelete(storeName, key) {
    try {
      const db = await openDB();
      return new Promise((resolve, reject) => {
        const tx    = db.transaction(storeName, "readwrite");
        const store = tx.objectStore(storeName);
        const req   = store.delete(key);
        req.onsuccess = () => resolve();
        req.onerror   = () => reject(req.error);
      });
    } catch (e) {
      console.warn("[Pindrop IDB] delete failed:", e);
    }
  }

  // ─────────────────────────────────────────────
  // 5. CACHE STORAGE  (HTML page cache)
  // ─────────────────────────────────────────────

  /**
   * Per web.dev: Cache Storage API is the right choice for URL-based
   * resources — HTML, CSS, JS, images. We use it here for full page HTML.
   *
   * Strategy: Stale-While-Revalidate
   *   1. Return cached response immediately (fast)
   *   2. Fetch fresh version in background
   *   3. Update cache silently
   * Falls back to cache-only when offline.
   */

  async function cacheGet(url) {
    try {
      const cache = await caches.open(CACHE_NAME);
      const res   = await cache.match(url);
      return res ? res.text() : null;
    } catch (e) {
      return null;
    }
  }

  async function cachePut(url, html) {
    try {
      const cache = await caches.open(CACHE_NAME);
      const res   = new Response(html, {
        headers: { "Content-Type": "text/html; charset=utf-8" },
      });
      await cache.put(url, res);

      // Record in IndexedDB so we can manage the cache size
      await idbSet("pages", {
        url,
        title:     document.title,
        cached_at: Date.now(),
      });

      // Evict oldest pages if over limit
      await evictOldPages();
    } catch (e) {
      console.warn("[Pindrop Cache] put failed:", e);
    }
  }

  async function evictOldPages() {
    try {
      const all = await idbGetAll("pages");
      if (all.length <= CACHE_MAX_PAGES) return;

      // Sort oldest first
      all.sort((a, b) => a.cached_at - b.cached_at);
      const toDelete = all.slice(0, all.length - CACHE_MAX_PAGES);

      const cache = await caches.open(CACHE_NAME);
      for (const page of toDelete) {
        await cache.delete(page.url);
        await idbDelete("pages", page.url);
      }
    } catch (e) {
      console.warn("[Pindrop Cache] evict failed:", e);
    }
  }

  // ─────────────────────────────────────────────
  // 6. STORAGE MANAGER  (quota + persistence)
  // ─────────────────────────────────────────────

  /**
   * Per web.dev: Use StorageManager to request persistence so the browser
   * never evicts our data under storage pressure, and to monitor quota.
   */

  async function initStorageManager() {
    if (!navigator.storage) return;

    // Request persistent storage — browser may grant based on engagement
    // (Chrome grants if PWA is installed; Firefox asks user)
    try {
      const persisted = await navigator.storage.persisted();
      if (!persisted) {
        const granted = await navigator.storage.persist();
        console.log(`[Pindrop Storage] Persistence ${granted ? "granted" : "not granted"}`);
      }
    } catch (e) {
      // Non-critical — silently ignore
    }

    // Log quota usage in dev mode
    try {
      const estimate = await navigator.storage.estimate();
      const used     = Math.round((estimate.usage  || 0) / 1024);
      const quota    = Math.round((estimate.quota  || 0) / 1024 / 1024);
      const pct      = estimate.quota
        ? ((estimate.usage / estimate.quota) * 100).toFixed(1)
        : "?";
      console.log(`[Pindrop Storage] ${used}KB used of ${quota}MB quota (${pct}%)`);

      // Warn if over 80% — expose via public API
      if (estimate.quota && estimate.usage / estimate.quota > 0.8) {
        console.warn("[Pindrop Storage] Storage over 80% — consider clearing cache");
        global.PindropEngine.storageWarning = true;
      }
    } catch (e) {
      // Non-critical
    }
  }

  // ─────────────────────────────────────────────
  // 7. OFFLINE QUEUE  (pending actions)
  // ─────────────────────────────────────────────

  /**
   * When offline: intercept form POSTs and store them in IndexedDB queue.
   * When back online: replay the queue automatically.
   */

  async function enqueueAction(url, method, body) {
    await idbSet("queue", {
      url,
      method,
      body,
      queued_at: Date.now(),
    });
    console.log(`[Pindrop Queue] Action queued for ${url}`);
    showOfflineBanner("Action saved — will sync when online");
  }

  async function replayQueue() {
    const queue = await idbGetAll("queue");
    if (!queue.length) return;

    console.log(`[Pindrop Queue] Replaying ${queue.length} queued action(s)`);

    for (const item of queue) {
      try {
        await fetch(item.url, {
          method:      item.method,
          body:        item.body,
          credentials: "same-origin",
          headers:     { "X-Pindrop-Engine": "1", "X-Pindrop-Replay": "1" },
        });
        await idbDelete("queue", item.id);
        console.log(`[Pindrop Queue] Replayed ${item.url}`);
      } catch (e) {
        console.warn(`[Pindrop Queue] Replay failed for ${item.url}`, e);
      }
    }
  }

  // ─────────────────────────────────────────────
  // 8. OFFLINE BANNER
  // ─────────────────────────────────────────────

  function showOfflineBanner(msg) {
    let banner = document.getElementById("pd-offline-banner");
    if (!banner) {
      banner = document.createElement("div");
      banner.id = "pd-offline-banner";
      banner.style.cssText = `
        position: fixed;
        bottom: calc(var(--pd-bottom-nav-height, 60px) + env(safe-area-inset-bottom, 0px) + 8px);
        left: 50%;
        transform: translateX(-50%);
        background: #323232;
        color: #fff;
        padding: 10px 20px;
        border-radius: 24px;
        font-size: 13px;
        font-weight: 500;
        font-family: -apple-system, sans-serif;
        z-index: 9000;
        white-space: nowrap;
        box-shadow: 0 4px 16px rgba(0,0,0,0.3);
        transition: opacity 0.3s ease, transform 0.3s ease;
        pointer-events: none;
      `;
      document.body.appendChild(banner);
    }

    banner.textContent = msg || "You're offline";
    banner.style.opacity = "1";
    banner.style.transform = "translateX(-50%) translateY(0)";

    clearTimeout(banner._timer);
    banner._timer = setTimeout(() => {
      banner.style.opacity = "0";
      banner.style.transform = "translateX(-50%) translateY(8px)";
    }, 3000);
  }

  function hideOfflineBanner() {
    const banner = document.getElementById("pd-offline-banner");
    if (banner) {
      banner.style.opacity = "0";
    }
  }

  // ─────────────────────────────────────────────
  // 9. PROGRESS BAR
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
      this.el.className   = "active";
      this.el.style.width = "0%";
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
  // 10. SKELETON SCREEN
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
  // 11. PAGE FETCHER  (network + cache)
  // ─────────────────────────────────────────────

  /**
   * Stale-While-Revalidate strategy:
   *   - Return cached HTML immediately (instant feel)
   *   - Fetch fresh from network in background
   *   - Update cache silently after fresh fetch
   *
   * Offline fallback:
   *   - If network fails and cache has the page → serve from cache
   *   - If cache also misses → show offline error in content area
   */

  // In-memory cache for the current session (avoids re-parsing)
  const memCache = new Map();

  async function fetchPage(url) {
    // 1. Memory cache hit (fastest — same session)
    if (memCache.has(url)) return memCache.get(url);

    const normalizedUrl = new URL(url, location.origin).href;

    // 2. Try Cache Storage (stale-while-revalidate)
    const cached = await cacheGet(normalizedUrl);

    if (cached) {
      // Return stale immediately
      memCache.set(url, cached);

      // Revalidate in background if online
      if (isOnline()) {
        networkFetch(url, normalizedUrl).then((fresh) => {
          if (fresh && fresh !== cached) {
            memCache.set(url, fresh);
            // Don't morph automatically — user already sees content
            // Cache is updated for next navigation
          }
        }).catch(() => {});
      }

      return cached;
    }

    // 3. No cache — must fetch from network
    if (!isOnline()) {
      throw new Error("OFFLINE");
    }

    return networkFetch(url, normalizedUrl);
  }

  async function networkFetch(url, normalizedUrl) {
    const res = await fetch(url, {
      headers:     { "X-Pindrop-Engine": "1" },
      credentials: "same-origin",
    });

    if (!res.ok) throw new Error(`HTTP ${res.status}`);

    const html = await res.text();

    // Store in Cache Storage + IndexedDB metadata
    await cachePut(normalizedUrl, html);

    // Update memory cache
    memCache.set(url, html);

    return html;
  }

  function parsePageContent(html) {
    const doc     = new DOMParser().parseFromString(html, "text/html");
    const content = $(CONTENT_SELECTOR, doc);
    const title   = doc.title;
    return { content, title, doc };
  }

  // Offline error page (shown in #app-content when truly no cache)
  function buildOfflinePage() {
    return `
      <div style="display:flex;flex-direction:column;align-items:center;
                  justify-content:center;padding:60px 24px;text-align:center;
                  min-height:60vh;font-family:-apple-system,sans-serif">
        <div style="font-size:56px;margin-bottom:16px">📡</div>
        <h2 style="margin:0 0 8px;font-size:20px;font-weight:700;color:var(--pd-text,#111)">
          You're offline
        </h2>
        <p style="margin:0 0 24px;color:var(--pd-text-secondary,#666);
                  font-size:15px;line-height:1.5;max-width:260px">
          This page isn't saved yet. Connect to the internet and try again.
        </p>
        <button onclick="PindropEngine.navigate(location.href)"
                style="height:44px;padding:0 24px;border-radius:9999px;
                       border:none;background:var(--pd-primary,#6200ea);
                       color:#fff;font-size:15px;font-weight:600;cursor:pointer">
          Try again
        </button>
      </div>
    `;
  }

  // ─────────────────────────────────────────────
  // 12. TRANSITIONS  (Web Animations API — no fill-mode residue)
  // ─────────────────────────────────────────────

  function animateEl(el, keyframes, duration) {
    return el.animate(keyframes, {
      duration,
      easing: "cubic-bezier(0.4, 0, 0.2, 1)",
      fill:   "none",  // never holds stale transform after finish
    }).finished;
  }

  async function transitionOut(contentEl, direction) {
    if (!IS_MOBILE || TRANSITION_TYPE === "none") return;
    if (TRANSITION_TYPE === "fade") {
      await animateEl(contentEl, [{ opacity: 1 }, { opacity: 0 }], TRANSITION_DURATION);
      return;
    }
    const toX = direction === "forward" ? "-30%" : "100%";
    await animateEl(contentEl, [
      { transform: "translateX(0)",      opacity: 1 },
      { transform: `translateX(${toX})`, opacity: direction === "forward" ? 0.4 : 1 },
    ], TRANSITION_DURATION);
  }

  async function transitionIn(contentEl, direction) {
    if (!IS_MOBILE || TRANSITION_TYPE === "none") return;
    if (TRANSITION_TYPE === "fade") {
      await animateEl(contentEl, [{ opacity: 0 }, { opacity: 1 }], TRANSITION_DURATION);
      return;
    }
    const fromX      = direction === "forward" ? "100%" : "-30%";
    const fromOpacity = direction === "forward" ? 1 : 0.4;
    await animateEl(contentEl, [
      { transform: `translateX(${fromX})`, opacity: fromOpacity },
      { transform: "translateX(0)",        opacity: 1 },
    ], TRANSITION_DURATION);
  }

  // ─────────────────────────────────────────────
  // 13. NAVIGATION CORE
  // ─────────────────────────────────────────────

  async function navigate(url, direction = "forward", pushState = true) {
    if (isNavigating) return;
    if (url === location.href && direction === "forward") return;

    isNavigating = true;
    Progress.start();

    const contentEl = $(CONTENT_SELECTOR);
    if (!contentEl) { location.href = url; return; }

    if (IS_MOBILE && navigator.vibrate) navigator.vibrate(8);

    try {
      const fetchPromise = fetchPage(url);

      if (IS_MOBILE && skeletonConfig.enabled) {
        await transitionOut(contentEl, direction);
        contentEl.innerHTML = buildSkeleton();

        let html;
        try {
          html = await fetchPromise;
        } catch (err) {
          if (err.message === "OFFLINE") {
            contentEl.innerHTML = buildOfflinePage();
            showOfflineBanner("You're offline — showing saved page");
            Progress.fail();
            isNavigating = false;
            return;
          }
          throw err;
        }

        const { content, title } = parsePageContent(html);
        if (!content) { location.href = url; return; }

        await morphContent(contentEl, content);
        document.title = title;
        await transitionIn(contentEl, direction);

      } else {
        let html;
        try {
          [html] = await Promise.all([fetchPromise, transitionOut(contentEl, direction)]);
        } catch (err) {
          if (err.message === "OFFLINE") {
            contentEl.innerHTML = buildOfflinePage();
            showOfflineBanner("You're offline");
            Progress.fail();
            isNavigating = false;
            return;
          }
          throw err;
        }

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

      reinitScripts(contentEl);
      updateBottomNav(url);

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
  // 14. LINK INTERCEPTION
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
  // 15. BOTTOM NAVIGATION (mobile)
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
      const a       = document.createElement("a");
      a.className   = "pd-nav-item";
      a.href        = item.route;
      a.setAttribute("aria-label", item.label);
      a.innerHTML   = `${getIcon(item.icon)}<span>${item.label}</span>`;

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
  // 16. SWIPE BACK (mobile)
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
  // 17. PULL TO REFRESH (mobile)
  // ─────────────────────────────────────────────

  function initPullToRefresh() {
    if (!IS_MOBILE || !gestureConfig.pull_to_refresh) return;

    const indicator     = document.createElement("div");
    indicator.id        = "pd-pull-indicator";
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
        // Force fresh fetch by clearing all caches for this URL
        const url = location.href;
        memCache.delete(url);
        caches.open(CACHE_NAME).then(c => c.delete(url));
        navigate(url, "forward", false).then(() => {
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
  // 18. PREFETCH ON HOVER (desktop)
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
  // 19. ONLINE / OFFLINE EVENTS
  // ─────────────────────────────────────────────

  function initNetworkListeners() {
    window.addEventListener("online", async () => {
      console.log("[Pindrop Engine] Back online");
      hideOfflineBanner();
      showOfflineBanner("Back online ✓");
      // Replay any queued offline actions
      await replayQueue();
    });

    window.addEventListener("offline", () => {
      console.log("[Pindrop Engine] Gone offline");
      showOfflineBanner("You're offline — using saved pages");
    });
  }

  // ─────────────────────────────────────────────
  // 20. THEME COLOR + DARK MODE
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
  // 21. DESKTOP ENGINE
  // ─────────────────────────────────────────────

  function initDesktopEngine() {
    initLinkInterception();
    initPrefetch();
    Object.defineProperty(transitionConfig, "type", { value: "fade", writable: false });
  }

  // ─────────────────────────────────────────────
  // 22. MOBILE ENGINE
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
  // 23. PUBLIC API
  // ─────────────────────────────────────────────

  global.PindropEngine = {
    navigate,
    device:         DEVICE,
    isMobile:       IS_MOBILE,
    historyStack,
    version:        "1.3.0",
    storageWarning: false,

    // Expose cache management
    cache: {
      clear:     async () => { memCache.clear(); await caches.delete(CACHE_NAME); },
      clearPage: async (url) => {
        memCache.delete(url);
        const c = await caches.open(CACHE_NAME);
        await c.delete(url);
        await idbDelete("pages", new URL(url, location.origin).href);
      },
      listPages: () => idbGetAll("pages"),
      estimate:  () => navigator.storage?.estimate?.() ?? Promise.resolve(null),
    },

    // Expose offline queue
    queue: {
      list:    () => idbGetAll("queue"),
      replay:  replayQueue,
      enqueue: enqueueAction,
    },
  };

  // ─────────────────────────────────────────────
  // 24. BOOT
  // ─────────────────────────────────────────────

  async function boot() {
    Progress.init();
    applyThemeColor();
    initDarkMode();
    initNetworkListeners();

    // Kick off idiomorph load in background
    idiomorphReady.catch((err) => {
      console.warn("[Pindrop Engine] idiomorph load failed, using innerHTML fallback:", err.message);
    });

    // Init IndexedDB and Storage Manager in background — non-blocking
    openDB().catch((e) => console.warn("[Pindrop IDB] init failed:", e));
    initStorageManager();

    // Pre-cache current page immediately
    const currentHtml = document.documentElement.outerHTML;
    const currentUrl  = new URL(location.href, location.origin).href;
    cachePut(currentUrl, currentHtml).catch(() => {});

    if (IS_MOBILE) {
      console.log("[Pindrop Engine] v1.3.0 — mobile + offline (idiomorph)");
      initMobileEngine();
    } else {
      console.log("[Pindrop Engine] v1.3.0 — desktop + offline (idiomorph)");
      initDesktopEngine();
    }

    // Show offline indicator if already offline at boot
    if (!isOnline()) {
      showOfflineBanner("You're offline — using saved pages");
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