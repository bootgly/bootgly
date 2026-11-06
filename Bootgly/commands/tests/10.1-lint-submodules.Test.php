<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const GLOB_BRACE;
use const PHP_BINARY;
use const SIGCONT;
use const SIGSTOP;
use function array_search;
use function array_splice;
use function assert;
use function chmod;
use function clearstatcache;
use function decoct;
use function defined;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fileinode;
use function fileperms;
use function function_exists;
use function getenv;
use function glob;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_decode;
use function json_encode;
use function mkdir;
use function posix_getuid;
use function posix_kill;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function rename;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_contents;
use function strrpos;
use function substr;
use function symlink;
use function trim;
use function unlink;
use function usleep;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Agent;


/**
 * `bootgly lint <submodule>` over the four submodules: the agent report names
 * the submodule and whether it is fixable, a fixable one rewrites the file on
 * `--fix`, a check-only one refuses `--fix` and leaves the file byte-identical.
 */

return new Test(
   description: '`lint` routes the four submodules: fixable ones rewrite on --fix, check-only ones refuse it and change nothing',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }
      $launcher = BOOTGLY_ROOT_BASE . '/bootgly';
      if (is_file($launcher) === false) {
         yield assert(assertion: true, description: 'Skipped: the bootgly launcher is not at the root');
         return;
      }

      // ! Scratch file carrying one violation of each rule
      $dir = Temporaries::reserve('lint-submodules');

      try {
         $file = "{$dir}/Probe.php";
         $source = "<?php\n\nnamespace Demo;\n\n\nuse function count;\nuse function array_map;\n\n\n"
            . "class Probe\n{\n   public function __construct (private ?int \$n = null) {}\n\n"
            . "   public function fooBar (?array \$a): ?int { return count(\$a) + count(array_map(fn (\$x) => \$x, \$a)); }\n}\n";
         file_put_contents($file, $source);

         // ! Agent environment — the JSON contract is what the assertions read
         $environment = getenv();
         $environment['AI_AGENT'] = '1';

         $run = static function (string $submodule, mixed ...$flags) use ($launcher, $file, $environment): array {
            // ! `--target <path>` swaps the probe for another path; `--environment <array>` the process environment
            $target = $file;
            $variables = $environment;
            if (($at = array_search('--target', $flags, true)) !== false) {
               $target = (string) $flags[$at + 1];
               array_splice($flags, (int) $at, 2);
            }
            if (($at = array_search('--environment', $flags, true)) !== false) {
               /** @var array<string,string> $variables */
               $variables = $flags[$at + 1];
               array_splice($flags, (int) $at, 2);
            }
            // ! `--php-ini <directive>` hands the child interpreter one more -d
            $directives = ['-d', 'opcache.jit=0'];
            if (($at = array_search('--php-ini', $flags, true)) !== false) {
               $directives[] = '-d';
               $directives[] = (string) $flags[$at + 1];
               array_splice($flags, (int) $at, 2);
            }
            /** @var array<int,string> $flags */
            $process = proc_open(
               [PHP_BINARY, ...$directives, $launcher, 'lint', $submodule, $target, ...$flags],
               [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
               $pipes,
               BOOTGLY_ROOT_BASE,
               $variables
            );
            if (is_resource($process) === false) {
               return ['status' => -1, 'report' => null];
            }
            /** @var array<int,resource> $pipes */
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            $report = json_decode($output, true);

            return ['status' => $status, 'report' => is_array($report) ? $report : null];
         };

         // @@ Check mode: every submodule finds its violation and fails the run
         $expected = [
            'imports'    => ['fixable' => true,  'type' => 'not_alphabetical'],
            'nullables'  => ['fixable' => true,  'type' => 'nullable_shorthand'],
            'promotions' => ['fixable' => false, 'type' => 'promoted_property'],
            'methods'    => ['fixable' => false, 'type' => 'multiword_method'],
         ];
         foreach ($expected as $submodule => $shape) {
            $result = $run($submodule);
            $report = $result['report'];
            $types = [];
            foreach ($report['report'][0]['issues'] ?? [] as $issue) {
               $types[] = $issue['type'];
            }

            yield assert(
               assertion: $result['status'] !== 0 && $report !== null
                  && $report['result'] === 'failed' && $report['submodule'] === $submodule
                  && $report['fixable'] === $shape['fixable'] && $report['mode'] === 'check'
                  && in_array($shape['type'], $types, true),
               description: "`lint {$submodule}` must report `{$shape['type']}` as a failed check with fixable="
                  . json_encode($shape['fixable']) . ', got: ' . json_encode($result)
            );
         }

         // @ A check-only submodule refuses --fix: mode stays check, the file is untouched
         $result = $run('promotions', '--fix');
         yield assert(
            assertion: $result['status'] !== 0 && ($result['report']['mode'] ?? null) === 'check'
               && ($result['report']['files']['fixed'] ?? null) === 0
               && file_get_contents($file) === $source,
            description: '`lint promotions --fix` must refuse and change nothing, got: ' . json_encode($result)
         );

         // @ --dry-run writes nothing, so the issues stay unresolved and the run stays red
         $result = $run('nullables', '--dry-run');
         yield assert(
            assertion: $result['status'] !== 0 && ($result['report']['mode'] ?? null) === 'dry-run'
               && ($result['report']['result'] ?? null) === 'failed'
               && ($result['report']['files']['fixed'] ?? null) === 0
               && ($result['report']['issues']['unresolved'] ?? null) === ($result['report']['issues']['total'] ?? -1)
               && file_get_contents($file) === $source,
            description: '`lint nullables --dry-run` must fail, resolve nothing and leave the file byte-identical, got: ' . json_encode($result)
         );

         // @ A fixable submodule rewrites on --fix and passes
         $result = $run('nullables', '--fix');
         $fixed = (string) file_get_contents($file);
         yield assert(
            assertion: $result['status'] === 0 && ($result['report']['mode'] ?? null) === 'fix'
               && ($result['report']['files']['fixed'] ?? null) === 1
               && str_contains($fixed, 'private null|int $n = null')
               && str_contains($fixed, 'fooBar (null|array $a): null|int'),
            description: '`lint nullables --fix` must rewrite every shorthand, got: ' . json_encode($result)
               . ' file: ' . json_encode($fixed)
         );

         // @ ...after which the check passes
         $result = $run('nullables');
         yield assert(
            assertion: $result['status'] === 0 && ($result['report']['result'] ?? null) === 'passed'
               && ($result['report']['issues']['total'] ?? null) === 0,
            description: '`lint nullables` must pass on the rewritten file, got: ' . json_encode($result)
         );

         // @ A file the formatter refuses (a comment in its import block) stays unresolved under --fix
         $commented = "{$dir}/Commented.php";
         $commentedSource = "<?php\n\nnamespace Demo;\n\n\nuse function count;\n// kept on purpose\nuse function array_map;\n\n\n"
            . "class Commented\n{\n   public function run (array \$a): int { return count(\$a) + count(array_map(fn (\$x) => \$x, \$a)); }\n}\n";
         file_put_contents($commented, $commentedSource);
         $result = $run('imports', '--fix', '--target', $commented);
         yield assert(
            assertion: $result['status'] !== 0 && ($result['report']['result'] ?? null) === 'failed'
               && ($result['report']['files']['fixed'] ?? null) === 0
               && ($result['report']['issues']['unresolved'] ?? null) === ($result['report']['issues']['total'] ?? -1)
               && ($result['report']['issues']['total'] ?? 0) > 0
               && file_get_contents($commented) === $commentedSource,
            description: '`lint imports --fix` on a commented import block must refuse, count every issue unresolved and leave the file byte-identical, got: ' . json_encode($result)
         );

         // @ The human branch — what a CI lane without an agent marker runs — exits by the same rule
         $scrubbed = getenv();
         unset($scrubbed['AI_AGENT']);
         foreach (Agent::MARKERS as $variables) {
            foreach ($variables as $variable) {
               unset($scrubbed[$variable]);
            }
         }
         $humanCheck = $run('methods', '--environment', $scrubbed);
         $humanDry = $run('imports', '--dry-run', '--target', $commented, '--environment', $scrubbed);
         $humanMissing = $run('nullables', '--target', "{$dir}/NoSuchDirectory/", '--environment', $scrubbed);
         $humanClean = $run('nullables', '--environment', $scrubbed);
         yield assert(
            assertion: $humanCheck['status'] !== 0 && $humanDry['status'] !== 0 && $humanMissing['status'] !== 0 && $humanClean['status'] === 0
               && $humanCheck['report'] === null && $humanClean['report'] === null,
            description: 'Without an agent marker the exit code must follow the same rule (check red, dry-run red, missing path red, clean green) '
               . 'and stdout must not be the JSON document, got: '
               . json_encode([$humanCheck['status'], $humanDry['status'], $humanMissing['status'], $humanClean['status'], $humanCheck['report'] !== null])
         );

         // @ A braced namespace has no `;` to insert after: --fix leaves it and says so
         $braced = "{$dir}/Braced.php";
         $bracedSource = "<?php\n\nnamespace Demo {\n   class Braced { public function run (): int { return strlen('x'); } }\n}\n";
         file_put_contents($braced, $bracedSource);
         $result = $run('imports', '--fix', '--target', $braced);
         yield assert(
            assertion: $result['status'] !== 0 && ($result['report']['result'] ?? null) === 'failed'
               && ($result['report']['files']['fixed'] ?? null) === 0
               && ($result['report']['issues']['unresolved'] ?? 0) > 0
               && file_get_contents($braced) === $bracedSource,
            description: '`lint imports --fix` on a braced namespace must leave the file byte-identical and stay red, got: ' . json_encode($result)
         );

         // @ A declaration the eye reads as ordinary — a space before the `;` — is found by token and fixed
         $spaced = "{$dir}/Spaced.php";
         file_put_contents($spaced, "<?php\n\nnamespace Demo\\Sub ;\n\n\nclass Spaced { public function run (): int { return strlen('x'); } }\n");
         $result = $run('imports', '--fix', '--target', $spaced);
         $rewritten = (string) file_get_contents($spaced);
         yield assert(
            assertion: $result['status'] === 0 && ($result['report']['files']['fixed'] ?? null) === 1
               && str_contains($rewritten, "namespace Demo\\Sub ;\n\n\nuse function strlen;\n"),
            description: '`lint imports --fix` must insert the import after a `namespace X ;` declaration, got: '
               . json_encode(['result' => $result, 'file' => $rewritten])
         );

         // @ A file that is not writable is left as found and the run goes on to the next
         @mkdir("{$dir}/locked", 0o700);
         $locked = "{$dir}/locked/Locked.php";
         $open = "{$dir}/locked/Open.php";
         $lockedSource = "<?php\n\nnamespace Demo;\n\n\nclass Locked { public function run (): int { return strlen('x'); } }\n";
         file_put_contents($locked, $lockedSource);
         chmod($locked, 0o444);
         file_put_contents($open, "<?php\n\nnamespace Demo;\n\n\nclass Open { public function run (): int { return strlen('x'); } }\n");
         $result = $run('imports', '--fix', '--target', "{$dir}/locked/");
         yield assert(
            assertion: posix_getuid() === 0 || (
               $result['status'] !== 0 && ($result['report']['result'] ?? null) === 'failed'
               && ($result['report']['files']['fixed'] ?? null) === 1
               && ($result['report']['issues']['unresolved'] ?? null) === 1
               && file_get_contents($locked) === $lockedSource
               && str_contains((string) file_get_contents($open), 'use function strlen;')
            ),
            description: '`--fix` must leave a read-only file byte-identical, still fix its writable sibling and answer with the JSON document, got: ' . json_encode($result)
         );
         chmod($locked, 0o644);

         // @ A symlinked file is never scanned — nor written through
         $outside = "{$dir}/Outside.txt";
         file_put_contents($outside, "<?php\n\nnamespace Demo;\n\n\nclass Outside { public function run (): int { return strlen('x'); } }\n");
         @mkdir("{$dir}/linked", 0o700);
         symlink($outside, "{$dir}/linked/Link.php");
         $result = $run('imports', '--fix', '--target', "{$dir}/linked/");
         yield assert(
            assertion: $result['status'] === 0 && ($result['report']['files']['scanned'] ?? null) === 0
               && str_contains((string) file_get_contents($outside), 'use function strlen;') === false,
            description: 'A symlinked file must not be scanned nor rewritten through the link, got: ' . json_encode($result)
         );

         // @ CRLF sources keep their line ending — with and without an existing import block
         $crlf = "{$dir}/Crlf.php";
         file_put_contents($crlf, "<?php\r\n\r\nnamespace Demo;\r\n\r\n\r\nclass Crlf { public function run (): int { return strlen('x'); } }\r\n");
         $result = $run('imports', '--fix', '--target', $crlf);
         $inserted = (string) file_get_contents($crlf);
         $crlfBlock = "{$dir}/CrlfBlock.php";
         file_put_contents($crlfBlock, "<?php\r\n\r\nnamespace Demo;\r\n\r\n\r\nuse function strlen;\r\nuse const PHP_EOL;\r\n\r\n\r\nclass CrlfBlock { public function run (): string { return strlen('x') . PHP_EOL; } }\r\n");
         $resultBlock = $run('imports', '--fix', '--target', $crlfBlock);
         $rewrittenBlock = (string) file_get_contents($crlfBlock);
         yield assert(
            assertion: $result['status'] === 0 && str_contains($inserted, "namespace Demo;\r\n\r\n\r\nuse function strlen;\r\n\r\n\r\nclass Crlf")
               && str_contains($inserted, "\n\n") === false
               && $resultBlock['status'] === 0 && str_contains($rewrittenBlock, "namespace Demo;\r\n\r\n\r\nuse const PHP_EOL;\r\nuse function strlen;\r\n\r\n\r\nclass CrlfBlock")
               && str_contains($rewrittenBlock, "\n\n") === false,
            description: 'A CRLF file must be rewritten in CRLF only, on both the insert and the reorder paths, got: ' . json_encode(['insert' => $inserted, 'block' => $rewrittenBlock]),
         );

         // @ A `\name` call reaches its import once the prefix is dropped: one --fix pass converges
         $prefixed = "{$dir}/Prefixed.php";
         file_put_contents($prefixed, "<?php\n\nnamespace Demo;\n\n\nuse function strlen;\n\n\nclass Prefixed { public function run (): int { return \\strlen('x'); } }\n");
         $result = $run('imports', '--fix', '--target', $prefixed);
         $check = $run('imports', '--target', $prefixed);
         yield assert(
            assertion: $result['status'] === 0 && ($result['report']['files']['fixed'] ?? null) === 1
               && $check['status'] === 0 && str_contains((string) file_get_contents($prefixed), "use function strlen;\n"),
            description: '`--fix` must drop the prefix and KEEP the import it then needs, in one pass, got: ' . json_encode(['fix' => $result, 'check' => $check])
         );

         // @ Without php -l the fix is never written — and the run still answers with the JSON document
         $unchecked = "{$dir}/Unchecked.php";
         $uncheckedSource = "<?php\n\nnamespace Demo;\n\n\nclass Unchecked { public function run (): int { return strlen('x'); } }\n";
         file_put_contents($unchecked, $uncheckedSource);
         $result = $run('imports', '--fix', '--target', $unchecked, '--php-ini', 'disable_functions=proc_open');
         yield assert(
            assertion: $result['status'] !== 0 && ($result['report']['result'] ?? null) === 'failed'
               && ($result['report']['files']['fixed'] ?? null) === 0
               && file_get_contents($unchecked) === $uncheckedSource,
            description: 'With proc_open disabled `--fix` must skip the write, stay red and keep the JSON contract, got: ' . json_encode($result)
         );
         // @ An LF file that merely CONTAINS a CRLF byte pair keeps LF endings and two blank lines
         $mixed = "{$dir}/Mixed.php";
         file_put_contents($mixed, "<?php\n\nnamespace Demo;\n\n\nuse function strlen;\nuse const PHP_EOL;\n\n\nclass Crossed { public function run (): string { return strlen(\"GET / HTTP/1.1\r\n\r\n\") . PHP_EOL; } }\n");
         $result = $run('imports', '--fix', '--target', $mixed);
         $rewrittenMixed = (string) file_get_contents($mixed);
         yield assert(
            assertion: $result['status'] === 0
               && str_contains($rewrittenMixed, "namespace Demo;\n\n\nuse const PHP_EOL;\nuse function strlen;\n\n\nclass Crossed")
               && str_contains($rewrittenMixed, "\r\nuse") === false
               && str_contains($rewrittenMixed, "GET / HTTP/1.1\r\n\r\n"),
            description: 'A CRLF inside a string must not turn an LF file into a CRLF one, got: ' . json_encode($rewrittenMixed)
         );

         // @ A fixed file keeps what was fixed in its report entry — and is a NEW inode
         //   with the mode of the old one: the rewrite replaced the name, never the file
         $entry = "{$dir}/Entry.php";
         file_put_contents($entry, "<?php\n\nnamespace Demo;\n\n\nclass Entry { public function run (): int { return strlen('x'); } }\n");
         chmod($entry, 0o640);
         $inode = fileinode($entry);
         $result = $run('imports', '--fix', '--target', $entry);
         clearstatcache(true, $entry);
         yield assert(
            assertion: $result['status'] === 0 && ($result['report']['report'][0]['fixed'] ?? null) === true
               && ($result['report']['report'][0]['issues'][0]['type'] ?? null) === 'missing_import'
               && fileinode($entry) !== $inode
               && (fileperms($entry) & 0o777) === 0o640,
            description: 'The report entry of a fixed file must list what was fixed, and the file must be a new inode keeping its mode, got: '
               . json_encode(['result' => $result, 'inode' => [$inode, fileinode($entry)], 'mode' => decoct(fileperms($entry) & 0o777)])
         );

         // @ A CRLF file whose FIRST break is a bare LF still gets a CRLF block — the
         //   ending in force where the block sits decides, not the file's first byte
         $crlfLater = "{$dir}/CrlfLater.php";
         file_put_contents($crlfLater, "<?php\n\r\nnamespace Demo;\r\n\r\n\r\nuse function strlen;\r\nuse const PHP_EOL;\r\n\r\n\r\nclass CrlfLater { public function run (): string { return strlen('x') . PHP_EOL; } }\r\n");
         $result = $run('imports', '--fix', '--target', $crlfLater);
         $rewrittenLater = (string) file_get_contents($crlfLater);
         yield assert(
            assertion: $result['status'] === 0
               && str_contains($rewrittenLater, "namespace Demo;\r\n\r\n\r\nuse const PHP_EOL;\r\nuse function strlen;\r\n\r\n\r\nclass CrlfLater"),
            description: 'A CRLF block must be written where the block sits in CRLF, whatever the first break of the file is, got: ' . json_encode($rewrittenLater)
         );

         // @ A file with two namespace blocks is listed as skipped — neither green by silence nor red
         $multi = "{$dir}/Multi.php";
         file_put_contents($multi, "<?php\n\nnamespace A;\n\n\nclass X { public function run (): int { return strlen('x'); } }\n\nnamespace B;\n\n\nclass Y { public function run (): int { return count([]); } }\n");
         $result = $run('imports', '--target', $multi);
         yield assert(
            assertion: $result['status'] === 0 && ($result['report']['files']['skipped'] ?? null) === 1
               && ($result['report']['files']['failed'] ?? null) === 0
               && str_contains((string) ($result['report']['skipped'][0]['notice'] ?? ''), 'More than one namespace'),
            description: 'A multi-namespace file must be reported under skipped with its notice, got: ' . json_encode($result)
         );

         // @ A name that becomes a link during the run is neither read nor written:
         //   the victim looks like a file with something to fix, so a lint that read
         //   through the link would try to write through it — the racer swaps the
         //   last name as soon as the first file is rewritten, long before the lint
         //   reaches it
         @mkdir("{$dir}/race", 0o700);
         $victim = "{$dir}/Victim.php";
         $victimSource = "<?php\n\nnamespace Elsewhere;\n\n\nclass Victim { public function run (): int { return strlen('x'); } }\n";
         file_put_contents($victim, $victimSource);
         for ($n = 0; $n < 40; $n++) {
            $name = $n === 39 ? 'Wide' : "Narrow{$n}";
            file_put_contents(
               "{$dir}/race/{$name}.php",
               "<?php\n\nnamespace Demo;\n\n\nclass {$name} { public function run (): int { return strlen('x'); } }\n"
            );
         }
         $racer = <<<'PHP'
         <?php
         [$script, $dir, $victim] = $argv;
         $mark = "{$dir}/Narrow0.php";
         $deadline = microtime(true) + 20;
         while (microtime(true) < $deadline) {
            if (str_contains((string) file_get_contents($mark), 'use function strlen;')) {
               unlink("{$dir}/Wide.php");
               symlink($victim, "{$dir}/Wide.php");
               exit(0);
            }
            usleep(200);
         }
         exit(1);
         PHP;
         $racerFile = "{$dir}/racer.php";
         file_put_contents($racerFile, $racer);
         $Racer = proc_open(
            [PHP_BINARY, $racerFile, "{$dir}/race", $victim],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $racerPipes
         );
         $result = $run('imports', '--fix', '--target', "{$dir}/race/");
         $swapped = is_resource($Racer) ? proc_close($Racer) : -1;
         yield assert(
            assertion: $swapped === 0 && file_get_contents($victim) === $victimSource
               && is_link("{$dir}/race/Wide.php") === true
               && ($result['report']['files']['fixed'] ?? null) === 39
               && ($result['report']['files']['skipped'] ?? null) === 1
               && str_contains((string) ($result['report']['skipped'][0]['notice'] ?? ''), 'No longer a regular file'),
            description: 'A name that became a link mid-run must be neither read nor written: the victim stays byte-identical, the link stays, the file is reported as skipped, got: '
               . json_encode(['swapped' => $swapped, 'victim' => file_get_contents($victim), 'link' => is_link("{$dir}/race/Wide.php"), 'result' => $result['report']['files'] ?? null, 'skipped' => $result['report']['skipped'] ?? null])
         );

         // @ A name carrying a backslash is neither read nor written: normalized, `..\\x\\y.php`
         //   would climb out of the scanned tree onto a neighbour — the neighbour stays
         //   byte-identical and the name is reported as skipped, with its reason
         @mkdir("{$dir}/climb", 0o700);
         @mkdir("{$dir}/neighbour", 0o700);
         $neighbour = "{$dir}/neighbour/target.php";
         $neighbourSource = "<?php // the neighbour\n";
         file_put_contents($neighbour, $neighbourSource);
         $climber = "{$dir}/climb/..\\neighbour\\target.php";
         $climberSource = "<?php\n\nnamespace Demo;\n\necho strlen('a');\n";
         file_put_contents($climber, $climberSource);
         $result = $run('imports', '--fix', '--target', "{$dir}/climb/");
         yield assert(
            assertion: file_get_contents($neighbour) === $neighbourSource
               && file_get_contents($climber) === $climberSource
               && ($result['report']['files']['scanned'] ?? null) === 1
               && ($result['report']['files']['fixed'] ?? null) === 0
               && ($result['report']['files']['skipped'] ?? null) === 1
               && str_contains((string) ($result['report']['skipped'][0]['notice'] ?? ''), 'backslash'),
            description: 'A backslash name must be skipped with its reason, and nothing outside the tree written, got: '
               . json_encode(['neighbour' => file_get_contents($neighbour), 'climber' => file_get_contents($climber), 'result' => $result['report'] ?? null])
         );

         // @ A directory swapped for a link out of the tree mid-run is neither read nor
         //   written through: the racer turns `zone/` into a link to a neighbour holding the
         //   same name once the first file is rewritten — the rewrite would land outside
         @mkdir("{$dir}/swap/zone", 0o700, true);
         @mkdir("{$dir}/elsewhere", 0o700);
         $elsewhere = "{$dir}/elsewhere/Wide.php";
         $elsewhereSource = "<?php\n\nnamespace Elsewhere;\n\n\nclass Wide { public function run (): int { return strlen('x'); } }\n";
         file_put_contents($elsewhere, $elsewhereSource);
         for ($n = 0; $n < 39; $n++) {
            file_put_contents(
               "{$dir}/swap/Narrow{$n}.php",
               "<?php\n\nnamespace Demo;\n\n\nclass Narrow{$n} { public function run (): int { return strlen('x'); } }\n"
            );
         }
         file_put_contents("{$dir}/swap/zone/Wide.php", "<?php\n\nnamespace Demo;\n\n\nclass Wide { public function run (): int { return strlen('x'); } }\n");
         $swapper = <<<'PHP'
         <?php
         [$script, $dir, $elsewhere] = $argv;
         $mark = "{$dir}/Narrow0.php";
         $deadline = microtime(true) + 20;
         while (microtime(true) < $deadline) {
            if (str_contains((string) file_get_contents($mark), 'use function strlen;')) {
               rename("{$dir}/zone", "{$dir}/zone.old");
               symlink($elsewhere, "{$dir}/zone");
               exit(0);
            }
            usleep(200);
         }
         exit(1);
         PHP;
         $swapperFile = "{$dir}/swapper.php";
         file_put_contents($swapperFile, $swapper);
         $Swapper = proc_open(
            [PHP_BINARY, $swapperFile, "{$dir}/swap", "{$dir}/elsewhere"],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $swapperPipes
         );
         $result = $run('imports', '--fix', '--target', "{$dir}/swap/");
         $swapped = is_resource($Swapper) ? proc_close($Swapper) : -1;
         yield assert(
            assertion: $swapped === 0 && file_get_contents($elsewhere) === $elsewhereSource
               && ($result['report']['files']['fixed'] ?? null) === 39
               && ($result['report']['files']['skipped'] ?? null) === 1
               && str_contains((string) ($result['report']['skipped'][0]['notice'] ?? ''), 'no longer resolves inside the scanned tree'),
            description: 'A directory swapped for a link out of the tree must be neither read nor written through, got: '
               . json_encode(['swapped' => $swapped, 'elsewhere' => file_get_contents($elsewhere), 'result' => $result['report']['files'] ?? null, 'skipped' => $result['report']['skipped'] ?? null])
         );

         // @ A directory swapped for a link out of the tree WHILE `php -l` validates the fix is
         //   never written through: the run passed its own check before the child started,
         //   so only the write itself, jailed in the scanned tree, can refuse it. The child
         //   is caught and frozen (SIGSTOP) — while it is stopped the run waits on it, so the
         //   swap lands deterministically between the check and the write; a child that
         //   ends before it is caught only costs an attempt, and no catch at all fails loud
         if (function_exists('posix_kill') === false || defined('SIGSTOP') === false || is_dir('/proc/self/task') === false) {
            yield assert(assertion: true, description: 'Skipped: no posix_kill(), signals or /proc to freeze the validating child');
         }
         else {
            $wideSource = "<?php\n\nnamespace Demo;\n\n\nclass Wide { public function run (): int { return strlen('x'); } }\n";
            // ! The descendants of a process, as the kernel lists them
            $descend = static function (int $PID) use (&$descend): array {
               $children = [];
               foreach (explode(' ', trim((string) @file_get_contents("/proc/{$PID}/task/{$PID}/children"))) as $child) {
                  if ($child !== '') {
                     $children[] = (int) $child;
                     $children = [...$children, ...$descend((int) $child)];
                  }
               }

               return $children;
            };
            // ! The state letter of a process (`T` once stopped), or '' when it is gone
            $state = static function (int $PID): string {
               $stat = (string) @file_get_contents("/proc/{$PID}/stat");
               $close = strrpos($stat, ')');

               return ($close === false) ? '' : substr($stat, $close + 2, 1);
            };
            $caught = 0;
            $attempts = 0;
            $frozen = null;
            $outcome = null;
            try {
               while ($caught === 0 && $attempts < 10) {
                  $attempts++;
                  // ! A fresh tree: zone/ a real directory, beyond/ empty
                  if (is_link("{$dir}/frozen/zone")) {
                     @unlink("{$dir}/frozen/zone");
                     @rename("{$dir}/frozen/zone.old", "{$dir}/frozen/zone");
                  }
                  @mkdir("{$dir}/frozen/zone", 0o700, true);
                  @mkdir("{$dir}/beyond", 0o700);
                  file_put_contents("{$dir}/frozen/zone/Wide.php", $wideSource);

                  $Lint = proc_open(
                     [PHP_BINARY, '-d', 'opcache.jit=0', $launcher, 'lint', 'imports', "{$dir}/frozen/", '--fix'],
                     [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                     $lintPipes,
                     BOOTGLY_ROOT_BASE,
                     $environment
                  );
                  if (is_resource($Lint) === false) {
                     break;
                  }
                  /** @var array<int,resource> $lintPipes */
                  $PID = (int) proc_get_status($Lint)['pid'];

                  // @@ Catch the validating child, freeze it, swap the directory, thaw it
                  while ($frozen === null && proc_get_status($Lint)['running'] === true) {
                     foreach ($descend($PID) as $child) {
                        if (@file_get_contents("/proc/{$child}/cmdline") !== PHP_BINARY . "\0-l\0") {
                           continue;
                        }
                        posix_kill($child, SIGSTOP);
                        for ($wait = 0; $wait < 2000 && ($letter = $state($child)) !== 'T' && $letter !== ''; $wait++) {
                           usleep(100);
                        }
                        if ($state($child) === 'T') {
                           $frozen = $child;
                        }
                        else {
                           posix_kill($child, SIGCONT);
                        }
                        break;
                     }
                  }
                  if ($frozen !== null) {
                     rename("{$dir}/frozen/zone", "{$dir}/frozen/zone.old");
                     symlink("{$dir}/beyond", "{$dir}/frozen/zone");
                     posix_kill($frozen, SIGCONT);
                     $frozen = null;
                     $caught = $attempts;
                  }

                  $output = (string) stream_get_contents($lintPipes[1]);
                  fclose($lintPipes[1]);
                  proc_close($Lint);
                  $outcome = json_decode($output, true);
               }
            }
            finally {
               // ? Never leave a stopped process behind
               if ($frozen !== null) {
                  posix_kill($frozen, SIGCONT);
               }
            }
            $beyond = scandir("{$dir}/beyond") ?: [];
            $original = (string) @file_get_contents("{$dir}/frozen/zone.old/Wide.php");
            yield assert(
               assertion: $caught > 0 && $beyond === ['.', '..'] && $original === $wideSource
                  && is_array($outcome)
                  && ($outcome['files']['fixed'] ?? null) === 0
                  && ($outcome['issues']['unresolved'] ?? null) === 1,
               description: 'A directory swapped out of the tree while `php -l` validates must never be written through, got: '
                  . json_encode(['caught' => $caught, 'attempts' => $attempts, 'beyond' => $beyond, 'original' => $original, 'outcome' => $outcome])
            );
         }

         // @ A directory the caller names is scanned whatever its ancestors are called
         @mkdir("{$dir}/examples", 0o700);
         file_put_contents("{$dir}/examples/Sample.php", "<?php\n\nnamespace Demo;\n\n\nclass Sample { public function fooBar (): void {} }\n");
         $result = $run('methods', '--target', "{$dir}/examples/");
         yield assert(
            assertion: $result['status'] !== 0 && ($result['report']['files']['scanned'] ?? null) === 1
               && ($result['report']['issues']['total'] ?? null) === 1,
            description: 'A named `examples/` directory must be scanned, not skipped, got: ' . json_encode($result)
         );

         // @ A path that is not there fails — and still answers with the JSON document
         $result = $run('nullables', '--target', "{$dir}/NoSuchDirectory/");
         yield assert(
            assertion: $result['status'] !== 0 && ($result['report']['result'] ?? null) === 'failed'
               && ($result['report']['files']['scanned'] ?? null) === 0
               && str_contains((string) ($result['report']['message'] ?? ''), 'Path not found'),
            description: '`lint nullables <missing path>` must fail with a JSON document naming the path, got: ' . json_encode($result)
         );

      }
      finally {
         @unlink("{$dir}/swap/zone");
         foreach (glob("{$dir}/swap/zone.old/*") ?: [] as $probe) {
            @unlink($probe);
         }
         @rmdir("{$dir}/swap/zone.old");
         foreach (glob("{$dir}/swap/zone/*") ?: [] as $probe) {
            @unlink($probe);
         }
         @rmdir("{$dir}/swap/zone");
         @unlink("{$dir}/frozen/zone");
         foreach (['frozen/zone.old', 'frozen/zone'] as $sub) {
            foreach (glob("{$dir}/{$sub}/{,.}*.{php,tmp}", GLOB_BRACE) ?: [] as $probe) {
               @unlink($probe);
            }
            @rmdir("{$dir}/{$sub}");
         }
         foreach (['examples', 'locked', 'linked', 'race', 'climb', 'neighbour', 'swap', 'elsewhere', 'frozen', 'beyond'] as $sub) {
            foreach (glob("{$dir}/{$sub}/*") ?: [] as $probe) {
               @chmod($probe, 0o644);
               @unlink($probe);
            }
            @rmdir("{$dir}/{$sub}");
         }
         foreach (glob("{$dir}/*") ?: [] as $probe) {
            @unlink($probe);
         }
         @rmdir($dir);
      }
   }
);
