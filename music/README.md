# Music Platform Plugin (`music`) for Pindrop CMS

A self-hosted, YouTube-Music-style streaming platform: artists, albums,
tracks, playlists, liked songs, followed artists, full-text search, a
persistent bottom player with a real queue (shuffle/repeat included), an
upload flow, moderation, and SEO (JSON-LD, sitemap).

**This is a self-hosted platform, not a YouTube client.** Artists/admins
upload their own licensed audio; tracks stream via HTML5 `<audio>` from the
site's own storage. It does not pull from, embed, or proxy YouTube's actual
catalog — that would violate YouTube's terms of service and copyright law.
"YouTube Music" describes the *interaction model* this plugin copies
(persistent player, queue, library, home feed), not the content source.

## Requires `mobile_app`

```yaml
# info.yml
dependencies:
  - mobile_app
```

This plugin's persistent player relies on `mobile_app`'s real `engine.js` +
idiomorph DOM-morphing navigation — loaded directly via hardcoded
`<script>` tags in `templates/base.html.twig`, not a parallel
reimplementation. `PluginManager::enablePlugin()` genuinely enforces this
dependency (same mechanism the `farm` plugin relies on for `cron`/
`signals_slots`) — `music` will refuse to enable unless `mobile_app` is
already installed.

**Important nuance**: `mobile_app`'s own base template
(`mobile.base.html.twig`) only renders when a site has `MOBILE=TRUE` set in
`.env` *and* `engine.enabled: true` in `mobile.settings.yml` — verified via
`MobileSettingsService::isEnabled()`. That's off by default on a typical
install. So this plugin does **not** extend that template — it ships its
own always-on shell (`templates/base.html.twig`) and loads `mobile_app`'s
real `engine.js`/`idiomorph.min.js` files directly, without setting
`window.__PINDROP_MOBILE__` at all (confirmed safe: every mobile-only
feature in `engine.js` — bottom nav, gesture config — gracefully no-ops
without it, while the DOM-morphing navigation itself, the part this plugin
actually needs, works with zero configuration).

## Install

```bash
cp -r music /path/to/pindrop/modules/music
cd /path/to/pindrop
./pindro plugin:install mobile_app   # if not already installed
./pindro plugin:enable mobile_app
./pindro plugin:install music
./pindro plugin:enable music
./pindro db:schema:create            # select music_schema (or "all")
./pindro cache:clear                 # if running in production mode
```

Visit `/music` to browse, `/music/upload` to upload (requires login —
you'll be prompted to set up an artist profile first if you don't have
one), and `/admin/music` for moderation (requires `moderator`/`admin`/
`super_admin`).

No new Composer dependency — audio duration is extracted **client-side**
(a temporary `<audio>` element reads `.duration` before upload), specifically
to avoid requiring a library like getID3. Same "flag new dependencies
explicitly, or avoid them" discipline as the `dev_console` plugin's
`psy/psysh` requirement — just resolved the other way here.

## Architecture, phase by phase

1. **Schema + permissions + core services.** 10 `music_*` tables (no FKs to
   core tables — `DatabasePermissionGuard` restricts core-table access to
   admin/super_admin, so ownership/authorship is denormalized, same pattern
   as the `qa` plugin). `ArtistService`/`AlbumService`/`TrackService` over
   `DatabaseService::table()` — no `StorageEntity`, which calls a
   `DatabaseService` method that no longer exists on current core.
2. **App shell.** `#app-content` is the idiomorph morph target; the player
   bar and `<audio>` element are siblings *after* it, not children, so
   in-app navigation never interrupts playback.
3. **Browse/search/artist/album/track pages**, all playable via
   `data-play-track`/`data-queue-track` — event delegation on `document`,
   not direct element bindings, since anything inside `#app-content` gets
   replaced wholesale on navigation.
4. **Real queue engine**: a `playOrder` permutation over `state.queue`
   (not just a linear index), so shuffle actually shuffles rather than
   being a UI-only toggle. Play-count beacon fires once per track after a
   30-second-or-half-duration threshold, POSTed with a CSRF token read
   from a hidden utility `<form>` (the beacon is a `fetch()` call, not a
   real form submission, so it has no natural form to pull the
   auto-injected token from otherwise).
