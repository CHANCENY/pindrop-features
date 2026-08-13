# Dev Console (`dev_console`) for Pindrop CMS

Adds `./pindro tinker` — an interactive PHP REPL powered by [PsySH](https://packagist.org/packages/psy/psysh),
with the app's service container pre-loaded. Same idea as Laravel's
`artisan tinker`: a fast way to poke at your app's services, run one-off
queries, or debug something without writing a throwaway script.

```
$ ./pindro tinker
Pindrop tinker — environment: development
Available: $container (service container), $db (DatabaseService, if resolved).
Example: $db->table('qa_questions')->count();
Type `exit` or press Ctrl+D to quit.
pindrop [development]> $db->table('qa_questions')->count();
=> 118
pindrop [development]> $container->get('qa.question')->find(1);
=> [...]
```

## Install

PsySH is a real Composer package, not something this plugin can bundle
itself — Pindrop's plugin model shares one `vendor/` at the project root
(none of the plugins in this ecosystem, including this one, ship their own
`composer.json`), so the dependency has to be added there:

```bash
cd /path/to/pindrop
composer require --dev psy/psysh
cp -r dev_console modules/dev_console
./pindro plugin:install dev_console
./pindro plugin:enable dev_console
```

That's it — no database schema, no permissions setup, no routing. Run
`./pindro tinker` from the project root whenever you want it.

`--dev` is a suggestion, not a requirement: if you want `tinker` available
in production too, drop the flag. Either way, see the safety notes below.

## Why this is CLI-only, on purpose

"Expose PsySH" could reasonably mean two very different things:

1. **A CLI REPL** — what this plugin does. It only runs for whoever already
   has shell/SSH access to the server, which is the same trust boundary
   every other `./pindro` command already relies on. It doesn't add any new
   way to reach the server; it just gives someone who's already in a nicer
   tool once they're there.
2. **A web-accessible console** — an HTTP endpoint that runs arbitrary PHP
   from a browser. That's a fundamentally different thing: effectively a
   remote-code-execution backdoor unless it's locked down far more tightly
   than a simple permission check (super_admin-only *and* an explicit
   opt-in flag *and* arguably an IP allowlist, at minimum).

This plugin deliberately only builds (1). It registers **no HTTP route at
all** — and this isn't just a design choice this plugin happens to follow,
it's structurally enforced one level up: Pindrop's own CLI bootstrap
(`cli/cli.inc.php`) hard-exits with `if (php_sapi_name() !== 'cli')`, so
nothing loaded through that path — including this command — can be reached
over HTTP no matter what, even by accident.

If you genuinely want a web-based console, that's a different, much
higher-stakes plugin to build with its own explicit threat model — ask for
it by name and go in with eyes open, rather than getting it as a side
effect of asking for "tinker."

## Safety notes

- **Production confirmation prompt.** If `APP_ENV=production`, running
  `tinker` shows an explicit warning and requires typing `yes` before it
  opens the shell — there's no sandbox once you're in; every line you type
  executes for real, against real data.
- **This is still a very powerful tool.** Anyone who can run `./pindro
  tinker` can do anything PHP can do on that server, including reading/
  writing the database directly, bypassing `DatabasePermissionGuard`
  entirely (the guard is a request-time /application-layer check — a raw
  PsySH session isn't going through routing or middleware at all). Treat
  server/SSH access as the actual security boundary here, same as you
  would for direct database CLI access or any other root-level tooling.
- **`$container` / `$db` might be null.** If the app container can't be
  resolved for some reason (e.g. an unusual bootstrap context), tinker
  still opens as a plain PHP REPL rather than failing outright — it just
  warns you those two variables won't be set.
