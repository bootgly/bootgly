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
use const FILE_IGNORE_NEW_LINES;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_BINARY;
use const SIGINT;
use function addcslashes;
use function array_diff;
use function array_values;
use function assert;
use function chmod;
use function count;
use function explode;
use function fclose;
use function feof;
use function file;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fread;
use function function_exists;
use function fwrite;
use function getenv;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_encode;
use function microtime;
use function mkdir;
use function posix_geteuid;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function rmdir;
use function scandir;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stream_select;
use function stream_set_blocking;
use function strpos;
use function substr;
use function trim;
use function umask;
use function unlink;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\ACI\VCS\Git;


/**
 * `projects import` screens its URL before anything is written or run, clones
 * without a shell under the transport allow-list, and stages the clone in a
 * private directory erased on every outcome the command reaches — and on a
 * Ctrl+C at the consent prompt, reached through the URL prompt (leg 10) or
 * straight from the command line (leg 10b) — driven
 * through the REAL launcher of a temporary kit, with a private `TMPDIR`/`HOME`,
 * no global or system git configuration and a pinned umask. Nothing reaches
 * the network: every URL is `*.invalid` and refused before use, or
 * `insteadOf`-mapped to a local fixture — and the legs whose URL a broken
 * screen would admit send https to a closed local proxy, git:// and ssh to
 * `false`.
 *
 * A `file://` URL with userinfo is refused like any other credential-carrying
 * URL (git ignores its authority, but keeps it as the clone's `origin`), so no
 * admitted URL holds userinfo for the display-site `redact()` to drop: that
 * call is unreachable end to end, and spec 1.5 pins `redact()` itself. The
 * legs below pin the refusal's redaction and the markup scrubbing of the
 * display sites: the `Fetching` line, the Import summary's Source row and the
 * Wizard's closing Source row (leg 7), and `Could not clone` (leg 6c).
 *
 * Legs that export `GIT_ALLOW_PROTOCOL` export `ext:file:https:ssh` — the
 * operator setting the allow-list must beat: an inherited list only narrows
 * Bootgly's, so `ext` is refused while `file` keeps working.
 */

