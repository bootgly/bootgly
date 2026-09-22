# Organizational structures

## Project anatomy

These names are contracts: the framework finds each file by them, and a wrong one is silently ignored.

- **MUST** — `<Name>.Project.php` at the project root returns the project's `Project`.
- **MUST** — The namespace equals the project path (`projects/Shop/Models/Item.php` → `namespace Shop\Models;`)
  and class directories are uppercase, mirroring it: the project autoloader maps one onto the other.
- **MUST** — A `--from` copy keeps the namespaces of its source: rename them to the new path
  (`Demo\Blog\…` → `MyBlog\…`) before editing, or the autoloader keeps loading the original example.
- **MUST** — The resource directories the framework reads keep their names: `configs/`, `database/`,
  `router/`, `tests/`, and `views/` with templates named `*.template.php`.
- **SHOULD** — Keep the scaffold's `statics/` name too (the `Statics` path is configurable).
- **MUST** — Configuration: `configs/<scope>/<scope>.Config.php` returns `new Config(scope: '<scope>')`
  (e.g. `configs/database/database.Config.php`).
- **MUST** — Routes (WPI): `router/router.index.php` returns the list of route set names; each name is
  `router/routes/<Name>.routes.php`. A route set missing from that list never loads.
- **MUST** — Tests: `tests/autoboot.php` returns the `Suites` (its `directories:` list); each suite
  directory has an `autoboot.php` returning its `Suite` (its `tests:` list, names without `.Test.php`);
  each case is a `*.Test.php` file returning a `Test`. A case or suite missing from its list never runs.
- **MUST** — Database: migrations in `database/migrations/<timestamp>_<name>.php`, seeders in
  `database/seeders/<name>.php`. A migration's name is its file name — never rename one that ran.
- **SHOULD** — Create a project with `projects create`, a migration with `project <Name> migrate create <name>`
  and a seeder with `project <Name> seed create <name>`; write the rest by hand, following the names above.

## Your own files

- **SHOULD** — A new resource file of your own that returns an entity instance is named
  `<name>.<Entity>.php` — a file returning an `Options` is `defaults.Options.php`. Never rename a file
  whose name a loader fixes (`autoboot.php`, `router.index.php`, `schedule.php`, configs, migrations,
  seeders).
- **RECOMMEND** — A directory named after a class (`Cart/` beside `Cart.php`) holds that class's
  internals, and those parts never depend back on `Cart`. Grouping directories (`Controllers/`,
  `Models/`, `Resources/`) need no class of their own. Avoid dependency cycles.
