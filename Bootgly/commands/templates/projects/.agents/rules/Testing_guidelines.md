# Testing guidelines

## The test framework

- **MUST** — Tests use the Bootgly test framework only: `bootgly test` runs Bootgly suites and nothing
  else, so a PHPUnit or Pest test never runs. The scaffolded `tests/example/` suite is the template —
  and a new case or suite runs only once it is listed (see *Organizational structures*).
- **SHOULD** — Every behaviour you add or change gets a test case: console commands, public model and
  service methods, controller logic.
- **RECOMMEND** — Route (HTTP) tests return a `Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test`
  (`request` / `response` / `test`) and need a suite that boots the server in test mode — the shipped
  `Web/projects/Demo/Auth/tests/E2E/` suite (with `Web/` initialized) is a working reference. When that
  is more than the change needs, test the logic behind the route and check the route itself with the
  running-server step below.

## Running tests

- **MUST** — The scope follows the directory you run from: inside `projects/<Name>/` that project,
  inside `projects/` every registered project. At the kit root a headless run executes nothing.
  Framework suites run from anywhere with `--bootgly`, `--console` or `--web`.
- **SHOULD** — Run as an agent — `AI_AGENT=1 php ../../bootgly test` from a project — for a JSON report.
  Any non-empty value, `0` included, means agent. Read `result` and `failures`, not only the totals.
- **SHOULD** — Target what you are fixing: `bootgly test <suite> <case>` (1-based indexes), with
  `--fail-fast` while hunting; do a full project run before you call the work done.
- **RECOMMEND** — PHPStan is optional: if the project adds `phpstan/phpstan` as its own dev dependency,
  keep it clean.

## Definition of done

- **SHOULD** — Before you say a change is finished, from the project directory:
  1. `bootgly lint imports <path> --fix` and `bootgly lint nullables <path> --fix` on what you touched;
  2. `bootgly lint promotions <path>` and `bootgly lint methods <path>` clean, or each deviation explained;
  3. `AI_AGENT=1 bootgly test` green;
  4. for a WPI project: `project <Name> show` first — if it is running, ask the user before touching it;
     otherwise start a throwaway instance on a free port (`PORT=18080 bootgly project <Name> start`),
     request what you changed, and stop only that instance — the port its start banner printed
     (`project <Name> stop 18080`).
