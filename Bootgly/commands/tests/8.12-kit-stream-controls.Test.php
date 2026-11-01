<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use function array_diff;
use function array_key_exists;
use function array_map;
use function assert;
use function fclose;
use function file_put_contents;
use function getenv;
use function implode;
use function ini_restore;
use function ini_set;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_decode;
use function mkdir;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function proc_close;
use function proc_open;
use function putenv;
use function rewind;
use function rmdir;
use function scandir;
use function sprintf;
use function str_contains;
use function stream_get_contents;
use function unlink;
use RuntimeException;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\CLI\Terminal\Output;


/**
 * `kit` shows what the releases remote and git wrote as text, never as
 * terminal controls: the release notes (`Command::clean()`), and every line of
 * git's own output it streams (the callback of `ACI\VCS\Git::execute()`) — the
 * checkout and `submodule update` of a move, the fetch of `kit list -v`.
 *
 * The fixture is the 8.x kit lineage. Five kits are cloned on v1.0.0 first;
 * only then does the canonical repository publish what a hostile remote can:
 *
 * - v1.0.1, whose annotated tag message carries an OSC 52 (a clipboard write,
 *   BEL-terminated), a CSI (erase the screen), the same OSC in C1 (U+009D …
 *   U+009C), Output markup, a byte that is not UTF-8 and a tab;
 * - v1.0.2, which renames the framework submodule to a path carrying the OSC,
 *   the CSI, the C1 OSC and a stray 0x9B — repeated by git's own
 *   `Submodule path '…'` line (only that line is checked here, never the
 *   verdict kit gives a release that moves its framework);
 * - a lightweight tag whose name carries the C1 OSC (git refuses a 7-bit
 *   control in a ref name, not a C1 code point) — repeated by the fetch's
 *   `[new tag]` line.
 *
 * Positive controls come first: git itself, on the same fixture, stores the
 * notes with every raw byte and writes the tag name and the path raw — what
 * each "nothing raw" check below would see without the neutralization.
 *
 * - `kit upgrade v1.0.1`: the notes show each control as a visible escape
 *   (`\u001b`, `\u0007`, `\u009d`, `\t`), the stray byte as `?` and the markup
 *   defused — the same text the JSON document carries in `notes`; nothing raw
 *   reaches the screen and the markup never styles it.
 * - `kit upgrade v1.0.2` (no `-v`: a move always streams git's output): the
 *   `Submodule path` line shows the path escaped and the stray byte as `?`;
 *   nothing raw, nothing that is not UTF-8.
 * - `kit list -v`: the fetch's `[new tag]` line shows the C1 tag name as
 *   `\u009d … \u009c`, never raw.
 * - An unknown release name carrying an SGR and a stray byte is refused under
 *   `--json` with the name shown escaped in the reason.
 *
 * Everything runs under a private `HOME`, with no global or system git
 * configuration, against local repositories only.
 */

