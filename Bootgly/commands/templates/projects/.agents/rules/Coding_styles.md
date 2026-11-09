# Coding styles

## Checked by `bootgly lint check`

- **SHOULD** — Run `bootgly lint check` from the project (`--fix` rewrites what it can) and keep it
  clean. It enforces what `projects/bootgly.Lint.php` enables — by default every rule: imports of
  global constants and functions, nullable types (`null|Type`), no constructor property promotion,
  one space before a declaration's parameter list, single-verb method names, the casing of object and
  acronym names, and property sections. Its report says how to fix each issue. A rule you must break
  is waived in that file with a reason (an exemption), never ignored.
- **SHOULD** — Global classes are imported too (`use DateTime;`): lint checks only constants and
  functions. A file without a namespace (a route set, a test, the `.Project.php`) imports nothing.
- **SHOULD** — Lint does not check the import layout: one `use` per line, never grouped
  (`use function a, b;`, `use Foo\{Bar, Baz};`); 2 blank lines after `namespace` and after the last
  `use`; 1 blank line between the global imports and the namespaced ones.

## House style

- **SHOULD** — String interpolation over concatenation: `"{$dir}{$name}.php"`, not `$dir . $name . '.php'`.
- **SHOULD** — When you comment, use the [Semantic Commenting Code](https://github.com/bootgly/semantic_commenting_code)
  markers the shipped examples use — never add a comment just to use one:
  `// ?` guard or precondition · `// !` setup · `// @` action (`// @@` loop) · `// :` return
  (`// ?:` conditional return) · `// *` property section · `// #` subsection · `// ---` separator.
- **RECOMMEND** — Lint checks that properties sit in sections; which section is your call:
  `// * Config` holds constructor inputs that are publicly readable, `// * Data` other inputs
  (protected, or with restricted writes), `// * Metadata` values derived from config, data or runtime
  state. A constructor input that is not publicly readable is Data. ORM models keep their columns
  under `// * Data`, in column order.

## File header

- **MUST** — Never add the Bootgly framework license block ("Bootgly PHP Framework … Licensed under
  MIT") to project code: the code belongs to the project. Use the project's own header, if it defines one.
  Files copied from a shipped example keep their notice.
