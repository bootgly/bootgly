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
use function array_slice;
use function assert;
use function chmod;
use function count;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function implode;
use function is_dir;
use function is_file;
use function is_link;
use function max;
use function mkdir;
use function putenv;
use function rmdir;
use function scandir;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strlen;
use function substr;
use function substr_count;
use function trim;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\ACI\VCS;


/**
 * Every transfer runs under the transport allow-list — narrowed, never
 * widened, by one the caller already exports — a caller value is never read
 * as an option, and a streamed command keeps only the tail of its output.
 *
 * Part A — a fake `git` records each call's exact argv and the
 * `GIT_ALLOW_PROTOCOL` it was started with, while the caller exports
 * `ext:file:https:ssh` (an operator who allows `ext` beside the usual
 * transports — the realistic setting the allow-list must beat): `clone`,
 * `fetch` and `ls-remote` get exactly `file:https:ssh` (`transfer()`),
 * `submodule update` keeps the caller's value (the override lives in
 * `transfer()`, never in `execute()`), `--` sits before the remote and the
 * remote name and after the reference, and an option-shaped reference or
 * commit is refused before git runs at all. An inherited allow-list only
 * narrows: `https` stays `https`, `file:ext:ssh` becomes `file:ssh`, none
 * inherited gives `file:https:ssh`, and `ext` — no name in common — and an
 * empty value — set, which git reads as allowing none — both give `none`.
 *
 * Part B — a fake `git` that floods: ~200 KiB of lines, then a 100 KiB line
 * with no break. A callback gets every line (the unbroken one in pieces of at
 * most `Git::TAIL` bytes) while `$output` holds at most twice `TAIL` bytes
 * mid-stream and keeps exactly the last `TAIL` bytes after it; without a
 * callback, `$output` keeps all of it.
 *
 * Part C — real git, with the user's configuration channels shut and a
 * private HOME, against a tagged fixture made here: `ext::` stays refused
 * while the caller allows it by environment and by config, `file://` is
 * refused under an inherited `https`, `ext` or empty allow-list, an
 * option-shaped remote, reference or commit is never read as a switch while a
 * real tag still checks out and describes, and a remote whose name starts
 * with a dash is added, listed and fetched as a name.
 */

