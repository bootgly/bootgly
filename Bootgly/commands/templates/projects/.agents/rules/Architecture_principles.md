# Architecture principles

Bootgly serves two platforms from one core through two interfaces: **CLI** (Command Line Interface)
gives rise to the **Console** platform — console applications — and **WPI** (Web Programming
Interface) to the **Web** platform — web (HTTP) servers. A project picks its interface with
`projects create <Name> --interfaces=CLI|WPI`; the optional `Console/` and `Web/` platform packages add
opinionated extras on top.

## Consumer boundary

- **MUST** — Use the framework through its public API only. Its layers are yours to call, in any
  combination: `Bootgly\ABI` (templates, file I/O, cache), `Bootgly\ACI` (tests, logs, events),
  `Bootgly\ADI` (SQL, ORM, migrations), `Bootgly\API` (projects, configuration), `Bootgly\CLI`
  (commands, terminal UI), `Bootgly\WPI` (HTTP server, router).
- **MUST** — Never modify `Bootgly/`, `Console/` or `Web/`: they are pinned, read-only submodules.
  Never copy framework internals into a project, and never shadow a `Bootgly\`, `Console\` or `Web\`
  class. All code, configuration and notes live under `projects/<Name>/`.
- **MUST** — A project path never starts with a reserved segment: `Bootgly`, `Console`, `Web`, `Data`,
  `Graphics`, `Embedded`, `Mobile`.
- **MUST** — A project that uses a platform package needs it initialized — a fresh kit sets both up;
  otherwise pass `--platform=console|web` to `projects create`: `Console\` and `Web\` classes do not
  exist in a kit booted without them.
- **RECOMMEND** — Inside the project, dependencies flow one way: routes and commands → controllers →
  models and services. Domain code never takes `Request`, `Response` or terminal objects, so the same
  code runs from a route, a command, `schedule.php` and a test.

## One way per concern

- **MUST** — Where the kit's tooling depends on it, use the Bootgly component and nothing else: the
  test framework, the project autoloader, the HTTP server and its router, the `<Name>.Project.php` entry.
- **SHOULD** — For every other concern Bootgly covers (configuration, logging, events, validation,
  templates, databases and migrations, queues, scheduling, cache, mail), use the native component.
  Ask the user before adding a package that competes with one.
- **SHOULD** — Keep one way per concern in your own code: no aliases, no duplicate wrappers of a
  framework API.

## Dependencies

- **MUST** — Composer is per project (`projects/<Name>/composer.json` + `vendor/`) and is yours for
  domain dependencies. Never `composer require` the framework: the kit delivers it as the pinned
  `Bootgly/` submodule, and a vendored copy is dead weight that misleads anyone reading `vendor/`.
- **RECOMMEND** — Keep each third-party library behind a class of your own, so the library never
  shapes the application.

## Efficiency

- **SHOULD** — No trivial wrappers: a method that only returns a property should not exist. Expose
  stored state as a property.
- **RECOMMEND** — Expose state in this order: an asymmetric-visibility property
  (`public private(set)`), then asymmetric visibility with a property hook (computed, aliased or lazy
  state), then a method — only when real behaviour exists.
- **RECOMMEND** — Prefer the simplest, lowest-overhead design at equal clarity. Never trade clarity,
  security or a framework contract for speed.
