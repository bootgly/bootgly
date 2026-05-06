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

**Special directories**:

- `@/` holds operational configs (phpstan, docker, php.ini) — mostly gitignored except `@/__php__/`, `@/__docker__/`, `@/phpstan.neon`.
- `&/` means "excluded" — gitignored (`**/&`), used for temporary files, drafts, WIP code, trash. Never reference `&/` contents in production code. Per-module `&/` dirs (e.g., `Bootgly/WPI/Nodes/&/`) hold incomplete drafts.
- `vs/` means "versus" — also gitignored (`**/vs`), used for comparison/alternative implementations.

**Repository naming**:

- Project: `bootgly` (no separator)
- Bootable: `bootgly-web` (dash)
- Template: `bootgly.web` (dot)
- Extension: `bootgly_docs` (underscore)

**Bootstrap**: `autoboot.php` → `Bootgly/autoload.php` (loads ABI→ACI→ADI→API→CLI→WPI) → `CLI->autoboot()` + `WPI->autoboot()` → project boot files in `projects/Bootgly/`.

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

## Registry Pattern (`@.php` files)

Bootstrap files for dynamic discovery:

- `projects/@.php` — project definitions and default project
- `scripts/@.php` — whitelisted scripts (`built-in`, `imported`, `user`)
- `tests/@.php` — test suite directories
- `**/commands/@.php` — CLI command instances

Unregistered scripts fail validation. Follow this pattern when adding new registries.
