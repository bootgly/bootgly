<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_WORKING_BASE;
use const T_AS;
use const T_ATTRIBUTE;
use const T_CLOSE_TAG;
use const T_COMMENT;
use const T_CONST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_CURLY_OPEN;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_ELLIPSIS;
use const T_FUNCTION;
use const T_INLINE_HTML;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAME_RELATIVE;
use const T_NAMESPACE;
use const T_NEW;
use const T_NS_SEPARATOR;
use const T_OPEN_TAG;
use const T_RETURN;
use const T_STRING;
use const T_USE;
use const T_WHITESPACE;
use const TOKEN_PARSE;
use function addcslashes;
use function array_filter;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function basename;
use function bin2hex;
use function chr;
use function count;
use function dirname;
use function end;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function glob;
use function hexdec;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function ksort;
use function ltrim;
use function mkdir;
use function octdec;
use function preg_match;
use function preg_replace_callback;
use function random_bytes;
use function rawurlencode;
use function realpath;
use function rename;
use function rmdir;
use function rtrim;
use function scandir;
use function str_contains;
use function str_ends_with;
use function str_pad;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtr;
use function substr;
use function token_get_all;
use function unlink;
use function var_export;
use FilesystemIterator;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;
use Throwable;

use function Bootgly\ABI\copy_recursively;
use function Bootgly\ABI\remove_recursively;
use Bootgly\ABI\Resources;
use Bootgly\API\Projects\Project;


abstract class Projects
{
   use Resources;


   // _Dir
   // Author
   public const string AUTHOR_DIR = BOOTGLY_ROOT_BASE . '/projects/';
   // Consumer
   public const string CONSUMER_DIR = BOOTGLY_WORKING_BASE . '/projects/';
   // Reserved
   /**
    * Top-level namespace roots a project may not use as its first path segment.
    * They would shadow the framework (`Bootgly`) and platform namespaces the
    * autoloader resolves for bare project classes. Compared case-insensitively —
    * PHP resolves namespaces case-insensitively.
    *
    * Current platforms: `Console`, `Web` (plus the framework root `Bootgly`).
    * Reserved for future platforms: `Data`, `Graphics`, `Embedded`, `Mobile`.
    *
    * @var array<int,string>
    */
   public const array RESERVED = [
      'Bootgly',
      'Console',
      'Web',
      'Data',
      'Graphics',
      'Embedded',
      'Mobile',
   ];
   // # Inspect
   /**
    * What `inspect()` reads for a metadata field the signature computes
    * instead of writing as a literal — shown as is by `projects list`/`info`.
    */
   public const string DYNAMIC = '(dynamic)';
   /**
    * The largest signature `inspect()` reads, in bytes: a signature is a few
    * hundred bytes of metadata, and tokens cost ~150× their source in memory.
    */
   public const int SIGNATURE = 262_144;

   // * Config
   // ...

   // * Data
   /** @var Project[] */
   protected static array $projects = [];

   // * Metadata
   // @ index
   private static int $index = 0;
   /** @var int[] */
   private static array $indexes = [];
   // @ registry
   /** @var null|array<string,array{interfaces?:array<string>}> */
   private static null|array $registry = null;


   public static function add (Project $Project): int
   {
      $index = count(self::$projects);

      self::$projects[$index] = $Project;

      return $index;
   }

   /**
    * Index a project by name. If the project is already indexed, it will return false.
    *
    * @param string $project
    *
    * @return bool 
    */
   public static function index (string $project): bool
   {
      // ?
      if ($project === '') {
         return false;
      }
      if (isSet(self::$indexes[$project]) === true) {
         return false;
      }
      // ? Nothing has been added yet — there is no slot to name
      if (self::$projects === []) {
         return false;
      }

      // @ Name the slot the most recent add() filled — never the next one
      $index = count(self::$projects) - 1;
      self::$index = $index;
      self::$indexes[$project] = $index;

      return true;
   }

   /**
    * Count the number of projects.
    *
    * @return int 
    */
   public static function count (): int
   {
      return count(self::$projects);
   }
   /**
    * Select a project by index or name. If no project is selected, the default project is selected.
    *
    * @param null|string|int $project 
    *
    * @return Project|false
    */
   public static function select (null|string|int $project = null): Project|false
   {
      // ?!
      if (is_string($project) === true) {
         $project = self::$indexes[$project] ?? null;
      }
      else if ($project === null) {
         $project = self::$index;
      }

      // @ Select by project index
      $Project = self::$projects[$project] ?? false;

      return $Project;
   }

   /**
    * Read and cache the unified project registry (`Bootgly.projects.php`).
    *
    * The consumer registry takes precedence over the framework one. The file
    * returns a project-keyed map where each key is the project's canonical path
    * (relative to the projects root) and each value carries its `interfaces`.
    *
    * @return array<string,array{interfaces?:array<string>}>
    */
   public static function read (): array
   {
      // ?
      if (self::$registry !== null) {
         return self::$registry;
      }

      // @ Consumer dir wins over framework dir
      $file = self::CONSUMER_DIR . 'Bootgly.projects.php';
      if (is_file($file) === false) {
         $file = self::AUTHOR_DIR . 'Bootgly.projects.php';
      }

      $loaded = is_file($file) ? include $file : [];
      /** @var array<string,array{interfaces?:array<string>}> $registry */
      $registry = is_array($loaded) ? $loaded : [];

      // :
      return self::$registry = $registry;
   }

