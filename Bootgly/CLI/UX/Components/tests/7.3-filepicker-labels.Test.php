<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use const SORT_STRING;
use function array_diff;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function assert;
use function base64_decode;
use function count;
use function explode;
use function fclose;
use function feof;
use function file_put_contents;
use function fopen;
use function fread;
use function function_exists;
use function getenv;
use function hrtime;
use function implode;
use function ini_restore;
use function ini_set;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_scrub;
use function mkdir;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function realpath;
use function rewind;
use function rmdir;
use function rtrim;
use function scandir;
use function sort;
use function str_replace;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;
use function substr;
use function unlink;
use ReflectionMethod;
use RuntimeException;

use Bootgly\ABI\Code\__String\Controls;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Tree;
use Bootgly\CLI\UI\Components\Tree\Node;


/**
 * A Tree label is authored markup and a filesystem name is not: the Filepicker
 * shows every name as text and keeps the real path as the node value, which is
 * what `pick()` returns. The label is lossless, so two names never share one: a
 * byte that is not UTF-8 becomes `?` (`mb_scrub()`), a backslash doubles, a
 * control character becomes its visible escape (`Controls::escape()`), so does
 * a zero-width or bidi format character (`\u202e`), and an `@` that would
 * open or close markup shows as `\u0040` instead of going.
 *
 * Fixture — a root directory whose own name holds an erase-screen ESC and
 * markup, with one file per hostile name: ESC cursor moves, an `@` then a C1
 * OSC 52 (clipboard write) ended by BEL — that `@` shows as `\u0040` only
 * when the markup pass runs last, on the escaped text —, `@#Red:x@;`, a LF, a
 * complete SGR (`\e[8m`, conceal: an escape that kept SGR would pass it whole),
 * a raw 0x9B byte (an 8-bit CSI, not UTF-8), a TAB and a DEL, and `plain.md`,
 * an ordinary name. A second directory holds look-alike names: `a;b` and
 * `a@;b`, `x-y` and `x-@y` (each pair one label once the `@` goes), `lit` + ESC
 * and `lit\u001b` spelled with a backslash (one label unless the backslash
 * doubles), `invoice` + RLO + `fdp.php` (a terminal that applies bidi shows
 * `invoicephp.pdf`), `hidden.txt` spelled with zero-width and format characters
 * inside, and `user@host.txt`, whose `@` opens no markup.
 *
 * Control — each hostile name, as a raw Tree label (what the Filepicker passed
 * before), drives the terminal or splits its row; `plain.md` does neither.
 * Leg 1 — `scan()` (by reflection): each label is the name shown as text and
 * each value the real path, byte for byte. Leg 2 — that Tree rendered: one row
 * per entry and bytes a terminal only paints. Leg 3 — an unreachable root with
 * ESC, a 0x9B byte and markup: the warning shows it as text. Leg 4 — `pick()`
 * on an interactive terminal (a child with BOOTGLY_TTY=1, so the leg runs on
 * pipes and CI too): the root row shows the root's name as text, the frame is
 * inert, and Down + Enter returns the real path of the entry shown as text.
 * Leg 5 — the look-alike names. Control: a label that removes the markup `@`
 * and leaves backslashes and format characters as they are merges each pair
 * and keeps the RLO and the zero-width characters raw. `scan()` gives every
 * name a label of its own, and their Tree renders nothing that hides or
 * reorders a name.
 *
 * The `?` a stray byte becomes is pinned (`mbstring.substitute_character` 63,
 * here and in the `pick()` child): ext-mbstring writes whatever it holds.
 */

