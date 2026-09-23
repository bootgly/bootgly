---
name: bootgly-test
description: "Write, register, run and fix tests for a Bootgly project inside a bootgly.kit with the native Bootgly test framework (Bootgly\\ACI\\Tests): the tests/autoboot.php Suites registry, a suite autoboot.php returning a Suite with its tests: list, *.Test.php cases returning a Test that yields assert(assertion:, description:) or fluent Assertion expectations, HTTP route tests, and reading the `AI_AGENT=1 bootgly test` JSON report (result, failures) with targeted runs and --fail-fast. Use when a change in projects/<Name>/ needs a test case, when a case or suite does not run or fails, when asked to run a project's tests, or when writing an HTTP route test. Building the feature itself: bootgly-build. Reviews and the final Definition-of-done check: bootgly-review. Not for PHPUnit/Pest, not for the framework's own suites (Bootgly/, Console/, Web/), not for benchmarks."
---
<!-- Machine-managed by `bootgly kit boot`: it rewrites this skill, and removes it once no longer shipped. Do not edit. -->

# Bootgly tests in a project

Commands are written `bootgly …`. Without the global wrapper, run `php ../bootgly …` from `projects/` and `php ../../bootgly …` from `projects/<Name>/` (one more `../` per nested segment).

The rules live in `<kit>/projects/.agents/rules/`; this skill links them as `../../../.agents/rules/<Section>.md`.

This skill covers testing the application in `<kit>/projects/<Name>/`. Use the Bootgly test framework only: `bootgly test` runs Bootgly suites and nothing else, so a PHPUnit or Pest test never runs. [Testing guidelines](../../../.agents/rules/Testing_guidelines.md) and [Organizational structures](../../../.agents/rules/Organizational_structures.md) decide any conflict. This skill turns them into steps; non-interactive flags for other commands are listed in `<kit>/AGENTS.md`. The feature under test is built with the bootgly-build skill and the platform's `bootgly-build-<platform>`, which show the case each feature needs.

## 1. Know the three files

```text
projects/<Name>/tests/
├── autoboot.php                  ← returns Suites: the registry (`directories:`)
└── <suite>/
    ├── autoboot.php              ← returns Suite: its `tests:` list
    └── 1.1-<topic>.Test.php      ← returns Test: one case
```

A case or suite that is not listed never runs. If a case is listed but its file is missing, the suite fails with `Test case not found`. `projects create` scaffolds the registry plus a `tests/example/` suite with one Basic case and one Advanced case. Copy from it. Once your own suites take over, delete the example suite and its registry entry.

## 2. Register a suite

`tests/autoboot.php` must return a `Suites` registry. The runner refuses a registry that returns a `Suite`. Paths are relative to the project root:

```php
<?php

use Bootgly\ACI\Tests\Suites;


return new Suites(
   directories: [
      'tests/project/',
   ]
);
```

`tests/project/autoboot.php` lists its cases in run order, without the `.Test.php` suffix:

```php
<?php

use Bootgly\ACI\Tests\Suite;


return new Suite(
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   suiteName: 'Project',
   tests: [
      '1.1-cart',
      '1.2-expectations',
   ]
);
```

## 3. Write a case with the Basic API (default)

In the Basic API, the case is a generator that yields one native `assert()` per check. `assertion:` takes any boolean expression. `description:` becomes the failure message. The first false check ends the case. The runner enables `zend.assertions` itself, so always go through `bootgly test` and never run a case with plain `php`. Project classes autoload from their namespace, e.g. `projects/Shop/Cart.php` → `Shop\Cart`.

```php
<?php

use Bootgly\ACI\Tests\Suite\Test;
use Shop\Cart;


return new Test(
   description: 'Cart: lines and count',
   test: function () {
      $Cart = new Cart;
      $Cart->add(5)->add(5);
      yield assert(assertion: $Cart->lines === [5 => 2], description: 'add() merges quantities of the same id');
      yield assert(assertion: $Cart->count === 2, description: 'count is the number of items across the lines');
   }
);
```

Test files have no namespace. Call global functions directly, with no `use function`, and do not import `Generator`, `Closure` or `Exception`. A resource file is loaded with `include __DIR__ . '/../../router/router.index.php'`, the way the cookbook tests do it. Test domain code directly. Keep `Request`, `Response` and terminal objects out of it, as the architecture rule requires.

## 4. Write a case with the Advanced API (fluent expectations)

Wrap the generator in `Assertions`. Every `new Assertion(...)` MUST end with `->assert()`. If it doesn't, the case fails.

```php
<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Advanced API: fluent expectations',
   test: new Assertions(Case: function (): Generator
   {
      yield new Assertion(description: 'identity (default comparator: ===)')
         ->expect(21 * 2)
         ->to->be(42)
         ->assert();

      yield new Assertion(description: 'operator', fallback: 'must exceed 40')
         ->expect(42, Op::GreaterThan, 40)
         ->assert();

      yield new Assertion(description: 'throws')
         ->expect(function () {
            throw new Exception('Boom');
         })
         ->to->call()
         ->to->throw(new Exception('Boom'))
         ->assert();
   })
);
```

