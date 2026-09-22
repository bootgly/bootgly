# Contributing to Bootgly

Thanks for helping build Bootgly. This guide covers what a change to the framework core needs
in order to be merged. The platform repositories (`bootgly-console`, `bootgly-web`) and the
starter kit (`bootgly.kit`) follow the same rules; their own specifics live in their READMEs.

## Before you start

- **Bugs:** open an issue with the bug template. A reproduction that runs in the native test
  runner (see [Tests](#tests)) is the fastest path to a fix.
- **Features and larger changes:** open an issue first and describe the problem you want to
  solve. Bootgly keeps one canonical way to do each thing, so a feature that duplicates an
  existing capability will be declined even if the code is good.
- **Security issues:** never open a public issue. Follow the [security policy](SECURITY.md).

## Setup

```bash
git clone https://github.com/bootgly/bootgly.git && cd bootgly
composer install        # dev tooling only: the framework core has no third-party dependencies
bootgly test 2          # run one suite — `bootgly test` alone runs every suite (slow)
```

Requirements: PHP 8.4+ (`php-cli`, `php-openssl`, `php-readline`; `php-mbstring` recommended)
on Linux or WSL2. The servers need `pcntl`/`posix`; on macOS and Windows only the CLI tooling
runs natively.

## The rules a change must follow

These are enforced in review; most of them are also checked by tooling.

- **Layers flow one way:** `ABI → ACI → ADI → API → CLI → WPI`. A layer may depend only on
  itself and on the layers before it — never on a later one, never skipping across.
- **No third-party packages in the core.** Essential features are native Bootgly components.
- **One way to do each thing.** Do not add an alias or a second pattern for an existing
  concern; deprecate and migrate instead.
- **Naming:** methods are single-word verbs in the infinitive (`render()`, `boot()`,
  `check()`), or the `-ing` form only for a method that *is* a continuous loop
  (`reading()`, `writing()`). Variables and properties holding objects or collections of
  objects start uppercase (`$Request`, `$Requests`); acronyms are always uppercase
  (`$SQL`, `$URL`).
- **Style:** `null|Type` instead of `?Type`; no constructor property promotion; a space
  before the parentheses of every function and method declaration (`function boot ()`);
  string interpolation instead of concatenation; explicit `use function` / `use const`
  imports in namespaced files, ordered `const → function → class`, alphabetically.
- **Comments:** [Semantic Commenting Code](https://github.com/bootgly/semantic_commenting_code)
  — `// ?` guard, `// !` setup, `// @` action, `// :` return, `// *` property section,
  `// #` subsection. Properties are grouped as `// * Config`, `// * Data`, `// * Metadata`.
- **PHPDoc** on every public method and property. **The license header** on every framework PHP
  file — never in the files a scaffold writes into a user's project.

## Tests

Bootgly uses its own test runner — no third-party test framework.

- A test is a file returning `new Test(...)` with a `description` and a `test` closure that
  `yield assert(assertion: ..., description: ...)`s. It lives in the `tests/` directory of the
  component it covers and is registered in that directory's `autoboot.php`.
- Run a suite by its index, or a single case: `bootgly test <suite> [<case>]`. Add
  `--fail-fast` to stop at the first failing case. `AI_AGENT=1 bootgly test <suite>` prints a
  JSON summary.
- Every public method needs tests. A bug fix ships with the test that reproduces the bug —
  make sure it fails on the code before the fix, or it proves nothing.

## Gates before opening a pull request

```bash
vendor/bin/phpstan analyse -c @/phpstan.neon   # must print: [OK] No errors
bootgly lint imports                           # import order and missing imports
bootgly test <suite>                           # every suite your change touches
```

## Documentation

A feature is not complete without its documentation. Docs live in the
[bootgly_docs](https://github.com/bootgly/bootgly_docs) repository, always in **both**
locales (`en-US` and `pt-BR`), flow-first with a Reference section at the end. Behavior
changes update the pages that describe them; examples must run against the current API.

## Commits and pull requests

- Commits follow [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/):
  `<type>(<scope>): <description>` — `feat`, `fix`, `refactor`, `perf`, `test`, `docs`,
  `style`, `build`, `ci`, `chore`, `revert`; imperative mood, lowercase, no trailing period.
  A breaking change adds `!` after the type/scope or a `BREAKING CHANGE:` footer — and,
  after `1.0.0`, targets the next major (see the
  [versioning guide](https://docs.bootgly.com/guide/versioning/)).
- Keep pull requests focused: one concern per PR. Fill in the pull request template; the
  checklist there is the review checklist.
- By contributing you agree that your contribution is licensed under the
  [MIT license](../LICENSE) and that you follow the [Code of Conduct](CODE_OF_CONDUCT.md).
