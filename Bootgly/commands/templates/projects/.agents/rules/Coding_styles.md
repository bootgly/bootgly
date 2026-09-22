# Coding styles

## Checked by `bootgly lint`

- **SHOULD** — Imports: in a namespaced file, import every global constant, function and class
  (`use const`, `use function`, `use`), ordered const → function → class, alphabetically, one per line,
  never grouped and never backslash-prefixed; a file without a namespace (a route set, a test, the
  `.Project.php`) calls them directly. `bootgly lint imports <path> --fix` writes the `use const` and
  `use function` lines; add `use` for global classes (`use DateTime;`) yourself — lint does not check them.
- **SHOULD** — Nullable types are written `null|Type`, never `?Type` (`bootgly lint nullables <path> --fix`).
- **SHOULD** — No constructor property promotion: declare properties in the class body
  (`bootgly lint promotions <path>`, check-only).

## House style

- **SHOULD** — One space between a function or method name and its parentheses in declarations:
  `public function boot ()`, never `public function boot()`.
- **SHOULD** — String interpolation over concatenation: `"{$dir}{$name}.php"`, not `$dir . $name . '.php'`.
- **SHOULD** — When you comment, use the [Semantic Commenting Code](https://github.com/bootgly/semantic_commenting_code)
  markers the shipped examples use — never add a comment just to use one:
  `// ?` guard or precondition · `// !` setup · `// @` action (`// @@` loop) · `// :` return
  (`// ?:` conditional return) · `// *` property section · `// #` subsection · `// ---` separator.
- **RECOMMEND** — In service and component classes, group properties under `// * Config` (constructor
  inputs that are publicly readable), `// * Data` (other inputs, protected or with restricted writes) and
  `// * Metadata` (values derived from config, data or runtime state — protected or private, written
  privately). ORM models keep their column order instead.

## File header

- **MUST** — Never add the Bootgly framework license block ("Bootgly PHP Framework … Licensed under
  MIT") to project code: the code belongs to the project. Use the project's own header, if it defines one.
  Files copied from a shipped example keep their notice.
