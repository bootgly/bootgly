---
name: bootgly-project
description: "Create, copy, import, boot and run a Bootgly project inside a bootgly.kit. Covers `projects create <Name> --interfaces=CLI|WPI` (console app or web server), copying a shipped example with `--from=Demo/...` and the namespace rename that copy needs, `projects import <url>`, and `project <Name> boot` (gives the project its own git repository). Also covers start, show, stop, restart, reload and logs, the file layout the loader requires, and per-project Composer. Use when you start a new application in the kit, turn a shipped example (a framework demo or a platform's example under Demo/) into your own project, import a project from a git URL, or add a Composer package to a project. Also use when a project will not start, reports \"Project not registered\", or has to be run, checked or stopped. Building features inside an existing project: bootgly-build and the platform's bootgly-build-<platform>. Configuration and secrets: bootgly-config. Tests: bootgly-test. Reviews: bootgly-review."
---
<!-- Machine-managed by `bootgly kit boot`: it rewrites this skill, and removes it once no longer shipped. Do not edit. -->

# Bootgly project: create, copy, import, run

Commands are written `bootgly …`. Without the global wrapper, run `php ../bootgly …` from `projects/` and `php ../../bootgly …` from `projects/<Name>/` (one more `../` per nested segment).

The rules live in `<kit>/projects/.agents/rules/`; this skill links them as `../../../.agents/rules/<Section>.md`.
Read them first, above all [Organizational_structures.md](../../../.agents/rules/Organizational_structures.md) and
[Workflow_pipelines.md](../../../.agents/rules/Workflow_pipelines.md); the flag reference is `<kit>/AGENTS.md`. Work from
`<kit>/projects/` (or the kit root): started inside `projects/<Name>/`, agents stop at the project repository and miss the
rules. Never commit in the kit repository. Never edit `Bootgly/`, `Console/`, `Web/`, `projects/Bootgly.projects.php`,
`projects/AGENTS.md` or `projects/.agents/`. The `bootgly-` prefix in `projects/.agents/skills/` is reserved: `kit boot`
rewrites or removes each skill it stamped (and its `projects/.claude/skills/` link), and one of the user's under a name
Bootgly ships keeps that Bootgly skill out — so a skill of the user's own takes another name.

## 1. Decide first

- **Interface**: a console app (commands, TUI, game, worker) is `CLI` (Console platform). A web
  server (pages, REST API, WebSocket) is `WPI` (Web platform). If the request fits both, ask.
- **Scratch or example**: `bootgly projects list` shows the framework demos (`Demo/HTTP_Server_CLI`, …) and
  each platform package's examples — its `bootgly-build-<platform>` skill says which to start from. Read or run
  one where it is (section 7); copy it only when you will build on it.
- **Name**: each segment starts uppercase and uses letters, digits, `_` or `-` (`Shop`, `App/API`).
  The first segment is never `Bootgly`, `Console`, `Web`, `Data`, `Graphics`, `Embedded` or `Mobile`.
- **Platform package**: a platform's classes come from its package at the kit root (`<kit>/Console/`, `<kit>/Web/`)
  once it is set up (its `autoboot.php` exists); a prepared kit has both. Add `--platform=web` or `--platform=console`
  to the create only when one is missing: it sets it up, its build skill included, and re-imports its deleted examples.

## 2. Create from scratch

```sh
bootgly projects create Shop --interfaces=WPI --port=8081 --yes   # web (HTTP) server
bootgly projects create Tool --interfaces=CLI --yes               # console app
```

- Always pass `--yes`: without it, a terminal opens the wizard. Optional metadata:
  `--description=`, `--version=`, `--author=`. `--port=` is for WPI only (default `8080`).
- The project goes into `projects/<Name>/`, gets registered in `projects/Bootgly.projects.php` and is
  booted: it becomes its own git repository, with the scaffold as the first commit (`--no-git` skips
  this). If no git identity is configured, git init runs but nothing is committed. Tell the user.

