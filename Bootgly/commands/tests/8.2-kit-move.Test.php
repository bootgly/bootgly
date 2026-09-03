<?php

namespace Bootgly\commands;


use function array_diff;
use function assert;
use function bin2hex;
use function count;
use function explode;
use function file_get_contents;
use function getmypid;
use function hash_file;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function json_decode;
use function ksort;
use function mkdir;
use function random_bytes;
use function rewind;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_contents;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;


/**
 * The move itself, on a cloned kit: `kit upgrade` lands on the newest release
 * with the framework submodule following the pin and the user's data
 * untouched; running again is an explicit no-op; `kit downgrade` walks back
 * one release; a named release goes exactly there; the verb must match the
 * direction; a major crossing and a release predating the command need `--yes`.
 */

return new Test(
   description: '`kit upgrade` / `kit downgrade` move a cloned kit between releases with the submodule following and the user\'s data intact',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-kit-move-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($base, 0775, true);
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
      // ! Every file under a directory, hashed — the user's data as a whole
      $digest = function (string $directory) use (&$digest): array {
         $hashes = [];
         foreach (array_diff((array) scandir($directory), ['.', '..']) as $entry) {
            $path = "{$directory}/{$entry}";
            if (is_dir($path) === true) {
               foreach ($digest($path) as $inner => $hash) {
                  $hashes["{$entry}/{$inner}"] = $hash;
               }
            }
            else {
               $hashes[$entry] = (string) hash_file('sha256', $path);
            }
         }
         ksort($hashes);

         return $hashes;
      };

      try {
         $fixture = (require __DIR__ . '/fixtures/kit_fixture.php')($base);
         $canon = $fixture['canon'];
         $commits = $fixture['commits'];
         $shas = $fixture['shas'];
         $run = $fixture['run'];

         $bind = static function (string $kit) use ($canon): KitCommand {
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
         $probe = static function (KitCommand $Command, array $arguments, array $options): array {
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
            $document = json_decode((string) stream_get_contents($Host->stream), true);

            return [$result, is_array($document) ? $document : []];
         };
         $where = static function (string $kit) use ($run): array {
            return [$run($kit, 'rev-parse HEAD'), $run("{$kit}/Bootgly", 'rev-parse HEAD')];
         };

         $kit = $fixture['clone']('kit', 'refs/tags/v1.0.0-beta.1');
         $Kit = $bind($kit);
         $before = [$digest("{$kit}/projects"), $digest("{$kit}/storage")];

         // # The newest release crosses a major: nothing moves without --yes
         [$result, $document] = $probe($Kit, ['upgrade'], ['json' => true]);

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'refused'
               && ($document['verb'] ?? null) === 'upgrade' && ($document['target']['tag'] ?? null) === 'v2.0.0'
               && str_contains($document['reason'] ?? '', 'Not confirmed')
               && $where($kit) === [$commits['v1.0.0-beta.1'], $shas['v1.0.0-beta.1']],
            description: 'a major crossing is refused under --json without --yes, and the kit does not move'
         );

         [$result, $document] = $probe($Kit, ['upgrade'], ['json' => true, 'yes' => true]);

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'moved'
               && ($document['current']['tag'] ?? null) === 'v1.0.0-beta.1' && ($document['target']['tag'] ?? null) === 'v2.0.0'
               && $where($kit) === [$commits['v2.0.0'], $shas['v2.0.0']],
            description: '`kit upgrade --yes` lands the kit on the newest release with the framework submodule on its pin'
         );
         $expected = $before[1] + [
            'seed.json' => (string) hash_file('sha256', "{$kit}/storage/seed.json"),
            'café.json' => (string) hash_file('sha256', "{$kit}/storage/café.json"),
         ];
         ksort($expected);

         yield assert(
            assertion: [$digest("{$kit}/projects"), $digest("{$kit}/storage")] === [$before[0], $expected]
               && file_get_contents("{$kit}/Bootgly/autoboot.php") === "<?php // v2.0.0\n"
               && file_get_contents("{$kit}/README.md") === "# Kit v2.0.0\n",
            description: 'every file of the user\'s projects/ and storage/ is byte-identical (the release only ADDED its two tracked files); the kit and framework files are the release\'s'
         );

         // # Running again is an explicit no-op
         [$result, $document] = $probe($Kit, ['upgrade'], ['json' => true, 'yes' => true]);

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'noop'
               && str_contains($document['reason'] ?? '', 'already on the newest release')
               && $where($kit) === [$commits['v2.0.0'], $shas['v2.0.0']],
            description: 'upgrading a kit already on the newest release is a no-op that says so'
         );

         // # Downgrade walks back one release
         [$result, $document] = $probe($Kit, ['downgrade'], ['json' => true, 'yes' => true]);

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'moved' && ($document['verb'] ?? null) === 'downgrade'
               && ($document['target']['tag'] ?? null) === 'v1.0.0' && ($document['notes'] ?? null) === "Stable\n\nThe first stable release."
               && $where($kit) === [$commits['v1.0.0'], $shas['v1.0.0']] && is_file("{$kit}/storage/seed.json") === false,
            description: '`kit downgrade` goes to the previous release (v1.0.0, not the beta below it), reports its notes, and the release\'s seed leaves with it'
         );

         // # A named release goes exactly there — in either direction
         //   v1.0.0-beta.1 predates the command: the move says so and waits for --yes
         [$result, $document] = $probe($Kit, ['downgrade', 'v1.0.0-beta.1'], ['json' => true]);

         yield assert(
            assertion: $result === false && ($document['predates'] ?? null) === true
               && str_contains($document['reason'] ?? '', 'Not confirmed')
               && $where($kit) === [$commits['v1.0.0'], $shas['v1.0.0']],
            description: 'a release whose framework predates `kit upgrade` is flagged and, headless, needs --yes'
         );

         [$result, $document] = $probe($Kit, ['downgrade', 'v1.0.0-beta.1'], ['json' => true, 'yes' => true]);

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'moved'
               && $where($kit) === [$commits['v1.0.0-beta.1'], $shas['v1.0.0-beta.1']],
            description: '`kit downgrade <release> --yes` lands on the named release, skipping the ones between'
         );

         [$result, $document] = $probe($Kit, ['upgrade', '1.0.0-beta.2'], ['json' => true]);

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'moved'
               && $where($kit) === [$commits['v1.0.0-beta.2'], $shas['v1.0.0-beta.2']],
            description: '`kit upgrade <release>` accepts the version without its `v` and lands on the tag'
         );

         // # The verb must match the direction
         [$result, $document] = $probe($Kit, ['upgrade', 'v1.0.0-beta.1'], ['json' => true]);

         yield assert(
            assertion: $result === false && str_contains($document['reason'] ?? '', 'older')
               && str_contains($document['detail'] ?? '', 'bootgly kit downgrade v1.0.0-beta.1')
               && $where($kit) === [$commits['v1.0.0-beta.2'], $shas['v1.0.0-beta.2']],
            description: '`kit upgrade` to an older release is refused with the `kit downgrade` hint'
         );

         [$result, $document] = $probe($Kit, ['downgrade', 'v2.0.0'], ['json' => true]);

         yield assert(
            assertion: $result === false && str_contains($document['reason'] ?? '', 'newer')
               && str_contains($document['detail'] ?? '', 'bootgly kit upgrade v2.0.0'),
            description: '`kit downgrade` to a newer release is refused with the `kit upgrade` hint'
         );

         [$result, $document] = $probe($Kit, ['upgrade', 'v1.0.0-beta.2'], ['json' => true]);

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'noop'
               && str_contains($document['reason'] ?? '', 'already on'),
            description: 'naming the release the kit is on is a no-op'
         );

         // # Unknown names — echoed clean of markup and control bytes
         [$result, $document] = $probe($Kit, ['upgrade', 'v9.9.9'], ['json' => true]);
         [$again, $other] = $probe($Kit, ['upgrade', "ma@in\e[31m\u{202E}\u{200B}"], ['json' => true]);

         yield assert(
            assertion: $result === false && str_contains($document['reason'] ?? '', 'No release v9.9.9')
               && $again === false && ($other['reason'] ?? '') === 'main[31m is not a release name.',
            description: 'an unknown release and a name that is no version are both refused, the name cleaned of markup, controls and bidi overrides before it is shown'
         );

         // # Nothing below the earliest
         [$result, $document] = $probe($Kit, ['downgrade', 'v1.0.0-beta.1'], ['json' => true, 'yes' => true]);
         [$again, $other] = $probe($Kit, ['downgrade'], ['json' => true]);

         yield assert(
            assertion: $result === true && $again === true && ($other['status'] ?? null) === 'noop'
               && str_contains($other['reason'] ?? '', 'earliest release'),
            description: '`kit downgrade` on the earliest release is a no-op that says so'
         );

         // # A kit past a release: `upgrade` RETURNS it to the release it is past — and says so
         $past = $fixture['clone']('kit-past', $commits['past']);
         $Past = $bind($past);
         $Host = new Output('php://memory');
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Host;
         try {
            $result = $Past->run(['upgrade', 'v1.0.0'], []);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         $spoken = (string) stream_get_contents($Host->stream);

         yield assert(
            assertion: $result === true && str_contains($spoken, 'Returning the kit to') && str_contains($spoken, '(1 commit back)')
               && str_contains($spoken, 'The kit is on') && $where($past) === [$commits['v1.0.0'], $shas['v1.0.0']],
            description: 'a kit one commit past v1.0.0 is returned onto v1.0.0 when that release is named — worded as a return, never `X → X`'
         );

         // # `--json -v`: the fetch is verbose for people only — one document, nothing else
         $Verbose = $bind($kit);
         $Verbose->verbosity = 1;
         $Host = new Output('php://memory');
         $Terminal->Output = $Host;
         try {
            $Verbose->run(['downgrade', 'v1.0.0'], ['json' => true, 'yes' => true]);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         $lines = explode("\n", trim((string) stream_get_contents($Host->stream)));

         yield assert(
            assertion: count($lines) === 1 && is_array(json_decode($lines[0], true)),
            description: '`--json -v` still emits exactly one line, the document'
         );
      }
      finally {
         $erase($base);
      }
   }
);
