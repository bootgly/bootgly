<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\VCS;


use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function assert;
use function chmod;
use function count;
use function file_put_contents;
use function getenv;
use function implode;
use function ini_restore;
use function ini_set;
use function is_dir;
use function is_file;
use function is_link;
use function mb_scrub;
use function mkdir;
use function preg_match;
use function putenv;
use function rmdir;
use function scandir;
use function str_contains;
use function str_repeat;
use function str_replace;
use function substr;
use function unlink;

use Bootgly\ABI\Code\__String\Controls;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\ACI\VCS;


/**
 * A callback of `Git::execute()` gets each line as text to show — UTF-8, a
 * stray byte `?`, every control character but TAB escaped visibly, a carriage
 * return dropped — while `$output`, the data channel the parsers read, keeps
 * the bytes git wrote.
 *
 * Part A — a fake `git` prints fixed hostile lines, built here byte by byte:
 * OSC 52, CSI with SGR, C1 OSC/ST/CSI in UTF-8, BEL/BS/VT/FF/DEL, a progress
 * line repainted by CR, a TAB-separated line, invalid UTF-8
 * (`x\x9B2J caf\xC3\xA9 \xFF`) and a NUL-separated record. Each line reaches
 * the callback as `Controls::escape(mb_scrub($line, 'UTF-8'), "\t")` — nothing
 * a terminal would obey, TAB raw, the stray bytes `?` — while `$output`,
 * `query()` and a callback-less `execute()` keep every byte, NUL included.
 *
 * Part B — real git, with the user's configuration channels shut and a
 * private HOME. A fixture tag named with a C1 OSC (git refuses only 7-bit
 * controls in a refname) is fetched with a callback: its `[new tag]` line
 * shows `\u009d` while `$output` holds the raw name, `Tags::probe()` lists it
 * by its exact bytes and `Tags::list()` still splits for-each-ref's NUL
 * fields. A remote whose upload-pack writes OSC 52, CSI, SGR, C1 and TAB on
 * its stderr — the sideband, relayed raw as git did before
 * `sideband.allowControlCharacters` existed (set to `true` here, so the run
 * does not depend on the git version) — reaches the callback as `remote:`
 * lines with visible escapes and TAB raw.
 */

