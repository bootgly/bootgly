# Bootgly Framework - AI Coding Agent Instructions

## Architecture Overview

**I2P (Interface-to-Platform)**: Bootgly serves CLI and Web from the same core via two interfaces:
- **CLI** → Console platform (`Bootgly\CLI`)
- **WPI** (Web Programming Interface) → Web platform (`Bootgly\WPI`)

**One-way policy**: Bootgly enforces a single canonical way to do things — one HTTP server implementation (CLI), one config location/schema, one autoloader, one test framework. This avoids confusion, reduces maintenance burden, and keeps AI-generated code precise and consistent. When implementing anything, look for the existing pattern — there should be exactly one.

**Minimum dependency policy**: No third-party packages in the framework core. All features are built-in and fully integrated. This maximizes integration, reduces supply-chain risk, and keeps the learning curve within a single codebase.

**Layered modules** (in `Bootgly/`, strict separation — no cross-layer skipping):

| Module | Full Name | Purpose |
|--------|-----------|---------|
| `ABI/` | Abstract Bootable Interface | Low-level: FS I/O, Data types, Templates, Debugging |
| `ACI/` | Abstract Common Interface | Cross-cutting: Tests, Events, Logs |
| `ADI/` | Abstract Data Interface | Data structures (Table, etc.) |
| `API/` | Application Programming Interface | Projects, Components, Environments, Server |
| `CLI/` | Command Line Interface | Terminal, Commands, Scripts, UI components |
| `WPI/` | Web Programming Interface | HTTP/TCP server, Routing, Connections |

**Dual-Root Architecture**: Autoloader checks `BOOTGLY_WORKING_DIR` (consumer) first, then `BOOTGLY_ROOT_DIR` (framework). A consumer class with the same namespace replaces the framework class — this is how you extend or override core components without modifying framework files.

**HTTP Server CLI only**: The sole HTTP server implementation is `Bootgly\WPI\Nodes\HTTP_Server_CLI`. There is no multi-SAPI support (no Apache/Nginx bridge). All WPI imports use `Nodes\HTTP_Server_CLI\*` (e.g., `Request`, `Response`).

**Special directories**: 
- `@/` holds operational configs (phpstan, docker, php.ini) — mostly gitignored except `@/__php__/`, `@/__docker__/`, `@/phpstan.neon`.
- `&/` means "excluded" — gitignored (`**/&`), used for temporary files, drafts, WIP code, trash. Never reference `&/` contents in production code. Per-module `&/` dirs (e.g., `Bootgly/WPI/Nodes/&/`) hold incomplete drafts.
- `vs/` means "versus" — also gitignored (`**/vs`), used for comparison/alternative implementations.

**Resource directories** (lowercase — source code lives at root-level uppercase dirs like `Bootgly/`, never inside `src/`):
- `projects/` — user apps/APIs
- `public/` — web files
- `scripts/` — CLI scripts
- `tests/` — test suites, co-located with the module being tested
- `workdata/` — cache, logs, temp

**Repository naming**:
- Project: `bootgly` (no separator)
- Bootable: `bootgly-web` (dash)
- Template: `bootgly.web` (dot)
- Extension: `bootgly_docs` (underscore)

**Bootstrap**: `autoboot.php` → `Bootgly/autoload.php` (loads ABI→ACI→ADI→API→CLI→WPI) → `CLI->autoboot()` + `WPI->autoboot()` → project boot files in `projects/Bootgly/`.

## Code Style (deviates from PSR-12)

**Space before parentheses** in function/method declarations:
```php
public function autoboot (): void    // ✅ correct
public function autoboot(): void     // ❌ wrong
```

**No `declare(strict_types=1)`** — the framework intentionally omits it.