5. **Upload flow** via core `FileSystemService::uploadFile()` + a
   moderation admin panel (dashboard, track/artist management, reports
   queue) gated behind `can_music_moderate`.
6. **Library**: liked songs (an implicit list, not a real playlist row),
   user playlists (create/add/remove/reorder/public toggle/delete), followed
   artists. Playlist reordering uses up/down semantics + a full reload
   rather than drag-and-drop — a deliberate choice given this plugin was
   built without the ability to visually test JS in a real browser;
   correctness over slickness for anything DOM-manipulation-heavy.
7. **SEO**: `MusicRecording`/`MusicAlbum`/`MusicGroup` JSON-LD, OpenGraph/
   Twitter Card meta tags, and `/music/sitemap.xml`. Plus rule-based "Made
   For You" on the home page (same-genre/same-artist suggestions seeded
   from the logged-in user's most recent play) — no ML, exactly as scoped.

## Bugs caught during development (verified against real source, not assumed)

- **`FileSystemService::uploadFile()`'s actual return shape** doesn't match
  what a reasonable first guess would produce: it never throws, returning
  `['success' => false, 'message' => ...]` on failure instead, and nests
  the real file info one level down in `['data'][0]`, not flat. Caught
  before shipping by reading the real method body — a naive
  `$result['uri'] ?? $intendedPath` fallback would have silently created a
  track record pointing at a file that was never actually written.
- **`->orderBy()->value()` combination** — verified against the real
  `QueryBuilder::value()` source before relying on it for "get the max
  playlist position" (it correctly appends `LIMIT 1` after any prior
  `orderBy()`, so this works, but it was the first time this plugin
  combined the two and it wasn't assumed without checking).
- **Two unverified Twig filter assumptions, both caught and fixed**:
  `{{ x|json_encode }}` (vanilla Twig doesn't ship this by default) and
  `{{ tracks|map(t => t._play_json)|join(...) }}` (assumes both the `map`
  filter and Twig 3.x arrow-function syntax are available). Both replaced
  with the proven pattern already used throughout this plugin: encode as
  JSON in PHP, embed via a `data-` attribute, `JSON.parse()` in JS.
- **Event-delegation ordering bug**: the queue button is nested *inside*
  a `data-play-track` row (see `partials/track_row.html.twig`), so
  `.closest('[data-play-track]')` would match the outer row even when
  clicking the inner queue button. Fixed by checking the more specific
  `data-queue-track` match first in the delegated click handler and
  returning immediately — not by `stopPropagation()`, which would have
  also blocked the plugin's own delegated listener from ever seeing the
  click at all, since it's bound on the same `document` target.
- **JSON-LD escaping context**: `<script type="application/ld+json">`
  needs `/` to stay escaped as `\/` (default `json_encode()` behavior) so
  a `</script>` appearing inside a track title or lyrics field can't
  prematurely close the tag — deliberately *not* reusing this plugin's own
  `JSON_UNESCAPED_SLASHES` pattern used for `data-` attributes elsewhere,
  since that's a different, incompatible escaping context.

## Explicitly out of scope (per the original build plan)

Native mobile apps, offline/downloaded playback, time-synced (LRC) lyrics,
ML-based recommendations, collaborative/real-time shared playback,
monetization/royalties/licensing management.

## Known simplifications worth knowing about

- **Anonymous plays aren't counted.** The play-count beacon requires login
  (`can_music_play_track`) rather than gambling on whether an anonymous
  request bypasses `DatabasePermissionGuard` or is checked against the
  `anonymous` role's grants (which only include `db.music.read`, not
  write) — same reasoning `qa`'s vote/bookmark/comment routes used. Actual
  audio *streaming* works fine for anonymous visitors regardless (the
  browser requests the file directly; Apache serves it, per the
  `/sites/default/files/` static-bypass rule confirmed in the project's
  root `.htaccess` — no PHP/permission involvement at all), it's just the
  play *count* and listening *history* that require a logged-in session.
- **Liked/queue state on list pages** (browse, album, search) doesn't
  batch-check the viewer's like status — those track rows always render
  as unliked until clicked. Wired correctly on the track permalink page
  and the persistent player bar (via `LikeService::isLiked()`/
  `likedMap()`), which are the highest-visibility surfaces; extending it
  to every list view is a straightforward follow-up, not a design gap.