More building blocks are available. Check their pages (see Go deeper) instead of guessing:
- `Op::` has `Equal`, `NotEqual`, `Identical`, `NotIdentical`, `GreaterThan`, `LessThan`, `GreaterThanOrEqual` and `LessThanOrEqual`. `null` is a valid expected value.
- Chains: `->not->to->be(…)`, `->to->be(Type::String)`, `->to->be(Value::Even)`, `->to->delimit(1, 2)`, `->to->find(In::ArrayKeys, 'status')`. Classes: `Bootgly\ACI\Tests\Assertion\Auxiliaries\Type`, `…\Auxiliaries\Value` and `…\Auxiliaries\In`, each imported on its own line.
- Direct form: `->assert(actual: 'Hello, World!', expected: new Regex('/World/'))`, with `Bootgly\ACI\Tests\Assertion\Expectations\Matchers\Regex`.
- `new Test(skip: true, …)` skips visibly, `ignore: true` silently, `new Assertion(…)->skip()` one check; `new Test(Fixture: $Fixture, …)` gives the case state (`prepare()` before, `dispose()` after). Mocks, spies and fakes: see the Doubles page.

## 5. Run it

The working directory sets the scope: `projects/<Name>/` runs that project, `projects/` runs every registered project in one merged run, and the kit root runs nothing when headless. An unregistered directory is refused; register it with `projects create` or `projects import`, never by hand.

```sh
cd projects/<Name>
AI_AGENT=1 bootgly test                  # every suite of this project → one JSON line
AI_AGENT=1 bootgly test 1                # suite 1 (1-based, order of `directories:`)
AI_AGENT=1 bootgly test 1 2 --fail-fast  # case 2 of suite 1 (order of `tests:`); stop at first red
bootgly test --help                      # every option (--view=list|heatmap, --coverage…)
```

When `AI_AGENT` has any non-empty value (`0` counts), the runner prints one JSON object on the last line of stdout. Parse only that line. If stdout is empty, no document was produced, and the reason is on stderr. A failed run exits non-zero. The document looks like this (illustrative):

```json
{"result":"failed","agent":"1","suites":{"total":1,"failed":1,"skipped":0,"passed":0},"cases":{"total":2,"failed":1,"skipped":0,"passed":1},"assertions":2,"duration_ms":12.3,"failures":[{"suite":"Project","case":1,"file":"1.1-cart","message":"count is the number of items across the lines","elapsed_ms":0.4}]}
```

Read `result` and `failures[]` (`suite` is the `suiteName`, `case` is the 1-based index, plus `file` and `message`). Do not go by the totals alone. `suites.total` counts every registered suite, so suites a targeted run skipped appear as `skipped`. Without `--fail-fast`, a run executes everything and lists every failure. A human run ends with `[test] PASSED — 1 suites: 0 failed, 0 skipped, 1 passed`. The loop: reproduce the failure with `bootgly test <suite> <case> --fail-fast`, fix it, re-run that case, then finish with a full project run.

## 6. Route (HTTP) tests, when they are worth it

A route case returns `Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test`. `request:` returns a raw HTTP request string (or pass `requests: [...]` for several; exactly one of the two is required). `response:` is the server-side handler; to exercise the real routes it can `yield from` your `router/routes/<Name>.routes.php` closure. `test:` receives the raw response and returns `true` or a failure message.

```php
<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


return new Test(
   description: 'GET / answers 200',
   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router): Generator {
      $routes = require __DIR__ . '/../../router/routes/Welcome.routes.php';
      yield from $routes($Request, $Response, $Router);
   },
   test: function (string $response) {
      return preg_match('#\AHTTP/1\.\d 200#', $response) === 1 ?: 'GET / did not answer 200';
   }
);
```

The suite holding these cases must boot the server in test mode inside its `autoBoot:` closure. The sequence is `HTTP_Server_CLI::pretest($Suite, specs: __DIR__)`, then `new HTTP_Server_CLI(Mode: Modes::Test)`, then `configure(new ServerConfigs(host: …, port: …, workers: 1))`, `start()` and `Commands->command('test')`, with teardown in a `finally` block. Do not write that from memory. Copy a working reference — the platform's build skill names a project-shaped one; the framework's own is `<kit>/Bootgly/Bootgly/WPI/Nodes/HTTP_Server_CLI/tests/E2E/autoboot.php` — give it a port of its own and isolate its database. If that is more than the change needs, unit-test the logic behind the route and check the route itself live (step 4 below).

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
- [ ] Every new or changed behaviour has a case listed in its suite's `tests:`, the suite is in `directories:`, and `cases.total` grew by the cases you added.
- [ ] The last run is a full project run (not a targeted one) reporting `"result":"passed"`.

## Go deeper

- Suites, cases, running (scope, indexes, the JSON document, `--fail-fast`, coverage): https://docs.bootgly.com/testing/about/testing/overview.md · https://docs.bootgly.com/testing/basic/running-tests/overview.md
- Basic and Advanced APIs and comparators: https://docs.bootgly.com/testing/core/assertions/overview.md (the pages below it cover modifiers, behaviors, finders, matchers, throwers and waiters) · skip/ignore, fixtures, doubles: https://docs.bootgly.com/testing/basic/skip-ignore/overview.md, https://docs.bootgly.com/testing/deep/fixtures/overview.md, https://docs.bootgly.com/testing/deep/doubles/overview.md
- `Modes::Test`: https://docs.bootgly.com/manual/WPI/HTTP/HTTP_Server_CLI/overview.md · E2E-verified "Test it" steps: https://docs.bootgly.com/cookbook/web/shop/overview.md, https://docs.bootgly.com/cookbook/console/monitor/overview.md
