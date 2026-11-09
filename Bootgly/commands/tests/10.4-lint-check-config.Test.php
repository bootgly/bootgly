<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const PHP_BINARY;
use function array_filter;
use function array_key_exists;
use function assert;
use function count;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getenv;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_decode;
use function json_encode;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function sprintf;
use function str_contains;
use function str_replace;
use function stream_get_contents;
use function symlink;
use function unlink;
use function var_export;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Agent;


/**
 * `bootgly lint check` driven by a `bootgly.Lint.php`: no file means the
 * four rules of 1.1; a file sets the paths, excludes, rules and exemptions;
 * a broken file fails loud; a stale exemption fails the run; `--fix` applies
 * every fixable rule; the per-rule subcommands are deprecated wrappers.
 */

return new Test(
   description: '`lint check`: defaults without a file, configured paths/excludes/rules/exemptions, malformed file and stale exemptions fail, multi-rule --fix, deprecated wrappers',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }
      $autoboot = BOOTGLY_ROOT_BASE . '/autoboot.php';
      if (is_file($autoboot) === false) {
         yield assert(assertion: true, description: 'Skipped: the framework autoboot is not at the root');
         return;
      }

      // ! A miniature workspace: a launcher whose working base is the
      //   workspace, one project, configured sources, a vendor tree and fixtures
      $root = Temporaries::reserve('lint-check-config');
      $Remove = static function (string $path) use (&$Remove): void {
         if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
               if ($entry !== '.' && $entry !== '..') {
                  $Remove("{$path}/{$entry}");
               }
            }
            @rmdir($path);
            return;
         }
         @unlink($path);
      };

      try {
         foreach (['projects/Alpha', 'src/tests/fixtures', 'vendor', 'other'] as $directory) {
            mkdir("{$root}/{$directory}", 0o775, true);
         }
         file_put_contents(
            "{$root}/bootgly",
            "<?php\n"
               . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
               . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
               . 'require ' . var_export($autoboot, true) . ";\n"
         );
         file_put_contents("{$root}/projects/Bootgly.projects.php", "<?php\n\nreturn [];\n");

         // ! One nullable shorthand and one call gap per probe
         $probe = static fn (string $class): string => "<?php\n\nnamespace Demo;\n\n\nuse function count;\n\n\n"
            . "class {$class}\n{\n   public function run (null|array \$a, ?int \$b): int { return count (\$a ?? []) + (int) \$b; }\n}\n";
         file_put_contents("{$root}/projects/Alpha/Probe.php", $probe('Probe'));
         file_put_contents("{$root}/src/Bad.php", $probe('Bad'));
         file_put_contents("{$root}/vendor/Vendored.php", $probe('Vendored'));
         file_put_contents("{$root}/src/tests/fixtures/Fixture.php", $probe('Fixture'));
         file_put_contents("{$root}/other/Other.php", $probe('Other'));
         // ! A legacy method name, waived for its class only
         file_put_contents(
            "{$root}/src/Legacy.php",
            "<?php\n\nnamespace Demo;\n\n\nclass Legacy\n{\n   public function fooBar (): void {}\n}\n\nclass Fresh\n{\n   public function fooBar (): void {}\n}\n"
         );

         // ! Agent environment — the JSON contract is what the assertions read
         $environment = getenv();
         $environment['AI_AGENT'] = '1';

         $Run = static function (string $cwd, array $arguments, null|array $variables = null) use ($root, $environment): array {
            $process = proc_open(
               [PHP_BINARY, '-d', 'opcache.jit=0', "{$root}/bootgly", 'lint', ...$arguments],
               [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
               $pipes,
               $cwd,
               $variables ?? $environment
            );
            if (is_resource($process) === false) {
               return ['status' => -1, 'report' => null, 'output' => ''];
            }
            /** @var array<int,resource> $pipes */
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            $report = json_decode($output, true);

            return ['status' => $status, 'report' => is_array($report) ? $report : null, 'output' => $output];
         };
         $Rules = static function (array $result): array {
            $found = [];
            foreach ($result['report']['report'] ?? [] as $entry) {
               foreach ($entry['issues'] as $issue) {
                  $found[] = "{$entry['file']}:{$issue['rule']}:{$issue['symbol']}";
               }
            }

            return $found;
         };

         // @ No file — the four rules of 1.1 over the project the caller stands in
         $result = $Run("{$root}/projects/Alpha", ['check']);
         yield assert(
            assertion: $result['status'] !== 0
               && ($result['report']['command'] ?? null) === 'check'
               && ($result['report']['rules'] ?? null) === ['imports', 'nullables', 'promotions', 'methods']
               && array_key_exists('config', $result['report'] ?? []) && $result['report']['config'] === null
               && $Rules($result) === ['projects/Alpha/Probe.php:nullables:int'],
            description: 'Without bootgly.Lint.php, `check` must run imports/nullables/promotions/methods only, got: ' . json_encode($result['report'])
         );

         // ! The configuration
         $config = "<?php\n\nuse Bootgly\\ABI\\Syntax\\Analyzers\\Rules;\nuse Bootgly\\ABI\\Syntax\\Lint;\nuse Bootgly\\ABI\\Syntax\\Lint\\Exemption;\n\n"
            . "return new Lint(\n"
            . "   version: 1,\n"
            . "   paths: ['src/', 'vendor/'],\n"
            . "   excludes: ['vendor/', '**/tests/fixtures/**'],\n"
            . "   rules: [Rules::Nullables, Rules::Spacing, Rules::Methods],\n"
            . "   Exemptions: [\n"
            . "      new Exemption(rule: Rules::Methods, path: 'src/Legacy.php', reason: 'Public API until 2.0', symbol: 'Legacy::fooBar'),\n"
            . "      %s"
            . "   ]\n"
            . ");\n";
         file_put_contents("{$root}/bootgly.Lint.php", sprintf($config, ''));

         // @ Configured: paths, excludes, rules and a class-narrowed exemption
         $result = $Run($root, ['check']);
         $found = $Rules($result);
         yield assert(
            assertion: $result['status'] !== 0
               && ($result['report']['rules'] ?? null) === ['nullables', 'spacing', 'methods']
               && ($result['report']['config'] ?? null) === 'bootgly.Lint.php'
               && $found === ['src/Bad.php:nullables:int', 'src/Bad.php:spacing:count', 'src/Legacy.php:methods:fooBar']
               && ($result['report']['issues']['exempted'] ?? null) === 1
               && ($result['report']['exemptions'] ?? null) === ['applied' => 1, 'stale' => []]
               && ($result['report']['files']['scanned'] ?? null) === 2,
            description: 'The file must set paths (vendor/ and fixtures excluded, other/ unlisted), the rules and Legacy::fooBar waived only in Legacy, got: '
               . json_encode($result['report'])
         );

         // @ The fixed line of Fresh is the waived one's twin — it stays reported
         yield assert(
            assertion: count($found) === 3,
            description: 'Fresh::fooBar must stay reported: the symbol names the class, got: ' . json_encode($found)
         );

         // @ A path narrows inside the configured paths; one outside them fails
         $narrowed = $Run($root, ['check', 'src/Bad.php']);
         $outside = $Run($root, ['check', 'other/']);
         $missing = $Run($root, ['check', 'src/Nope.php']);
         yield assert(
            assertion: ($narrowed['report']['files']['scanned'] ?? null) === 1
               && $Rules($narrowed) === ['src/Bad.php:nullables:int', 'src/Bad.php:spacing:count']
               && $outside['status'] !== 0 && str_contains((string) ($outside['report']['message'] ?? ''), 'outside the configured paths')
               && $missing['status'] !== 0 && str_contains((string) ($missing['report']['message'] ?? ''), 'Path not found'),
            description: '`check <path>` must narrow inside the configured paths and refuse one outside or missing, got: '
               . json_encode([$narrowed['report'], $outside['report'], $missing['report']])
         );

         // @ Stale exemptions fail the run — a glob that matches nothing, a waiver that waives nothing
         file_put_contents("{$root}/bootgly.Lint.php", sprintf($config,
            "new Exemption(rule: Rules::Spacing, path: 'src/Gone.php', reason: 'Removed file'),\n"
            . "      new Exemption(rule: Rules::Methods, path: 'src/Bad.php', reason: 'Nothing to waive', symbol: 'run'),\n"
         ));
         $result = $Run($root, ['check']);
         $stale = [];
         foreach ($result['report']['exemptions']['stale'] ?? [] as $entry) {
            $stale[] = "{$entry['path']}:{$entry['why']}";
         }
         $narrowed = $Run($root, ['check', 'src/Legacy.php']);
         $narrowedStale = [];
         foreach ($narrowed['report']['exemptions']['stale'] ?? [] as $entry) {
            $narrowedStale[] = $entry['path'];
         }
         yield assert(
            assertion: $result['status'] !== 0
               && $stale === ['src/Gone.php:matches no checked file', 'src/Bad.php:waives nothing in the files it matches']
               && $narrowedStale === [],
            description: 'Stale exemptions must be reported (and fail the run) on a full check, never for files a narrowed run did not reach, got: '
               . json_encode([$result['report']['exemptions'] ?? null, $narrowed['report']['exemptions'] ?? null])
         );

         // @ --fix applies every fixable rule in one run; a check-only rule is reported
         file_put_contents("{$root}/bootgly.Lint.php", sprintf($config, ''));
         $result = $Run($root, ['check', 'src/', '--fix']);
         $fixed = (string) file_get_contents("{$root}/src/Bad.php");
         yield assert(
            assertion: ($result['report']['mode'] ?? null) === 'fix'
               && ($result['report']['files']['fixed'] ?? null) === 1
               && str_contains($fixed, 'null|int $b') && str_contains($fixed, 'count($a ?? [])')
               && $Rules($result) === ['src/Bad.php:nullables:int', 'src/Bad.php:spacing:count', 'src/Legacy.php:methods:fooBar']
               && ($result['report']['report'][0]['fixed'] ?? null) === true
               && ($result['report']['report'][1]['fixed'] ?? null) === false
               && ($result['report']['issues']['unresolved'] ?? null) === 1
               && $result['status'] !== 0,
            description: '`check --fix` must rewrite both nullables and spacing in one pass (reported as fixed) and leave the methods issue unresolved, got: '
               . json_encode(['report' => $result['report'], 'file' => $fixed])
         );

         // @ A deprecated wrapper runs its one rule, under the file's excludes and exemptions
         file_put_contents("{$root}/src/Bad.php", $probe('Bad'));
         $result = $Run($root, ['nullables', 'src/']);
         yield assert(
            assertion: ($result['report']['submodule'] ?? null) === 'nullables'
               && ($result['report']['deprecated'] ?? null) === 'use bootgly lint check'
               && ($result['report']['fixable'] ?? null) === true
               && $Rules($result) === ['src/Bad.php:nullables:int'],
            description: '`lint nullables` must answer in the legacy shape, say it is deprecated and run only its rule, got: ' . json_encode($result['report'])
         );
         // ! The human branch — no agent marker at all
         $scrubbed = getenv();
         unset($scrubbed['AI_AGENT']);
         foreach (Agent::MARKERS as $variables) {
            foreach ($variables as $variable) {
               unset($scrubbed[$variable]);
            }
         }
         $human = $Run($root, ['methods', 'src/'], $scrubbed);
         yield assert(
            assertion: $human['report'] === null && str_contains($human['output'], 'is deprecated') && str_contains($human['output'], 'bootgly lint check'),
            description: 'The human output of a deprecated subcommand must say so, got: ' . $human['output']
         );

         // @ Under a file, `examples/` and `vs/` are checked unless excluded — only
         //   the defaults skip them
         mkdir("{$root}/src/examples");
         file_put_contents("{$root}/src/examples/Example.php", $probe('Example'));
         file_put_contents("{$root}/bootgly.Lint.php", sprintf($config, ''));
         $result = $Run($root, ['check']);
         $Remove("{$root}/src/examples");
         yield assert(
            assertion: $result['status'] !== 0
               && $Rules($result) === [
                  'src/Bad.php:nullables:int', 'src/Bad.php:spacing:count',
                  'src/Legacy.php:methods:fooBar',
                  'src/examples/Example.php:nullables:int', 'src/examples/Example.php:spacing:count',
               ],
            description: 'A configured path must check its examples/ directory (no hidden skip), got: ' . json_encode($result['report'])
         );

         // @ A `./` spelling — of the path given, or of a configured path — never
         //   escapes the excludes or the exemptions, and `--fix` never rewrites
         //   an excluded file
         //   (an anchored exclude: `**/` would match a `./` segment anyway)
         $anchored = str_replace("'**/tests/fixtures/**'", "'src/tests/fixtures/'", sprintf($config, ''));
         file_put_contents("{$root}/bootgly.Lint.php", $anchored);
         $pristine = (string) file_get_contents("{$root}/src/tests/fixtures/Fixture.php");
         $dotted = $Run($root, ['check', './src/', '--fix']);
         file_put_contents("{$root}/src/Bad.php", $probe('Bad'));
         file_put_contents("{$root}/bootgly.Lint.php", str_replace("paths: ['src/', 'vendor/']", "paths: ['./src/', 'vendor/']", $anchored));
         $configured = $Run($root, ['check', '--fix']);
         file_put_contents("{$root}/src/Bad.php", $probe('Bad'));
         $kept = [];
         foreach (['given' => $dotted, 'configured' => $configured] as $label => $result) {
            $kept[$label] = [
               $Rules($result),
               $result['report']['issues']['exempted'] ?? null,
               $result['report']['exemptions']['stale'] ?? null,
            ];
         }
         $expected = [['src/Bad.php:nullables:int', 'src/Bad.php:spacing:count', 'src/Legacy.php:methods:fooBar'], 1, []];
         yield assert(
            assertion: $kept === ['given' => $expected, 'configured' => $expected]
               && file_get_contents("{$root}/src/tests/fixtures/Fixture.php") === $pristine,
            description: 'A ./ path must keep the excludes and the exemptions (the excluded fixture untouched), got: ' . json_encode($kept)
         );

         // @ An exemption on a fixable rule: `--fix` rewrites only what is not
         //   waived, and counts the waived issue once
         file_put_contents("{$root}/bootgly.Lint.php", sprintf($config,
            "new Exemption(rule: Rules::Spacing, path: 'src/Bad.php', reason: 'Kept on purpose', symbol: 'count'),\n"
         ));
         $checked = $Run($root, ['check', 'src/']);
         $result = $Run($root, ['check', 'src/', '--fix']);
         $fixed = (string) file_get_contents("{$root}/src/Bad.php");
         file_put_contents("{$root}/src/Bad.php", $probe('Bad'));
         yield assert(
            assertion: ($checked['report']['issues']['exempted'] ?? null) === 2
               && ($result['report']['issues']['exempted'] ?? null) === 2
               && str_contains($fixed, 'null|int $b') && str_contains($fixed, 'count ($a ?? [])')
               && ($result['report']['report'][0]['fixed'] ?? null) === true,
            description: '`--fix` must leave the waived spacing in place and count each waived issue once, got: '
               . json_encode(['checked' => $checked['report']['issues'] ?? null, 'fixed' => $result['report'], 'file' => $fixed])
         );

         // @ No PHP file in the configured paths: the exemptions still go stale
         mkdir("{$root}/empty");
         file_put_contents("{$root}/bootgly.Lint.php",
            "<?php\n\nuse Bootgly\\ABI\\Syntax\\Analyzers\\Rules;\nuse Bootgly\\ABI\\Syntax\\Lint;\nuse Bootgly\\ABI\\Syntax\\Lint\\Exemption;\n\n"
            . "return new Lint(version: 1, paths: ['empty/'], Exemptions: [new Exemption(rule: Rules::Methods, path: 'nowhere/*.php', reason: 'Gone')]);\n"
         );
         $result = $Run($root, ['check']);
         $Remove("{$root}/empty");
         yield assert(
            assertion: $result['status'] !== 0
               && ($result['report']['result'] ?? null) === 'failed'
               && ($result['report']['exemptions']['stale'][0]['why'] ?? null) === 'matches no checked file',
            description: 'An empty file set must not hide a stale exemption, got: ' . json_encode($result['report'])
         );

         // @ A configured path that is a link: a narrowed run answers through the
         //   configured spelling, so its excludes hold and --fix leaves them alone
         mkdir("{$root}/real/gen", 0o775, true);
         file_put_contents("{$root}/real/gen/Generated.php", $probe('Generated'));
         $linked = symlink("{$root}/real", "{$root}/linked");
         file_put_contents("{$root}/bootgly.Lint.php",
            "<?php\n\nuse Bootgly\\ABI\\Syntax\\Analyzers\\Rules;\nuse Bootgly\\ABI\\Syntax\\Lint;\n\n"
            . "return new Lint(version: 1, paths: ['linked/'], excludes: ['linked/gen/'], rules: [Rules::Nullables, Rules::Spacing]);\n"
         );
         $result = $Run($root, ['check', 'linked/gen', '--fix']);
         // ! The same through a deprecated wrapper
         $wrapper = $Run($root, ['nullables', 'linked/gen', '--fix']);
         $generated = (string) file_get_contents("{$root}/real/gen/Generated.php");
         if ($linked) {
            unlink("{$root}/linked");
         }
         $Remove("{$root}/real");
         yield assert(
            assertion: $linked === false || (
               ($result['report']['files']['scanned'] ?? null) === 0
               && ($wrapper['report']['files']['scanned'] ?? null) === 0
               && $generated === $probe('Generated')
            ),
            description: 'A narrowed run (check or wrapper) through a linked configured path must keep its excludes (the file untouched), got: '
               . json_encode(['report' => $result['report'], 'wrapper' => $wrapper['report'], 'file' => $generated])
         );

         // @ A pinned tree of the kit: `--fix` over a path that holds it is
         //   refused unless an exclude covers the WHOLE tree (`Bootgly/*` only
         //   covers its first level)
         mkdir("{$root}/Bootgly/ABI", 0o775, true);
         $pinned = [];
         foreach (["[]", "['Bootgly/*', 'vendor/']", "['Bootgly/*', 'Bootgly/a/**', 'vendor/']", "['Bootgly/', 'vendor/']"] as $excludes) {
            file_put_contents("{$root}/Bootgly/ABI/Pinned.php", $probe('Pinned'));
            file_put_contents("{$root}/bootgly.Lint.php",
               "<?php\n\nuse Bootgly\\ABI\\Syntax\\Analyzers\\Rules;\nuse Bootgly\\ABI\\Syntax\\Lint;\n\n"
               . "return new Lint(version: 1, paths: ['.'], excludes: {$excludes}, rules: [Rules::Spacing]);\n"
            );
            $result = $Run($root, ['check', '--fix']);
            $pinned[$excludes] = [
               str_contains((string) ($result['report']['message'] ?? ''), 'pinned tree'),
               file_get_contents("{$root}/Bootgly/ABI/Pinned.php") === $probe('Pinned'),
               $result['status'] !== 0,
            ];
         }
         $Remove("{$root}/Bootgly");
         file_put_contents("{$root}/src/Bad.php", $probe('Bad'));
         yield assert(
            assertion: $pinned === [
               // # Refused up front
               "[]"                       => [true, true, true],
               "['Bootgly/*', 'vendor/']" => [true, true, true],
               // # Past the up-front probe (it matches `Bootgly/` and one deep
               //   path): the write itself is refused, and the run fails
               "['Bootgly/*', 'Bootgly/a/**', 'vendor/']" => [false, true, true],
               // # Excluded whole: never collected
               "['Bootgly/', 'vendor/']"  => [false, true, false],
            ],
            description: '--fix must refuse to hold a pinned tree its excludes do not cover whole, and never rewrite it, got: ' . json_encode($pinned)
         );

         // @ A deprecated wrapper under the file: its path, however spelled
         //   (`..`, a link), meets the excludes as `check` does
         mkdir("{$root}/src/gen");
         mkdir("{$root}/src/sub");
         file_put_contents("{$root}/src/gen/Generated.php", $probe('Generated'));
         file_put_contents("{$root}/bootgly.Lint.php",
            "<?php\n\nuse Bootgly\\ABI\\Syntax\\Analyzers\\Rules;\nuse Bootgly\\ABI\\Syntax\\Lint;\n\n"
            . "return new Lint(version: 1, paths: ['src/'], excludes: ['src/gen/', 'src/tests/fixtures/'], rules: [Rules::Nullables]);\n"
         );
         $wrapped = [];
         $wrapped['..'] = $Run("{$root}/src/sub", ['nullables', '../gen', '--fix']);
         $aliased = symlink($root, "{$root}.alias");
         if ($aliased) {
            $wrapped['link'] = $Run($root, ['nullables', "{$root}.alias/src/gen", '--fix']);
            unlink("{$root}.alias");
         }
         $dirlinked = symlink('gen', "{$root}/src/genlink");
         if ($dirlinked) {
            $wrapped['dir link'] = $Run($root, ['nullables', 'src/genlink', '--fix']);
            unlink("{$root}/src/genlink");
         }
         $untouched = file_get_contents("{$root}/src/gen/Generated.php") === $probe('Generated');
         // ! A file named through a link out of the tree is refused, never written through
         mkdir("{$root}.outside/vendor", 0o775, true);
         file_put_contents("{$root}.outside/Out.php", $probe('Out'));
         file_put_contents("{$root}.outside/vendor/Vendored.php", $probe('Vendored'));
         $leaf = symlink("{$root}.outside/Out.php", "{$root}/src/Link.php");
         $through = $leaf ? $Run($root, ['nullables', 'src/Link.php', '--fix']) : null;
         $outside = file_get_contents("{$root}.outside/Out.php") === $probe('Out');
         // ! A path the file does not govern keeps the built-in skips (vendor/)
         $foreign = $Run($root, ['nullables', "{$root}.outside", '--dry-run']);
         // ! A check-only rule with --fix runs as a check, whatever it finds
         $checkOnly = $Run($root, ['methods', 'src/sub', '--fix']);
         $outlinked = symlink("{$root}.outside", "{$root}/src/outlink");
         $outlink = $outlinked ? $Run($root, ['nullables', 'src/outlink', '--dry-run']) : null;
         if ($outlinked) {
            unlink("{$root}/src/outlink");
         }
         if ($leaf) {
            unlink("{$root}/src/Link.php");
         }
         $Remove("{$root}.outside");
         $Remove("{$root}/src/gen");
         $Remove("{$root}/src/sub");
         $scanned = [];
         foreach ($wrapped as $label => $result) {
            $scanned[$label] = $result['report']['files']['scanned'] ?? null;
         }
         yield assert(
            assertion: $untouched && $scanned === array_filter(
               ['..' => 0, 'link' => $aliased ? 0 : null, 'dir link' => $dirlinked ? 0 : null],
               static fn (null|int $count): bool => $count !== null
            ),
            description: 'A wrapper path through `..` or a link must keep the excludes (the excluded file untouched), got: '
               . json_encode(['scanned' => $scanned, 'untouched' => $untouched])
         );
         yield assert(
            assertion: $outside
               && ($leaf === false || (($through['report']['files']['skipped'] ?? null) === 1
                  && str_contains((string) ($through['report']['skipped'][0]['notice'] ?? ''), 'A link')))
               && ($foreign['report']['files']['scanned'] ?? null) === 1
               && ($outlinked === false || ($outlink['report']['files']['scanned'] ?? null) === 1),
            description: 'A wrapper must refuse a file linked out of the tree and keep the vendor/ skip outside the file\'s directory, got: '
               . json_encode(['through' => $through['report'] ?? null, 'outside' => $outside, 'foreign' => $foreign['report'], 'outlink' => $outlink['report'] ?? null])
         );
         yield assert(
            assertion: ($checkOnly['report']['mode'] ?? null) === 'check',
            description: 'A check-only rule run with --fix must report the check mode, with or without files, got: ' . json_encode($checkOnly['report'])
         );

         // @@ A broken file fails loud — never the defaults
         $broken = [
            'parse error' => "<?php\nreturn new ;\n",
            'not a Lint'  => "<?php\nreturn ['paths' => ['src/']];\n",
            'bad entry'   => "<?php\nreturn new Bootgly\\ABI\\Syntax\\Lint(version: 1, paths: ['/etc/']);\n",
            'no version'  => "<?php\nreturn new Bootgly\\ABI\\Syntax\\Lint(paths: ['src/']);\n",
            'later version' => "<?php\nreturn new Bootgly\\ABI\\Syntax\\Lint(version: 2, paths: ['src/']);\n",
         ];
         $wrong = [];
         foreach ($broken as $label => $content) {
            file_put_contents("{$root}/bootgly.Lint.php", $content);
            foreach ([['check'], ['imports', 'src/']] as $arguments) {
               $result = $Run($root, $arguments);
               if ($result['status'] === 0
                  || ($result['report']['result'] ?? null) !== 'failed'
                  || str_contains((string) ($result['report']['message'] ?? ''), 'Malformed bootgly.Lint.php') === false
                  || ($result['report']['files']['scanned'] ?? null) !== 0
               ) {
                  $wrong[] = [$label, $arguments[0], $result['report']];
               }
            }
         }
         yield assert(
            assertion: $wrong === [],
            description: 'A broken bootgly.Lint.php must fail `check` and the wrappers with the file and the reason, got: ' . json_encode($wrong)
         );
      }
      finally {
         if (is_link("{$root}.alias")) {
            unlink("{$root}.alias");
         }
         foreach (['Link.php', 'genlink', 'outlink'] as $link) {
            if (is_link("{$root}/src/{$link}")) {
               unlink("{$root}/src/{$link}");
            }
         }
         $Remove("{$root}.outside");
         $Remove($root);
      }
   }
);