```text
projects/Shop/
├── Shop.Project.php          ← the signature: returns the Project; named after the LAST path segment
├── router/                   ← WPI only: router.index.php lists route sets → routes/Welcome.routes.php
├── schedule.php              ← cron jobs: `project Shop schedule run|list`
├── tests/autoboot.php        ← the Suites registry → tests/example/
└── .gitignore                ← /vendor/ and configs/**/.env
```

Create migrations and seeders with `bootgly project Shop migrate create <name>` and `seed create <name>`
(into `database/migrations/` and `database/seeders/`). Add the rest by hand under the fixed names:
`configs/<scope>/<scope>.Config.php`, `views/*.template.php`, `statics/`. Put classes in uppercase directories
whose namespace is the project path (`projects/Shop/Models/Item.php` → `namespace Shop\Models;`). A wrong
name is silently ignored. What goes in them: the bootgly-build skill.

## 3. Copy a shipped example (`--from`)

```sh
bootgly projects create MyNotes --from=Demo/Notes --yes   # Demo/Notes: the example; MyNotes: your target
```

- Always name the target. Without a name, the copy takes the source path and refreshes the example.
- The interface comes from the example. The copy has no git repository (no `.git`) until section 5.
- Only the signature file is renamed (`Notes.Project.php` → `MyNotes.Project.php`). Every class still declares
  `Demo\Notes\…`, and the autoloader keeps loading those classes from the ORIGINAL example. Rename them
  before editing, from `projects/`, with GNU sed: put in your own source path, source leaf and target. The
  last grep is the positive check: every namespace must start with the target's.

```sh
grep -rlE 'Demo[\\/]Notes|Notes\.Project\.php' MyNotes --include=*.php | xargs -r sed -i \
   -e 's/\bDemo\\Notes\b/MyNotes/g' -e 's#\bDemo/Notes\b#MyNotes#g' \
   -e 's/\bNotes\.Project\.php/MyNotes.Project.php/g'
grep -rnE 'Demo[\\/]Notes' MyNotes                   # must print nothing
grep -rhoE '^namespace [^;]+' MyNotes | sort -u     # each line must start with: namespace MyNotes
```

A nested target (`projects create Apps/Notes --from=Demo/Notes --yes`) keeps the leaf `Notes.Project.php`. In the
replacement, double the namespace backslash (`Apps\\Notes`): GNU sed drops a single one and writes `AppsNotes`.

```sh
grep -rlE 'Demo[\\/]Notes' Apps/Notes --include=*.php | xargs -r sed -i \
   -e 's/\bDemo\\Notes\b/Apps\\Notes/g' -e 's#\bDemo/Notes\b#Apps/Notes#g'
grep -rnE 'Demo[\\/]Notes' Apps/Notes                  # must print nothing
grep -rhoE '^namespace [^;]+' Apps/Notes | sort -u    # each line must start with: namespace Apps\Notes
```

- If the name should change, change `name:` in the signature and in the signature test that checks it.
- Adopt the copy with `project MyNotes boot` (section 5). Examples hard-code their port: start the copy with `PORT=`.

## 4. Import from a git repository

```sh
bootgly projects import https://github.com/acme/shop.git Shop --interfaces=WPI --yes
```

- The repository root must hold a `*.Project.php`. The copy keeps its history and `origin`. The name
  defaults to the repository name. `--interfaces` defaults to `WPI`: pass `CLI` for a console app.
- Imported code runs when the project starts. `--yes` skips that confirmation: only for a user's URL.
- A different target name renames the signature file and leaves that change uncommitted. Namespaces
  are not renamed: apply the rename from section 3 and let the user review and commit it.

## 5. Adopt a project: `boot`

`bootgly project MyNotes boot` turns a `--from` copy, a shipped example or a `--no-git` project into its own
git repository, with the current files as the first commit. A nested project inside another project's
repository (`App/API` inside a booted `App`) joins that repository instead. After `project MyNotes boot`, before
any git write of your own, `git -C MyNotes rev-parse --show-toplevel` must print the project directory; if not, stop and ask.

## 6. The signature