return new Test(
   description: '`projects import` refuses credentials, helpers and plaintext URLs before any write, enforces the transport allow-list inside git and stages privately',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }

      // ! The real git — the fixtures, the controls and the shim's target
      $binary = Git::locate();
      yield assert(
         assertion: $binary !== null,
         description: 'probe precondition: git is on PATH'
      );
      // ?
      if ($binary === null) {
         return;
      }

      // ! Runner — explicit environment, stdin from /dev/null unless a leg
      //   feeds input, and a time guard: a child that hangs is killed and
      //   reported as status -1, never waited on forever
      $run = static function (array $command, array $environment, string $cwd, null|string $input = null): array {
         /** @var list<string> $command */
         $process = proc_open(
            $command,
            [
               0 => $input === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
               1 => ['pipe', 'w'],
               2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd,
            $environment
         );
         // ? A child that never started would make every "nothing ran" leg true
         if (is_resource($process) === false) {
            throw new RuntimeException('The probe could not spawn a child process.');
         }

         /** @var array<int,resource> $pipes */
         if ($input !== null) {
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
         }
         stream_set_blocking($pipes[1], false);
         stream_set_blocking($pipes[2], false);

         $output = '';
         $expired = false;
         $deadline = microtime(true) + 180;
         $open = [1 => $pipes[1], 2 => $pipes[2]];
         // @@ Drain both streams until they close or the deadline passes
         while ($open !== []) {
            if (microtime(true) > $deadline) {
               $expired = true;
               proc_terminate($process, 9);

               break;
            }

            $read = array_values($open);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
               break;
            }

            foreach ($open as $index => $pipe) {
               $chunk = fread($pipe, 65536);
               if ($chunk !== false) {
                  $output .= $chunk;
               }
               if (feof($pipe) === true) {
                  fclose($pipe);
                  unset($open[$index]);
               }
            }
         }
         foreach ($open as $pipe) {
            fclose($pipe);
         }

         $status = proc_close($process);

         // :
         return [$expired === true ? -1 : $status, $output];
      };
      $erase = function (string $target) use (&$erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);
            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $erase("{$target}/{$entry}");
         }
         rmdir($target);
      };
      // ! Output without its escape codes — for text matches only
      $plain = static fn (string $output): string => (string) preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $output);
      // ! The tail of an output, for a failing description
      $show = static fn (string $output): string => (string) json_encode(
         substr($plain($output), -700),
         JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
      );
      // ! A path as `sh` reads it (single-quoted) and as an `ext::` command line
      //   reads it (`%` and a blank escaped) — or a payload under a TMPDIR with
      //   either never runs, and the controls go red for the host, not the code
      $Quote = static function (string $path): string {
         $path = str_replace("'", "'\\''", $path);

         return "'{$path}'";
      };
      $Escape = static fn (string $argument): string => str_replace(['%', ' '], ['%%', '% '], $argument);
      // ! The Source row's value after the LAST frame titled `$title` — up to the closing border
      $Source = static function (string $output, string $title) use ($plain): array {
         $lines = explode("\n", $output);
         $start = null;
         $quoted = preg_quote($title, '/');
         foreach ($lines as $index => $line) {
            if (preg_match("/┌ +{$quoted} /u", $plain($line)) === 1) {
               $start = $index;
            }
         }
         // ?:
         if ($start === null) {
            return ['', ''];
         }

         foreach ($lines as $index => $line) {
            if ($index > $start && preg_match('/│ Source +(.+?) +│/u', $plain($line), $matches) === 1) {
               return [$line, $matches[1]];
            }
         }

         // :
         return ['', ''];
      };

      $directory = Temporaries::reserve('project-import-url');
      $kit = "{$directory}/kit";
      $temp = "{$directory}/tmp";
      $home = "{$directory}/home";
      $fixtures = "{$directory}/fixtures";

      // ! The umask every child inherits — a 077 runner would turn a 0755
      //   staging directory into 700 and the mode check into a tautology
      $mask = umask(0o022);
      try {
         // ! Kit — an entry point naming its directory the working one, nothing else
         $root = BOOTGLY_ROOT_DIR;
         mkdir($kit, 0o700);
         mkdir($temp, 0o700);
         mkdir($home, 0o700);
         mkdir($fixtures, 0o700);
         file_put_contents("{$kit}/bootgly", <<<PHP
         <?php
         define('BOOTGLY_WORKING_BASE', __DIR__);
         define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);
         (include '{$root}autoboot.php') || exit(1);

         PHP);

         // ! Every child: no agent markers (the human contract), git in one
         //   language with no global or system configuration, a private
         //   TMPDIR and HOME, a width no message is clipped at (a refusal
         //   quotes a fixture path, as long as the runner's TMPDIR makes it)
         $PATH = getenv('PATH');
         $environment = [
            'PATH' => $PATH === false || $PATH === '' ? '/usr/local/bin:/usr/bin:/bin' : $PATH,
            'HOME' => $home,
            'TMPDIR' => $temp,
            'LC_ALL' => 'C',
            'COLUMNS' => '1000',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
         ];
         $launch = static fn (array $arguments, array $extras = [], null|string $input = null): array => $run(
            [PHP_BINARY, '-d', 'opcache.jit=0', "{$kit}/bootgly", ...$arguments],
            [...$environment, ...$extras],
            $kit,
            $input
         );

         // ? The config channels every leg relies on — a git that ignores them
         //   (GIT_CONFIG_COUNT arrived in 2.31, GIT_CONFIG_GLOBAL in 2.32) runs
         //   the legs on another configuration: the controls and the backstop
         //   leg lose their setup, and the offline proxy is gone
         $channel = "{$home}/channel.gitconfig";
         file_put_contents($channel, "[bootgly]\n\tglobal = honoured\n");
         [, $global] = $run(
            [$binary, 'config', '--get', 'bootgly.global'],
            [...$environment, 'GIT_CONFIG_GLOBAL' => $channel],
            $directory
         );
         [, $counted] = $run(
            [$binary, 'config', '--get', 'bootgly.count'],
            [...$environment, 'GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'bootgly.count', 'GIT_CONFIG_VALUE_0' => 'honoured'],
            $directory
         );
         $honoured = trim($global) === 'honoured' && trim($counted) === 'honoured';
         $answers = json_encode([trim($global), trim($counted)]);
         yield assert(
            assertion: $honoured,
            description: "probe precondition: the git on PATH ({$binary}) honours GIT_CONFIG_GLOBAL and GIT_CONFIG_COUNT (git 2.32 or later) — read: {$answers}"
         );
         // ?
         if ($honoured === false) {
            return;
         }

         // ! The witnesses a kit bootstrap leaves behind
         $bootstrapped = static fn (): bool => is_dir("{$kit}/projects") === true
            || is_dir("{$kit}/storage") === true
            || is_dir("{$kit}/scripts") === true;
         // ! The staging directories left in the private TMPDIR
         $staged = static function () use ($temp): array {
            $left = [];
            foreach (array_diff((array) scandir($temp), ['.', '..']) as $entry) {
               if (str_starts_with((string) $entry, 'bootgly-import-') === true) {
                  $left[] = (string) $entry;
               }
            }

            return $left;
         };
         $registered = static function (string $name) use ($kit): bool {
            $registry = "{$kit}/projects/Bootgly.projects.php";
            $quoted = preg_quote($name, '/');

            return is_file($registry) === true
               && preg_match("/'{$quoted}'\\s*=>/", (string) file_get_contents($registry)) === 1;
         };
         // ! A URL a broken screen would admit still reaches no network: https
         //   goes through a closed local proxy, git:// and ssh run `false`
         $offline = [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.proxy',
            'GIT_CONFIG_VALUE_0' => 'http://127.0.0.1:9',
            'GIT_PROXY_COMMAND' => 'false',
            'GIT_SSH_COMMAND' => 'false',
         ];

         // ! Fixtures — three committed repositories carrying a signature: a
         //   plain one, one whose path holds Output markup (`@*:` opens bold)
         //   and one whose leaf is a valid project path (leg 10's default)
         $signature = "<?php\n\nuse Bootgly\\API\\Projects\\Project;\n\nreturn new Project(boot: static function (): void {}, name: 'Fix');\n";
         $repository = "{$fixtures}/repo";
         $markup = "{$fixtures}/Fix@*:bold";
         $named = "{$fixtures}/Fix";
         $committed = 0;
         foreach ([$repository, $markup, $named] as $fixture) {
            mkdir($fixture, 0o700);
            file_put_contents("{$fixture}/Fix.Project.php", $signature);

            $identity = ['-c', 'user.name=probe', '-c', 'user.email=probe@example.invalid', '-c', 'commit.gpgsign=false'];
            [$initialized] = $run([$binary, 'init', '--quiet', $fixture], $environment, $directory);
            [$added] = $run([$binary, '-C', $fixture, 'add', 'Fix.Project.php'], $environment, $directory);
            [$recorded] = $run([$binary, '-C', $fixture, ...$identity, 'commit', '--quiet', '-m', 'one'], $environment, $directory);
            if ($initialized === 0 && $added === 0 && $recorded === 0) {
               $committed++;
            }
         }
         yield assert(
            assertion: $committed === 3,
            description: 'probe precondition: the three fixture repositories commit'
         );

         // ---

         // @ Leg 1 — credentials in an https URL: refused before anything is
         //   written or run (the kit stays unbootstrapped: the screen runs
         //   ahead of `prepare()`), and the refusal never echoes the secret
         $failed = [];
         foreach (
            [
               'https://user:S3CRETpw@bootgly-poc.invalid/o/Fix.git' => ['S3CRETpw', 'user:'],
               'https://ghp_TOKENASUSER@bootgly-poc.invalid/o/Fix.git' => ['TOKENASUSER'],
            ] as $URL => $secrets
         ) {
            [$status, $output] = $launch(['projects', 'import', $URL, '--platform=none', '--interfaces=CLI'], $offline);
            $text = $plain($output);

            $leaked = false;
            foreach ($secrets as $secret) {
               $leaked = $leaked || str_contains($output, $secret);
            }

            if (
               $status === 0
               || str_contains($text, 'credential helper') === false
               || str_contains($text, 'https://bootgly-poc.invalid/o/Fix.git') === false
               || $leaked === true
               || str_contains($text, 'Fetching') === true
               || $bootstrapped() === true
               || $staged() !== []
            ) {
               $excerpt = $show($output);
               $failed[] = "{$URL} (exit {$status}): {$excerpt}";
            }
         }
         $failures = json_encode($failed);
         yield assert(
            assertion: $failed === [],
            description: "credentials in an https URL (user:password, token-as-user) are refused with the credential-helper hint and the redacted URL, never echo the secret, fetch nothing and leave the kit unbootstrapped — failed: {$failures}"
         );

         // @ Leg 1b — the same credential URL spelled so a screen anchored on
         //   the scheme misses it: a slash past the second one (curl dials the
         //   host after it and sends the userinfo), a leading blank or NBSP,
         //   a leading zero-width space (no blank at all: git would read an
         //   scp-like host), padded by a trailing blank — and short of a
         //   slash (`https:/…`: git dials an scp-like host `https`, and no
         //   `scheme://` leaves userinfo for the redaction to drop). Each is
         //   Malformed — refused before anything is written or run, the
         //   secret never shown
         $failed = [];
         foreach (
            [
               'https:///user:S3CRETpw@bootgly-poc.invalid/o/Fix.git',
               'https:/user:S3CRETpw@bootgly-poc.invalid/o/Fix.git',
               ' https://user:S3CRETpw@bootgly-poc.invalid/o/Fix.git',
               "\u{00A0}https://user:S3CRETpw@bootgly-poc.invalid/o/Fix.git",
               "\u{200B}https://user:S3CRETpw@bootgly-poc.invalid/o/Fix.git",
               'https://user:S3CRETpw@bootgly-poc.invalid/o/Fix.git ',
            ] as $URL
         ) {
            [$status, $output] = $launch(
               ['projects', 'import', $URL, 'FixM', '--yes', '--platform=none', '--interfaces=CLI'],
               $offline
            );
            $text = $plain($output);

            if (
               $status === 0
               || str_contains($text, 'Malformed repository URL') === false
               || str_contains($output, 'S3CRETpw') === true
               || str_contains($text, 'Fetching') === true
               || $bootstrapped() === true
               || $staged() !== []
            ) {
               $shown = json_encode($URL, JSON_UNESCAPED_SLASHES);
               $excerpt = $show($output);
               $failed[] = "{$shown} (exit {$status}): {$excerpt}";
            }
         }
         $failures = json_encode($failed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
         yield assert(
            assertion: $failed === [],
            description: "`https:///user:pw@host/…`, `https:/user:pw@host/…`, and `https://user:pw@host/…` behind a blank, an NBSP or a zero-width space or followed by a blank, are refused as Malformed — the secret never printed, nothing fetched, bootstrapped or staged — failed: {$failures}"
         );

         // @ Leg 2 — remote helpers, with `ext` allowed by every channel the
         //   caller controls: refused by the screen, nothing runs
         $witness = "{$directory}/witness-ext";
         $ext = "ext::sh -c touch% {$Escape($Quote($witness))};git% %s% {$Escape($Quote($repository))}";
         $allowed = [
            'GIT_ALLOW_PROTOCOL' => 'ext:file:https:ssh',
            'GIT_CONFIG_COUNT' => '2',
            'GIT_CONFIG_KEY_0' => 'protocol.ext.allow',
            'GIT_CONFIG_VALUE_0' => 'always',
            'GIT_CONFIG_KEY_1' => 'http.proxy',
            'GIT_CONFIG_VALUE_1' => 'http://127.0.0.1:9',
         ];
         $failed = [];
         foreach ([$ext, 'fd::0', 'https::http://bootgly-poc.invalid/o/Fix.git'] as $URL) {
            [$status, $output] = $launch(
               ['projects', 'import', $URL, 'FixH', '--yes', '--platform=none', '--interfaces=CLI'],
               $allowed
            );
            $text = $plain($output);

            if (
               $status === 0
               || str_contains($text, 'Remote helpers') === false
               || str_contains($text, 'Fetching') === true
               || file_exists($witness) === true
               || $bootstrapped() === true
               || $staged() !== []
            ) {
               $excerpt = $show($output);
               $failed[] = "{$URL} (exit {$status}): {$excerpt}";
            }
         }
         $failures = json_encode($failed);
         yield assert(
            assertion: $failed === [],
            description: "`ext::`, `fd::` and `https::` URLs are refused with the helper message under GIT_ALLOW_PROTOCOL=ext:file:https:ssh and protocol.ext.allow=always — no witness, no fetch, no bootstrap — failed: {$failures}"
         );

         // # Control — the same environment DOES run the `ext` command for
         //   plain git, so the refusal above is not vacuous
         [$status] = $run(
            [$binary, 'clone', '--quiet', $ext, "{$directory}/control-ext"],
            [...$environment, ...$allowed],
            $directory
         );
         yield assert(
            assertion: $status === 0 && file_exists($witness) === true,
            description: "control: plain git with that environment clones through `ext::` and leaves the witness (exit {$status})"
         );

         // @ Leg 4 — plaintext transports, and a scheme the screen does not
         //   know: each refused with its own message, nothing fetched
         $failed = [];
         foreach (
            [
               'http://bootgly-poc.invalid/o/Fix.git' => 'Plaintext transports',
               'git://bootgly-poc.invalid/o/Fix.git' => 'Plaintext transports',
               'rsync://x.invalid/o/r' => 'Unsupported transport',
            ] as $URL => $message
         ) {
            [$status, $output] = $launch(['projects', 'import', $URL, 'FixP', '--yes', '--platform=none', '--interfaces=CLI'], $offline);
            $text = $plain($output);

            if (
               $status === 0
               || str_contains($text, $message) === false
               || str_contains($text, 'Fetching') === true
               || $bootstrapped() === true
            ) {
               $excerpt = $show($output);
               $failed[] = "{$URL} (exit {$status}): {$excerpt}";
            }
         }
         $failures = json_encode($failed);
         yield assert(
            assertion: $failed === [],
            description: "`http://` and `git://` are refused with the plaintext message, `rsync://` with the unsupported-transport one, and nothing is fetched — failed: {$failures}"
         );

         // @ Leg 5 — the interactive prompt (an emulated TTY): an
         //   option-shaped answer is refused AT the prompt, which asks again;
         //   EOF then ends the run with no URL
         [$status, $output] = $launch(
            ['projects', 'import', '--platform=none', '--interfaces=CLI'],
            ['BOOTGLY_TTY' => '1'],
            "--upload-pack=touch x\n"
         );
         $text = $plain($output);
         $refused = strpos($text, 'Malformed repository URL');
         $required = strpos($text, 'A repository URL is required');
         $reasked = $refused !== false && strpos($text, 'Repository URL (git)', $refused) !== false;
         $excerpt = $show($output);
         yield assert(
            assertion: $status !== 0
               && $refused !== false
               && $required !== false
               && $refused < $required
               && $reasked === true
               && str_contains($text, 'Fetching') === false
               && $bootstrapped() === false,
            description: "the URL prompt refuses `--upload-pack=…` (Malformed), re-asks, and EOF ends with \"A repository URL is required\" — no fetch (exit {$status}): {$excerpt}"
         );

         // @ Leg 8 — a `file://` URL carrying userinfo is refused as
         //   credentials (git ignores its authority but keeps it as the
         //   clone's `origin`); the refusal shows it redacted and scrubbed of
         //   markup. Run before leg 3, the first leg that bootstraps the kit
         $cleaned = "file://{$fixtures}/Fix*:bold";
         [$status, $output] = $launch(
            ['projects', 'import', "file://u:S3CRET@{$markup}", 'FixR', '--platform=none', '--interfaces=CLI']
         );
         $text = $plain($output);
         $excerpt = $show($output);
         yield assert(
            assertion: $status !== 0
               && str_contains($text, 'credential helper')
               && str_contains($text, $cleaned)
               && str_contains($output, 'S3CRET') === false
               && str_contains($text, 'Fetching') === false
               && $bootstrapped() === false
               && $staged() === [],
            description: "a `file://` URL with userinfo is refused with the credential-helper hint and shown redacted and scrubbed ({$cleaned}) — the secret is never printed, nothing is fetched, bootstrapped or staged (exit {$status}): {$excerpt}"
         );

         // @ Leg 3 — the backstop inside git: an `insteadOf` rewrite the
         //   typed URL cannot show turns an admitted https URL into `ext::`,
         //   with `ext` allowed by the global config and the environment
         $rewritten = "{$directory}/witness-insteadof";
         $config = "{$home}/insteadof.gitconfig";
         // ! A subsection name keeps a backslash or a double quote only escaped
         $section = addcslashes("ext::sh -c touch% {$Escape($Quote($rewritten))};git% %s% {$Escape($Quote($repository))}", '"\\');
         file_put_contents($config, <<<INI
         [url "{$section}"]
            insteadOf = https://bootgly-poc.invalid/o/Fix.git
         [protocol "ext"]
            allow = always

         INI);
         $rewriting = ['GIT_CONFIG_GLOBAL' => $config, 'GIT_ALLOW_PROTOCOL' => 'ext:file:https:ssh'];
         [$status, $output] = $launch(
            ['projects', 'import', 'https://bootgly-poc.invalid/o/Fix.git', 'FixB', '--yes', '--platform=none', '--interfaces=CLI'],
            $rewriting
         );
         $text = $plain($output);
         $excerpt = $show($output);
         yield assert(
            assertion: $status !== 0
               && str_contains($text, 'Could not clone')
               && str_contains($text, "transport 'ext' not allowed")
               && file_exists($rewritten) === false
               && $registered('FixB') === false
               && is_dir("{$kit}/projects/FixB") === false
               && $staged() === [],
            description: "an insteadOf rewrite to `ext::` is stopped inside git (\"transport 'ext' not allowed\") while the caller exports GIT_ALLOW_PROTOCOL=ext:file:https:ssh — no witness, nothing registered or staged (exit {$status}): {$excerpt}"
         );

         // # Control — plain git with that same configuration and environment
         //   follows the rewrite and runs the command
         [$status] = $run(
            [$binary, 'clone', '--quiet', 'https://bootgly-poc.invalid/o/Fix.git', "{$directory}/control-insteadof"],
            [...$environment, ...$rewriting],
            $directory
         );
         yield assert(
            assertion: $status === 0 && file_exists($rewritten) === true,
            description: "control: plain git follows the insteadOf rewrite through `ext::` and leaves the witness (exit {$status})"
         );

         // @ Leg 6 — staging: a shim `git` first on PATH records the clone
         //   target's parent (mode, owner, path), then runs the real git
         $shim = "{$directory}/shim";
         $log = "{$directory}/staging.log";
         mkdir($shim, 0o700);
         file_put_contents("{$shim}/git", <<<SH
         #!/bin/sh
         for target in "\$@"; do :; done
         case " \$* " in
            *" clone "*) printf 'clone %s\\n' "\$(stat -c '%a %u %n' "\$(dirname "\$target")" 2>&1)" >> {$Quote($log)} ;;
         esac
         exec {$Quote($binary)} "\$@"

         SH);
         chmod("{$shim}/git", 0o700);
         $PATH = $environment['PATH'];
         $shimmed = ['PATH' => "{$shim}:{$PATH}"];
         $clones = static fn (): array => is_file($log) === true ? (array) file($log, FILE_IGNORE_NEW_LINES) : [];
         $owner = posix_geteuid();
         $parent = preg_quote($temp, '#');
         $pattern = "#^clone 700 {$owner} {$parent}/bootgly-import-\\d+-[0-9a-f]{32}\$#";

         // # A successful import
         [$status, $output] = $launch(
            ['projects', 'import', "file://{$repository}", 'FixOK', '--yes', '--platform=none', '--interfaces=CLI'],
            $shimmed
         );
         $logged = $clones();
         $excerpt = $show($output);
         yield assert(
            assertion: $status === 0
               && $registered('FixOK') === true
               && is_file("{$kit}/projects/FixOK/FixOK.Project.php") === true
               && count($logged) === 1,
            description: "`file://<fixture> FixOK --yes` clones once through PATH's git and registers the project (exit {$status}): {$excerpt}"
         );
         $first = (string) ($logged[0] ?? '');
         yield assert(
            assertion: preg_match($pattern, $first) === 1,
            description: "the staging parent is mode 700 under umask 022, owned by the euid ({$owner}) and named bootgly-import-<pid>-<32 hex> in TMPDIR — got: {$first}"
         );
         $left = json_encode($staged());
         yield assert(
            assertion: $staged() === [],
            description: "nothing stays staged after a successful import — left: {$left}"
         );

         // # An aborted import — consent answered by EOF
         [$status, $output] = $launch(
            ['projects', 'import', "file://{$repository}", 'FixAbort', '--platform=none', '--interfaces=CLI'],
            $shimmed
         );
         $text = $plain($output);
         $left = json_encode($staged());
         $excerpt = $show($output);
         yield assert(
            assertion: str_contains($text, 'Import aborted.')
               && count($clones()) === 2
               && $staged() === []
               && is_dir("{$kit}/projects/FixAbort") === false,
            description: "nothing stays staged after an aborted import (it cloned, then the consent read EOF) — left: {$left}, output: {$excerpt}"
         );

         // # A failed clone — of a missing path holding markup, shown scrubbed
         $missing = "file://{$fixtures}/Missing*:bold.git";
         [$status, $output] = $launch(
            ['projects', 'import', "file://{$fixtures}/Missing@*:bold.git", 'FixFail', '--yes', '--platform=none', '--interfaces=CLI'],
            $shimmed
         );
         $text = $plain($output);
         $left = json_encode($staged());
         $excerpt = $show($output);
         yield assert(
            assertion: $status !== 0
               && str_contains($text, "Could not clone {$missing}.")
               && str_contains($output, "\x1b[1mbold") === false
               && count($clones()) === 3
               && $staged() === [],
            description: "a failed clone says \"Could not clone {$missing}.\" (scrubbed, no ESC[1m) and leaves nothing staged — left: {$left}, output: {$excerpt}"
         );

         // # A staging directory that cannot be created fails the import —
         //   the clone never runs anywhere else. TMPDIR names a regular file:
         //   mkdir() under it fails for any uid, root included
         $file = "{$directory}/not-a-dir";
         file_put_contents($file, '');
         [$status, $output] = $launch(
            ['projects', 'import', "file://{$repository}", 'FixRO', '--yes', '--platform=none', '--interfaces=CLI'],
            [...$shimmed, 'TMPDIR' => $file]
         );
         $text = $plain($output);
         $excerpt = $show($output);
         yield assert(
            assertion: $status !== 0
               && str_contains($text, 'Could not create a private staging directory')
               && count($clones()) === 3
               && is_dir("{$kit}/projects/FixRO") === false,
            description: "an uncreatable staging directory (TMPDIR is a regular file) fails the import before any clone (exit {$status}): {$excerpt}"
         );

         // @ Leg 9 — a relative path is read against the CALLER's directory:
         //   run from the directory holding `fixtures/`, the kit elsewhere
         [$status, $output] = $run(
            [PHP_BINARY, '-d', 'opcache.jit=0', "{$kit}/bootgly", 'projects', 'import', 'fixtures/repo', 'FixRel', '--yes', '--platform=none', '--interfaces=CLI'],
            $environment,
            $directory
         );
         $excerpt = $show($output);
         yield assert(
            assertion: $status === 0
               && is_dir("{$kit}/fixtures") === false
               && $registered('FixRel') === true
               && is_file("{$kit}/projects/FixRel/FixRel.Project.php") === true,
            description: "`fixtures/repo FixRel --yes` run from the directory that holds it (not the kit) clones it from there and registers FixRel (exit {$status}): {$excerpt}"
         );

         // @ Leg 7 — the Wizard (emulated TTY): Git-remote mode (↓↓ Enter),
         //   `ext::x` refused AT the URL prompt, then a `file://` URL with
         //   markup in its path, path `FixW`, consent `y`
         [$status, $output] = $launch(
            ['projects', 'create', '--platform=none', '--interfaces=CLI'],
            ['BOOTGLY_TTY' => '1'],
            "\033[B\033[B\next::x\nfile://{$markup}\nFixW\ny\n"
         );
         $text = $plain($output);
         $helper = strpos($text, 'Remote helpers');
         $prompt = strpos($text, 'Project path');
         $excerpt = $show($output);
         yield assert(
            assertion: $status === 0
               && $helper !== false
               && $prompt !== false
               && $helper < $prompt
               && $registered('FixW') === true
               && is_file("{$kit}/projects/FixW/FixW.Project.php") === true,
            description: "the Wizard refuses `ext::x` at its URL prompt (before the path is asked) and imports the next URL (exit {$status}): {$excerpt}"
         );

         // # The display sites — the `Fetching` line, the Import summary's
         //   Source row and the Wizard's closing Source row (the LAST frame of each)
         [$summary, $imported] = $Source($output, 'Import project');
         [$closing, $reported] = $Source($output, 'Project FixW');
         $shown = json_encode([$imported, $reported], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
         yield assert(
            assertion: str_contains($text, "Fetching {$cleaned}")
               && $imported === $cleaned
               && $reported === $cleaned
               && str_contains($summary, "\x1b[1m") === false
               && str_contains($closing, "\x1b[1m") === false,
            description: "the `Fetching` line, the Import summary's Source row and the Wizard's closing Source row show the URL as text ({$cleaned}), no ESC[1m from `@*:` — Source rows: {$shown}"
         );

         // @ Leg 10 — Ctrl+C at the consent prompt of a REAL terminal (a
         //   pty). While a clone is staged, `import()` turns SIGINT into an
         //   exit(130) that skips its `finally` — the shutdown hook still
         //   erases the staged clone. The blocked read restarts on the
         //   signal, so the handler runs once a line follows it
         if (function_exists('pcntl_signal') === false) {
            yield assert(assertion: true, description: 'Skipped: pcntl is unavailable — no SIGINT handler can be installed');
         }
         else {
            // ! `projects import <arguments>` on a pty: answer the URL prompt
            //   with `$answer` (none: the URL is an argument), wait at "Import
            //   as" and count the clones it staged, then Ctrl+C and a line
            //   every 2 s until the child exits — a hang killed at the deadline.
            //   Returns [waiting, staged then, exit, staged after it, screen]
            $Interrupt = static function (array $arguments, null|string $answer) use ($kit, $environment, $plain, $staged): array {
               $before = $staged();
               /** @var list<string> $arguments */
               $process = proc_open(
                  [PHP_BINARY, '-d', 'opcache.jit=0', "{$kit}/bootgly", 'projects', 'import', ...$arguments, '--platform=none', '--interfaces=CLI'],
                  [0 => ['pty'], 1 => ['pty'], 2 => ['pty']],
                  $pipes,
                  $kit,
                  $environment
               );
               // ? A child that never started would make the clean TMPDIR vacuous
               if (is_resource($process) === false) {
                  throw new RuntimeException('The probe could not spawn a child on a pseudo-terminal.');
               }

               /** @var array<int,resource> $pipes */
               $terminal = $pipes[0];
               stream_set_blocking($terminal, false);
               $screen = '';
               // ! Read the terminal for up to `$seconds` — until `$text` shows, when given
               $Pump = static function (float $seconds, null|string $text = null) use ($terminal, &$screen, $plain): bool {
                  $deadline = microtime(true) + $seconds;
                  // @@
                  while (microtime(true) < $deadline) {
                     if ($text !== null && str_contains($plain($screen), $text) === true) {
                        return true;
                     }

                     $read = [$terminal];
                     $write = null;
                     $except = null;
                     if (stream_select($read, $write, $except, 0, 100_000) === 1) {
                        // ! A pty whose child has exited reads EIO — an end, not a notice
                        $chunk = @fread($terminal, 65536);
                        $screen .= $chunk === false ? '' : $chunk;
                        if (feof($terminal) === true) {
                           break;
                        }
                     }
                  }

                  // :
                  return $text !== null && str_contains($plain($screen), $text) === true;
               };

               if ($answer !== null) {
                  $Pump(60, 'Repository URL');
                  fwrite($terminal, "{$answer}\r");
               }
               $waiting = $Pump(60, 'Import as');
               $held = count(array_diff($staged(), $before));

               // @ Ctrl+C, then a line — the read returns and the handler exits
               proc_terminate($process, SIGINT);
               $Pump(0.5);
               $exit = null;
               $deadline = microtime(true) + 30;
               // @@ Until the child exits: a further line every 2 s, a hang killed at the deadline
               while (microtime(true) < $deadline) {
                  $state = proc_get_status($process);
                  if ($state['running'] === false) {
                     $exit = $state['signaled'] === true ? "signal {$state['termsig']}" : $state['exitcode'];

                     break;
                  }

                  // ! The child may exit between the status and the write — EIO is no failure
                  @fwrite($terminal, "x\r");
                  $Pump(2);
               }
               if ($exit === null) {
                  proc_terminate($process, 9);
                  $exit = 'hung';
               }
               foreach ($pipes as $pipe) {
                  if (is_resource($pipe) === true) {
                     fclose($pipe);
                  }
               }
               proc_close($process);

               // :
               return [$waiting, $held, $exit, array_values(array_diff($staged(), $before)), $screen];
            };

            // # Through the URL prompt — whose editor also armed the terminal's
            //   own SIGINT handler (an exit(130) as well)
            [$waiting, $held, $exit, $left, $screen] = $Interrupt([], "file://{$named}");
            $asked = str_contains($plain($screen), 'Repository URL');
            $remained = json_encode($left);
            $excerpt = $show($screen);
            yield assert(
               assertion: $asked === true && $waiting === true && $held === 1,
               description: "probe precondition: on a pty, the URL prompt takes `file://…/Fix` and the run waits at \"Import as\" with one clone staged (staged: {$held}): {$excerpt}"
            );
            yield assert(
               assertion: $exit === 130
                  && $left === []
                  && $registered('Fix') === false,
               description: "Ctrl+C at the consent prompt reached through the URL prompt exits 130 and the staged clone is erased — exit: {$exit}, left: {$remained}"
            );

            // @ Leg 10b — the DIRECT path: the URL and the path are arguments,
            //   so no prompt ever armed the terminal's handler — only
            //   `import()`'s own turns Ctrl+C into an exit; SIGINT's default
            //   action would end the process with the clone left staged
            [$waiting, $held, $exit, $left, $screen] = $Interrupt(["file://{$named}", 'FixI'], null);
            $asked = str_contains($plain($screen), 'Repository URL');
            $remained = json_encode($left);
            $excerpt = $show($screen);
            yield assert(
               assertion: $asked === false && $waiting === true && $held === 1,
               description: "probe precondition: on a pty, `projects import file://…/Fix FixI` (no --yes) reaches \"Import as\" without the URL prompt, one clone staged (staged: {$held}): {$excerpt}"
            );
            yield assert(
               assertion: $exit === 130
                  && $left === []
                  && $registered('FixI') === false
                  && is_dir("{$kit}/projects/FixI") === false,
               description: "Ctrl+C at the consent prompt of `projects import <url> <path>` exits 130 through import()'s own handler and the staged clone is erased — exit: {$exit}, left: {$remained}"
            );
         }
      }
      finally {
         umask($mask);
         $erase($directory);
      }
   }
);