   /**
    * List the registered project paths bound to one interface (`CLI`|`WPI`),
    * preserving the registry declaration order.
    *
    * @param string $interface
    *
    * @return array<string>
    */
   public static function filter (string $interface): array
   {
      // !
      $paths = [];

      // @
      foreach (self::read() as $path => $meta) {
         if (in_array($interface, $meta['interfaces'] ?? [], true)) {
            $paths[] = $path;
         }
      }

      // :
      return $paths;
   }

   /**
    * Discover the registered projects of one interface, with the metadata
    * their signature files carry.
    *
    * @param string $interface CLI or WPI
    *
    * @return array<string, array{name: string, description: string, version: string, author: string, exportable: bool, error: string}>
    */
   public static function discover (string $interface): array
   {
      // !
      $projects = [];

      // @ Try consumer dir first, then framework dir
      $projectsDir = is_dir(self::CONSUMER_DIR) ? self::CONSUMER_DIR : self::AUTHOR_DIR;

      // @ Iterate the registered paths for this interface (leaf-named project files)
      foreach (self::filter($interface) as $path) {
         $leaf = basename($path);
         $file = "{$projectsDir}{$path}/{$leaf}.Project.php";
         // ! Every registered path is a row — a missing or symlinked signature
         //   is one marked by `inspect()`, never a silently absent project
         $projects[$path] = self::inspect($file, $path);
      }

      // :
      return $projects;
   }

   /**
    * Inspect one project signature for its metadata — without running it.
    *
    * A signature is PHP that only the executing verbs may run (`start`, and
    * `migrate`/`seed`/`schedule`, which open it). Listings read it as tokens
    * instead: the top-level `return new Project(...)` — its class resolved
    * through the file's namespace and `use` imports, and accepted only as
    * `Bootgly\API\Projects\Project` — gives `name`, `description`, `version`
    * and `author` when each is a plain string literal (named or positional),
    * and `exportable` when it is the literal `true`. A field written as any
    * other expression reads `Projects::DYNAMIC`. A signature that is a
    * symlink, unreadable, unparseable or not a `return new Project(...)`
    * keeps the fallbacks and carries a fixed `error`, so one bad project never
    * aborts a listing.
    *
    * The values are the file's own text: neutralize them before rendering.
    *
    * @param string $file The project file path
    * @param string $folder The project folder name (fallback)
    *
    * @return array{name: string, description: string, version: string, author: string, exportable: bool, error: string}
    */
   public static function inspect (string $file, string $folder): array
   {
      // !
      $metadata = [
         'name'        => $folder,
         'description' => '',
         'version'     => '',
         'author'      => '',
         'exportable'  => false,
         'error'       => '',
      ];

      // ? A symlinked signature reads a file outside the project
      if (is_link($file) === true) {
         $metadata['error'] = 'symlinked signature refused';

         return $metadata;
      }
      // ? A registered project without its signature
      if (is_file($file) === false) {
         $metadata['error'] = 'missing signature';

         return $metadata;
      }

      // @ Tokens — TOKEN_PARSE runs the real parser, never the code; the
      //   read is bounded (a file that grows after the size check included)
      try {
         $size = @filesize($file);
         $source = $size === false || $size > self::SIGNATURE
            ? false
            : @file_get_contents($file, false, null, 0, self::SIGNATURE + 1);
         if ($size !== false && $size > self::SIGNATURE || is_string($source) && strlen($source) > self::SIGNATURE) {
            $metadata['error'] = 'signature too large';

            return $metadata;
         }
         if ($source === false) {
            $metadata['error'] = 'unreadable signature';

            return $metadata;
         }
         // @ Compile-time notices (an octal escape overflow, a deprecated
         //   interpolation) belong to the signature, not to the listing
         $tokens = @token_get_all($source, TOKEN_PARSE);
      }
      catch (ParseError $Error) {
         $metadata['error'] = "syntax error on line {$Error->getLine()}";

         return $metadata;
      }
      catch (Throwable) {
         $metadata['error'] = 'unreadable signature';

         return $metadata;
      }

      // ? The top-level `return new Bootgly\API\Projects\Project(...)`
      $located = self::locate($tokens);
      if ($located === null || strtolower($located[0]) !== strtolower(Project::class)) {
         $metadata['error'] = 'no top-level `return new Project(...)`';

         return $metadata;
      }

      // ! Constructor order — positional arguments bind through it
      $parameters = [];
      foreach (new ReflectionMethod(Project::class, '__construct')->getParameters() as $Parameter) {
         $parameters[] = $Parameter->name;
      }

      // @@ Bind each argument to its parameter (PHP's own call rules)
      /** @var array<string,array<int,array{0:int,1:string,2:int}|string>> $values */
      $values = [];
      $named = false;
      $spread = false;
      foreach ($located[1] as $position => $argument) {
         $head = $argument[0];
         // ? `...$arguments` — any field left unnamed is unknown
         if (is_array($head) === true && $head[0] === T_ELLIPSIS) {
            $spread = true;

            continue;
         }

         if (
            ($argument[1] ?? null) === ':'
            && is_array($head) === true
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $head[1]) === 1
         ) {
            $named = true;
            $parameter = $head[1];
            $value = array_slice($argument, 2);
         }
         else {
            // ? A positional argument after a named or unpacked one never compiles
            $parameter = ($named === true || $spread === true) ? null : ($parameters[$position] ?? null);
            $value = $argument;
         }

         // ? Unknown, duplicated or out-of-place — PHP would refuse the call
         if ($parameter === null || in_array($parameter, $parameters, true) === false || isSet($values[$parameter]) === true) {
            $metadata['error'] = 'invalid `new Project(...)` arguments';

            return $metadata;
         }
         $values[$parameter] = $value;
      }

