<?php

namespace Bootgly\commands;


use function array_column;
use function array_diff;
use function assert;
use function bin2hex;
use function count;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function getmypid;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rewind;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_contents;
use function substr;
use function sys_get_temp_dir;
use function unlink;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;


/**
 * The template population: a kit generated from the GitHub template has a
 * squashed history and no upstream — `kit upgrade` adds the canonical
 * remote, fetches its releases and moves the kit like any other. And a
 * remote that cannot be reached degrades to the releases already known,
 * with a warning.
 */

return new Test(
   description: 'a template-generated kit (no upstream) upgrades through the canonical remote; an unreachable remote falls back to local releases',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-kit-template-' . getmypid() . '-' . bin2hex(random_bytes(4));
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

      try {
         $fixture = (require __DIR__ . '/fixtures/kit_fixture.php')($base);
         $canon = $fixture['canon'];
         $commits = $fixture['commits'];
         $shas = $fixture['shas'];
         $run = $fixture['run'];

         $bind = static function (string $kit, string $repository): KitCommand {
            return new class ($kit, $repository) extends KitCommand {
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

         // # Generated from the template at v1.0.0-beta.1: one squashed commit, no remote
         $kit = $fixture['template']('kit', 'v1.0.0-beta.1');
         $squashed = $run($kit, 'rev-parse HEAD');

         yield assert(
            assertion: $run($kit, 'remote') === '' && $run($kit, 'tag --no-column -l') === '',
            description: 'probe precondition: the template kit has no remote and no tag'
         );

         [$result, $document] = $probe($bind($kit, $canon), ['upgrade', 'v1.0.0'], ['json' => true]);

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'moved'
               && ($document['remote'] ?? null) === 'bootgly' && ($document['added'] ?? null) === true
               && $run($kit, 'remote get-url bootgly') === $canon
               && ($document['current']['source'] ?? null) === 'pin' && ($document['current']['tag'] ?? null) === 'v1.0.0-beta.1',
            description: 'the canonical remote is added as `bootgly`, the kit is located by its Bootgly pin and moves'
         );
         yield assert(
            assertion: $run($kit, 'rev-parse HEAD') === $commits['v1.0.0'] && $run("{$kit}/Bootgly", 'rev-parse HEAD') === $shas['v1.0.0']
               && file_get_contents("{$kit}/projects/App/notes.txt") === "mine\n" && $squashed !== $commits['v1.0.0'],
            description: 'the template kit lands on the release with the submodule on its pin and projects/ intact'
         );

         // # From there it is a kit on a release like any other
         [$result, $document] = $probe($bind($kit, $canon), ['downgrade'], ['json' => true]);

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'moved'
               && ($document['current']['source'] ?? null) === 'tag' && $run($kit, 'rev-parse HEAD') === $commits['v1.0.0-beta.2'],
            description: 'once on a release, `kit downgrade` walks the template kit back one release'
         );

         // # A template kit whose pin IS the newest release is placed on its tag — worded so
         $placed = $fixture['template']('placed', 'v2.0.0');
         $Placed = $bind($placed, $canon);
         $Host = new Output('php://memory');
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Host;
         try {
            $result = $Placed->run(['upgrade'], []);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         $spoken = (string) stream_get_contents($Host->stream);

         yield assert(
            assertion: $result === true && str_contains($spoken, 'Placing the kit on') && str_contains($spoken, 'by the Bootgly pin only')
               && str_contains($spoken, 'commits back') === false && $run($placed, 'rev-parse HEAD') === $commits['v2.0.0'],
            description: 'a template kit already pinned at the newest release is "placed" on the tag — never "returned N commits back"'
         );

         // # The remote cannot be reached: the releases already fetched still serve
         $stale = $fixture['clone']('stale', 'refs/tags/v1.0.0-beta.1');
         $run($stale, 'remote set-url origin ' . escapeshellarg("{$base}/nowhere"));
         [$result, $document] = $probe($bind($stale, "{$base}/nowhere"), ['list'], ['json' => true]);

         yield assert(
            assertion: $result === true && ($document['fetched'] ?? null) === false && ($document['status'] ?? null) === 'listed'
               && count($document['releases'] ?? []) === 4 && ($document['remote'] ?? null) === 'origin'
               && ($document['verified'] ?? null) === false,
            description: 'an unreachable repository is a warning — the list still comes from the tags already known, marked unverified'
         );

         // # ...but nothing MOVES on releases that could not be verified: a fork's tag would pass for one
         [$result, $document] = $probe($bind($stale, "{$base}/nowhere"), ['upgrade'], ['json' => true, 'yes' => true]);

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'refused'
               && str_contains($document['reason'] ?? '', 'could not be checked')
               && $run($stale, 'rev-parse HEAD') === $commits['v1.0.0-beta.1'],
            description: 'with the canonical remote unreachable, `kit upgrade` refuses — unverified releases are listed, never moved to'
         );

         // # Provenance: a version-shaped tag from a FORK is not a release — the
         //   canonical remote never advertised it, however the kit came to hold it
         $fork = "{$base}/fork";
         exec('git clone --quiet --bare ' . escapeshellarg($canon) . ' ' . escapeshellarg($fork) . ' 2>/dev/null');
         $run($fork, 'tag v99.0.0 ' . $commits['v1.0.0-beta.1']);
         $forked = $fixture['clone']('forked', 'refs/tags/v1.0.0-beta.1');
         $run($forked, 'remote add fork ' . escapeshellarg($fork));
         $run($forked, 'fetch --quiet --tags fork');

         yield assert(
            assertion: $run($forked, 'rev-parse --verify refs/tags/v99.0.0^{commit}') === $commits['v1.0.0-beta.1'],
            description: 'probe precondition: the fork\'s v99.0.0 is a local tag of the kit'
         );

         [$result, $document] = $probe($bind($forked, $canon), ['list'], ['json' => true]);
         $tags = array_column($document['releases'] ?? [], 'tag');
         [$moved, $outcome] = $probe($bind($forked, $canon), ['upgrade'], ['json' => true, 'yes' => true]);

         yield assert(
            assertion: $result === true && ($document['verified'] ?? null) === true
               && in_array('v99.0.0', $tags, true) === false && in_array('v2.0.0', $tags, true) === true
               && $moved === true && ($outcome['target']['tag'] ?? null) === 'v2.0.0'
               && $run($forked, 'rev-parse HEAD') === $commits['v2.0.0'],
            description: 'a tag the canonical remote never advertised is neither listed nor moved to — the fork\'s v99.0.0 is not a release'
         );

         // # Transport: the releases are code — a remote at the canonical repository over
         //   a plaintext scheme is refused before anything is fetched
         foreach (['http://github.com/bootgly/bootgly.kit', 'ftp://github.com/bootgly/bootgly.kit', 'git://github.com/bootgly/bootgly.kit'] as $URL) {
            $plain = $fixture['template']('plain-' . substr($URL, 0, 3), 'v1.0.0-beta.1');
            $run($plain, 'remote add origin ' . escapeshellarg($URL));
            [$result, $document] = $probe($bind($plain, 'https://github.com/bootgly/bootgly.kit'), ['list'], ['json' => true]);

            yield assert(
               assertion: $result === false && str_contains($document['reason'] ?? '', 'insecure transport')
                  && str_contains($document['detail'] ?? '', 'git remote set-url origin https://github.com/bootgly/bootgly.kit'),
               description: "a releases remote over {$URL} is refused as an insecure transport, with the set-url fix"
            );
         }

         // # A remote named `bootgly` that points elsewhere is not silently reused
         $taken = $fixture['template']('taken', 'v1.0.0-beta.1');
         $run($taken, 'remote add bootgly ' . escapeshellarg("{$base}/elsewhere"));
         [$result, $document] = $probe($bind($taken, $canon), ['upgrade'], ['json' => true]);

         yield assert(
            assertion: $result === false && str_contains($document['reason'] ?? '', 'bootgly')
               && $run($taken, 'remote get-url bootgly') === "{$base}/elsewhere",
            description: 'a kit whose `bootgly` remote points elsewhere is refused, and the remote is left as it is'
         );

         // # A kit with no tag at all and no reachable remote
         $orphan = $fixture['template']('orphan', 'v1.0.0-beta.1');
         [$result, $document] = $probe($bind($orphan, "{$base}/nowhere"), ['upgrade'], ['json' => true]);

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'refused'
               && str_contains($document['reason'] ?? '', 'No release is known'),
            description: 'no local tag and no reachable remote is refused as "no release known"'
         );
      }
      finally {
         $erase($base);
      }
   }
);
