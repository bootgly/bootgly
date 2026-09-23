---
name: bootgly-review
description: "Review a change to a Bootgly project in a bootgly.kit against the kit rules and run the Definition of done before calling it finished. Reads the project's diff, runs the MUST checks (project anatomy and file names, the consumer boundary with Bootgly/Console/Web, kit operation, secrets), then the SHOULD checks (lint-backed style, naming, house style), runs the Definition of done (bootgly lint on the touched paths, AI_AGENT=1 bootgly test, the running-server check for WPI projects) and reports each finding as file:line with the rule section it breaks. Use when asked to review, audit or check a project change, a diff or a pull request in a kit; when finishing your own change, to confirm it is done; or when asked whether a project follows the Bootgly rules. Writing, running or fixing tests: bootgly-test. Building or fixing the feature itself: bootgly-build. Not for changing the framework itself."
---
<!-- Machine-managed by `bootgly kit boot`: it rewrites this skill, and removes it once no longer shipped. Do not edit. -->

# Review a Bootgly project change

Commands are written `bootgly …`. Without the global wrapper, run `php ../bootgly …` from `projects/` and `php ../../bootgly …` from `projects/<Name>/` (one more `../` per nested segment).

The rules live in `<kit>/projects/.agents/rules/`; this skill links them as `../../../.agents/rules/<Section>.md`.

You review application code under `projects/<Name>/`, never the framework.
Read the rules the change touches before judging it. Quote them; do not restate or extend them.
Non-interactive flags and environment variables live only in the kit manual, `<kit>/AGENTS.md`.
Severity follows the rule's keyword: **MUST** = blocking, **SHOULD** = fix or explain the
deviation, **RECOMMEND** = suggestion. Report first; change code only when the user asks — or
when the change under review is your own and you are finishing it.

## 1. Scope the change

From `projects/<Name>/`, read-only git only:

```sh
git rev-parse --show-toplevel          # must print this project directory
git status --porcelain                 # staged, unstaged and untracked files
git diff HEAD --stat && git diff HEAD  # what changed since the last commit
git ls-files --others --exclude-standard
```

- `rev-parse` prints anything else (the kit root, a parent repository, an error): the project is not
  its own repository yet (a `--from` copy, a shipped example, a `--no-git` project). Do not trust
  that diff — ask the user which files changed, or which commit or branch to compare against.
- No commit yet (`git rev-parse HEAD` fails — boot skips the initial commit when git has no
  identity): every file is new, review them all.
- Reviewing a branch or PR: `git diff <base>...HEAD`. Read every touched file whole, not only hunks.
- Note the interface (`projects/Bootgly.projects.php` maps each path to `CLI` and/or `WPI`) — the
  WPI checks below apply only to WPI projects.

## 2. MUST checks — blocking

Each check finds the evidence and names the rule section that decides it. Read-only commands only.

**Kit boundary** — [Workflow_pipelines.md](../../../.agents/rules/Workflow_pipelines.md) › Operating the kit,
[Architecture_principles.md](../../../.agents/rules/Architecture_principles.md) › Consumer boundary:

```sh
for m in Bootgly Console Web; do       # edits INSIDE each initialized submodule
   [ -f "<kit>/$m/autoboot.php" ] && git -C "<kit>/$m" status --porcelain
done
git -C <kit> status --porcelain -- AGENTS.md   # the kit's own AGENTS.md
cat <kit>/CLAUDE.md <kit>/projects/CLAUDE.md 2>/dev/null
```

- Any output from the loop or the `AGENTS.md` line blocks (say whether this change or an earlier one
  made it); the fix is a workaround in the project plus a drafted upstream report. A moved
  `Bootgly`, `Console` or `Web` gitlink seen only in `git -C <kit> status` is expected after install
  or `kit upgrade`: not a finding. Skip a platform folder without `autoboot.php` — `git -C` in an
  empty submodule folder reports the kit instead.
- A `CLAUDE.md` in the kit root or `projects/` holding anything but `@AGENTS.md` — the one exception,
  for clients that cannot read `AGENTS.md`. A project's own `CLAUDE.md` starts with `@../AGENTS.md`
  (one more `../` per nested segment).
- The change edits `projects/Bootgly.projects.php`, `projects/AGENTS.md` or `projects/.agents/`, makes
  git writes in the kit or a submodule, or updates the kit with `git pull` or
  `git submodule update --remote`. The `bootgly-` prefix in `projects/.agents/skills/` is reserved for
  Bootgly's skills: `kit boot` rewrites or removes each one it stamped, and a skill of the user's own
  under a name Bootgly ships keeps that Bootgly skill out — a user skill under the prefix is a finding.
- `grep -rnE '^namespace (Bootgly|Console|Web)\b' --include=*.php .` finds a shadowed framework class;
  also look for copied framework internals and a reserved first path segment (Consumer boundary).
- `composer.json` requires `bootgly/bootgly`, `bootgly/bootgly-console` or `bootgly/bootgly-web`
  (Architecture_principles.md › Dependencies), or PHPUnit/Pest (Testing_guidelines.md › The test framework).

**Project anatomy** — [Organizational_structures.md](../../../.agents/rules/Organizational_structures.md) ›
Project anatomy (a wrong name is silently ignored):

- `grep -n '^namespace' <file>` on every new class matches its path; in a `--from` copy,
  `grep -rn 'Demo\\' --include=*.php .` prints nothing.
- Every new route set, suite and case is in its list: `router/router.index.php`,
  `tests/autoboot.php` `directories:`, the suite's `autoboot.php` `tests:`.
- New files under `configs/`, `database/`, `router/`, `tests/` and `views/` carry the names the
  section fixes; `git diff --name-status HEAD -- database/migrations` shows no `R` or `D` for a
  migration that already ran.
