# Site Identity Plugin (`site_identity`) for Pindrop CMS

Owns the site-wide, singular paths that don't belong to any one content
plugin: `robots.txt`, `ads.txt`, the favicon/PWA icon set, and
`site.webmanifest`. Includes an admin UI to edit `robots.txt`/`ads.txt` and
to generate the entire icon set from either short text (e.g. site initials)
or an uploaded image — no external image tool required.

## Why a separate plugin

Paths like `/favicon.ico` and `/robots.txt` are conventionally singular —
there's exactly one of each per site, not one per content module. Bolting
them onto a content plugin (as an earlier draft of the `qa` plugin did)
means every other content plugin that *also* wants a favicon has to either
duplicate the routes (a hard conflict — two plugins can't register the same
path) or depend on that one content plugin, which makes no sense. This
plugin has no opinion about what content your site serves; any number of
content plugins (`qa`, a blog, a shop, ...) can link to its routes.

## Install

```bash
cp -r site_identity /path/to/pindrop/modules/site_identity
cd /path/to/pindrop
./pindro plugin:install site_identity
./pindro plugin:enable site_identity
./pindro db:schema:create   # select site_identity_schema (or "all")
./pindro cache:clear        # if running in production mode
```

Then visit `/admin/site-identity` (requires `admin`/`super_admin`) to set
your site name, brand colors, `robots.txt`/`ads.txt` content, and generate
a favicon.

Add this to any template's `<head>` (or use `qa`'s `base.html.twig`, which
already has it):

```html
<link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="/favicon.svg" />
<link rel="shortcut icon" href="/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
<link rel="manifest" href="/site.webmanifest" />
```

## Requirements

- **PHP `ext-gd`** for icon generation (`generateFromText`/`generateFromImage`).
  Bundled with PHP on most hosts but not universal — `IconGeneratorService`
  checks `extension_loaded('gd')` up front and throws a clear error (surfaced
  in the admin UI) rather than fataling mid-render if it's missing. Serving
  *already-generated* icons doesn't need GD at all — only generating new ones
  does.
- A bundled font (`assets/fonts/Outfit-Bold.ttf`, OFL-licensed geometric
  sans, part of a common open font bundle) is used for text-based logos.
  Swap the file for your own TTF if you want different typography — just
  keep the filename or update the `$fontPath` default in
  `IconGeneratorService`. If the font file or `ext-gd`'s FreeType support is
  missing, text rendering falls back to GD's built-in bitmap font rather
  than failing outright.

## How it works

- **Settings** (`site_identity_settings`, a single row, `id = 1` always) —
  site name, theme/background color, `robots_txt`, `ads_txt`, an optional
  `sitemap_url`, and which logo mode was last used.
- **Generated assets** (`site_identity_assets`) — one row per icon variant
  (`favicon.svg`, `favicon.ico`, `favicon-96x96.png`, `apple-touch-icon.png`,
  `icon-192.png`, `icon-512.png`), storing raw bytes + MIME type directly in
  the DB. `PublicAssetController` streams straight from this table. This
  avoids depending on any particular filesystem layout or a confirmed
  static-file-serving route (see design note below) — same reasoning as
  `qa`'s inlined CSS/JS.
- **Fresh-install fallback**: before an admin generates anything,
  `/favicon.svg` etc. serve a small built-in placeholder rather than 404ing,
  so a new install never shows a broken favicon.
- **Text-to-icon**: draws a rounded-square background (GD, hand-built via
  `imagefilledellipse` corners + `imagefilledrectangle` fills — GD has no
  native rounded-rect primitive) with centered bold text, at every size
  browsers/iOS/PWA installs expect (16/32/48/96/180/192/512px), plus a true
  vector SVG for browsers that support it.
- **Image-to-icon**: uploads get cropped to a centered square, resized per
  target size, and corner-rounded via an alpha mask (same rounded shape,
  composited over the resized photo pixel-by-pixel). The SVG variant in this
  mode is *not* true vector — it's the 512px PNG embedded as a base64 data
  URI inside an `<svg><image>` wrapper, since vectorizing an arbitrary photo
  isn't something you can reasonably do without a proper vectorization
  library. It's a legitimate, browser-supported technique, just not a
  scalable vector in the traditional sense — worth knowing if you were
  expecting infinite zoom.
- **`favicon.ico`**: GD can't write `.ico` — `IconGeneratorService::buildIco()`
  hand-packs the 16/32/48px PNGs into a minimal, modern (Vista+) PNG-embedded
  ICO container. This format is supported by every current browser and OS;
  it is not the legacy BMP-based ICO format, which would need a much larger
  bespoke encoder for little practical benefit today.

## Permissions

- `db.site_identity.read` (select-only) is granted to **every** role,
  including `anonymous` — browsers request `/favicon.ico` etc. on every page
  load regardless of who's logged in, and those requests still go through
  `DatabasePermissionGuard` for logged-in (non-anonymous) users. Without this
  broad read grant, a logged-in `user`/`moderator`'s browser would get a
  `DatabasePermissionException` fetching their own favicon.
- `db.site_identity.write` + `can_manage_site_identity` (the admin UI itself)
  are `admin`/`super_admin` only — deliberately not granted to `moderator`,
  since site-wide SEO/branding config is a different class of sensitivity
  than day-to-day content moderation.

## Known limitations / explicitly out of scope

- **No automatic sitemap discovery.** `robots.txt`'s `Sitemap:` line comes
  from the `sitemap_url` setting, which you set manually in the admin UI
  (e.g. to `qa`'s `/sitemap-questions.xml`). This plugin doesn't hardcode
  knowledge of any specific content plugin's routes — see the design
  rationale above.
- **Route collisions are your responsibility to avoid.** If more than one
  installed plugin registers the same path (e.g. two plugins both trying to
  own `/robots.txt`), Pindrop's router doesn't arbitrate that for you —
  install only one such plugin, or remove the conflicting routes from
  `routing.yml` in whichever one you don't want serving that path.
- **No PWA service worker.** The manifest satisfies the "Manifest" PWA
  requirement; offline mode/push/background sync need a service worker,
  which is genuinely a front-end/JS concern outside a PHP plugin's scope —
  wire one up in your theme if you need it.
- **Logo generation is synchronous and can take a moment** for larger image
  uploads (the alpha-mask rounding step is a per-pixel loop across all
  raster sizes). If your host has a very low `max_execution_time` for admin
  routes, bump it — this isn't a hot path (admins generate a logo rarely,
  not per-request), so a few seconds is an acceptable trade-off against
  building a queued/async job for it.