return new Test(
   description: 'Filepicker shows entry names as text and keeps every two apart — controls, bidi and zero-width characters as visible escapes, stray bytes as `?`, a markup `@` as `\u0040`, a backslash doubled — while pick() returns the real path',
   test: function () {
      // ! One private directory; every leg below writes only inside it
      $base = Temporaries::reserve('filepicker-labels');
      $Erase = function (string $target) use (&$Erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);

            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff(scandir($target) ?: [], ['.', '..']) as $entry) {
            $Erase("{$target}/{$entry}");
         }
         rmdir($target);
      };
      // ! What a terminal would obey instead of paint: an ESC that opens anything but the guides'
      //   own SGR (`\e[90m`, `\e[0m`) — a cursor move, an erase, an OSC, any other SGR (the SGR 91
      //   a `@#Red:` name renders to, a conceal) —, a C0 other than LF (TAB included), DEL, a C1
      //   in UTF-8, or a byte that is not UTF-8 (the 8-bit CSI 0x9B)
      $Hostile = static fn (string $bytes): bool => preg_match('//u', $bytes) !== 1
         || preg_match('/\e(?!\[(?:0|90)m)|[\x00-\x09\x0B-\x1A\x1C-\x1F\x7F]|\xC2[\x80-\x9F]/', $bytes) === 1;
      // ! The format characters that hide or reorder part of a name on screen: ALM, the
      //   zero-width and directional marks, the embeddings and overrides, the invisible operators,
      //   the isolates and the BOM
      $formats = '/[\x{061C}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}]/u';
      // ! Text that holds one of them raw
      $Disguised = static fn (string $bytes): bool => preg_match($formats, $bytes) === 1;
      // ! The rows a terminal shows — the components' own SGR styling (dimmed guides) removed
      $Rows = static fn (string $bytes): array => explode(
         "\n",
         rtrim((string) preg_replace('/\e\[[0-9;]*m/', '', $bytes), "\n")
      );
      // ! A path of this host as the picker shows it — the whole path is defused, step by step as
      //   defuse() does it, so a TMPDIR's own `@` before a non-alphanumeric (`t@.d`) shows as
      //   `\u0040` and its own backslash doubles too; the hostile tails below stay literal
      $Shown = static function (string $path) use ($formats): string {
         $path = Controls::escape(str_replace('\\', '\\\\', mb_scrub($path, 'UTF-8')));
         $path = (string) preg_replace_callback(
            $formats,
            static fn (array $match): string => substr((string) json_encode($match[0]), 1, -1),
            $path
         );

         // :
         return (string) preg_replace('/(?<=[*~_-])@|@(?![A-Za-z0-9\x80-\xFF])/', '\\\\u0040', $path);
      };
      // ! A label that removes the markup `@` and leaves backslashes and format characters as
      //   they are — the Control of Leg 5
      $Scrubbed = static fn (string $name): string => TemplateEscaped::scrub(Controls::escape(mb_scrub($name, 'UTF-8')));
      // ! Did the filesystem keep every name byte for byte? Or the legs below would test other names
      $Kept = static function (string $directory, array $names): bool {
         $listed = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
         sort($names, SORT_STRING);
         sort($listed, SORT_STRING);

         // :
         return $listed === $names;
      };
      // ! The rows of one level of files, in order
      $Branches = static function (array $labels): array {
         $last = count($labels) - 1;
         $rows = [];

         foreach (array_values($labels) as $index => $label) {
            $connector = $index === $last ? '└─' : '├─';
            $rows[] = "{$connector} 📄 {$label}";
         }

         return $rows;
      };

      try {
         // ! `?` per stray byte on every host — ext-mbstring's `mb_scrub()` writes whatever
         //   `mbstring.substitute_character` holds (the polyfill always writes `?`)
         ini_set('mbstring.substitute_character', '63');

         // ! The root to browse — its own name is hostile too
         $root = "{$base}/root\e[2J@#Red:R@;";
         mkdir($root);
         // ! The name on disk → the label the picker must show (in scan order)
         $entries = [
            "esc\e[2A\e[2K"                 => 'esc\u001b[2A\u001b[2K',
            "line\nfeed"                    => 'line\nfeed',
            'markup@#Red:x@;'               => 'markup\u0040#Red:x\u0040;',
            "osc@\u{9D}52;c;SGVsbG8=\x07"   => 'osc\u0040\u009d52;c;SGVsbG8=\u0007',
            'plain.md'                      => 'plain.md',
            "sgr\e[8mhidden"                => 'sgr\u001b[8mhidden',
            "stray\x9B2J"                   => 'stray?2J',
            "tab\tdel\x7F"                  => 'tab\tdel\u007f',
         ];

         // @@ Create the entries — a filesystem that keeps names in UTF-8 (APFS) refuses the stray byte only
         $refused = false;
         foreach (array_keys($entries) as $name) {
            if (@file_put_contents("{$root}/{$name}", 'x') !== false) {
               continue;
            }
            if ($name !== "stray\x9B2J") {
               throw new RuntimeException('The Filepicker fixture could not be created.');
            }

            unset($entries[$name]);
            $refused = true;
         }
         // ! Unsafe as a raw label? Every name but the ordinary one
         $unsafe = array_map(
            static fn (int|string $name): bool => $name !== 'plain.md',
            array_keys($entries)
         );
         // ? The filesystem keeps every name byte for byte
         if ($Kept($root, array_keys($entries)) === false) {
            throw new RuntimeException('The Filepicker fixture names were not kept byte for byte.');
         }

         // ! The look-alike names → the label each must get (in scan order); all UTF-8, so no
         //   filesystem refuses one, and none differs from another by case alone (APFS folds case)
         $alike = "{$base}/alike";
         mkdir($alike);
         $invisible = "hid\u{061C}\u{200B}\u{2060}\u{2066}\u{FEFF}den.txt";
         $lookalikes = [
            'a;b'                    => 'a;b',
            'a@;b'                   => 'a\u0040;b',
            $invisible               => 'hid\u061c\u200b\u2060\u2066\ufeffden.txt',
            "invoice\u{202E}fdp.php" => 'invoice\u202efdp.php',
            "lit\e"                  => 'lit\u001b',
            'lit\u001b'              => 'lit\\\\u001b',
            'user@host.txt'          => 'user@host.txt',
            'x-@y'                   => 'x-\u0040y',
            'x-y'                    => 'x-y',
         ];

         // @@ Create them
         foreach (array_keys($lookalikes) as $name) {
            if (@file_put_contents("{$alike}/{$name}", 'x') === false) {
               throw new RuntimeException('The Filepicker look-alike fixture could not be created.');
            }
         }
         // ? Kept byte for byte too
         if ($Kept($alike, array_keys($lookalikes)) === false) {
            throw new RuntimeException('The Filepicker look-alike names were not kept byte for byte.');
         }

         // ! In-memory terminal
         $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         // ! The Filepicker's own resolver on a node that carries the fixture path
         $Filepicker = new Filepicker($Input, $Output);
         $Scan = new ReflectionMethod($Filepicker, 'scan');
         $Tree = new Tree($Input, $Output);
         $Fixture = $Tree->add('fixture', value: $root);
         $Scan->invoke($Filepicker, $Fixture);
         // ! …and on the look-alike directory, in a Tree with an Output of its own
         $Lookalike = new Tree($Input, new Output('php://memory'));
         $Alike = $Lookalike->add('alike', value: $alike);
         $Scan->invoke($Filepicker, $Alike);

         // ! Wide rows — a clipped row (`…`) would fail the exact rows below for the wrong reason
         $width = isSet(Terminal::$width) === true ? Terminal::$width : null;
         try {
            if ($width !== null) {
               Terminal::$width = 400;
            }

            // @@ Control — each name as a raw label, as the Filepicker passed it before
            $raw = [];
            foreach (array_keys($entries) as $name) {
               $Raw = new Tree($Input, $Output);
               $Raw->add((string) $name);
               $painted = TemplateEscaped::render((string) $Raw->render(Tree::RETURN_OUTPUT));

               $raw[] = $Hostile($painted) === true || count($Rows($painted)) !== 1;
            }

            // @ Leg 2 — the scanned Tree, through the Output
            $Tree->render();
            // @ Leg 5 — the look-alike Tree, through its own
            $Lookalike->render();
         }
         finally {
            if ($width !== null) {
               Terminal::$width = $width;
            }
         }
         rewind($Output->stream);
         $painted = (string) stream_get_contents($Output->stream);
         rewind($Lookalike->Output->stream);
         $printed = (string) stream_get_contents($Lookalike->Output->stream);

         yield assert(
            assertion: $raw === $unsafe,
            description: 'Control: each hostile name as a raw Tree label (what the Filepicker passed before) drives the terminal — ESC moves, a C1 OSC 52 with BEL, an SGR conceal, the 8-bit CSI byte, a TAB and a DEL, its red markup as SGR 91 — or splits its row (LF); `plain.md` does neither'
         );

         // # Leg 1 — scan(): labels are text, values are the real paths
         $scanned = array_map(
            static fn (Node $Node): array => [$Node->label, $Node->value],
            $Fixture->Nodes
         );
         $expected = [];
         foreach ($entries as $name => $label) {
            $expected[] = [$label, "{$root}/{$name}"];
         }

         yield assert(
            assertion: $scanned === $expected,
            description: 'scan(): every label is the name shown as text — `\u001b[2A`, `\u0040\u009d…\u0007`, `\u0040#Red:x\u0040;`, `line\nfeed`, a complete SGR as `\u001b[8m`, `?` for the 0x9B byte, `tab\tdel\u007f`, `plain.md` as it is — while every value is the real path, byte for byte'
         );

         // # Leg 2 — render(): one row per entry, nothing for the terminal to obey
         yield assert(
            assertion: $Rows($painted) === ['▾ fixture', ...$Branches(array_values($entries))],
            description: 'render(): one row per entry — the LF name included — each showing its name as text'
         );
         yield assert(
            assertion: $Hostile($painted) === false,
            description: 'render(): the bytes the terminal gets hold no ESC but the guides\' own SGR (`\e[90m`, `\e[0m`) — no SGR 91, no conceal —, no C0 but LF (no TAB), no DEL, no C1 and no stray byte'
         );

         // # Leg 3 — an unreachable root (never created, so any byte): the warning shows it as text
         $Missing = new Filepicker($Input, new Output('php://memory'));
         $Missing->root = "{$base}/gone\e[2J\x9B@#Red:G@;";
         $missed = $Missing->pick();
         rewind($Missing->Output->stream);
         $warning = (string) stream_get_contents($Missing->Output->stream);

         yield assert(
            assertion: $missed === null
               && $warning === TemplateEscaped::render("@#Red:✖@; Filepicker: unreachable root `{$Shown($base)}/gone\\u001b[2J?\\u0040#Red:G\\u0040;`.\n"),
            description: 'An unreachable root holding ESC, a 0x9B byte and markup picks nothing and its warning shows it as text: `gone\u001b[2J?\u0040#Red:G\u0040;`, the ✖ the only red'
         );

         // # Leg 4 — pick() on an interactive terminal
         if (function_exists('proc_open') === false) {
            yield (new Assertion(description: 'pick() legs: proc_open is unavailable here'))->skip();
         }
         else {
            // ! A child on an interactive terminal (BOOTGLY_TTY=1): Esc on the first frame, then
            //   Down + Enter on the first entry; it reports both on one line
            $Script = <<<'PHP'
            require getenv('FILEPICKER_AUTOBOOT');

            use Bootgly\CLI\Terminal\Input;
            use Bootgly\CLI\Terminal\Output;
            use Bootgly\CLI\UX\Components\Filepicker;

            $Pick = static function (string $keys): array {
               $stream = fopen('php://memory', 'r+');
               fwrite($stream, $keys);
               rewind($stream);

               $Filepicker = new Filepicker(new Input($stream), new Output('php://memory'));
               $Filepicker->root = (string) getenv('FILEPICKER_ROOT');
               $picked = $Filepicker->pick();

               rewind($Filepicker->Output->stream);

               return [$picked, (string) stream_get_contents($Filepicker->Output->stream)];
            };

            [, $frame] = $Pick("\e");
            [$picked] = $Pick("\e[B\n");

            echo 'FILEPICKER ', json_encode([
               'interactive' => BOOTGLY_TTY,
               'frame' => base64_encode($frame),
               'picked' => is_string($picked) ? base64_encode($picked) : null,
            ]), "\n";
            PHP;

            $environment = getenv();
            $environment['BOOTGLY_TTY'] = '1';
            // ! Width is an assertion input (no clip of the rows matched below)
            $environment['COLUMNS'] = '400';
            $environment['LINES'] = '40';
            $environment['FILEPICKER_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
            $environment['FILEPICKER_ROOT'] = $root;

            // ! The child's stray byte is a `?` too, whatever its php.ini holds
            $process = proc_open(
               [PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'mbstring.substitute_character=63', '-r', $Script],
               [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
               $pipes,
               BOOTGLY_ROOT_BASE,
               $environment
            );
            // ? A child that never started would leave every leg below without a subject
            if (is_resource($process) === false) {
               throw new RuntimeException('The pick() probe could not spawn a PHP child process.');
            }

            // @@ Collect the report — the child ends on its own; the deadline only guards a hang
            $output = '';
            try {
               /** @var array<int,resource> $pipes */
               stream_set_blocking($pipes[1], false);
               $deadline = hrtime(true) + 30_000_000_000;

               while (feof($pipes[1]) === false && hrtime(true) < $deadline) {
                  $read = [$pipes[1]];
                  $write = null;
                  $except = null;

                  if ((int) stream_select($read, $write, $except, 0, 100_000) > 0) {
                     $output .= (string) fread($pipes[1], 65_536);
                  }
               }
            }
            finally {
               // ? Still running past the deadline — stop it by its own PID (SIGKILL)
               if (proc_get_status($process)['running'] === true) {
                  proc_terminate($process, 9);
               }
               fclose($pipes[1]);
               proc_close($process);
            }

            $report = preg_match('/^FILEPICKER (\{.*\})$/m', $output, $match) === 1
               ? json_decode($match[1], true)
               : null;
            $report = is_array($report) === true ? $report : [];
            $frame = is_string($report['frame'] ?? null) === true
               ? (string) base64_decode($report['frame'], true)
               : '';
            $picked = is_string($report['picked'] ?? null) === true
               ? base64_decode($report['picked'], true)
               : null;
            // ! The cursor hide/show the Tree writes around a navigation
            $frame = str_replace(["\e[?25l", "\e[?25h"], '', $frame);
            // ! pick() browses the resolved root (a TMPDIR may sit behind a link)
            $real = (string) realpath($root);
            $resolved = (string) realpath($base);

            yield assert(
               assertion: ($report['interactive'] ?? null) === true
                  && $Rows($frame) === [
                     'Pick a path',
                     "=> 📁 {$Shown($resolved)}/root\\u001b[2J\\u0040#Red:R\\u0040;",
                     ...array_map(
                        static fn (string $row): string => "   {$row}",
                        $Branches(array_values($entries))
                     ),
                  ],
               description: 'pick() on an interactive terminal: the root row shows the root\'s own name as text (`root\u001b[2J\u0040#Red:R\u0040;`), then one row per entry as text'
            );
            yield assert(
               assertion: $frame !== '' && $Hostile($frame) === false,
               description: 'pick(): the frame the terminal gets — root row included — holds no ESC but the guides\' own SGR (`\e[90m`, `\e[0m`) — no SGR 91, no conceal —, no C0 but LF (no TAB), no DEL, no C1 and no stray byte'
            );
            yield assert(
               assertion: $picked === "{$real}/esc\e[2A\e[2K",
               description: 'pick(): Down + Enter on the entry shown as `esc\u001b[2A\u001b[2K` returns its real path, raw ESC bytes and all'
            );
         }

         // # Leg 5 — the look-alike names: a label of their own each
         // ! The label scan() gave each name, by its real path
         $labelled = [];
         foreach ($Alike->Nodes as $Node) {
            $labelled[(string) $Node->value] = $Node->label;
         }
         $Label = static fn (string $name): null|string => $labelled["{$alike}/{$name}"] ?? null;

         yield assert(
            assertion: $Scrubbed('a@;b') === $Scrubbed('a;b')
               && $Scrubbed('x-@y') === $Scrubbed('x-y')
               && $Scrubbed('lit\u001b') === $Scrubbed("lit\e")
               && $Disguised($Scrubbed("invoice\u{202E}fdp.php")) === true
               && $Disguised($Scrubbed($invisible)) === true,
            description: 'Control: a label that removes a markup `@` and leaves backslashes and format characters as they are — `Escaped::scrub(Controls::escape(mb_scrub($name, \'UTF-8\')))` — gives `a;b` and its twin with an `@` before the `;` one label, `x-y` and its twin with one after the `-` one label, `lit\u001b` spelled with a backslash the label of `lit` + ESC, and keeps the RLO and the zero-width characters raw'
         );
         yield assert(
            assertion: $Label('a@;b') === 'a\u0040;b' && $Label('a;b') === 'a;b'
               && $Label('x-@y') === 'x-\u0040y' && $Label('x-y') === 'x-y',
            description: 'scan(): a markup `@` shows as `\u0040`, never removed — the twins of `a;b` and `x-y` are labelled `a\u0040;b` and `x-\u0040y`: two labels where removing the `@` gave one'
         );
         yield assert(
            assertion: $Label('lit\u001b') === 'lit\\\\u001b' && $Label("lit\e") === 'lit\u001b',
            description: 'scan(): a backslash doubles — `lit\u001b` spelled with a backslash is labelled `lit\\\\u001b`, so it never reads as `lit` + ESC, labelled `lit\u001b`'
         );
         yield assert(
            assertion: $Label("invoice\u{202E}fdp.php") === 'invoice\u202efdp.php'
               && $Label($invisible) === 'hid\u061c\u200b\u2060\u2066\ufeffden.txt'
               && $Disguised(implode("\n", $labelled)) === false,
            description: 'scan(): a bidi or zero-width character shows as its escape — `invoice` + RLO + `fdp.php`, which a bidi terminal shows as `invoicephp.pdf`, is labelled `invoice\u202efdp.php`, and `hidden.txt` spelled with ALM, ZWSP, WJ, LRI and BOM inside is labelled `hid\u061c\u200b\u2060\u2066\ufeffden.txt` — no label holds one raw'
         );
         yield assert(
            assertion: $Label('user@host.txt') === 'user@host.txt',
            description: 'scan(): `user@host.txt` stays as it is — an `@` between word characters opens no markup'
         );

         $scanned = array_map(
            static fn (Node $Node): array => [$Node->label, $Node->value],
            $Alike->Nodes
         );
         $expected = [];
         foreach ($lookalikes as $name => $label) {
            $expected[] = [$label, "{$alike}/{$name}"];
         }

         yield assert(
            assertion: $scanned === $expected
               && count(array_unique($labelled)) === count($lookalikes),
            description: 'scan(): every look-alike value is the real path, byte for byte, and no two look-alike names share a label'
         );
         yield assert(
            assertion: $Rows($printed) === ['▾ alike', ...$Branches(array_values($lookalikes))]
               && $Hostile($printed) === false
               && $Disguised($printed) === false,
            description: 'render(): one row per look-alike name, each showing its label, and the bytes the terminal gets hold nothing it would obey and no bidi or zero-width character raw'
         );

         // ? Last, so no assertion after it inherits its description
         if ($refused === true) {
            yield (new Assertion(description: 'stray-byte entry: this filesystem refuses a name that is not UTF-8, so `stray\x9B2J` cannot reach the picker here'))->skip();
         }
      }
      finally {
         ini_restore('mbstring.substitute_character');
         $Erase($base);
      }
   }
);