return new Test(
   description: '`kit` shows release notes and the git output it streams as text: controls as visible escapes, stray bytes as `?`, markup defused — on screen and in the JSON document',
   test: function () {
      // ! Every variable this spec exports, with what the process had — restored in `finally`
      $saved = [];
      $Export = static function (array $variables) use (&$saved): void {
         foreach ($variables as $name => $value) {
            if (array_key_exists($name, $saved) === false) {
               $saved[$name] = getenv($name);
            }
            putenv($value === null ? $name : "{$name}={$value}");
         }
      };
      $Erase = function (string $target) use (&$Erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);

            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $Erase("{$target}/{$entry}");
         }
         rmdir($target);
      };

      $base = Temporaries::reserve('kit-stream-controls');

      try {
         // ! `?` per stray byte on every host — ext-mbstring's `mb_scrub()` writes whatever
         //   `mbstring.substitute_character` holds (the polyfill always writes `?`)
         ini_set('mbstring.substitute_character', '63');

         // ! A private HOME and a hermetic git: no user or system configuration,
         //   no inherited config channel, transport policy or repository location
         $home = "{$base}/home";
         mkdir($home, 0700);
         $Export([
            'HOME' => $home,
            'XDG_CONFIG_HOME' => null,
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_COUNT' => null,
            'GIT_CONFIG_PARAMETERS' => null,
            'GIT_ALLOW_PROTOCOL' => null,
            'GIT_DIR' => null,
            'GIT_WORK_TREE' => null,
            'GIT_INDEX_FILE' => null,
            'GIT_OBJECT_DIRECTORY' => null,
            'GIT_ALTERNATE_OBJECT_DIRECTORIES' => null,
            'GIT_COMMON_DIR' => null,
            'GIT_NAMESPACE' => null,
         ]);

         $fixture = (require __DIR__ . '/fixtures/kit_fixture.php')($base);
         $canon = $fixture['canon'];
         $Clone = $fixture['clone'];
         // ! The framework commit both hostile releases pin — already in every kit's submodule
         $pin = $fixture['shas']['v2.0.0'];

         // ! Plain git — no shell, no Bootgly engine, messages in English:
         //   the exit status and both streams as git wrote them
         $Git = static function (string $directory, array $arguments): array {
            $environment = getenv();
            $environment['LC_ALL'] = 'C';
            $pipes = [];
            $process = proc_open(
               [
                  'git', '-C', $directory,
                  '-c', 'user.name=Bootgly', '-c', 'user.email=tests@bootgly.local',
                  '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false',
                  ...$arguments,
               ],
               [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
               $pipes,
               null,
               $environment
            );
            // ? A git that never ran would leave every control below vacuous
            if (is_resource($process) === false) {
               throw new RuntimeException('The spec could not start git.');
            }
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            // :
            return [proc_close($process), $output];
         };
         // ! The command bound to a fixture kit and the canonical repository
         $Bind = static function (string $kit) use ($canon): KitCommand {
            return new class ($kit, $canon) extends KitCommand {
               public function __construct (string $kit, string $repository)
               {
                  parent::__construct();
                  $this->kit = $kit;
                  $this->repository = $repository;
               }

               // ! The registry and the pid files belong to the process's own kit, not the fixture's
               protected function scan (): array
               {
                  return [];
               }
            };
         };
         // ! One run, its Output captured: the result and every byte the terminal would get
         $Probe = static function (KitCommand $Command, array $arguments, array $options): array {
            $Host = new Output('php://memory');
            $Terminal = CLI->Terminal;
            $Restore = $Terminal->Output;
            $Terminal->Output = $Host;
            try {
               $result = $Command->run($arguments, $options);
            }
            finally {
               $Terminal->Output = $Restore;
            }
            rewind($Host->stream);

            // :
            return [$result, (string) stream_get_contents($Host->stream)];
         };
         // ! What would drive the terminal once Bootgly's own styling (complete
         //   SGR sequences) is set aside: a C0 control but the tab and the line
         //   feed (git's own lines keep their tabs), DEL, a C1 code point
         $Raw = static function (string $text): bool {
            $text = preg_replace('/\e\[[0-9;]*m/', '', $text) ?? '';

            // :
            return preg_match('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', $text) === 1;
         };

         // # The kits — cloned BEFORE the hostile releases exist, so every fetch brings them as new
         $kits = [];
         foreach (['notes-json', 'notes-shown', 'path', 'listed', 'control'] as $name) {
            $kits[$name] = $Clone("kit-{$name}", 'refs/tags/v1.0.0');
         }

         // # What a hostile remote publishes
         // ! An OSC 52 (a clipboard write, BEL-terminated), a CSI (erase the screen)
         //   and the same OSC in C1 — U+009D … U+009C, the UTF-8 form a terminal obeys
         $OSC = "\e]52;c;SGVsbG8=\x07";
         $CSI = "\e[2J";
         $C1 = "\u{9D}52;c;SGVsbG8=\u{9C}";

         // ! v1.0.1 — release notes with every one of them, markup, a stray byte and a tab
         $lines = [
            'Hostile notes',
            '',
            "OSC [{$OSC}] CSI [{$CSI}] C1 [{$C1}]",
            "MARK [@#red:MARK@;] stray [\xFF] TAB [\t]",
            'end',
         ];
         $message = implode("\n", $lines);
         file_put_contents("{$base}/notes.txt", "{$message}\n");
         file_put_contents("{$canon}/README.md", "# Kit v1.0.1\n");
         // ! The exit status of every git command that publishes
         $published = [];
         $published[] = $Git($canon, ['add', 'README.md'])[0];
         $published[] = $Git($canon, ['commit', '--quiet', '-m', 'v1.0.1'])[0];
         $published[] = $Git($canon, ['tag', '-a', '--cleanup=verbatim', '-F', "{$base}/notes.txt", 'v1.0.1'])[0];
         // ! v1.0.2 — the framework submodule renamed to a hostile path (the stray 0x9B is no UTF-8)
         $hostile = "B{$OSC}{$CSI}{$C1}\x9Bx";
         $published[] = $Git($canon, ['mv', 'Bootgly', $hostile])[0];
         $published[] = $Git($canon, ['commit', '--quiet', '-m', 'v1.0.2'])[0];
         $published[] = $Git($canon, ['tag', '-a', '-m', 'Renamed', 'v1.0.2'])[0];
         // ! A lightweight tag named with the C1 OSC
         $tag = "t{$C1}";
         $published[] = $Git($canon, ['tag', $tag])[0];

         yield assert(
            assertion: $published === [0, 0, 0, 0, 0, 0, 0],
            description: 'probe precondition: the canonical repository publishes v1.0.1 (hostile notes), v1.0.2 (hostile submodule path) and the C1-named tag'
         );

         // # Positive controls — git itself on the same fixture: every byte raw
         [, $annotation] = $Git($canon, ['for-each-ref', '--format=%(contents)', 'refs/tags/v1.0.1']);

         yield assert(
            assertion: str_contains($annotation, "OSC [\e]52;c;SGVsbG8=\x07]") && str_contains($annotation, "CSI [\e[2J]")
               && str_contains($annotation, "C1 [\u{9D}52;c;SGVsbG8=\u{9C}]") && str_contains($annotation, 'MARK [@#red:MARK@;]')
               && str_contains($annotation, "stray [\xFF]") && str_contains($annotation, "TAB [\t]"),
            description: 'positive control: git stores the v1.0.1 annotation with the raw ESC, BEL, C1, markup, stray byte and tab'
         );

         [$fetched, $fetch] = $Git($kits['control'], ['fetch', '--no-recurse-submodules', 'origin', '+refs/tags/*:refs/tags/*']);
         [$checked] = $Git($kits['control'], ['-c', 'advice.detachedHead=false', 'checkout', '--quiet', 'refs/tags/v1.0.2', '--']);
         [$updated, $update] = $Git($kits['control'], ['submodule', 'update']);

         yield assert(
            assertion: $fetched === 0 && $checked === 0 && $updated === 0
               && preg_match(sprintf('/^ \* \[new tag\] +%s$/m', preg_quote("t\u{9D}52;c;SGVsbG8=\u{9C} -> t\u{9D}52;c;SGVsbG8=\u{9C}", '/')), $fetch) === 1
               && str_contains($update, "Submodule path 'B\e]52;c;SGVsbG8=\x07\e[2J\u{9D}52;c;SGVsbG8=\u{9C}\x9Bx': checked out '{$pin}'"),
            description: 'positive control: plain git writes the C1 tag name in its fetch and the hostile path in its `Submodule path` line raw — ESC, BEL, C1 and the stray byte as bytes'
         );

         // # Release notes — shown escaped, the same text in the JSON document
         $expected = [
            'Hostile notes',
            '',
            'OSC [\u001b]52;c;SGVsbG8=\u0007] CSI [\u001b[2J] C1 [\u009d52;c;SGVsbG8=\u009c]',
            'MARK [#red:MARK;] stray [?] TAB [\t]',
            'end',
         ];
         [$result, $JSON] = $Probe($Bind($kits['notes-json']), ['upgrade', 'v1.0.1'], ['json' => true]);
         $document = json_decode($JSON, true);
         $document = is_array($document) ? $document : [];

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'moved'
               && ($document['target']['tag'] ?? null) === 'v1.0.1'
               && ($document['notes'] ?? null) === implode("\n", $expected),
            description: '`kit upgrade v1.0.1 --json`: `notes` carries each control as a visible escape (\u001b, \u0007, \u009d, \t), the stray byte as `?` and the markup defused'
         );

         [$result, $shown] = $Probe($Bind($kits['notes-shown']), ['upgrade', 'v1.0.1'], ['yes' => true]);
         $block = implode('', array_map(static fn (string $line): string => "   {$line}\n", $expected));

         yield assert(
            assertion: $result === true && str_contains($shown, $block),
            description: '`kit upgrade v1.0.1`: the rendered notes are the same escaped text as the JSON `notes`, line by line'
         );
         yield assert(
            assertion: $Raw($shown) === false,
            description: '`kit upgrade v1.0.1`: no raw ESC, BEL or C1 from the notes reaches the screen — only Bootgly\'s own SGR styling'
         );
         yield assert(
            assertion: preg_match('/\e\[[0-9;]*mMARK/', $shown) === 0 && str_contains($shown, 'MARK [#red:MARK;]'),
            description: '`kit upgrade v1.0.1`: the markup in the notes is defused — shown as text, it never styles the screen'
         );

         // # Git's own messages — the `Submodule path` line of a move, on the default path (no -v);
         //   the line alone is the subject, not the verdict on a release that renames the framework
         [, $moved] = $Probe($Bind($kits['path']), ['upgrade', 'v1.0.2'], ['yes' => true]);
         $path = 'B\u001b]52;c;SGVsbG8=\u0007\u001b[2J\u009d52;c;SGVsbG8=\u009c?x';

         yield assert(
            assertion: str_contains($moved, "   Submodule path '{$path}': checked out '{$pin}'\n"),
            description: '`kit upgrade v1.0.2`: the `Submodule path` line shows the hostile path with every control as a visible escape and the stray byte as `?`'
         );
         yield assert(
            assertion: $Raw($moved) === false && preg_match('//u', $moved) === 1,
            description: '`kit upgrade v1.0.2`: no raw ESC, BEL or C1 reaches the screen, and no byte that is not UTF-8'
         );

         // # The fetch of `kit list -v` — the C1-named tag arrives as a new tag
         $Lister = $Bind($kits['listed']);
         $Lister->verbosity = 1;
         [$result, $listed] = $Probe($Lister, ['list'], ['v' => 1]);
         $escaped = 't\u009d52;c;SGVsbG8=\u009c';

         yield assert(
            assertion: $result === true
               && preg_match(sprintf('/^ +\* \[new tag\] +%s$/m', preg_quote("{$escaped} -> {$escaped}", '/')), $listed) === 1
               && $Raw($listed) === false,
            description: '`kit list -v`: the fetch\'s `[new tag]` line shows the C1 tag name as \u009d … \u009c, and no raw C1 reaches the screen'
         );

         // # An unknown name — echoed in the reason with controls escaped and the stray byte scrubbed
         [$result, $JSON] = $Probe($Bind($kits['listed']), ['upgrade', "ma@in\e[31m\xFFx"], ['json' => true]);
         $document = json_decode($JSON, true);
         $document = is_array($document) ? $document : [];

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'refused'
               && ($document['reason'] ?? null) === 'ma@in\u001b[31m?x is not a release name.',
            description: 'an unknown release name with an SGR and a stray byte is refused under --json as `ma@in\u001b[31m?x`: the control escaped, the byte `?`, the plain `@` kept'
         );
      }
      finally {
         ini_restore('mbstring.substitute_character');
         // ! Nothing this spec exported outlives it
         foreach ($saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
         }
         $Erase($base);
      }
   }
);
