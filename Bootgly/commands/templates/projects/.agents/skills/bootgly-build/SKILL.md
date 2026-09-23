---
name: bootgly-build
description: "Build features inside an existing Bootgly project in a bootgly.kit — the base every platform's build skill extends. Covers the ground to check first, picking the project's platform build skill (bootgly-build-<platform>, shipped by each platform package set up in the kit), what every feature shares (class namespaces, fixed resource names, one-way dependencies, verifiable steps, tests, per-project Composer), SQL databases for any platform (configs/database, migrations, seeders, ORM models, queries, transactions) and the Definition of done. Use when adding or changing a feature, table, migration, seeder, model or query in a projects/<Name>/ project, before opening the platform's own build skill, or when a migrate or seed command fails. Creating, copying, importing or running a project: bootgly-project. Configuration and secrets: bootgly-config. Tests: bootgly-test. Reviews: bootgly-review. Not for changing the framework itself."
---
<!-- Machine-managed by `bootgly kit boot`: it rewrites this skill, and removes it once no longer shipped. Do not edit. -->

# Bootgly build: the base of every platform

Commands are written `bootgly …`. Without the global wrapper, run `php ../bootgly …` from `projects/` and `php ../../bootgly …` from `projects/<Name>/` (one more `../` per nested segment).

The rules live in `<kit>/projects/.agents/rules/`; this skill links them as `../../../.agents/rules/<Section>.md`.

This skill builds features inside an existing project under `projects/<Name>/`; paths are relative to it.
Obey the rules instead of restating them: [Architecture](../../../.agents/rules/Architecture_principles.md) ·
[Coding styles](../../../.agents/rules/Coding_styles.md) · [Naming](../../../.agents/rules/Naming_conventions.md) ·
[Organization](../../../.agents/rules/Organizational_structures.md) · [Testing](../../../.agents/rules/Testing_guidelines.md) ·
[Workflow](../../../.agents/rules/Workflow_pipelines.md). The non-interactive flags live only in `<kit>/AGENTS.md`.

## 1. Check the ground

- `bootgly projects list` shows the project and its interface. No project yet, or a shipped example to
  build on (`--from`, its namespace rename, `project <Name> boot`): the bootgly-project skill comes first.
- Search before inventing: the shipped examples in `projects/`, the documentation, then the pinned
  source under `<kit>/Bootgly/` and the platform packages beside it. Never guess a signature, named
  argument, enum case or configuration key; when the docs and the pinned source disagree, the source wins.

## 2. Open the platform's build skill

A project is built on a Bootgly platform, and its interface names it: **CLI** gives rise to the
**Console** platform, **WPI** to the **Web** platform. Each platform package set up in the kit ships its
own build skill, `bootgly-build-<platform>`, laid down next to this one by `kit boot`: its shells, their
classes, the shipped examples to copy from and the checks its features add. It extends this skill — §1,
§3 and the Definition of done below still apply.

1. Find the project's interface (`bootgly projects list`) and its platform: CLI → Console, WPI → Web.
2. List the skills in `projects/.agents/skills/` and open that platform's `bootgly-build-<platform>`.
   A project with both interfaces reads both. A request that fits either platform (a dashboard: a
   terminal screen or a web page?) — ask the user.
3. No build skill for the platform: its package is not set up in the kit (the bootgly-project skill,
   §1), or it ships none yet — work from its documentation and its pinned source. A project on the bare
   interface, without a platform shell, needs only this skill: the framework's `Bootgly\CLI` and
   `Bootgly\WPI` classes.
4. Add [references/database.md](references/database.md) whenever the feature stores data: config,
   migrations, seeders, ORM models, queries, transactions. It is part of the framework, not of a
   platform, and serves every one. Other configuration and secrets: the bootgly-config skill.

Read the whole platform skill before you write: it ends with the checks its features add.

## 3. What every feature shares

- **Classes** live in uppercase directories whose namespace is the project path
  (`projects/Shop/Models/Item.php` → `namespace Shop\Models;`). Resource files keep the names the
  framework reads (`configs/`, `database/`, `router/`, `tests/`, `views/`): a wrong name is silently ignored.
- **Direction**: routes, screens and commands → controllers → models and services. Domain code never
  takes `Request`, `Response` or terminal objects, so a route, a command, `schedule.php` and a test run
  the same code.
- **Steps** you can verify one by one: config → migration → model → routes or screens → tests.
- **Tests**: every behaviour you add or change gets a case in a registered suite. The platform skill and
  the database reference show the case their feature needs; the bootgly-test skill owns the registry,
  the run and the failure loop.
- **Composer** is per project (bootgly-project §8): ask the user before adding a package for something
  Bootgly already provides (HTTP server, tests, config, logs, templates, SQL).

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
- [ ] The checks at the end of the platform skill and of the database reference, when used, pass.
- [ ] Every new or changed behaviour has a case listed in its suite (bootgly-test).

## Go deeper

- https://docs.bootgly.com/guide/getting-started/overview.md: kit layout, project anatomy, namespaces
- https://docs.bootgly.com/llms.txt: the index of every documentation page
- The platform skill and the database reference end with the guides, manuals and cookbooks of their subject.