**[Semantic Commenting Code](https://github.com/bootgly/semantic_commenting_code)** — a standard (originated in Bootgly) for structuring code comments to improve AI autocomplete, code predictability, and quick readability. Every method body is organized with these markers:
```php
public function autoboot (): void
{
   // ?                     ← guard / precondition / early return
   if (self::$booted)
      throw new Exception("Already booted.");

   // * Metadata            ← property-section header (also: // * Config, // * Data)
   self::$booted = true;

   // !                     ← initialization / setup
   [$CLI, $WPI] = require(__DIR__ . '/Bootgly/autoload.php');

   // @                     ← main execution / action
   $CLI->autoboot();
   $WPI->autoboot();

   // :                     ← return (// ?: for conditional return)
}
```

Full marker reference:
- `// ?` — guard / precondition / early return
- `// !` — initialization / setup
- `// @` — main execution / action
- `// :` — return value (`// ?:` for conditional return)
- `// *` — property-section header (`// * Config`, `// * Data`, `// * Metadata`)
- `// #` — subsection header (`// # Mode`, `// # Socket`)
- `// ---` — logical separator between phases within a method

**Property organization** — every class groups properties in this order:
```php
// * Config    ← public, injectable
// * Data      ← public|protected, injectable
// * Metadata  ← protected|private, not injectable
```

**File header** — every PHP file starts with the standard Bootgly license block.

## PHP 8.4 Features (actively used)

**Property hooks** (79+ usages) — lazy initialization with `isSet()`:
```php
public protected(set) bool $exists {
   get {
      if (isSet($this->exists) === false) {
         $this->exists = is_file($this->file);
      }
      return $this->exists;
   }
}
```
Virtual/computed properties: `public null|string $format { get => $this->MIME?->format; }`

**Asymmetric visibility** (44+ usages): `public protected(set)`, `public private(set)`.

**Other**: backed enums, `match` expressions (100+), typed constants, `#[AllowDynamicProperties]`, first-class callable syntax.

## Naming Conventions

| Symbol | Convention | Examples |
|--------|-----------|---------|
| Classes | Substantive nouns; abstract/collections plural | `Logger`, `Logs`, `Suite` |
| Interfaces | `-ing` gerund suffix | `Asserting`, `Logging` |
| Traits | `-able` or `-ed` suffix | `Loggable`, `Escapeable` |
| Enums | Plural nouns | `Modes`, `Environments` |
| Methods | Single-word verbs; avoid camelCase combos | `render`, `boot`, `reset` |
| Properties | Substantive nouns | `$host`, `$workers` |
| Wrapper types | `__` prefix for PHP primitive wrappers | `__String`, `__Array` |

**Method rules**: No pseudo-property accessors — use actual property hooks. Keep leading-verb rule (`renderRoute`, not `routeRender`). Single responsibility per method. Never repeat the class context in the method name — e.g., `Cookies->build()` not `Cookies->buildCookies()` (the class already provides the noun). Prefer single-word method names when possible (`render()`, not `renderRoute()`), especially for core actions in the context of the class.

## Imports

- One `use` per line — never grouped multi-line `use`
- Always import explicitly, even within the same namespace
- Import global functions: `use function fclose;` (not `\fclose()`)
- Import global classes: `use ArrayIterator;` (not `\ArrayIterator`)

## Autoloading

- `composer.json` only loads `autoboot.php` — **never** add PSR-4 rules
- Class `Foo\Bar\Baz` maps to file `Foo/Bar/Baz.php` via the custom autoloader
- Singleton constants: `const Bootgly = new Bootgly;`, `const CLI = new CLI;`

## Registry Pattern (`@.php` files)

Bootstrap files for dynamic discovery:
- `projects/@.php` — project definitions and default project
- `scripts/@.php` — whitelisted scripts (`built-in`, `imported`, `user`)
- `tests/@.php` — test suite directories
- `**/commands/@.php` — CLI command instances

Unregistered scripts fail validation. Follow this pattern when adding new registries.

## CLI Development

- Extend `Bootgly\CLI\Command`, register in `projects/Bootgly/CLI/commands/@.php`
- Implement `run (array $arguments, array $options): bool`
- Output access: `use const Bootgly\CLI;` then `CLI->Terminal->Output`
- Terminal markup: `@#green:text@;` for colors, `@.;` for newline — use `$Output->render()` for markup, `$Output->write()` for plain text, `$Output->writing()` for animated char-by-char output
- UI components: `Alert`, `Fieldset`, `Header`, `Menu`, `Progress`, `Table` in `Bootgly\CLI\Terminal\components/`
- Run: `php bootgly <command>` or `bootgly <command>` (if globally installed)
- Global setup: `sudo php bootgly setup`

## WPI Development

- HTTP Server (the only implementation): `Bootgly\WPI\Nodes\HTTP_Server_CLI` — `configure()` then `start()`
- Use `Bootgly\API\Environments` enum (not strings) for environment modes

**Router** — use `yield` for multi-route definitions (Generator-based):
```php
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

yield $Router->route('/', function (Request $Request, Response $Response) {
   return $Response(body: 'Hello World!');
}, GET);
yield $Router->route('/user/:id', function ($Request, $Response) {
   return $Response(body: 'User: ' . $this->Params->id);
}, GET);
yield $Router->route('/*', function ($Request, $Response) {
   return $Response(code: 404, body: 'Not Found');
});
```
Nested routes: `$Router->route('/profile/:*', fn () => yield $Router->route('user/:id', ...))`. Params: `$this->Params->id` inside Closures (`$Route = $this`). Route methods: `GET`, `POST`, or `[GET, POST]`.

**Request API** (property access, not methods): `$Request->method`, `$Request->URI`, `$Request->URL`, `$Request->URN`, `$Request->query`, `$Request->queries`, `$Request->host`, `$Request->domain`, `$Request->subdomain`, `$Request->input`, `$Request->post`, `$Request->files`, `$Request->cookies`, `$Request->Header->get('X-Header')`. Content negotiation: `$Request->negotiate(Request::ACCEPTS_LANGUAGES)`. Caching: `$Request->fresh` / `$Request->stale`. Auth: `$Request->authenticate()`.

**Response API**: `$Response(code, headers, body)` (invocable), `$Response->send()`, `$Response->render('view', $data)`, `$Response->upload('/path/file.pdf')`, `$Response->redirect($URI, 301)`, `$Response->authenticate(new Authentication\Basic(...))`, `$Response->end()`, `$Response->JSON->send([...])`.

## Testing

**Custom framework** (`Bootgly\ACI\Tests`) — not PHPUnit. Progressive API (basic → advanced).

**Test directory**: `tests/` co-located with the module, containing `@.php` (suite bootstrap) and `*.test.php` files.

**Suite registration** (`tests/@.php`):
```php
return [
   'autoBoot' => __DIR__,
   'autoInstance' => true,
   'autoReport' => true,
   'autoSummarize' => true,
   'exitOnFailure' => true,
   'suiteName' => __NAMESPACE__,
   'tests' => ['1.1-test_name', '2.1-other_test']  // omit .test.php suffix
];
```

**API Level 1** — return bool:
```php
return [
   'describe' => 'It should work',
   'test' => function (): bool { return true === true; }
];
```

**API Level 2** — Generator yielding bools (multiple assertions per case):
```php
'test' => function (): Generator {
   yield true === true;
   Assertion::$description = 'Second check';
   yield $value !== false;
}
```

**API Level 3** — fluent Assertion objects:
```php
'test' => new Assertions(function (): Generator {
   yield new Assertion(description: 'should equal')
      ->expect($actual)->to->be($expected)->assert();
})
```
Chain: `->expect()->to->be()`, `->not->to->be()`, `->and->to->be()`, `->or->to->be()`. Retests: add `'retest' => function (callable $test, bool $passed, mixed ...$args) { ... }` key.

**File naming**: `{num}.{sub}-{description}.test.php` (e.g., `1.1-request_as_response-address.test.php`). Retestable files: `*.retestable.test.php`.

**Run**: `php bootgly test` or `php bootgly test --bootgly`.

## Development Workflows

- **Static Analysis**: `vendor/bin/phpstan analyse -c @/phpstan.neon` — level 9, excludes `tests/`, `examples/`, `&/`
- **CI**: GitHub Actions run PHPStan + test suite on push to `main` (PHP 8.4, Ubuntu 24.04)
- **Docker**: Images in `@/__docker__/` for http-server-cli, tcp-server-cli, tcp-client-cli
- **Opcache + JIT**: Config in `@/__php__/php-opcache.ini` (~50% performance boost)

## Anti-Patterns

- ❌ Adding PSR-4 autoload rules to composer.json
- ❌ Hardcoding paths instead of using Projects registry
- ❌ Cross-layer imports (e.g., CLI importing directly from ABI internals)
- ❌ Unregistered scripts (will throw on `Scripts->validate()`)
- ❌ Using `\ClassName` instead of `use ClassName`
- ❌ Grouped multi-line `use` statements
- ❌ Methods acting as getters/setters — use property hooks instead
- ❌ `function name()` without the space before `(`
- ❌ Creating alternative SAPI implementations — HTTP Server CLI is the only server
- ❌ Offering multiple ways to achieve the same thing — find and follow the one existing pattern
