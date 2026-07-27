# Q&A Platform Plugin (`qa`) for Pindrop CMS

A Stack Overflow / Quora style Q&A core: questions, answers, nested comments,
voting, tags, bookmarks, reputation tracking, full-text search, and SEO
(meta tags, JSON-LD, sitemap, RSS) — built as a single Pindrop plugin.

This plugin was built against verified, current Pindrop core behavior (not
assumptions) — see "Design notes / known constraints" below for the specific
things that shaped the architecture.

## Changelog

**v1.1.0**
- **Fixed: bookmarks were unreachable.** Bookmarking worked (`POST /bookmark/{id}`
  and the `qa_bookmarks` row were both fine), but there was no nav link
  anywhere pointing at `/dashboard/qa`, where the bookmarks list lives — so
  there was simply no way to click through to them. Added "Bookmarks" and
  "Dashboard" links to the header nav (shown via core's `is_login` Twig
  global), plus Login/Logout/Sign up links for logged-out visitors.
- **Added: bulk question import**, two entry points sharing one
  `ImportService` so behavior can't drift between them:
  - Admin UI: `/admin/qa/import` (new sidebar link) — upload a `.json` file
    or paste JSON directly.
  - CLI: `./pindro qa:import /path/to/file.json [user_id] [username]`
  - Expected shape (matches `questions_data_with_answers.json`): a JSON
    array of `{title, body, tags, answers[]}` objects, where `tags` is a
    comma-separated string (or an array) and `answers` is an array of
    answer body strings (0 or more).
  - Imported content is attributed to whoever runs the import (the logged-in
    admin for the UI path, an optional `user_id`/`username` CLI argument for
    the CLI path — defaults to a synthetic "Import Bot" with `user_id = 0`,
    which is safe since `qa_questions.user_id` has no FK constraint to core
    `users` by design — see the denormalization note above).
  - Tags are found-or-created and `questions_count` stays in sync via the
    same `TagService::syncQuestionTags()` used by the ask/edit forms.

## Install

```bash
cp -r qa /path/to/pindrop/modules/qa
cd /path/to/pindrop
./pindro plugin:install qa
./pindro plugin:enable qa
./pindro db:schema:create   # select qa_schema when prompted (or "all")
./pindro cache:clear        # if running in production mode
```

> Upgrading from v1.0.0? The schema is unchanged in v1.1.0 — no migration
> needed, just replace the plugin files and clear the cache.

Then visit `/questions` to browse, `/questions/ask` to post (requires login),
and `/admin/qa` for moderation (requires `moderator`/`admin`/`super_admin`).

## What's included (MVP core, fully wired)

- **Questions**: ask, edit (owner or moderator), view, close, soft-delete,
  SEO-friendly slugs with automatic de-duplication (`-2`, `-3`, ...).
- **Answers**: post, accept/unaccept (question owner only), vote.
- **Comments**: nested (one level) on questions and answers.
- **Voting**: upvote/downvote with toggle/switch logic, one vote per user per
  item enforced at the schema level (`UNIQUE(user_id, votable_id, votable_type)`).
- **Tags**: find-or-create on question submit, tag pages with related tags,
  auto-maintained `questions_count`.
- **Bookmarks**, **Reputation** (point history + totals, Stack-Overflow-style
  defaults — see `ReputationService` constants), **Reports** (flag content,
  admin resolve queue).
- **Search**: MySQL `FULLTEXT` search over title+body, plus filters (newest,
  oldest, votes, views, unanswered, accepted).
- **SEO**: per-question meta title/description/canonical, OpenGraph, Twitter
  Card, Question/Answer JSON-LD (schema.org `QAPage`), breadcrumb JSON-LD,
  `sitemap-questions.xml`, `/questions/feed` RSS.
- **Admin/moderation**: dashboard with counts, question list with
  close/delete, reports queue, tag description editor — all under `/admin/qa`,
  gated by `can_qa_moderate`.
- **Light/dark mode**, responsive card-based UI, skeleton-friendly CSS,
  AJAX vote/bookmark without full page reloads.
- **Signals**: emits `qa.question.created`, `qa.answer.created`,
  `qa.answer.accepted`, `qa.vote.cast`, `qa.comment.created`,
  `qa.report.created` etc. *if* the `signals_slots` plugin is installed
  (optional, not a hard dependency) — see Design notes below.

## Explicitly out of scope for this build

The blueprint's own module breakdown treats these as **separate, independently
enable-able Pindrop modules** — they're not built here, to keep this plugin
focused and to match that structure:

- Real-time notifications module
- AI features (answer suggestions, duplicate detection, summarization,
  auto-tagging, grammar, AI moderation)
- Badges (bronze/silver/gold) — reputation *point tracking* is implemented;
  badge awarding rules are not
- Full admin analytics/charts (daily users, traffic graphs) — the admin
  dashboard here shows counts and most-viewed only
- REST API surface, PWA manifest/offline/push, i18n, rich-text/Markdown
  editor integration (bodies are stored/rendered as pre-sanitized HTML —
  wire in your editor of choice, e.g. TipTap/Quill, on the ask/edit forms)
