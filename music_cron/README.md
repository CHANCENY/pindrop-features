# Music Cron (`music_cron`) for Pindrop CMS

A cron-driven bulk importer for the `music` plugin. Drop whole album
folders into `sites/default/files/music/albums/untrace`, and this
plugin's scheduled subscriber reads each track's real audio metadata via
`ffmpeg_worker`, creates/reuses the matching `music_artists`/
`music_albums`/`music_tracks` rows through `music`'s own services, moves
each file into permanent storage, and clears out album folders once
they're fully imported.

## Requires `music`, `ffmpeg_worker`, `cron`

```yaml
# info.yml
dependencies:
  - cron
  - music
  - ffmpeg_worker
```

This plugin creates **no new database tables** and defines **no new HTTP
routes**. It only calls `ArtistService`/`AlbumService`/`TrackService`'s
public methods on `music`, and `SongsApiStandard` on `ffmpeg_worker` —
per core's cross-plugin isolation rule, a plugin may never query another
plugin's tables directly, only its public PHP API.

## Install

```bash
cp -r music_cron /path/to/pindrop/modules/music_cron
cd /path/to/pindrop
./pindro plugin:install music          # if not already installed
./pindro plugin:install ffmpeg_worker  # if not already installed
./pindro plugin:enable music
./pindro plugin:enable ffmpeg_worker
./pindro plugin:enable cron
./pindro plugin:enable music_cron
./pindro cache:clear                   # if running in production mode
```

**Run the self-test before scheduling anything for real:**

```bash
./pindro music_cron:selftest
```

See "Unverified assumption" below for why this matters.

Then, from the `cron` admin UI (`/admin/crons`), create a scheduler job
under the **Music Album Ingest** category and a schedule pointing at the
**Music album ingest subscriber**. Or trigger a one-off run manually
without touching the scheduler at all:

```bash
./pindro music-cron:run
```

## Expected folder layout

```
sites/default/files/music/albums/untrace/
  Starrgirl/
    1. Commas.mp3
    2. Woman Commando.mp3
    7. Hot Body.mp3
  Some Other Album/
    01 track.mp3
```

Each immediate subfolder of `untrace/` = one album. Everything inside it
with a recognized audio extension (`mp3`, `wav`, `ogg`, `flac`, `m4a`,
`aac`) is treated as a track.

## How identity is resolved

**Artist and album come from each track's own ffprobe tags
(`format.tags.artist` / `format.tags.album`), not the folder name.** The
folder name is only a display fallback used as the album title when a
track has no `album` tag at all — tags are per-file ground truth, a
folder name is not. A folder is assumed to hold one artist's tracks; a
file tagged with a different artist than the first successfully-probed
file in the same folder is skipped with a `warn` log rather than being
silently merged in or used to fork a second album.

Imported artists/albums/tracks are attributed to a fixed house account
(`owner_user_id = 0`, username `"system"`) — this matches
`music_artists.owner_user_id`'s own column comment: *"0 =
house/admin-managed"*.

## Dedupe, partial failure, and cleanup — what actually happens

- **Dedupe is title-based, not checksum-based.** Before creating a track,
  the plugin checks `TrackService::findByArtistAndSlug()` for the
  resolved artist + slugified title. A match is treated as
  already-imported and skipped — the file is **left in place**, not
  moved or deleted, so it's visible for manual review rather than
  silently discarded. Re-dropping a genuinely different recording under
  the same title will not be detected as new; this is a known
  simplification, same spirit as the ones documented in `music`'s own
  README.
- **An album folder is only deleted once every file inside it has either
  been imported or skipped as a duplicate.** Any file that fails outright
  (unreadable by ffprobe, artist mismatch, storage move failure) is left
  where it is, and the whole folder is left for the next scheduled run —
  a single bad file never costs you the rest of an otherwise-good album.
  The `untrace/` root only ends up empty as a side effect of every
  dropped album eventually succeeding, not from an unconditional wipe at
  the end of each run.
- **Only one cover image is extracted per album**, from the first track
  that has embedded art, via `SongsApiStandard::extractAudioCoverImage()`.
  It's stored as `music_albums.cover_url`; tracks are created with a
  `null` `cover_url` and fall back to the album cover client-side, per
  the schema's own documented convention.

## Unverified assumption — read before scheduling this in production

`music`'s own `UploadService` moves files via
`FileSystemService::uploadFile()`, which expects a real HTTP `$_FILES`
array. A file sitting in `untrace/` never went through an HTTP POST, so
if `uploadFile()`'s move step relies on `move_uploaded_file()`
internally, it will report failure for every file this plugin hands it
(safely — `uploadFile()` never throws, so nothing gets a DB row pointing
at a file that wasn't actually written).

`StorageMover` handles this with two attempts, in order:

1. `FileSystemService::uploadFile()` with a synthetic `$_FILES`-shaped
   array pointing `tmp_name` at the real on-disk file.
2. A direct `copy()` straight to the `public://...` URI, on the
   assumption that it's a genuinely registered PHP stream wrapper (`simp/
   streamwrapper` is a declared Composer dependency of `simp/pindrop`,
   which is what makes this a reasonable bet rather than a wild guess —
   but it's still unconfirmed against the real core source, exactly the
   kind of assumption `music`'s README flags as "verified against real
   source, not assumed" for its own API calls).

Run `./pindro music_cron:selftest` in your actual environment first. If
it fails, neither strategy works and `StorageMover` needs a real,
core-verified move method before this plugin is safe to schedule.

## Explicitly out of scope

Checksum-based dedupe, splitting a mixed-artist folder into multiple
albums automatically, per-track cover art, partial-file cleanup within a
folder (a folder is all-handled-or-left-alone, never partially deleted),
and re-running ingestion concurrently (no locking — don't schedule this
to overlap with a manual `music-cron:run`, or with itself on a very
short interval against a large `untrace/`).
