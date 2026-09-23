---
name: bootgly-config
description: "Configure a Bootgly project in a bootgly.kit: a configs/<scope>/<scope>.Config.php scope returning a Config tree, bind() to environment keys with defaults, required secrets and Types casts, the scope-local .env and .env.<BOOTGLY_ENV> files (never committed), reading values with BOOTGLY_PROJECT->Configs->get('<scope>') and object navigation, allow()/lock() for .env keys, and a test that loads a scope. Use when adding a setting, a feature flag, a credential or an API key to a projects/<Name>/ project, when a value must differ per environment (development, production), when a config value reads null or a scope is not found, when a required value is reported missing, or when asked where a secret goes. The keys of the database scope: bootgly-build (references/database.md). Not for the framework's own configs."
---
<!-- Machine-managed by `bootgly kit boot`: it rewrites this skill, and removes it once no longer shipped. Do not edit. -->

# Bootgly config: settings and secrets

Commands are written `bootgly …`. Without the global wrapper, run `php ../bootgly …` from `projects/` and `php ../../bootgly …` from `projects/<Name>/` (one more `../` per nested segment).

The rules live in `<kit>/projects/.agents/rules/`; this skill links them as `../../../.agents/rules/<Section>.md`.

This skill configures a project under `projects/<Name>/`; paths are relative to it. Two rules decide it:
[Workflow_pipelines.md](../../../.agents/rules/Workflow_pipelines.md) › Secrets and commits (never hard-code a
credential, never commit a `configs/**/.env` file) and
[Organizational_structures.md](../../../.agents/rules/Organizational_structures.md) › Project anatomy (the fixed
`configs/<scope>/<scope>.Config.php` name). Pinned source: `<kit>/Bootgly/Bootgly/API/Environment/Configs/`.

## 1. One directory per scope

```text
configs/
└── payments/
    ├── payments.Config.php   ← returns new Config(scope: 'payments') — the three names must match
    ├── .env                  ← local values; git-ignored by the scaffold (configs/**/.env)
    └── .env.production       ← read after .env when BOOTGLY_ENV=production (configs/**/.env.* is ignored too)
```

- A scope name is one segment of `A-Z a-z 0-9 _ -`. A directory, file or `scope:` that does not match is
  never registered: `get('payments')` answers `null`, silently.
- Some scopes are read by the framework under a fixed name: `database` (the SQL connection — its keys are
  in bootgly-build's `references/database.md`) and `kv`. Every other scope is yours to name.
- A `.Config.php` file is trusted PHP that runs when the scope loads: never write one from user input.

## 2. Declare the tree

```php
<?php

use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\Config\Types;


return new Config(scope: 'payments')
   ->Provider->bind(key: 'PAYMENTS_PROVIDER', default: 'sandbox')
   ->Timeout->bind(key: 'PAYMENTS_TIMEOUT', default: 10, cast: Types::Integer)
   ->Live->bind(key: 'PAYMENTS_LIVE', default: false, cast: Types::Boolean)
   ->Credentials
      ->Key->bind(key: 'PAYMENTS_KEY', required: true)
      ->up();
```

- Navigating a name (`->Timeout`) creates that node; `bind()` sets its value and returns the parent, so
  siblings chain; `->up()` closes a group. The loader takes the root, wherever the chain ends.
- `bind(key: '', default: …)` is a constant: no environment key reads it.
- `cast:` parses the value strictly: `Types::Integer`, `Types::Float`, `Types::Boolean` (`true false 1 0
  yes no on off`) or `Types::String`. An invalid value throws; nothing is coerced.
- `required: true` is for secrets: no default, and a missing or empty value throws
  `Required config value is missing: PAYMENTS_KEY` the first time the scope loads.

## 3. Where a value comes from

For each `bind()`, the first that has the key wins:

1. the process environment (`getenv()`): `PAYMENTS_TIMEOUT=30 bootgly project <Name> start`, `docker run -e`,
   a service manager's environment — deployments put secrets here;
2. the scope's own `.env`, then `.env.<BOOTGLY_ENV>` over it (`BOOTGLY_ENV=production` reads
   `.env.production`) — these values stay local to the scope and never enter the process environment;
