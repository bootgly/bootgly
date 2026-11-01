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


use const BOOTGLY_ROOT_DIR;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const PHP_BINARY;
use function array_diff;
use function array_filter;
use function array_slice;
use function array_values;
use function assert;
use function chmod;
use function count;
use function explode;
use function fclose;
use function feof;
use function file_get_contents;
use function file_put_contents;
use function fread;
use function function_exists;
use function getenv;
use function ini_restore;
use function ini_set;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_encode;
use function mb_scrub;
use function microtime;
use function mkdir;
use function preg_match;
use function preg_replace;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function putenv;
use function rewind;
use function rmdir;
use function scandir;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;
use function strlen;
use function substr;
use function substr_count;
use function trim;
use function unlink;
use ReflectionMethod;
use RuntimeException;

use const Bootgly\ABI\BOOTSTRAP_FILENAME;
use const Bootgly\CLI;
use Bootgly\ABI\Code\__String\Controls;
use Bootgly\ABI\Templates\Template\Escaped;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\ACI\VCS\Git;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Output\Region;


/**
 * `projects` shows git's output, and the platform release it lands on, as
 * text. `ACI\VCS\Git` hands each line to its callback with every control
 * character escaped visibly and a stray byte as `?`; `prepare()` and `align()`
 * run git through it — no shell, `LC_ALL=C` — and the release name reaches
 * the "pinned past a release" row through `clean()`. `prepare()` updates the
 * platform submodules under the network deadline and never under the
 * transfer allow-list.
 *
 * Every hostile line comes from one payload file, `M9 <label>[<bytes>]` per
 * line: OSC 52 (the clipboard), DCS, APC, an erase, a BEL, the C1 OSC and C1
 * CSI code points, a stray 0x9B byte (a CSI to a terminal that is not in UTF-8
 * mode) and Output markup. Every child runs with no global or system git
 * configuration, a private HOME and TMPDIR, and `LC_ALL=POSIX`: a hook that
 * reports `LC_ALL=C` saw the engine's locale, never the caller's. The `?` is
 * pinned: ext-mbstring's `mb_scrub()` writes whatever
 * `mbstring.substitute_character` holds, so this process sets it to 63 and
 * every PHP child runs with `-d mbstring.substitute_character=63`.
 *
 * - Leg 1 — `projects import` through a fake `git` first on PATH, which prints
 *   the payloads on stderr for `clone` and exits 128: each line arrives as
 *   text, "Could not clone" follows — the URL as `clean()` shows it, so a
 *   TMPDIR's own `@` before punctuation goes — and nothing raw reaches the
 *   output.
 * - Leg 2 — `align()`, by reflection, nested in a Region as the Wizard nests
 *   it, on a kit whose platform submodule has a `post-checkout` hook printing
 *   the commit it landed on, the payloads and `LC_ALL`: the release checks
 *   out, the hook's lines arrive as text behind the region's guide, and its git
 *   ran under `LC_ALL=C` — the shell path `align()` used before inherited the
 *   caller's locale.
 * - Leg 3 — a fixture kit end to end: `projects import --platform=console`
 *   initializes a `Console` platform pinned one commit past its release
 *   `v1.0.1-<C1 OSC>`. The same hook, laid by `init.templateDir`, fires in the
 *   submodule update (`prepare()`) and in the checkout to the release
 *   (`align()`), both shown as text under `LC_ALL=C`, and the row reads
 *   `using v1.0.1-\u009d52;c;SGVsbG8=\u009c.`
 * - Leg 4 — `prepare()`'s contract with git, through the fake of leg 1, which
 *   logs its arguments and `GIT_ALLOW_PROTOCOL` on every call and fails a
 *   `submodule` one: on a kit with a `.gitmodules`, `projects import
 *   --platform=console` runs `git -c http.lowSpeedLimit=1000 -c
 *   http.lowSpeedTime=20 submodule update --init -- Console` — the network
 *   deadline, `--` before the targets — with no `GIT_ALLOW_PROTOCOL` (never
 *   `transfer()`: an allow-list naming `file` lifts git's
 *   `protocol.file.allow=user`), and refuses with "Run manually: …"; with no
 *   `git` on PATH, the refusal says "git was not found on PATH".
 *
 * Each hostile fixture is first run without Bootgly — the fake, the hook,
 * plain git under the leg 3 environment: its bytes really are emitted raw, and
 * its locale is POSIX, so what ProjectsCommand shows is ProjectsCommand's.
 */