- File upload attachments (the `qa_attachments` table exists; no upload UI
  wired yet — hook into Pindrop's file upload facilities and POST to a new
  `qa.attachment.upload` route)

These are natural follow-up plugins/PRs, not gaps in this one.

## Design notes / known constraints (why some things are built the way they are)

1. **No `StorageEntity`.** Every table access goes through
   `DatabaseService::table()` (the QueryBuilder) via plain service classes.
   `StorageEntity`'s `save()`/`load()`/etc. call a `DatabaseService::query()`
   method that no longer exists on the current core `DatabaseService` — using
   it would fatal at runtime. This plugin avoids that path entirely.

2. **Author display is denormalized, not joined.** `DatabasePermissionGuard`
   only allows `admin`/`super_admin` to `SELECT` from core tables (including
   `users`). Since question/answer pages are public, this plugin snapshots
   `author_username`/`author_avatar_url` onto `qa_questions`/`qa_answers`/
   `qa_comments` at write time (from the poster's own session data — no query
   needed) instead of joining `users` live. Trade-off: a display name change
   won't retroactively update old posts. If you want live-accurate names,
   either grant a narrow `db.system.read`-equivalent permission designed for
   this purpose, or add a small "public username" core table that's safe for
   everyone to read.

3. **Public profile pages (`/users/{id}`) are best-effort for the same
   reason** — they derive the shown name from the user's most recent
   question/answer rather than the `users` table. A user with zero posts
   shows as "User #{id}". If you need full profile data (bio/website/location
   from `users`), route that through the `admin` plugin instead, which
   already operates with appropriate context.

4. **CSRF is automatic.** Core auto-injects a hidden `_csrf_token` field into
   every rendered `<form>` and validates it on POST — templates here use
   plain `<form method="post">` and controllers `unset($data['_csrf_token'])`
   before persisting, matching the pattern used by the `farm` plugin.

5. **`_permission` route requirements only work because `admin` is
   installed.** Enforcement of `routing.yml`'s `_permission` key happens in
   the `admin` plugin's `AuthMiddleware`, not in core routing. If `admin`
   isn't installed/enabled, every route in this plugin becomes effectively
   public (the permission keys are declared but nothing checks them). Make
   `admin` a dependency in `info.yml` if you want a hard guarantee — left
   optional here on purpose so this plugin doesn't force that dependency
   the way `farm` unnecessarily hard-depends on unrelated plugins.

6. **`signals_slots` integration is optional and defensive.** `QaSignalDispatcher::emit()`
   checks `class_exists()` and wraps the call in try/catch, mirroring the
   pattern used by `core_signals` — a Q&A action never fails because an
   unrelated plugin isn't installed.

7. **Templates use the `@qa/...` Twig namespace**, confirmed against the
   only *working* template references in the shipped `admin` plugin
   (`@admin/content/article.twig` et al. — note several *other* references
   inside `admin`'s own `AdminController.php`, like `'admin/dashboard.twig'`
   without the `@` prefix, point at files that don't exist in that plugin at
   all; those routes are broken as shipped). This plugin's `@qa/foo.twig`
   maps to `modules/qa/templates/foo.twig` — verified every reference in
   this plugin resolves to a real file on disk before packaging.

8. **CSS/JS are inlined in `base.html.twig`** (via `{% include %}` of
   `.css.twig`/`.js.twig` partials) rather than linked from a static asset
   URL like `/modules/qa/assets/...`, because plugin static-file serving
   isn't a confirmed core route. The same CSS/JS also live under
   `assets/css/qa.css` and `assets/js/qa.js` for reference/editing — if your
   Pindrop install does serve plugin assets statically, switch the `<link>`/
   `<script>` tags in `base.html.twig` to point there instead.

## Reputation point values

`ReputationService`'s constants (`POINTS_ASK_QUESTION = 5`,
`POINTS_ANSWER_QUESTION = 10`, `POINTS_ACCEPTED_ANSWER = 15`,
`POINTS_RECEIVE_UPVOTE = 10`, `POINTS_RECEIVE_DOWNVOTE = -2`) follow the
conventional Stack Overflow scheme. The source blueprint's own numeric values
were lost in its PDF-to-text extraction (rendered as "Ask Question: +" with
no number) — adjust the constants directly if you want different values.

## Extending

- New moderation actions: add to `ModerationController` + `routing.yml`,
  gated by `can_qa_moderate`.
- Notifications module: subscribe to this plugin's `signals.yml` events via
  `signals_slots`, or add your own `src/Plugin/Events/EventsSubscriber.php`
  if you'd rather hook core's native `Events` system directly.
- Rich text editor: swap the plain `<textarea>` in `question_ask.html.twig`/
  `question_edit.html.twig` for your editor of choice; bodies are stored as-is
  in `qa_questions.body`/`qa_answers.body` (MEDIUMTEXT), rendered with the
  `|raw` filter, so sanitize on submit in the controller before persisting.