      // @ String fields: one literal each, anything else is dynamic
      foreach (['name', 'description', 'version', 'author'] as $field) {
         if (isSet($values[$field]) === false) {
            if ($spread === true) {
               $metadata[$field] = self::DYNAMIC;
            }

            continue;
         }

         $metadata[$field] = self::unquote($values[$field]) ?? self::DYNAMIC;
      }
      if ($metadata['name'] === '') {
         $metadata['name'] = $folder;
      }

      // @ Exportable: only the literal `true`
      $flag = $values['exportable'] ?? [];
      $metadata['exportable'] = count($flag) === 1
         && is_array($flag[0]) === true
         && ($flag[0][0] === T_STRING || $flag[0][0] === T_NAME_FULLY_QUALIFIED)
         && strtolower(ltrim($flag[0][1], '\\')) === 'true';

      // :
      return $metadata;
   }

   /**
    * Locate the top-level `return new <Class>(...)` statement of a signature.
    *
    * Only the first `return` that starts a statement outside every block is
    * read (a braced namespace counts as top level), it must construct a named
    * class, and the call must end the statement. `<Class>` is resolved
    * through the namespace and the class imports in effect there.
    *
    * @param array<int,array{0:int,1:string,2:int}|string> $tokens
    *
    * @return null|array{0:string,1:array<int,non-empty-array<int,array{0:int,1:string,2:int}|string>>}
    *         The resolved class and its arguments (insignificant tokens dropped).
    */
   private static function locate (array $tokens): null|array
   {
      // ! Significant tokens
      $list = [];
      foreach ($tokens as $token) {
         if (is_array($token) === true && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === true) {
            continue;
         }
         $list[] = $token;
      }
      $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
      $bounds = [';', '{', '}', T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML];

      // ! Scope
      $namespace = '';
      /** @var array<string,string> $aliases */
      $aliases = [];
      $depth = 0;
      $base = 0;

      // @@
      $count = count($list);
      for ($i = 0; $i < $count; $i++) {
         $token = $list[$i];
         $id = is_array($token) ? $token[0] : $token;

         // # Blocks
         if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;

            continue;
         }
         if ($id === '}') {
            $depth--;
            if ($depth < $base) {
               $base = 0;
            }

            continue;
         }
         // ? Statements only start at the top level, after a boundary
         $previous = $i > 0 ? $list[$i - 1] : ';';
         $previous = is_array($previous) ? $previous[0] : $previous;
         if ($depth !== $base || in_array($previous, $bounds, true) === false) {
            continue;
         }

         // # Namespace — resets the imports
         if ($id === T_NAMESPACE) {
            $next = $list[$i + 1] ?? ';';
            $namespace = is_array($next) && in_array($next[0], $names, true) ? $next[1] : '';
            $aliases = [];
            $j = $i + 1;
            while ($j < $count && $list[$j] !== ';' && $list[$j] !== '{') {
               $j++;
            }
            if (($list[$j] ?? null) === '{') {
               $base = 1;
               $depth = 1;
            }
            $i = $j;

            continue;
         }

         // # Imports — classes only (`use function` / `use const` skipped)
         if ($id === T_USE) {
            $kind = is_array($list[$i + 1] ?? null) ? $list[$i + 1][0] : null;
            $group = '';
            $pending = null;
            $alias = null;
            $skip = $kind === T_FUNCTION || $kind === T_CONST;
            for ($i++; $i < $count && $list[$i] !== ';'; $i++) {
               $part = $list[$i];
               if (is_array($part) && $part[0] === T_NS_SEPARATOR && ($list[$i + 1] ?? null) === '{') {
                  $group = ltrim((string) $pending, '\\');
                  $pending = null;
                  $i++;

                  continue;
               }
               if (is_array($part) && ($part[0] === T_FUNCTION || $part[0] === T_CONST)) {
                  $pending = false;

                  continue;
               }
               if (is_array($part) && $part[0] === T_AS) {
                  $alias = is_array($list[$i + 1] ?? null) ? $list[$i + 1][1] : null;
                  $i++;

                  continue;
               }
               if (is_array($part) && in_array($part[0], $names, true) && $pending !== false) {
                  $pending = $part[1];

                  continue;
               }
               if ($part === ',' || $part === '}') {
                  if ($skip === false && is_string($pending)) {
                     $full = ltrim($group !== '' ? "{$group}\\{$pending}" : $pending, '\\');
                     $segments = explode('\\', $full);
                     $aliases[strtolower($alias ?? end($segments))] = $full;
                  }
                  $pending = null;
                  $alias = null;
                  if ($part === '}') {
                     $group = '';
                  }
               }
            }
            if ($skip === false && is_string($pending)) {
               $full = ltrim($group !== '' ? "{$group}\\{$pending}" : $pending, '\\');
               $segments = explode('\\', $full);
               $aliases[strtolower($alias ?? end($segments))] = $full;
            }

            continue;
         }

         // ? Anything but `return` is not the statement this reads
         if ($id !== T_RETURN) {
            continue;
         }

         // ?: `return new <Name>(` — the first top-level return decides
         $new = $list[$i + 1] ?? null;
         $class = $list[$i + 2] ?? null;
         if (
            is_array($new) === false || $new[0] !== T_NEW
            || is_array($class) === false || in_array($class[0], $names, true) === false
            || ($list[$i + 3] ?? null) !== '('
         ) {
            return null;
         }

         // @@ Arguments up to the matching parenthesis, split at its own commas
         $arguments = [];
         $argument = [];
         $level = 0;
         for ($j = $i + 4; $j < $count; $j++) {
            $part = $list[$j];
            $mark = is_array($part) ? $part[0] : $part;
            if ($level === 0 && ($mark === ')' || $mark === ',')) {
               if ($argument !== []) {
                  $arguments[] = $argument;
               }
               $argument = [];
               if ($mark === ')') {
                  break;
               }

               continue;
            }
            if ($mark === '(' || $mark === '[' || $mark === '{' || $mark === T_CURLY_OPEN || $mark === T_DOLLAR_OPEN_CURLY_BRACES || $mark === T_ATTRIBUTE) {
               $level++;
            }
            else if ($mark === ')' || $mark === ']' || $mark === '}') {
               $level--;
            }
            $argument[] = $part;
         }
         // ? The construction must end the statement (no chained call, no operator)
         $end = $list[$j + 1] ?? null;
         $end = is_array($end) ? $end[0] : $end;
         if ($end !== ';' && $end !== T_CLOSE_TAG) {
            return null;
         }

         // :
         return [self::resolve($class, $namespace, $aliases), $arguments];
      }

      // :
      return null;
   }

   /**
    * Resolve a class name token to its fully qualified name, the way PHP does
    * for `new`: fully qualified as written, `namespace\` against the current
    * namespace, otherwise the first segment through the class imports, then
    * the current namespace.
    *
    * @param array{0:int,1:string,2:int} $token
    * @param string $namespace
    * @param array<string,string> $aliases Lower-cased alias => fully qualified name.
    *
    * @return string
    */
   private static function resolve (array $token, string $namespace, array $aliases): string
   {
      [$id, $name] = $token;

      // ?: `\Foo\Bar`
      if ($id === T_NAME_FULLY_QUALIFIED) {
         return substr($name, 1);
      }
      // ?: `namespace\Bar`
      if ($id === T_NAME_RELATIVE) {
         $rest = substr($name, strlen('namespace\\'));

         return $namespace !== '' ? "{$namespace}\\{$rest}" : $rest;
      }

      // @ First segment through the imports
      $segments = explode('\\', $name, 2);
      $imported = $aliases[strtolower($segments[0])] ?? null;
      if ($imported !== null) {
         return isSet($segments[1]) ? "{$imported}\\{$segments[1]}" : $imported;
      }

      // :
      return $namespace !== '' ? "{$namespace}\\{$name}" : $name;
   }

   /**
    * Unquote one PHP string literal token — the only value a listing reads.
    *
    * Single-quoted literals unescape `\\` and `\'`; double-quoted ones (the
    * lexer only emits them as one constant token when nothing interpolates)
    * unescape PHP's sequences: `\n` `\r` `\t` `\v` `\e` `\f` `\\` `\$` `\"`,
    * octal, `\x` and `\u{}`. Anything that is not exactly one literal is
    * dynamic (null).
    *
    * @param array<int,array{0:int,1:string,2:int}|string> $value
    *
    * @return null|string
    */
   private static function unquote (array $value): null|string
   {
      // ? One string literal, nothing else
      if (count($value) !== 1 || is_array($value[0]) === false || $value[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
         return null;
      }

      // ! Binary prefix (`b'…'`)
      $literal = ltrim($value[0][1], 'bB');
      $body = substr($literal, 1, -1);

      // ?: Single-quoted
      if ($literal[0] === "'") {
         return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
      }

      // : Double-quoted
      return preg_replace_callback(
         '/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
         static function (array $escape): string {
            if ($escape[1] !== '') {
               return match ($escape[1]) {
                  'n' => "\n",
                  'r' => "\r",
                  't' => "\t",
                  'v' => "\v",
                  'e' => "\e",
                  'f' => "\f",
                  default => $escape[1],
               };
            }
            if ($escape[2] !== '') {
               return chr(((int) octdec($escape[2])) & 255);
            }
            if ($escape[3] !== '') {
               return chr((int) hexdec($escape[3]));
            }

            // : `\u{…}` — UTF-8, encoded the way PHP does (surrogates included)
            $code = (int) hexdec($escape[4]);

            return match (true) {
               $code < 0x80    => chr($code),
               $code < 0x800   => chr(0xC0 | $code >> 6) . chr(0x80 | $code & 0x3F),
               $code < 0x10000 => chr(0xE0 | $code >> 12) . chr(0x80 | $code >> 6 & 0x3F) . chr(0x80 | $code & 0x3F),
               default         => chr(0xF0 | $code >> 18) . chr(0x80 | $code >> 12 & 0x3F)
                  . chr(0x80 | $code >> 6 & 0x3F) . chr(0x80 | $code & 0x3F),
            };
         },
         $body
      ) ?? $body;
   }

   /**
    * Load a project's Composer autoloader when present.
    *
    * Composer is per project: dependencies live in `<project>/vendor/`, never
    * at the kit root — a root vendor tree could shadow the pinned framework.
    * Runs BEFORE the project signature file is required, so third-party
    * classes referenced at the signature's top level resolve. `require_once`
    * plus Composer's own re-entry guard make repeated calls safe.
    *
    * @param string $dir Project directory, with a trailing separator.
    *
    * @return void
    */
   public static function load (string $dir): void
   {
      // ?
      $autoload = "{$dir}vendor/autoload.php";
      if (is_file($autoload) === false) {
         return;
      }

      // @
      require_once $autoload;
   }

   /**
    * Check a project path against the path-safety rules.
    *
    * Rejects path traversal, absolute paths, backslashes, null bytes, double
    * slashes and trailing slashes. Membership in the registry allow-list is
    * NOT checked here — that is `validate()`'s job.
    *
    * @param string $path
    *
    * @return bool
    */
   public static function check (string $path): bool
   {
      // ? Path-safety
      if ($path === '' || $path[0] === '/' || str_ends_with($path, '/')) {
         return false;
      }
      if (str_contains($path, '..') || str_contains($path, '\\')) {
         return false;
      }
      if (str_contains($path, "\0") || str_contains($path, '//')) {
         return false;
      }

      // :
      return true;
   }

   /**
    * Vet a project path against the write-path naming alphabet.
    *
    * Registry keys and stub tokens are emitted into PHP source, so paths the
    * framework mints (`register()`/`generate()`/`import()`) come from a closed
    * alphabet: segments start uppercase and use only letters, numbers, `_` or
    * `-`. The read path (`check()`/`validate()`) intentionally stays wider —
    * a legacy hand-registered path keeps booting and is re-emitted as-is.
    *
    * @param string $path
    *
    * @return bool
    */
   public static function vet (string $path): bool
   {
      // :
      // ! `/D` — without it PCRE's `$` also matches before a final newline, so
      //   `"App\n"` passed this closed alphabet and reached the registry as a
      //   key with an embedded newline
      return preg_match('#^[A-Z][A-Za-z0-9_-]*(?:/[A-Z][A-Za-z0-9_-]*)*$#D', $path) === 1;
   }

   /**
    * Validate a project path against the security boundary.
    *
    * Applies the path-safety rules (`check()`), then requires the path to be
    * an exact key of the registry allow-list. This is the only gate for
    * starting a project.
    *
    * @param string $path
    *
    * @return bool
    */
   public static function validate (string $path): bool
   {
      // ? Path-safety
      if (self::check($path) === false) {
         return false;
      }

      // ?: Allow-list membership (the real boundary)
      return array_key_exists($path, self::read());
   }

   /**
    * Screen a path and its metadata against every write-path gate.
    *
    * The gates `register()` enforces — path-safety, the naming alphabet, the
    * reserved platform roots and a non-empty interfaces list — are pure, so
    * the mutators run them BEFORE copying anything: a refused path never
    * reaches the disk, and a registration can then only fail on I/O.
    *
    * @param string $path
    * @param array{interfaces?:array<string>} $meta
    *
    * @return bool
    */
   private static function screen (string $path, array $meta): bool
   {
      // ? Path-safety
      if (self::check($path) === false) {
         return false;
      }
      // ? Naming alphabet — registry keys are emitted into PHP source
      if (self::vet($path) === false) {
         return false;
      }
      // ? Reserved platform namespace root (Bootgly, Console, Web)
      $root = strtolower($path);
      foreach (self::RESERVED as $reserved) {
         $reserved = strtolower($reserved);
         if ($root === $reserved || str_starts_with($root, "{$reserved}/")) {
            return false;
         }
      }
      // ? Interfaces are required
      if (($meta['interfaces'] ?? []) === []) {
         return false;
      }

      // :
      return true;
   }

   /**
    * Register a project path in the registry allow-list (`Bootgly.projects.php`).
    *
    * The whole registry file is deterministically re-emitted: current entries
    * are loaded, the new entry is inserted, keys are sorted and the file is
    * rewritten atomically with the canonical header. The registry format is
    * machine-managed — hand-added comments inside the array are not preserved.
    *
    * @param string $path
    * @param array{interfaces?:array<string>} $meta
    * @param null|string $file Registry file (defaults to the consumer registry).
    *
    * @return bool
    */
   public static function register (string $path, array $meta, null|string $file = null): bool
   {
      // ? Every write-path gate, before the registry is touched
      if (self::screen($path, $meta) === false) {
         return false;
      }

      // !
      $interfaces = $meta['interfaces'] ?? [];
      $file ??= self::CONSUMER_DIR . 'Bootgly.projects.php';

      // @ Load the current registry
      $loaded = is_file($file) ? include $file : [];
      /** @var array<string,array{interfaces?:array<string>}> $registry */
      $registry = is_array($loaded) ? $loaded : [];

      // @ Insert the entry (alphabetical order for readability)
      $entry = ['interfaces' => array_values($interfaces)];
      $registry[$path] = $entry;
      ksort($registry);

      // @ Re-emit the whole file
      $written = self::write($file, $registry);

      // @ Reset the registry cache
      self::$registry = null;

      // :
      return $written;
   }

   /**
    * Probe a project tree for a file the global `bootgly` command would run
    * as a launcher: the wrapper walks up from the working directory and runs
    * the nearest `bootgly` that defines `BOOTGLY_WORKING_BASE`, so one shipped
    * inside an imported project would run whenever a verb is called from in
    * there — `projects list` and `project info` included.
    *
    * @param string $source The project tree.
    *
    * @return null|string The offending path, relative to `$source` (`.` when
    *    the tree cannot be read); `null` when the tree carries no launcher.
    */
   public static function probe (string $source): null|string
   {
      // ?
      if (is_dir($source) === false) {
         return null;
      }

      // @@ Every entry named `bootgly` — links are never followed
      try {
         $Entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
         );
         foreach ($Entries as $Entry) {
            /** @var SplFileInfo $Entry */
            $path = $Entry->getPathname();
            if ($Entry->getFilename() !== 'bootgly' || $Entry->isLink() === false && $Entry->isDir() === true) {
               continue;
            }

            $head = $Entry->isLink() === true ? false : @file_get_contents($path, false, null, 0, 1_048_576);
            // ? A link (resolved by the wrapper) or a launcher
            if ($head === false || str_contains($head, 'BOOTGLY_WORKING_BASE') === true) {
               return substr($path, strlen(rtrim($source, '/')) + 1);
            }
         }
      }
      catch (Throwable) {
         return '.';
      }

      // :
      return null;
   }

   /**
    * Import a project available on disk into the consumer projects directory.
    *
    * The source directory must carry the Bootgly project signature — a
    * `*.Project.php` file at its root. The project is copied, its signature
    * file is renamed to the new leaf and the path is registered in the
    * registry allow-list. The imported content is kept as-is.
    *
    * Every gate `register()` applies runs BEFORE anything is copied, the copy
    * is built in a staging sibling and swapped into place only once it is
    * complete, and whatever stops the call after that removes what the call
    * made — a refused or failed import leaves no tree behind.
    *
    * @param string $source Source project directory (platform project or fetched clone).
    * @param string $path Canonical target project path (e.g. `App/API`).
    * @param array{interfaces?:array<string>} $meta
    * @param null|string $base Projects base directory (defaults to the consumer directory).
    * @param bool $refresh Replace an existing copy at the target — it is kept until the new one is complete.
    *
    * @return bool
    */
   public static function import (
      string $source, string $path, array $meta, null|string $base = null, bool $refresh = false
   ): bool
   {
      // !
      $source = rtrim($source, '/');
      $base ??= self::CONSUMER_DIR;

      // ? Source must be a Bootgly project (signature: `*.Project.php` at its root)
      if (is_dir($source) === false) {
         return false;
      }
      $signatures = glob("{$source}/*.Project.php");
      if ($signatures === false || $signatures === []) {
         return false;
      }
      $leaf = basename($signatures[0], '.Project.php');
      // ? A symlinked signature would import (and later run) a file from
      //   outside the project — copy_recursively() keeps links as links
      foreach ($signatures as $signature) {
         if (is_link($signature) === true) {
            return false;
         }
      }
      // ? A launcher the global `bootgly` command would run from inside it
      if (self::probe($source) !== null) {
         return false;
      }

      // ? Every write-path gate, before anything is copied — the path becomes
      //   a registry key
      if (self::screen($path, $meta) === false) {
         return false;
      }
      // !
      $target = "{$base}{$path}";
      $registry = "{$base}Bootgly.projects.php";
      $newLeaf = basename($path);
      $parent = dirname($target);
      $staging = "{$parent}/.{$newLeaf}.staging";
      $backup = "{$parent}/.{$newLeaf}.backup";

      // ? A backup with no project beside it is a refresh that died between
      //   its two renames — the user's project, whole, under a hidden name.
      //   It goes back first, so no later call can mistake the path for free
      if (is_dir($target) === false && is_dir($backup) === true) {
         rename($backup, $target);
      }
      // ? Target collision — unless the caller asked for a refresh
      if (is_dir($target) === true && $refresh === false) {
         return false;
      }
      // ?: Source and target are one directory (the framework checkout, where
      //    the platform folder IS the working one): nothing to copy, and a
      //    refresh would erase the source out from under itself. A path the
      //    registry already lists is left alone.
      if (realpath($source) === realpath($target)) {
         $loaded = is_file($registry) ? include $registry : [];
         if (is_array($loaded) && array_key_exists($path, $loaded) === true) {
            return true;
         }

         return self::register($path, $meta, $registry);
      }
      // ? A refresh replaces a PROJECT. A target without a signature at its
      //   root is a group of projects or a hand-made tree — never a copy to
      //   replace — and a source inside the target (or a target inside the
      //   source) would be destroyed, or copied into itself, on the way.
      if (is_dir($target) === true) {
         if ((glob("{$target}/*.Project.php") ?: []) === []) {
            return false;
         }
         $inner = realpath($source) . '/';
         $outer = realpath($target) . '/';
         if (str_starts_with($inner, $outer) === true || str_starts_with($outer, $inner) === true) {
            return false;
         }
      }

      // @ Build the copy in a staging sibling — dot-prefixed, so neither the
      //   import picker's globs nor the autoloader can see it — and swap it in
      //   only once it is complete
      $ancestor = self::ascend($parent);
      $done = false;
      try {
         remove_recursively($staging);
         if (is_dir($parent) === false) {
            mkdir($parent, 0755, true);
         }
         copy_recursively($source, $staging);

         // @ Rename the signature file to the new leaf — the content is kept
         //   as-is: rewriting the old leaf inside it would also hit the
         //   namespaces, identifiers and enum cases that merely contain it
         if ($leaf !== $newLeaf) {
            rename("{$staging}/{$leaf}.Project.php", "{$staging}/{$newLeaf}.Project.php");
         }

         // @ Refresh: move the old copy aside, place the new one, register —
         //   every step that can fail runs while the old copy still exists,
         //   and a failure puts it back. The registry is written last, so it
         //   never advertises a copy that is not there.
         if (is_dir($target) === true) {
            // ! A backup a previous refresh could not remove must not refuse
            //   this one: whatever of it still cannot go stays, under its own
            //   name, and this refresh backs up beside it
            try {
               remove_recursively($backup);
            }
            catch (Throwable) {
            }
            if (file_exists($backup) === true) {
               $backup = "{$parent}/.{$newLeaf}.backup-" . bin2hex(random_bytes(4));
            }
            rename($target, $backup);
            $placed = false;
            try {
               rename($staging, $target);
               $placed = true;
               $done = self::register($path, $meta, $registry);
            }
            finally {
               if ($done === false) {
                  if ($placed === true) {
                     remove_recursively($target);
                  }
                  rename($backup, $target);
               }
            }

            // ! The old copy goes last and best-effort: a file inside it the
            //   process cannot remove (a socket, a read-only tree) is no reason
            //   to fail a refresh that already completed — the remainder stays
            //   beside the project as the backup directory
            try {
               remove_recursively($backup);
            }
            catch (Throwable) {
            }

            // :
            return $done;
         }

         // @ New path: place it, then register — a registry that cannot be
         //   written rolls the placed copy back
         rename($staging, $target);
         try {
            $done = self::register($path, $meta, $registry);
         }
         finally {
            if ($done === false) {
               remove_recursively($target);
            }
         }

         // :
         return $done;
      }
      finally {
         // ! The staging copy never outlives the call, and neither does a
         //   parent directory the call created for nothing
         remove_recursively($staging);
         if ($done === false) {
            self::prune($parent, $ancestor);
         }
      }
   }

   /**
    * Ascend from a directory to its nearest existing ancestor.
    *
    * @param string $directory
    *
    * @return string
    */
   private static function ascend (string $directory): string
   {
      // @@
      while (is_dir($directory) === false) {
         $directory = dirname($directory);
      }

      // :
      return $directory;
   }

   /**
    * Prune the empty directories a failed call created, from `$directory` up
    * to (never including) `$until` — the ancestor that already existed.
    *
    * @param string $directory
    * @param string $until
    *
    * @return void
    */
   private static function prune (string $directory, string $until): void
   {
      // @@
      while ($directory !== $until && is_dir($directory) === true) {
         // ? Anything inside means it is in use — stop there
         if ((array) scandir($directory) !== ['.', '..']) {
            return;
         }
         rmdir($directory);

         $directory = dirname($directory);
      }
   }

   /**
    * Generate a project from a stub directory into the consumer projects directory.
    *
    * Copies the given stub directory, substitutes the metadata tokens and
    * registers the path in the registry allow-list. Which stub to use is the
    * caller's decision — this layer only copies, fills and registers.
    *
    * String metadata is escaped for the single-quoted literals the stubs place
    * it in, so quotes and backslashes round-trip exactly. Metadata carrying
    * control characters is refused — not because a literal could not hold
    * them (it can, byte for byte) but because a name or description with a
    * newline or an escape sequence is unreadable in every consumer, from the
    * registry listing to the terminal. A generation whose emitted signature
    * does not parse is refused before registration, and the stub copy that
    * call made is removed with it.
    *
    * @param array<string>|string $sources Stub directories layered onto the target, in order.
    * @param string $path Canonical target project path (e.g. `App/API`).
    * @param array{interfaces?:array<string>,name?:string,description?:string,version?:string,author?:string,port?:int|string} $meta
    * @param null|string $base Projects base directory (defaults to the consumer directory).
    *
    * @return bool
    */
   public static function generate (array|string $sources, string $path, array $meta, null|string $base = null): bool
   {
      // !
      $base ??= self::CONSUMER_DIR;
      $sources = (array) $sources;

      // ? Every stub source must exist
      if ($sources === []) {
         return false;
      }
      foreach ($sources as $source) {
         if (is_dir($source) === false) {
            return false;
         }
      }
      // ? Every write-path gate, before anything is copied — the path lands in
      //   stub literals and becomes a registry key
      if (self::screen($path, $meta) === false) {
         return false;
      }
      // ? Metadata must not carry control characters — a literal would hold
      //   them byte for byte, and every listing that renders them would break
      foreach (['name', 'description', 'version', 'author', 'port'] as $field) {
         if (preg_match('#[\x00-\x1F]#', (string) ($meta[$field] ?? '')) === 1) {
            return false;
         }
      }
      // ? Target collision
      $target = "{$base}{$path}";
      if (is_dir($target) === true) {
         return false;
      }

      // ! Metadata tokens — the stubs own the quotes: metadata values land
      //   inside single-quoted literals, so their quote-body characters are
      //   escaped here. __PATH__/__LEAF__ stay raw: vet() confines them to
      //   the naming alphabet, and __LEAF__ also names files and identifiers,
      //   where an escape would corrupt.
      $leaf = basename($path);
      $tokens = [
         '__NAME__'        => addcslashes((string) ($meta['name'] ?? $leaf), "\\'"),
         '__PATH__'        => $path,
         '__LEAF__'        => $leaf,
         '__DESCRIPTION__' => addcslashes((string) ($meta['description'] ?? ''), "\\'"),
         '__VERSION__'     => addcslashes((string) ($meta['version'] ?? '1.0.0'), "\\'"),
         '__AUTHOR__'      => addcslashes((string) ($meta['author'] ?? ''), "\\'"),
         '__PORT__'        => addcslashes((string) ($meta['port'] ?? 8080), "\\'"),
      ];

      // @ Copy the stub + fill the tokens — whatever stops this call from here
      //   on (a refused signature, a registry that could not be written, an
      //   I/O warning the framework escalated) removes the tree it made:
      //   nothing in it is user-authored yet, and a leftover would block every
      //   retry of the path and show up in the import picker.
      $parent = dirname($target);
      $ancestor = self::ascend($parent);
      $done = false;
      try {
         if (is_dir($parent) === false) {
            mkdir($parent, 0755, true);
         }
         // @@ Layered, in order — later sources may add files, never replace
         //   the signature the first one owns
         foreach ($sources as $source) {
            copy_recursively($source, $target);
         }
         self::fill($target, $tokens);

         // ? Defense-in-depth: never register a project whose emitted signature
         //   does not parse (a broken or adversarial stub source)
         $signature = "{$target}/{$leaf}.Project.php";
         if (is_file($signature) === true) {
            token_get_all((string) file_get_contents($signature), TOKEN_PARSE); // @phpstan-ignore function.resultUnused
         }

         // : Register in the allow-list
         $entry = ['interfaces' => array_values($meta['interfaces'] ?? [])];
         $done = self::register($path, $entry, "{$base}Bootgly.projects.php");

         return $done;
      }
      catch (ParseError) {
         return false;
      }
      finally {
         if ($done === false) {
            remove_recursively($target);
            self::prune($parent, $ancestor);
         }
      }
   }

   /**
    * Re-emit the registry file with the canonical header, atomically.
    *
    * The entries come back from a file a human may have edited, so the shape
    * of each one is trusted no further than the emission needs.
    *
    * @param string $file
    * @param array<int|string,array<string,mixed>> $registry
    *
    * @return bool
    */
   private static function write (string $file, array $registry): bool
   {
      // ! Key literals + column width — a path is emitted as an escaped PHP
      //   literal and escaping can widen it, so the width comes from the
      //   literal, never from the raw path
      /** @var array<int|string,string> $keys */
      $keys = [];
      $width = 0;
      foreach ($registry as $path => $meta) {
         $key = var_export((string) $path, true);
         $keys[$path] = $key;

         $length = strlen($key);
         if ($length > $width) {
            $width = $length;
         }
      }

      // @ Emit the entries
      $entries = '';
      foreach ($registry as $path => $meta) {
         $key = str_pad($keys[$path], $width);
         // ! A hand-edited registry may carry a malformed list — degrade it,
         //   never abort the CLI's only way of rewriting the file
         $list = array_filter((array) ($meta['interfaces'] ?? []), is_string(...));
         $interfaces = implode(', ', array_map(
            static fn (string $interface): string => var_export($interface, true),
            $list
         ));
         $entries .= "   {$key} => ['interfaces' => [{$interfaces}]],\n";
      }

      $content = <<<REGISTRY
      <?php
      /*
       * --------------------------------------------------------------------------
       * Bootgly PHP Framework
       * Developed by Rodrigo Vieira (@rodrigoslayertech)
       * Copyright (c) 2023-present Bootgly and contributors
       * Licensed under MIT
       * --------------------------------------------------------------------------
       */

      // Unified project registry — the security allow-list.
      //
      // Only the project paths declared here may be started
      // (`bootgly project <path> start`). Each key is the project's canonical path,
      // relative to this `projects/` directory, at any depth (subprojects). Each value
      // binds the project to one or more interfaces:
      //   - CLI → Console platform
      //   - WPI → Web platform (served by Bootgly's own CLI HTTP server)

      // Kept in alphabetical order by project path. This file is machine-managed by
      // `bootgly projects create/import` — hand-added comments inside the array are
      // not preserved.
      return [
      {$entries}];

      REGISTRY;

      // @ Write atomically (tmp + rename) behind a parse gate
      $tmp = "{$file}.tmp";
      try {
         if (file_put_contents($tmp, $content) !== strlen($content)) {
            return false;
         }
         // ? A registry the engine cannot parse must never be installed —
         //   `register()` and `read()` both `include` this file, so a broken
         //   emission would take the whole CLI down with it. `token_get_all`
         //   alone is a lexer; TOKEN_PARSE runs the real parser.
         token_get_all($content, TOKEN_PARSE); // @phpstan-ignore function.resultUnused

         // :
         return rename($tmp, $file);
      }
      catch (ParseError) {
         return false;
      }
      finally {
         // ! Whatever ended the install — a short write, an unparseable
         //   emission, a refused rename, or a warning the framework escalated
         //   into an exception before any `=== false` could run — the temp
         //   file never outlives it. A rename that succeeded already took it.
         if (is_file($tmp) === true) {
            unlink($tmp);
         }
      }
   }

   /**
    * Walk a generated project: rename `__LEAF__` path segments and substitute
    * the metadata tokens inside every file.
    *
    * @param string $target
    * @param array<string,string> $tokens
    *
    * @return void
    */
   private static function fill (string $target, array $tokens): void
   {
      $paths = scandir($target);
      // ?
      if ($paths === false) {
         return;
      }

      // @@
      foreach ($paths as $entry) {
         if ($entry === '.' || $entry === '..') {
            continue;
         }

         $path = "{$target}/{$entry}";

         // @ Rename entries carrying the __LEAF__ token
         if (str_contains($entry, '__LEAF__') === true) {
            $renamed = "{$target}/" . strtr($entry, ['__LEAF__' => $tokens['__LEAF__']]);
            rename($path, $renamed);
            $path = $renamed;
         }

         if (is_dir($path) === true) {
            self::fill($path, $tokens);

            continue;
         }

         // @ Substitute tokens
         $content = file_get_contents($path);
         if ($content === false) {
            continue;
         }
         file_put_contents($path, strtr($content, $tokens));
      }
   }

   /**
    * Encode a canonical project path into an injective filesystem identifier.
    *
    * Names accepted by ProjectCommand retain their legacy identity, including
    * `Demo/HTTP_Server_CLI` becoming `Demo~HTTP_Server_CLI`. Characters that
    * can alias the slash marker, percent escapes, instance separator or glob
    * syntax are encoded, so manually registered names cannot share state.
    *
    * @param string $path
    *
    * @return string
    */
   public static function encode (string $path): string
   {
      return strtr(rawurlencode($path), [
         '%2F' => '~',
         '~' => '%7E',
         '.' => '%2E',
      ]);
   }
}