`start` loads the project's `vendor/autoload.php`, then `projects/<Path>/<Leaf>.Project.php`, and runs `boot`.
Below is the WPI scaffold, trimmed. Keep the `-f`/`-i`/`-m` mapping (`project startup` needs `-f`):

```php
<?php

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Configs;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;


return new Project(
   name: 'Shop',
   exportable: true,
   boot: function (array $arguments = [], array $options = []): void
   {
      $Server = new HTTP_Server_CLI(Mode: match (true) {
         isSet($options['f']) => Modes::Foreground,
         isSet($options['i']) => Modes::Interactive,
         isSet($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $Server->configure(new Configs(host: '0.0.0.0', port: getenv('PORT') ? (int) getenv('PORT') : 8081, workers: 2));
      $Server
         ->on(Events::RequestReceived, HTTP_Server_CLI::$Router->load(__DIR__ . '/router'))
         ->on(Events::ServerAdvertised, function ($Server) {
            $Server->advertise();
         });

      $Server->start();
   }
);
```

The CLI scaffold's `boot` only prints a greeting through `CLI->Terminal->Output`. For a platform's shell (an app,
a game, …), follow its build skill, `bootgly-build-<platform>`; never guess its arguments.

## 7. Run, check, stop

```sh
bootgly project Shop show                  # its running instances; if you did not start one, ask first
PORT=18080 bootgly project Shop start      # WPI: daemonizes, prints the banner, returns
curl -s http://localhost:18080/
bootgly project Shop logs --since=15m      # past records; `logs -f` never exits
bootgly project Shop reload 18080          # hot-reload code (SIGUSR2)
bootgly project Shop restart 18080
bootgly project Shop stop 18080            # that instance only; no port = every instance
bootgly projects show --json               # every running instance in the kit
```

- `start -f` (foreground), `-i` and `-m` keep the terminal busy, so run them only as a background task.
- A CLI project runs `boot` in the foreground; its instance is its PID (`stop <PID>`). Interactivity is read from
  STDIN, so a stdout pipe alone does not stop a full-screen program: `BOOTGLY_TTY=0` makes it run headless — the
  platform's build skill gives its smoke test.
- "Project not registered": create or import the project. Never hand-edit the registry.
  "No project file found": the signature file is not named `<Leaf>.Project.php`.

## 8. Composer, per project

Run `composer require vendor/package` inside `projects/<Name>/`: `vendor/` is git-ignored, `composer.lock`
is committed, and the autoloader loads before the signature. Never run Composer at the kit root, never
require the framework itself, and ask the user before adding a package for something Bootgly already
provides (HTTP server, tests, config, logs, templates, SQL).

## Done when

From `projects/<Name>/`, the [Definition of done](../../../.agents/rules/Testing_guidelines.md):

1. `bootgly lint imports <path> --fix` and `bootgly lint nullables <path> --fix` on what you touched.
2. `bootgly lint promotions <path>` and `bootgly lint methods <path>` clean, or each deviation explained.
3. `AI_AGENT=1 bootgly test` green: read `result` and `failures`, not only the totals.
4. WPI only: `bootgly project <Name> show` first; if it is running, ask the user before touching it.
   Otherwise start a throwaway instance on a free port (`PORT=18080 bootgly project <Name> start`),
   request what you changed, and stop only that instance, on the port its start banner printed
   (`bootgly project <Name> stop 18080`).

Also, for this skill:
- [ ] `bootgly projects list` shows the project with the intended interface.
- [ ] Copy or import: the greps of section 3 pass; once booted, `git rev-parse --show-toplevel` prints its directory.

## Go deeper

- https://docs.bootgly.com/manual/Bootgly/essential/projects/overview.md: every `projects`/`project` flag
- https://docs.bootgly.com/guide/getting-started/overview.md: kit layout, project anatomy, namespaces, import ·
  https://docs.bootgly.com/guide/kit/overview.md: `kit boot`, `upgrade`, `downgrade`
- From start to finish: https://docs.bootgly.com/cookbook/web/guestbook/overview.md (a WPI project) ·
  https://docs.bootgly.com/cookbook/console/monitor/overview.md (a CLI project)