return new Test(
   description: 'transfers run under the deadline and the transport allow-list, which an inherited one only narrows; a caller value is never read as an option; a streamed output keeps its tail',
   test: function () {
      // ! One private directory; every run below — refused ones included — writes only inside it
      $base = Temporaries::reserve('vcs-git-transfer');
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
      // ! An argv as the fake records it — every argument bracketed, so the match is exact
      $Argv = static fn (string ...$arguments): string => implode('', array_map(
         static fn (string $argument): string => "[{$argument}]",
         $arguments
      ));
      // ! A path as `sh` reads it (single-quoted) and as an `ext::` command line reads it
      //   (`%` and a blank escaped) — or a payload under a TMPDIR with either never runs
      $Quote = static function (string $path): string {
         $path = str_replace("'", "'\\''", $path);

         return "'{$path}'";
      };
      $Escape = static fn (string $argument): string => str_replace(['%', ' '], ['%%', '% '], $argument);

      try {
         // # Part A — a `git` that records its argv and its GIT_ALLOW_PROTOCOL, and answers `git remote`
         $fake = "{$base}/git";
         file_put_contents($fake, <<<'SH'
         #!/bin/sh
         log="$(dirname "$0")/calls.log"
         for argument in "$@"; do
            printf '[%s]' "$argument" >> "$log"
         done
         printf '\t%s\n' "${GIT_ALLOW_PROTOCOL-(unset)}" >> "$log"
         if [ "$#" -eq 1 ] && [ "$1" = remote ]; then
            printf '%s\n' '-dash'
         fi
         exit 0

         SH);
         chmod($fake, 0o755);
         mkdir("{$base}/tree", 0o700);

         $URL = 'https://bootgly-transfer.invalid/owner/repository.git';
         // ! An operator who allows `ext` beside the three transports: only the
         //   intersection drops `ext` and still lets `file` through
         $operator = 'ext:file:https:ssh';
         $previous = $Export(['GIT_ALLOW_PROTOCOL' => $operator]);
         try {
            $VCS = new VCS("{$base}/tree", $fake);
            $VCS->Git->clone($URL, 'target');
            $VCS->Git->fetch('origin');
            $VCS->Tags->probe('origin');
            $VCS->Submodules->update();
            $VCS->Remotes->add('-dash', $URL);
            $listed = $VCS->Remotes->list();
            $VCS->Git->checkout('refs/tags/v1.0.0');
            $VCS->Git->describe('HEAD', ['v*']);
            $refusals = [$VCS->Git->checkout('--orphan=injected'), $VCS->Git->describe('--dirty=X')];
         }
         finally {
            $Export($previous);
         }

         // @ One clone per inherited allow-list into a target named after its label —
         //   `null` inherits none, `''` is set but empty (git reads it as allowing none)
         $Narrow = new Git("{$base}/tree", $fake);
         $inheritances = ['https' => 'https', 'file:ext:ssh' => 'file:ext:ssh', 'unset' => null, 'ext' => 'ext', 'empty' => ''];
         foreach ($inheritances as $label => $inherited) {
            $previous = $Export(['GIT_ALLOW_PROTOCOL' => $inherited]);
            try {
               $Narrow->clone($URL, "under-{$label}");
            }
            finally {
               $Export($previous);
            }
         }

         // ! argv => the GIT_ALLOW_PROTOCOL that call ran under
         $log = trim((string) file_get_contents("{$base}/calls.log"));
         $calls = [];
         foreach (explode("\n", $log) as $line) {
            $fields = explode("\t", $line, 2);
            $calls[$fields[0]] = $fields[1] ?? '';
         }
         $Protocol = static function (string $command) use ($calls): null|string {
            foreach ($calls as $argv => $protocol) {
               if (str_contains($argv, "[{$command}]") === true) {
                  return $protocol;
               }
            }

            return null;
         };
         $Carried = static fn (string $label): null|string => $calls[$Argv(
            '-c', 'http.lowSpeedLimit=1000', '-c', 'http.lowSpeedTime=20', 'clone', '--', $URL, "under-{$label}"
         )] ?? null;

         yield assert(
            assertion: isSet($calls[$Argv('-c', 'http.lowSpeedLimit=1000', '-c', 'http.lowSpeedTime=20', 'clone', '--', $URL, 'target')]) === true,
            description: 'clone() runs exactly `-c http.lowSpeedLimit=1000 -c http.lowSpeedTime=20 clone -- <URL> <target>`'
         );
         yield assert(
            assertion: $Protocol('clone') === 'file:https:ssh'
               && $Protocol('fetch') === 'file:https:ssh'
               && $Protocol('ls-remote') === 'file:https:ssh',
            description: 'clone, fetch and ls-remote each run under exactly GIT_ALLOW_PROTOCOL=file:https:ssh while the caller exports ext:file:https:ssh — `ext` dropped, the shared names kept'
         );
         yield assert(
            assertion: $Protocol('submodule') === $operator,
            description: 'submodule update keeps the caller\'s GIT_ALLOW_PROTOCOL — the allow-list is set by transfer(), not by every execute()'
         );
         yield assert(
            assertion: isSet($calls[$Argv('-c', 'http.lowSpeedLimit=1000', '-c', 'http.lowSpeedTime=20', 'fetch', '--no-recurse-submodules', '--', 'origin', '+refs/tags/*:refs/tags/*')]) === true
               && isSet($calls[$Argv('remote', 'add', '--', '-dash', $URL)]) === true
               && isSet($calls[$Argv('remote', 'get-url', '--', '-dash')]) === true && $listed === ['-dash' => '']
               && isSet($calls[$Argv('-c', 'advice.detachedHead=false', 'checkout', '--quiet', 'refs/tags/v1.0.0', '--')]) === true
               && isSet($calls[$Argv('describe', '--tags', '--long', '--match', 'v*', 'HEAD')]) === true,
            description: '`--` ends option parsing before the remote in fetch, remote add and remote get-url, and follows the reference in `checkout --quiet <reference> --`; describe runs `--tags --long --match v* HEAD`'
         );
         yield assert(
            assertion: $refusals === [126, null]
               && substr_count($log, '[checkout]') === 1 && substr_count($log, '[describe]') === 1
               && str_contains($log, 'injected') === false && str_contains($log, '--dirty=X') === false,
            description: 'checkout(\'--orphan=injected\') returns 126 and describe(\'--dirty=X\') null without running git — the fake records one checkout and one describe, neither carrying the option-shaped value'
         );
         yield assert(
            assertion: $Carried('https') === 'https' && $Carried('file:ext:ssh') === 'file:ssh'
               && $Carried('unset') === 'file:https:ssh',
            description: 'an inherited GIT_ALLOW_PROTOCOL narrows the allow-list, never widens it: `https` → `https`, `file:ext:ssh` → `file:ssh`, none inherited → `file:https:ssh`'
         );
         yield assert(
            assertion: $Carried('ext') === 'none' && $Carried('empty') === 'none',
            description: 'an inherited GIT_ALLOW_PROTOCOL=ext shares no name with the allow-list, and one set but empty allows none: either way the transfer carries `none` — not an empty value (dropped before it reaches the child), nor the whole list'
         );

         // # Part B — a `git` that floods: ~200 KiB of lines, then a 100 KiB line with no break
         $lines = [];
         $padding = str_repeat('.', 20);
         for ($index = 1; $index <= 4_096; $index++) {
            $lines[] = "remote: Counting objects {$index} {$padding}";
         }
         $unbroken = str_repeat('0123456789abcdef', 6_400);
         $flood = implode("\n", [...$lines, $unbroken]);
         file_put_contents("{$base}/flood.txt", $flood);
         $flooder = "{$base}/flooder";
         file_put_contents($flooder, <<<'SH'
         #!/bin/sh
         exec cat "$(dirname "$0")/flood.txt"

         SH);
         chmod($flooder, 0o755);

         $Flood = new Git("{$base}/tree", $flooder);
         $received = [];
         // ! The most `$output` held while a line was handed over — mid-stream, not after it
         $peak = 0;
         $Flood->execute(['log'], static function (string $line) use (&$received, &$peak, $Flood): void {
            $received[] = $line;
            $peak = max($peak, strlen($Flood->output));
         });
         $streamed = $Flood->output;
         $queried = $Flood->query(['log']);
         $Flood->execute(['log']);
         $kept = $Flood->output;
         $pieces = array_slice($received, count($lines));

         yield assert(
            assertion: array_slice($received, 0, count($lines)) === $lines
               && count($pieces) > 1 && implode('', $pieces) === $unbroken
               && array_filter($pieces, static fn (string $piece): bool => strlen($piece) > Git::TAIL) === [],
            description: 'streamed: the callback gets each of the 4096 lines, then the 100 KiB line with no break in pieces of at most Git::TAIL bytes that join back to it'
         );
         yield assert(
            assertion: $streamed === substr($flood, -Git::TAIL),
            description: 'streamed: $output keeps exactly the last Git::TAIL bytes of the ~300 KiB — the end of the flood, never all of it'
         );
         yield assert(
            assertion: $peak > 0 && $peak <= Git::TAIL * 2,
            description: 'streamed: while the callback runs, $output never holds more than 2 × Git::TAIL bytes of the ~300 KiB — bounded during the stream, not only cut after it'
         );
         yield assert(
            assertion: $queried === $flood && $kept === $flood,
            description: 'without a callback, query() and execute() keep the whole ~300 KiB in $output'
         );

         // # Part C — real git: no global or system configuration, a private HOME, nothing inherited that allows a transport
         $home = "{$base}/home";
         $fixture = "{$base}/fixture";
         $clone = "{$base}/clone";
         $fresh = "{$base}/fresh";
         $witness = "{$base}/witness-ext";
         $planted = "{$base}/witness-upload-pack";
         mkdir($home, 0o700);
         mkdir($fixture, 0o700);
         mkdir($fresh, 0o700);

         $previous = $Export([
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'HOME' => $home,
            'GIT_ALLOW_PROTOCOL' => null,
            'GIT_CONFIG_COUNT' => null,
            'GIT_CONFIG_PARAMETERS' => null,
         ]);
         try {
            // ! A fixture repository: one commit on `main`, tagged `v1.0.0` (annotated)
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
            $commit = $Fixture->resolve('HEAD');
            $Base = new Git($base);

            // @ A caller that allows `ext` by every channel: the environment (beside
            //   the usual transports, so `file` stays allowed) and the config
            $hostile = $Export([
               'GIT_ALLOW_PROTOCOL' => $operator,
               'GIT_CONFIG_COUNT' => '1',
               'GIT_CONFIG_KEY_0' => 'protocol.ext.allow',
               'GIT_CONFIG_VALUE_0' => 'always',
            ]);
            try {
               $cloned = $Base->clone("file://{$fixture}", $clone);
               $refused = $Base->clone("ext::sh -c touch% {$Escape($Quote($witness))};git% %s% {$Escape($Quote($fixture))}", "{$base}/ext-clone");
               $refusal = $Base->output;
            }
            finally {
               $Export($hostile);
            }

            // @ A caller whose own allow-list is stricter — or set but empty, which git
            //   reads as allowing none: a clone of the fixture under it fails as git's
            //   "transport 'file' not allowed", creating nothing
            $Narrowed = static function (string $inherited) use ($Export, $Base, $base, $fixture): bool {
               $label = $inherited === '' ? 'empty' : $inherited;
               $target = "{$base}/narrowed-{$label}";
               $previous = $Export(['GIT_ALLOW_PROTOCOL' => $inherited]);
               try {
                  $status = $Base->clone("file://{$fixture}", $target);
               }
               finally {
                  $Export($previous);
               }

               return $status !== 0 && str_contains($Base->output, "transport 'file' not allowed") === true
                  && is_dir("{$target}/.git") === false;
            };
            $narrowed = [$Narrowed('https'), $Narrowed('ext'), $Narrowed('')];

            // @ An option-shaped remote, reference and commit
            $Clone = new Git($clone);
            $head = $Clone->resolve('HEAD');
            $fetched = $Clone->fetch("--upload-pack=touch {$Quote($planted)}");
            $blocked = $Clone->output;
            $before = $Clone->query(['symbolic-ref', '--short', 'HEAD']);
            $checked = $Clone->checkout('--orphan=injected');
            $after = $Clone->query(['symbolic-ref', '--short', 'HEAD']);
            $tagged = $Clone->checkout('refs/tags/v1.0.0');
            $detached = $Clone->query(['symbolic-ref', '--quiet', 'HEAD']);
            $described = $Fixture->describe('HEAD');
            $dirtied = $Fixture->describe('--dirty=X');

            // @ A remote whose name starts with a dash
            $Fresh = new VCS($fresh);
            $Fresh->Git->execute(['init', '--quiet', '-b', 'main']);
            $untagged = $Fresh->Tags->list();
            $added = $Fresh->Remotes->add('-dash', $fixture);
            $remotes = $Fresh->Remotes->list();
            $dashed = $Fresh->Git->fetch('-dash');
            $tags = $Fresh->Tags->list();
         }
         finally {
            $Export($previous);
         }

         yield assert(
            assertion: $commit !== null && $cloned === 0 && is_dir("{$clone}/.git") === true && $head === $commit,
            description: 'control — under the same hostile environment (GIT_ALLOW_PROTOCOL=ext:file:https:ssh, protocol.ext.allow=always), clone() of a file:// fixture succeeds and checks out its commit'
         );
         yield assert(
            assertion: $refused !== 0 && str_contains($refusal, "transport 'ext' not allowed") === true
               && file_exists($witness) === false,
            description: 'clone() of an ext:: URL fails with "transport \'ext\' not allowed" and runs nothing, while the caller allows ext through GIT_ALLOW_PROTOCOL and protocol.ext.allow=always'
         );
         yield assert(
            assertion: $narrowed === [true, true, true],
            description: 'under an inherited GIT_ALLOW_PROTOCOL=https — under `ext`, which shares no name with the allow-list, and under an empty one, which git reads as allowing none — clone(file://<fixture>) fails with "transport \'file\' not allowed" and creates nothing'
         );
         yield assert(
            assertion: $fetched !== 0 && str_contains($blocked, 'strange pathname') === true
               && file_exists($planted) === false,
            description: 'fetch(\'--upload-pack=touch <witness>\') is a repository path git blocks, never an option — no witness'
         );
         yield assert(
            assertion: $checked === 126 && $before === 'main' && $after === 'main'
               && $tagged === 0 && $detached === null,
            description: 'checkout(\'--orphan=injected\') is refused (126) and the branch stays `main`, while checkout(\'refs/tags/v1.0.0\') succeeds and detaches HEAD — a checkout broken for every reference turns this red'
         );
         yield assert(
            assertion: $described === ['tag' => 'v1.0.0', 'distance' => 0] && $dirtied === null,
            description: 'describe(\'--dirty=X\') is no commit (null) on the clean tagged fixture where describe(\'HEAD\') reads `v1.0.0`'
         );
         yield assert(
            assertion: $untagged === [] && $added === true && ($remotes['-dash'] ?? null) === $fixture
               && $dashed === 0 && array_keys($tags) === ['v1.0.0'] && $tags['v1.0.0']['commit'] === $commit,
            description: 'Remotes::add(\'-dash\', <fixture>) succeeds, list() reads it back, and fetch(\'-dash\') brings in the fixture\'s tags'
         );
      }
      finally {
         $Erase($base);
      }
   }
);