3. the `default:`.

```ini
# configs/payments/.env — never committed
PAYMENTS_KEY=sk_test_123
PAYMENTS_TIMEOUT=20
```

- `.env` keys are `[A-Z_][A-Z0-9_]*`: one invalid key and the whole scope fails to load (`get()` → `null`).
- Before you finish, `git check-ignore -v configs/payments/.env` must name the project's `.gitignore`. The
  scaffold ignores `configs/**/.env` and `configs/**/.env.*` (an `.env.example` included): list the keys
  a scope needs in its `.Config.php` — every `bind()` names one — and tell the user which to set.

## 4. Read it

```php
// ! <Name>.Project.php, in `boot`: read once — failing loudly — and hand plain values on (`use Shop\Gateway;` on top)
$Config = BOOTGLY_PROJECT->Configs?->get('payments') ?? throw new RuntimeException('configs/payments did not load');
$Gateway = new Gateway(timeout: (int) $Config->Timeout->get());
```

- `BOOTGLY_PROJECT->Configs` is `null` when the project has no `configs/` directory; `get()` loads a
  scope on first use and answers `null` for one that does not exist or failed to load — never let a `?->`
  chain turn that into a silent `0` or `''`.
- `get()` takes a scope name only — `get('payments.Timeout')` is always `null`. Go down the tree with
  properties and read a node with `->get()`. Navigating a name that was never declared creates an empty
  node that reads `null`: test with `$Config->check('Timeout')` first when the key is optional.
- Domain classes take values, never `BOOTGLY_PROJECT`: the same class then runs in a route, a command, a
  schedule and a test. A namespaced file that reads it imports it: `use const BOOTGLY_PROJECT;`.
- In `boot`, before the first `get()`, `BOOTGLY_PROJECT->Configs?->allow('payments', ['PAYMENTS_TIMEOUT',
  'PAYMENTS_KEY'])` limits the scope's `.env` files to those keys, and `->lock('payments', ['PAYMENTS_KEY'])`
  reserves keys for the process environment. Either way a `.env` key outside the list, or a locked one,
  fails the WHOLE scope: `get()` answers `null`.

## 5. Test it

List a case in a registered suite (the bootgly-test skill runs the loop). `bootgly test` does not boot the
project, so the case loads the scope itself and pins the keys it reads — a developer's `.env` must not
change the result:

```php
<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects\Configs;


return new Test(
   description: 'Config: the payments scope loads and casts its values',
   test: function () {
      // ! The process environment wins over any local .env
      putenv('PAYMENTS_KEY=sk_test');
      putenv('PAYMENTS_TIMEOUT=30');
      try {
         $Payments = new Configs(__DIR__ . '/../../configs/')->get('payments');
      }
      finally {
         putenv('PAYMENTS_KEY');
         putenv('PAYMENTS_TIMEOUT');
      }
      yield assert(assertion: $Payments !== null, description: 'the payments scope is registered');
      yield assert(assertion: $Payments?->Timeout->get() === 30, description: 'the timeout is cast to an integer');
   }
);
```

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
- [ ] `git status --porcelain` in the project lists no `.env` file; `git check-ignore` names the ones you wrote.
- [ ] No credential appears in code, a `.Config.php` default or a test; secrets are `required: true`.
- [ ] You told the user every key they must set, and where (process environment or the scope's `.env`).

## Go deeper

- https://docs.bootgly.com/guide/configuration/overview.md: resolution order, `allow()`/`lock()`, required values,
  casts, project overlay, the security model, `Environment::put()`
- https://docs.bootgly.com/guide/database-dbal/overview.md: the `database` scope