return new Test(
   description: '`projects` shows git\'s output and the platform release name as text: controls escaped, a stray byte as `?`, markup inert, git under `LC_ALL=C`; the submodule update runs under the network deadline, never the transfer allow-list',
   // ? Nothing to spawn
   skip: function_exists('proc_open') === false,
   test: function () {
      // ! The real git — the fixtures, the controls and the fake's target
      $binary = Git::locate();
      yield assert(
         assertion: $binary !== null,
         description: 'probe precondition: git is on PATH'
      );
      // ?
      if ($binary === null) {
         return;
      }

      // ! Runner — an explicit environment (`null` inherits this process's),
      //   stdin from /dev/null, both streams in one pipe, and a time guard: a
      //   child that hangs is killed and reported as status -1
      $Run = static function (array $command, null|array $environment, string $cwd): array {
         /** @var list<string> $command */
         $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $cwd,
            $environment
         );
         // ? A child that never started would make every "nothing raw" leg true
         if (is_resource($process) === false) {
            throw new RuntimeException('The probe could not spawn a child process.');
         }

         /** @var array<int,resource> $pipes */
         stream_set_blocking($pipes[1], false);
         $output = '';
         $expired = false;
         $deadline = microtime(true) + 120;
         // @@ Drain the pipe until it closes or the deadline passes
         while (feof($pipes[1]) === false) {
            if (microtime(true) > $deadline) {
               $expired = true;
               proc_terminate($process, 9);

               break;
            }

            $read = [$pipes[1]];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
               break;
            }
            $output .= (string) fread($pipes[1], 65536);
         }
         fclose($pipes[1]);

         $status = proc_close($process);

         // :
         return [$expired === true ? -1 : $status, $output];
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
      // ! A path as `sh` reads it — single-quoted
      $Quote = static function (string $path): string {
         $path = str_replace("'", "'\\''", $path);

         return "'{$path}'";
      };
      // ! The command's own messages without their SGR (a raw erase or OSC stays)
      $Plain = static fn (string $output): string => (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
      // ! The tail of an output for a failing description — JSON-escaped, so no
      //   byte of it drives the terminal that shows the report
      $Show = static fn (string $output): string => (string) json_encode(
         substr($output, -1200),
         JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES
      );
      // ! A path of this host as `clean()` shows it — UTF-8, the zero-width and
      //   bidi format characters dropped, controls escaped, every markup `@`
      //   gone: the whole URL is defused, so a TMPDIR's own `@` before
      //   punctuation (`t@.d`) goes too
      $Shown = static fn (string $path): string => Escaped::scrub(Controls::escape(
         (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}]/u', '', mb_scrub($path, 'UTF-8'))
      ));
      // ! Whether `$block` stands in `$rows` as consecutive rows
      $Contains = static function (array $rows, array $block): bool {
         $size = count($block);
         for ($index = 0, $last = count($rows) - $size; $index <= $last; $index++) {
            if (array_slice($rows, $index, $size) === $block) {
               return true;
            }
         }

         return false;
      };
      // ! The lines of a log — none before its first write
      $Lines = static function (string $log): array {
         // ?
         if (is_file($log) === false) {
            return [];
         }
         $content = trim((string) file_get_contents($log));

         // :
         return $content === '' ? [] : explode("\n", $content);
      };

      // ! The payloads — label => [the bytes, as they must be shown]
      $payloads = [
         'OSC52' => ["\e]52;c;SGVsbG8=\x07", '\u001b]52;c;SGVsbG8=\u0007'],
         'DCS' => ["\eP+q544e\e\\", '\u001bP+q544e\u001b\\'],
         'APC' => ["\e_Gi=1;a=q\e\\", '\u001b_Gi=1;a=q\u001b\\'],
         'ED' => ["\e[2J", '\u001b[2J'],
         'BEL' => ["\x07", '\u0007'],
         'C1OSC' => ["\u{9D}52;c;SGVsbG8=\u{9C}", '\u009d52;c;SGVsbG8=\u009c'],
         'C1CSI' => ["\u{9B}31m", '\u009b31m'],
         'BYTE' => ["\x9B2J", '?2J'],
         'MARK' => ['@#red:MARK@;', '@#red:MARK@;'],
      ];
      // ! What a payload leaves when it passes raw: an OSC, DCS or APC
      //   introducer, the erase, a BEL, a C1 code point, a byte that is not UTF-8
      $kinds = ['OSC', 'DCS', 'APC', 'ED', 'BEL', 'C1', 'not UTF-8'];
      $Raw = static function (string $output): array {
         $found = [];
         foreach (['OSC' => "\e]", 'DCS' => "\eP", 'APC' => "\e_", 'ED' => "\e[2J", 'BEL' => "\x07"] as $kind => $bytes) {
            if (str_contains($output, $bytes) === true) {
               $found[] = $kind;
            }
         }
         if (preg_match('/\xC2[\x80-\x9F]/', $output) === 1) {
            $found[] = 'C1';
         }
         if (preg_match('//u', $output) !== 1) {
            $found[] = 'not UTF-8';
         }

         return $found;
      };

      $directory = Temporaries::reserve('project-git-stream');

      try {
         // ! `?` per stray byte on every host — ext-mbstring's `mb_scrub()` writes
         //   whatever `mbstring.substitute_character` holds (the polyfill always
         //   writes `?`); every PHP child below gets the same pin
         ini_set('mbstring.substitute_character', '63');

         $home = "{$directory}/home";
         $temp = "{$directory}/tmp";
         $shim = "{$directory}/shim";
         mkdir($home, 0o700);
         mkdir($temp, 0o700);
         mkdir($shim, 0o700);

         // ! The payload file, and the rows it must show as
         $payload = "{$directory}/payload.txt";
         $lines = '';
         $shown = [];
         foreach ($payloads as $label => [$bytes, $text]) {
            $lines .= "M9 {$label}[{$bytes}]\n";
            $shown[] = "M9 {$label}[{$text}]";
         }
         file_put_contents($payload, $lines);
         $expected = json_encode($shown, JSON_UNESCAPED_SLASHES);

         // ! Every child: git with no global or system configuration, a private
         //   HOME and TMPDIR, a locale that is not C, and a width no message
         //   is clipped at
         $PATH = getenv('PATH');
         $PATH = $PATH === false || $PATH === '' ? '/usr/local/bin:/usr/bin:/bin' : $PATH;
         $environment = [
            'PATH' => $PATH,
            'HOME' => $home,
            'TMPDIR' => $temp,
            'LC_ALL' => 'POSIX',
            'COLUMNS' => '1000',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
         ];
         // ! Every PHP child: no JIT, and the stray byte's `?` pinned as above
         $interpreter = [PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'mbstring.substitute_character=63'];
         // ! Pinned identity: no fixture commit depends on the machine's git
         //   config, and none is signed — `$Fixture` runs the real git for them
         $identity = [
            '-c', 'user.name=Bootgly', '-c', 'user.email=tests@bootgly.local',
            '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false',
         ];
         $Fixture = static fn (array $arguments): array => $Run([$binary, ...$arguments], $environment, $directory);
         // ! A kit — an entry point naming its directory the working one, and
         //   the empty registry that marks it prepared: no resource boot, no
         //   shipped examples
         $root = BOOTGLY_ROOT_DIR;
         $Kit = static function (string $kit) use ($root): void {
            mkdir("{$kit}/projects", 0o700, true);
            file_put_contents("{$kit}/bootgly", <<<PHP
            <?php
            define('BOOTGLY_WORKING_BASE', __DIR__);
            define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);
            (include '{$root}autoboot.php') || exit(1);

            PHP);
            file_put_contents("{$kit}/projects/Bootgly.projects.php", "<?php\n\nreturn [];\n");
         };
         // ! A `post-checkout` hook: the commit it landed on, the payloads, and
         //   the locale its git ran under
         $hook = <<<SH
         #!/bin/sh
         printf 'M9 CHECKOUT %s\\n' "\$2"
         cat {$Quote($payload)}
         printf 'LC_ALL=%s\\n' "\${LC_ALL-(unset)}"

         SH;
         // ! The rows LC_ALL is reported in
         $Locales = static fn (array $rows): array => array_values(array_filter(
            $rows,
            static fn (string $row): bool => str_starts_with($row, 'LC_ALL=')
         ));

         // ---

         // # Leg 1 — `projects import` through a fake `git` first on PATH
         $kit = "{$directory}/kit";
         $Kit($kit);
         // ! What the fake logs on every call: its arguments, and the transport
         //   allow-list it was given (`(unset)` when none — leg 4)
         $calls = "{$directory}/calls.log";
         $protocols = "{$directory}/protocols.log";
         $fake = "{$shim}/git";
         file_put_contents($fake, <<<SH
         #!/bin/sh
         printf '%s\\n' "\$*" >> {$Quote($calls)}
         printf 'GIT_ALLOW_PROTOCOL=%s\\n' "\${GIT_ALLOW_PROTOCOL-(unset)}" >> {$Quote($protocols)}
         for argument in "\$@"; do
            if [ "\$argument" = clone ]; then
               cat {$Quote($payload)} >&2
               exit 128
            fi
            if [ "\$argument" = submodule ]; then
               exit 1
            fi
         done
         exec {$Quote($binary)} "\$@"

         SH);
         chmod($fake, 0o700);
         $shimmed = [...$environment, 'PATH' => "{$shim}:{$PATH}"];

         // @ Control — the fake, run by hand, prints every payload raw
         [$status, $output] = $Run(
            [$fake, 'clone', '--', 'file:///nowhere.invalid.git', "{$directory}/nowhere"],
            $shimmed,
            $directory
         );
         $found = json_encode($Raw($output));
         yield assert(
            assertion: $status === 128 && $Raw($output) === $kinds,
            description: "control: the fake `git clone`, run by hand, exits 128 and prints every payload raw — found {$found} (exit {$status})"
         );
         $before = count($Lines($calls));

         // @ The import — the clone goes to the fake, which fails it
         $URL = "file://{$directory}/hostile.git";
         [$status, $output] = $Run(
            [...$interpreter, "{$kit}/bootgly", 'projects', 'import', $URL, 'FixStream', '--yes', '--platform=none', '--interfaces=CLI'],
            $shimmed,
            $kit
         );
         $logged = $Lines($calls);
         $last = (string) ($logged[count($logged) - 1] ?? '');
         $excerpt = $Show($output);
         yield assert(
            assertion: $status !== 0
               && count($logged) === $before + 1
               && str_starts_with($last, "-c http.lowSpeedLimit=1000 -c http.lowSpeedTime=20 clone -- {$URL} {$temp}/bootgly-import-")
               && str_contains($Plain($output), "Could not clone {$Shown($URL)}."),
            description: "`projects import` clones once through the `git` first on PATH and, on its exit 128, says \"Could not clone {$Shown($URL)}.\" (exit {$status}): {$excerpt}"
         );
         yield assert(
            assertion: $Contains(explode("\n", $output), $shown),
            description: "the clone's lines reach the output as text, row for row: {$expected} — got: {$excerpt}"
         );
         $found = json_encode($Raw($output));
         yield assert(
            assertion: $Raw($output) === [],
            description: "no payload reaches the output raw — no OSC, DCS or APC introducer, erase, BEL or C1, and every byte is UTF-8 — found {$found}"
         );

         // ---

         // # Leg 2 — `align()` on a platform submodule whose `post-checkout` hook speaks
         $platform = "{$directory}/platform";
         $super = "{$directory}/super";
         $sub = "{$super}/Sub";
         $Fixture(['init', '--quiet', '-b', 'main', $platform]);
         file_put_contents("{$platform}/f.txt", "c1\n");
         $Fixture(['-C', $platform, 'add', 'f.txt']);
         $Fixture(['-C', $platform, ...$identity, 'commit', '--quiet', '-m', 'c1']);
         $Fixture(['-C', $platform, ...$identity, 'tag', '-a', 'v1.0.0', '-m', 'v1.0.0']);
         file_put_contents("{$platform}/f.txt", "c2\n");
         $Fixture(['-C', $platform, 'add', 'f.txt']);
         $Fixture(['-C', $platform, ...$identity, 'commit', '--quiet', '-m', 'c2']);
         $Fixture(['init', '--quiet', '-b', 'main', $super]);
         $Fixture(['-C', $super, ...$identity, 'commit', '--quiet', '--allow-empty', '-m', 'base']);
         $Fixture(['-C', $super, ...$identity, '-c', 'protocol.file.allow=always', 'submodule', 'add', '--quiet', $platform, 'Sub']);
         $Fixture(['-C', $super, ...$identity, 'commit', '--quiet', '-m', 'add']);
         [, $tagged] = $Fixture(['-C', $sub, 'rev-parse', 'refs/tags/v1.0.0^{commit}']);
         [, $pinned] = $Fixture(['-C', $sub, 'rev-parse', 'HEAD']);
         [, $hooks] = $Fixture(['-C', $sub, 'rev-parse', '--git-path', 'hooks']);
         $tagged = trim($tagged);
         $pinned = trim($pinned);
         $hooks = trim($hooks);
         $hooks = str_starts_with($hooks, '/') === true ? $hooks : "{$sub}/{$hooks}";
         if (is_dir($hooks) === false) {
            mkdir($hooks, 0o700, true);
         }
         file_put_contents("{$hooks}/post-checkout", $hook);
         chmod("{$hooks}/post-checkout", 0o700);

         $Align = new ReflectionMethod(ProjectsCommand::class, 'align');
         $Command = new ProjectsCommand;
         // ! The Wizard's region: a guide every row re-enters after
         $gutter = '│ ';
         $Host = new Output('php://memory');
         $Region = new Region($Host->stream, $gutter, 2);
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;

         // ! This process's environment is the one `align()` runs git under —
         //   and the one a shell would hand down: the same configuration
         //   channels as the children, nothing that points git elsewhere, POSIX
         $previous = $Export([
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'HOME' => $home,
            'LC_ALL' => 'POSIX',
            'GIT_CONFIG_COUNT' => null,
            'GIT_CONFIG_PARAMETERS' => null,
            'GIT_DIR' => null,
            'GIT_WORK_TREE' => null,
            'GIT_INDEX_FILE' => null,
            'GIT_OBJECT_DIRECTORY' => null,
            'GIT_ALTERNATE_OBJECT_DIRECTORIES' => null,
            'GIT_COMMON_DIR' => null,
            'GIT_NAMESPACE' => null,
            'GIT_PREFIX' => null,
         ]);
         try {
            // @ Control — the hook, run by hand in this environment
            [$status, $control] = $Run(["{$hooks}/post-checkout", $pinned, $tagged, '1'], null, $directory);

            // @ The correction, nested in the region
            $Terminal->Output = $Region;
            $aligned = $Align->invoke($Command, "{$super}/", 'Sub');
         }
         finally {
            $Terminal->Output = $Restore;
            $Export($previous);
         }

         $found = json_encode($Raw($control));
         $reported = json_encode($Locales(explode("\n", $control)));
         yield assert(
            assertion: $status === 0 && $Raw($control) === $kinds && $Locales(explode("\n", $control)) === ['LC_ALL=POSIX'],
            description: "control: the hook, run by hand in the environment `align()` inherits, prints every payload raw and reports LC_ALL=POSIX — found {$found}, reported {$reported} (exit {$status})"
         );

         rewind($Host->stream);
         $painted = (string) stream_get_contents($Host->stream);
         $nested = [];
         foreach (explode("\n", $painted) as $index => $row) {
            $nested[] = $index > 0 && str_starts_with($row, $gutter) === true ? substr($row, strlen($gutter)) : $row;
         }
         [, $landed] = $Fixture(['-C', $sub, 'rev-parse', 'HEAD']);
         $landed = trim($landed);
         $excerpt = $Show($painted);
         $returned = json_encode($aligned);
         yield assert(
            assertion: $aligned === 'v1.0.0' && $landed === $tagged && $tagged !== $pinned,
            description: "align() lands the submodule on the release (v1.0.0, {$tagged}) from its pin one commit past it — returned {$returned}, HEAD {$landed}"
         );
         yield assert(
            assertion: $Contains($nested, ["M9 CHECKOUT {$tagged}", ...$shown]) && $Raw($painted) === [],
            description: "the hook's lines of that checkout reach the Output as text, row for row ({$expected}), nothing raw: {$excerpt}"
         );
         $reported = json_encode($Locales($nested));
         yield assert(
            assertion: $Locales($nested) === ['LC_ALL=C'],
            description: "the checkout's git runs under LC_ALL=C — the engine's, not the caller's POSIX a shell would hand down — reported {$reported}"
         );
         $breaks = substr_count($painted, "\n");
         yield assert(
            assertion: $breaks >= count($shown) + 2 && substr_count($painted, "\n{$gutter}") === $breaks,
            description: "every row of the hook re-enters the current Output's region after its guide ({$breaks} breaks): {$excerpt}"
         );

         // ---

         // # Leg 3 — a kit end to end: a `Console` platform pinned one commit
         //   past a release whose tag name carries a C1 OSC
         $release = "v1.0.1-\u{9D}52;c;SGVsbG8=\u{9C}";
         $bootstrap = BOOTSTRAP_FILENAME;
         $console = "{$directory}/console";
         $kit = "{$directory}/kit-platform";
         $template = "{$directory}/template";
         $Fixture(['init', '--quiet', '-b', 'main', $console]);
         file_put_contents("{$console}/{$bootstrap}", "<?php\n\nreturn true;\n");
         $Fixture(['-C', $console, 'add', $bootstrap]);
         $Fixture(['-C', $console, ...$identity, 'commit', '--quiet', '-m', 'c1']);
         $Fixture(['-C', $console, ...$identity, 'tag', '-a', $release, '-m', 'release']);
         file_put_contents("{$console}/next.txt", "next\n");
         $Fixture(['-C', $console, 'add', 'next.txt']);
         $Fixture(['-C', $console, ...$identity, 'commit', '--quiet', '-m', 'c2']);
         [, $pin] = $Fixture(['-C', $console, 'rev-parse', 'HEAD']);
         [, $released] = $Fixture(['-C', $console, 'rev-parse', "refs/tags/{$release}^{commit}"]);
         [, $tags] = $Fixture(['-C', $console, 'tag', '--list']);
         $pin = trim($pin);
         $released = trim($released);
         $Fixture(['init', '--quiet', '-b', 'main', $kit]);
         file_put_contents("{$kit}/.gitmodules", "[submodule \"Console\"]\n\tpath = Console\n\turl = {$console}\n");
         $Fixture(['-C', $kit, 'add', '.gitmodules']);
         $Fixture(['-C', $kit, 'update-index', '--add', '--cacheinfo', "160000,{$pin},Console"]);
         $Fixture(['-C', $kit, ...$identity, 'commit', '--quiet', '-m', 'kit']);
         $Kit($kit);
         mkdir("{$template}/hooks", 0o700, true);
         file_put_contents("{$template}/hooks/post-checkout", $hook);
         chmod("{$template}/hooks/post-checkout", 0o700);
         // ! The platform remote is a local path (file transport: allowed for a
         //   submodule only by configuration) and every repository git creates
         //   gets the hook — through the environment, so every git child has both
         $platformed = [
            ...$environment,
            'GIT_CONFIG_COUNT' => '2',
            'GIT_CONFIG_KEY_0' => 'protocol.file.allow',
            'GIT_CONFIG_VALUE_0' => 'always',
            'GIT_CONFIG_KEY_1' => 'init.templateDir',
            'GIT_CONFIG_VALUE_1' => $template,
         ];

         // @ Control — the tag name is raw, and plain git under that environment
         //   runs the template's hook (needs GIT_CONFIG_COUNT: git 2.31): raw
         //   payloads, POSIX
         [$cloned, $control] = $Run([$binary, 'clone', '--quiet', $console, "{$directory}/control"], $platformed, $directory);
         $found = json_encode($Raw($control));
         $reported = json_encode($Locales(explode("\n", $control)));
         yield assert(
            assertion: trim($tags) === $release
               && $cloned === 0
               && $Raw($control) === $kinds
               && $Locales(explode("\n", $control)) === ['LC_ALL=POSIX']
               && is_file("{$kit}/Console/{$bootstrap}") === false,
            description: "control: the release tag carries a raw C1 OSC, and plain `git clone` under the leg's environment runs the template hook — every payload raw, LC_ALL=POSIX — while the kit's Console is not set up — found {$found}, reported {$reported} (exit {$cloned})"
         );

         // @ The import — `--platform=console` sets the platform up first; the
         //   clone of a missing repository then fails, which is not this leg's
         [$status, $output] = $Run(
            [...$interpreter, "{$kit}/bootgly", 'projects', 'import', "file://{$directory}/missing.git", 'FixPlatform', '--yes', '--platform=console', '--interfaces=CLI'],
            $platformed,
            $kit
         );
         [, $landed] = $Fixture(['-C', "{$kit}/Console", 'rev-parse', 'HEAD']);
         $landed = trim($landed);
         $streamed = explode("\n", $output);
         $excerpt = $Show($output);
         yield assert(
            assertion: is_file("{$kit}/Console/{$bootstrap}") === true
               && $landed === $released
               && $released !== $pin,
            description: "`projects import --platform=console` initializes Console and lands it on the release ({$released}) from its pin one commit past it — HEAD {$landed} (exit {$status}): {$excerpt}"
         );
         // ! Every row naming the release — one, and the tag name in it is text
         $named = 'Console: pinned past a release — using v1.0.1-\u009d52;c;SGVsbG8=\u009c.';
         $matching = array_values(array_filter(
            explode("\n", $Plain($output)),
            static fn (string $row): bool => str_contains($row, 'pinned past a release')
         ));
         $listed = json_encode($matching, JSON_UNESCAPED_SLASHES);
         yield assert(
            assertion: $matching === [$named],
            description: "the release row shows the tag name as text, C1 escaped: \"{$named}\" — rows: {$listed}"
         );
         $reported = json_encode($Locales($streamed));
         yield assert(
            assertion: $Contains($streamed, ["M9 CHECKOUT {$pin}", ...$shown, 'LC_ALL=C'])
               && $Contains($streamed, ["M9 CHECKOUT {$released}", ...$shown, 'LC_ALL=C'])
               && $Locales($streamed) === ['LC_ALL=C', 'LC_ALL=C'],
            description: "the submodule update (`prepare()`, onto the pin) and the checkout to the release (`align()`) each stream the hook as text, row for row, under LC_ALL=C — reported {$reported}: {$excerpt}"
         );
         $found = json_encode($Raw($output));
         yield assert(
            assertion: $Raw($output) === [],
            description: "nothing reaches the output of the platform setup raw — the release name included — found {$found}"
         );

         // ---

         // # Leg 4 — `prepare()`'s contract with git: a kit with a `.gitmodules`,
         //   asked for its `Console` platform, through the fake of leg 1
         $kit = "{$directory}/kit-modules";
         $Kit($kit);
         file_put_contents("{$kit}/.gitmodules", "[submodule \"Console\"]\n\tpath = Console\n\turl = {$directory}/nowhere-console.git\n");
         $empty = "{$directory}/empty";
         mkdir($empty, 0o700);
         $import = [...$interpreter, "{$kit}/bootgly", 'projects', 'import', "file://{$directory}/missing.git", 'FixModules', '--yes', '--platform=console', '--interfaces=CLI'];
         // ! The update as the engine must run it: the network deadline
         //   (`Git::DEADLINE`), then `--` before the targets
         $update = '-c http.lowSpeedLimit=1000 -c http.lowSpeedTime=20 submodule update --init -- Console';
         $refusal = 'Could not initialize the platform submodules.';

         // @ Control — the engine's own lookup finds the fake first on the
         //   shimmed PATH and no `git` on a PATH of one empty directory; the
         //   fake, run by hand with an allow-list set, reports it and fails the
         //   update
         $previous = $Export(['PATH' => "{$shim}:{$PATH}"]);
         try {
            $lookup = [Git::locate()];
            $Export(['PATH' => $empty]);
            $lookup[] = Git::locate();
         }
         finally {
            $Export($previous);
         }
         $seen = count($Lines($protocols));
         [$status] = $Run(
            [$fake, 'submodule', 'update', '--init', '--', 'Console'],
            [...$shimmed, 'GIT_ALLOW_PROTOCOL' => 'file:https:ssh'],
            $kit
         );
         $read = array_slice($Lines($protocols), $seen);
         $found = json_encode($lookup, JSON_UNESCAPED_SLASHES);
         $reported = json_encode($read, JSON_UNESCAPED_SLASHES);
         yield assert(
            assertion: $lookup === [$fake, null]
               && $status === 1
               && $read === ['GIT_ALLOW_PROTOCOL=file:https:ssh'],
            description: "control: `Git::locate()` finds the fake first on the shimmed PATH and nothing on a PATH without git, and the fake, run by hand with GIT_ALLOW_PROTOCOL set, reports it and fails the update — found {$found}, reported {$reported} (exit {$status})"
         );
         $before = count($Lines($calls));
         $seen = count($Lines($protocols));

         // @ The import — the update goes to the fake, which fails it
         [$status, $output] = $Run($import, $shimmed, $kit);
         $made = array_slice($Lines($calls), $before);
         $read = array_slice($Lines($protocols), $seen);
         $excerpt = $Show($output);
         $listed = json_encode($made, JSON_UNESCAPED_SLASHES);
         yield assert(
            assertion: $made === [$update],
            description: "`projects import --platform=console` runs the platform submodule update once, through the `git` first on PATH, as `git {$update}` — the network deadline, then `--` before the targets — calls: {$listed}"
         );
         $reported = json_encode($read, JSON_UNESCAPED_SLASHES);
         yield assert(
            assertion: $read === ['GIT_ALLOW_PROTOCOL=(unset)'],
            description: "the submodule update runs without GIT_ALLOW_PROTOCOL — never `transfer()`, whose allow-list names `file` and would lift git's protocol.file.allow=user — reported {$reported}"
         );
         yield assert(
            assertion: $status !== 0
               && str_contains($Plain($output), "{$refusal} Run manually: git submodule update --init Console"),
            description: "the failed update is refused with \"{$refusal} Run manually: git submodule update --init Console\" (exit {$status}): {$excerpt}"
         );

         // @ No `git` on PATH — the engine cannot start, and the refusal says why
         [$status, $output] = $Run($import, [...$environment, 'PATH' => $empty], $kit);
         $missing = "{$refusal} git was not found on PATH — install git, then run: git submodule update --init Console";
         $excerpt = $Show($output);
         yield assert(
            assertion: $status !== 0 && str_contains($Plain($output), $missing),
            description: "with no `git` on PATH, the refusal says so: \"{$missing}\" (exit {$status}): {$excerpt}"
         );
      }
      finally {
         ini_restore('mbstring.substitute_character');
         $Erase($directory);
      }
   }
);