return new Test(
   description: 'a git callback gets every line as text to show — controls escaped visibly, TAB kept, stray bytes `?` — while $output keeps the raw bytes for the parsers',
   test: function () {
      // ! One private directory; every run below writes only inside it
      $base = Temporaries::reserve('vcs-git-stream-controls');
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
      // ! Export variables (null unsets one) and hand back what they replaced — itself the restore
      $Export = static function (array $variables): array {
         $previous = [];
         foreach ($variables as $name => $value) {
            $current = getenv($name);
            $previous[$name] = $current === false ? null : $current;
            putenv($value === null ? $name : "{$name}={$value}");
         }

         return $previous;
      };
      // ! A value as `sh` reads it (single-quoted)
      $Quote = static function (string $value): string {
         $value = str_replace("'", "'\\''", $value);

         return "'{$value}'";
      };
      // ! The lines a terminal would obey: a C0 byte but TAB, DEL, a C1 code point — or not UTF-8 at all
      $Live = static fn (array $lines): array => array_values(array_filter(
         $lines,
         static fn (string $line): bool => preg_match('/[\x00-\x08\x0A-\x1F\x7F]|\xC2[\x80-\x9F]/', $line) === 1
            || preg_match('//u', $line) !== 1
      ));
      // ! The first line that carries a text — empty when none does
      $Find = static function (array $lines, string $text): string {
         foreach ($lines as $line) {
            if (str_contains($line, $text) === true) {
               return $line;
            }
         }

         return '';
      };

      try {
         // ! `?` per stray byte on every host — ext-mbstring's `mb_scrub()` writes whatever
         //   `mbstring.substitute_character` holds (the polyfill always writes `?`)
         ini_set('mbstring.substitute_character', '63');

         // # Part A — a `git` that prints fixed hostile lines (built here, never typed raw)
         $record = ['v1.0.0', 'tag', str_repeat('a', 40), 'commit', str_repeat('b', 40)];
         $hostile = [
            "osc \e]52;c;SGVsbG8=\x07 end",
            "csi \e[2J\e[H\e[31mred\e[0m",
            "c1 \u{9D}52;c;SGVsbG8=\u{9C} \u{9B}2J",
            "bel \x07 bs \x08 vt \x0B ff \x0C del \x7F",
            "Counting objects:  50% (1/2)\rCounting objects: 100% (2/2), done.",
            "col1\tcol2",
            "x\x9B2J caf\xC3\xA9 \xFF",
            implode("\0", $record),
         ];
         // ! What each line must show — written out, not computed
         $visible = [
            'osc \u001b]52;c;SGVsbG8=\u0007 end',
            'csi \u001b[2J\u001b[H\u001b[31mred\u001b[0m',
            'c1 \u009d52;c;SGVsbG8=\u009c \u009b2J',
            'bel \u0007 bs \b vt \u000b ff \f del \u007f',
            'Counting objects:  50% (1/2)Counting objects: 100% (2/2), done.',
            "col1\tcol2",
            "x?2J caf\u{E9} ?",
            implode('\u0000', $record),
         ];
         $raw = implode("\n", [...$hostile, '']);
         file_put_contents("{$base}/payload", $raw);
         $fake = "{$base}/git";
         file_put_contents($fake, <<<'SH'
         #!/bin/sh
         exec cat "$(dirname "$0")/payload"

         SH);
         chmod($fake, 0o755);
         mkdir("{$base}/tree", 0o700);

         $Git = new Git("{$base}/tree", $fake);
         $received = [];
         $Git->execute(['fetch'], static function (string $line) use (&$received): void {
            $received[] = $line;
         });
         $streamed = $Git->output;
         $queried = $Git->query(['for-each-ref']);
         $Git->execute(['log']);
         $kept = $Git->output;

         yield assert(
            assertion: $received === $visible
               && $received === array_map(
                  static fn (string $line): string => Controls::escape(mb_scrub(str_replace("\r", '', $line), 'UTF-8'), "\t"),
                  $hostile
               ),
            description: 'each line reaches the callback as Controls::escape(mb_scrub($line, \'UTF-8\'), "\t") — OSC 52, CSI, SGR, C1, BEL, BS, VT, FF, DEL and NUL as visible escapes (`\u001b]52;…\u0007`, `\u009d`, `\b`, `\u0000`), a progress carriage return dropped'
         );
         yield assert(
            assertion: count($received) === count($hostile) && $Live($received) === [],
            description: 'no callback line carries a byte a terminal would obey — no ESC, BEL, CR, NUL or other C0 but TAB, no DEL, no C1 code point, nothing that is not UTF-8'
         );
         yield assert(
            assertion: ($received[5] ?? null) === "col1\tcol2",
            description: 'TAB reaches the callback raw — a column separator, never the `\t` escape'
         );
         yield assert(
            assertion: ($received[6] ?? null) === "x?2J caf\u{E9} ?",
            description: 'a stray byte becomes `?`: `x\x9B2J caf\xC3\xA9 \xFF` — an 8-bit CSI a terminal off UTF-8 mode obeys, then 0xFF — arrives as `x?2J café ?`, the valid é kept'
         );
         yield assert(
            assertion: $streamed === $raw,
            description: 'streamed: $output keeps every byte the run wrote — ESC, C1, CR, NUL and the stray bytes — the data channel, not the shown text'
         );
         yield assert(
            assertion: $queried === substr($raw, 0, -1) && $kept === $raw,
            description: 'query() and a callback-less execute() read the same raw bytes — the NUL-separated record intact for the parsers'
         );

         // # Part B — real git: no global or system configuration, a private HOME, nothing inherited
         $home = "{$base}/home";
         $fixture = "{$base}/fixture";
         $fresh = "{$base}/fresh";
         $side = "{$base}/side";
         foreach ([$home, $fixture, $fresh, $side] as $directory) {
            mkdir($directory, 0o700);
         }
         // ! A tag named with a C1 OSC — git refuses a 7-bit control in a refname, never a C1 one
         $named = "v1.0.1-\u{9D}52;c;SGVsbG8=\u{9C}";
         // ! What a remote writes on its stderr: relayed to the client over the sideband
         $sideband = [
            "SB1 osc[\e]52;c;SGVsbG8=\x07]",
            "SB2 csi[\e[2J\e[31mred\e[0m]",
            "SB3 c1[\u{9D}52;c;SGVsbG8=\u{9C}]",
            "SB4 tab[a\tb]",
         ];
         file_put_contents("{$base}/sideband", implode("\n", [...$sideband, '']));
         // ! Run by upload-pack in place of pack-objects: the lines on stderr, then the pack
         $hook = "{$base}/hook";
         file_put_contents($hook, <<<'SH'
         #!/bin/sh
         cat "$(dirname "$0")/sideband" >&2
         exec "$@"

         SH);
         chmod($hook, 0o755);

         $previous = $Export([
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'HOME' => $home,
            'GIT_ALLOW_PROTOCOL' => null,
            'GIT_CONFIG_COUNT' => null,
            'GIT_CONFIG_PARAMETERS' => null,
         ]);
         try {
            // ! A fixture repository: one commit on `main`, tagged `v1.0.0` (annotated) and with the C1 name
            $identity = [
               '-c', 'user.name=Bootgly', '-c', 'user.email=tests@bootgly.local',
               '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false',
            ];
            $Fixture = new Git($fixture);
            $Fixture->execute(['init', '--quiet', '-b', 'main']);
            file_put_contents("{$fixture}/README", "fixture\n");
            $Fixture->execute(['add', 'README']);
            $Fixture->execute([...$identity, 'commit', '--quiet', '-m', 'first']);
            $Fixture->execute([...$identity, 'tag', '-a', 'v1.0.0', '-m', 'Stable']);
            $tagged = $Fixture->execute(['tag', $named]);
            $commit = $Fixture->resolve('HEAD');

            // @ A fresh tree fetches the fixture's tags, streamed to a callback
            $Fresh = new VCS($fresh);
            $Fresh->Git->execute(['init', '--quiet', '-b', 'main']);
            $fetched = [];
            $status = $Fresh->Git->fetch($fixture, [], static function (string $line) use (&$fetched): void {
               $fetched[] = $line;
            });
            $relayed = $Fresh->Git->output;
            $tags = $Fresh->Tags->list();
            $probed = $Fresh->Tags->probe($fixture);

            // @ A tree whose `origin` runs upload-pack with the hook — upload-pack honours the hook only
            //   from protected configuration, so it comes on upload-pack's own command line. The sideband
            //   relays every control, as git did before it masked any (`true` on git >= 2.55, ignored before)
            $Side = new Git($side);
            $Side->execute(['init', '--quiet', '-b', 'main']);
            $Side->execute(['remote', 'add', 'origin', $fixture]);
            $setting = $Quote("uploadpack.packObjectsHook={$Quote($hook)}");
            $Side->execute(['config', 'remote.origin.uploadpack', "{$Quote($Side->binary)} -c {$setting} upload-pack"]);
            $Side->execute(['config', 'sideband.allowControlCharacters', 'true']);
            $sidebanded = [];
            $transferred = $Side->fetch('origin', ['+refs/tags/v1.0.0:refs/tags/v1.0.0'], static function (string $line) use (&$sidebanded): void {
               $sidebanded[] = $line;
            });
            $carried = $Side->output;
         }
         finally {
            $Export($previous);
         }
         $announced = $Find($fetched, 'v1.0.1-');

         yield assert(
            assertion: $commit !== null && $tagged === 0 && $status === 0
               && str_contains($relayed, '[new tag]') === true && str_contains($relayed, $named) === true,
            description: 'control — real git takes a tag named `v1.0.1-<C1 OSC>` (it refuses only 7-bit controls in a refname), and its fetch writes the name raw: $output carries the C2 9D bytes'
         );
         yield assert(
            assertion: str_contains($announced, '[new tag]') === true
               && str_contains($announced, 'v1.0.1-\u009d52;c;SGVsbG8=\u009c') === true
               && count($fetched) > 1 && $Live($fetched) === [],
            description: 'the fetch streamed to a callback shows the C1 tag on its `[new tag]` line as `v1.0.1-\u009d52;c;SGVsbG8=\u009c`, and no streamed line carries a raw control'
         );
         yield assert(
            assertion: array_keys($tags) === ['v1.0.0'] && $tags['v1.0.0']['commit'] === $commit
               && $probed !== null && count($probed) === 2 && isSet($probed['v1.0.0'], $probed[$named]) === true,
            description: 'the parsers read the raw bytes: probe() lists the C1 tag by its exact name beside v1.0.0, and list() still splits for-each-ref\'s NUL fields — v1.0.0 on the fixture commit (the C1 name is no version)'
         );
         yield assert(
            assertion: $transferred === 0
               && str_contains($carried, "remote: SB1 osc[\e]52;c;SGVsbG8=\x07]") === true
               && str_contains($carried, "remote: SB2 csi[\e[2J\e[31mred\e[0m]") === true
               && str_contains($carried, "remote: SB3 c1[\u{9D}52;c;SGVsbG8=\u{9C}]") === true,
            description: 'control — a remote whose upload-pack writes OSC 52, CSI, SGR and C1 on its stderr: real git relays them raw over the sideband, as `remote:` lines in $output'
         );
         yield assert(
            assertion: $Find($sidebanded, 'remote: SB1 osc[\u001b]52;c;SGVsbG8=\u0007]') !== ''
               && $Find($sidebanded, 'remote: SB2 csi[\u001b[2J\u001b[31mred\u001b[0m]') !== ''
               && $Find($sidebanded, 'remote: SB3 c1[\u009d52;c;SGVsbG8=\u009c]') !== ''
               && $Find($sidebanded, "remote: SB4 tab[a\tb]") !== ''
               && $Live($sidebanded) === [],
            description: 'the sideband reaches the callback as text — `remote: SB1 osc[\u001b]52;c;SGVsbG8=\u0007]`, CSI and SGR as `\u001b[`, the C1 OSC as `\u009d…\u009c`, TAB raw — and no streamed line carries a raw control'
         );
      }
      finally {
         ini_restore('mbstring.substitute_character');
         $Erase($base);
      }
   }
);