- Method names a framework or platform mapper dispatches to (a mapped controller's actions) —
  [Naming_conventions.md](../../../.agents/rules/Naming_conventions.md) › Methods and the platform's build skill.
- `grep -rln 'Licensed under MIT' <touched files>` hits only files copied from a shipped example —
  [Coding_styles.md](../../../.agents/rules/Coding_styles.md) › File header.

**Secrets** — Workflow_pipelines.md › Secrets and commits (no commit yet: grep the files themselves):

```sh
git ls-files | grep -E '(^|/)\.env'                          # a tracked configs/**/.env file
git diff HEAD | grep -niE '^\+.*(password|passwd|secret|token|api_?key)'
```

A hit blocks when a credential is written into code or config instead of bound to an environment key.

**Never guessed** — Workflow_pipelines.md › Think before coding: find every Bootgly class, method,
named argument, enum case and config key the change uses in the pinned source under
`<kit>/Bootgly/` (and `Console/`, `Web/`).

## 3. SHOULD checks — fix or explain

- **Lint-backed** (`Coding_styles.md`, `Naming_conventions.md`): imports, `null|Type`, no
  constructor promotion, single-word method names — all from step 4.
- **By eye**, on the touched lines only:
  - a method name is one verb in base form — `lint methods` only catches multi-word names; nouns
    (`status()`), states and past forms (`loaded()`) you flag yourself; `-ing` only for a method
    that IS a loop;
  - objects and object collections start uppercase (`$Post`, `$Posts`), acronyms are uppercase
    (`$SQL`, `$URL`, `$HTML`, `$JSON`): `grep -nE '\$(sql|url|html|json|uri)\b' <file>`;
  - one space before the parentheses in declarations: `grep -nE 'function [A-Za-z_]\w*\(' <file>`;
  - interpolation over concatenation; Semantic Commenting markers only where a comment is useful;
  - no trivial getter wrapping a property; no package competing with a native component unless
    the user approved it; no alias or duplicate wrapper of a framework API;
  - a new resource file returning an entity is `<name>.<Entity>.php`;
  - every added or changed behaviour has a test case (`Testing_guidelines.md`).
- **RECOMMEND** items (dependency direction routes/commands → controllers → models, domain code
  free of `Request`/`Response`, libraries behind your own class, PHPDoc on reusable public API)
  go in the report as suggestions, never as blockers.

## 4. Run the Definition of done

From `projects/<Name>/`. `lint` takes one path per call — a file or a directory, relative to
where you stand; under an agent it prints JSON (`result`, `report[].issues[].line`/`message`).

```sh
if git rev-parse -q --verify HEAD >/dev/null; then
   files=$( { git diff --name-only --diff-filter=d HEAD -- '*.php'; \
              git ls-files --others --exclude-standard -- '*.php'; } | sort -u )
else                                                           # no commit yet: every file is new
   files=$( { git ls-files -- '*.php'; git ls-files --others --exclude-standard -- '*.php'; } | sort -u )
fi
for f in $files; do
   bootgly lint imports "$f";    bootgly lint nullables "$f"   # add --fix when finishing your own change
   bootgly lint promotions "$f"; bootgly lint methods "$f"     # check-only
done
AI_AGENT=1 bootgly test                                        # this project's suites
```

- Lint must end `passed`; every remaining `promotions`/`methods` issue needs a stated reason.
- Read the test JSON's `result` and `failures`, not only the totals. A new case that is missing
  from its list does not fail — confirm `cases.total` grew by the cases the change added.
- Hunting a failure or writing a missing case: the bootgly-test skill owns that loop. Fixing a
  finding in the feature itself: the bootgly-build skill.

**WPI projects — the running-server check:**

```sh
bootgly project <Name> show                  # running? ask the user before touching it
PORT=18080 bootgly project <Name> start      # otherwise: a throwaway instance on a free port
curl -si http://localhost:18080/<changed route> | head -20
bootgly project <Name> stop 18080            # stop only that instance — the port the banner printed
```

The scaffolded `<Name>.Project.php` reads `PORT` and daemonizes by default; if the project's boot
function no longer does, say so in the report instead of starting it on its configured port.

## 5. Report

One line per finding, blocking first, then should-fix, then suggestions:

```text
[MUST]   Models/Item.php:3 — namespace `Demo\Shop\Models` does not match the path `Shop/Models/` — Organizational_structures.md › Project anatomy
[SHOULD] Controllers/Items.php:41 — method `loadItems()` is multi-word (lint methods) — Naming_conventions.md › Methods
```

Close with the checks you ran and their results (each lint submodule, the test `result` with
case counts, the server check or why it was skipped) and a verdict: **ready** only with zero MUST
findings, green checks and every SHOULD deviation fixed or explained. Never commit, stage or push
as part of a review.

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
- [ ] Reviewing someone else's change: the lints ran without `--fix`, and you reported instead of fixing.
- [ ] The report lists every finding as file:line + rule section, with zero open MUST findings.

## Go deeper

- https://docs.bootgly.com/guide/linter/overview.md — every lint submodule, issue type and the JSON report
- https://docs.bootgly.com/testing/basic/running-tests/overview.md — scopes, targeting, agent output
- https://docs.bootgly.com/testing/about/testing/overview.md — suites, cases and registration
- https://docs.bootgly.com/guide/configuration/overview.md — `Config`, `bind()` and `.env` files
- https://docs.bootgly.com/guide/security/overview.md — security defaults to check a WPI change against
- https://docs.bootgly.com/guide/kit/overview.md — the kit, its launcher and what is read-only
- https://docs.bootgly.com/cookbook/web/guestbook/overview.md — a running-server check end to end
