<?php

namespace Bootgly\commands;

use function array_column;
use function array_diff;
use function assert;
use function bin2hex;
use function getmypid;
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
use function sys_get_temp_dir;
use function unlink;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;

/**
 * `kit list`: the releases a kit can move to, newest first, and where the
 * kit stands — on a release, past one, or (a kit from before the kit was
 * tagged, or generated from the template) by its framework pin.
 */

return new Test(
   description: '`kit list` names every release newest first and where the kit stands — on one, past one, or by the Bootgly pin',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-kit-list-' . getmypid() . '-' . bin2hex(random_bytes(4));
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

         // ! The command bound to a fixture kit and its canonical repository
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

            return [$result, (string) stream_get_contents($Host->stream)];
         };

         // # A cloned kit on v1.0.0-beta.1 — bound with a trailing slash, as BOOTGLY_WORKING_DIR carries one
         $kit = $fixture['clone']('kit-clone', 'refs/tags/v1.0.0-beta.1');
         [$result, $output] = $probe($bind("{$kit}/"), ['list'], ['json' => true]);
         $document = json_decode($output, true);

         yield assert(
            assertion: is_array($document) && $document['kit'] === $kit,
            description: 'the kit path is reported without its trailing slash'
         );

         yield assert(
            assertion: $result === true && is_array($document) && $document['status'] === 'listed'
               && $document['command'] === 'kit' && $document['verb'] === 'list' && $document['fetched'] === true
               && $document['remote'] === 'origin' && $document['added'] === false,
            description: '`list --json` emits one `listed` document after fetching the releases through the remote already there'
         );
         yield assert(
            assertion: is_array($document) && array_column($document['releases'], 'tag') === ['v2.0.0', 'v1.0.0', 'v1.0.0-beta.2', 'v1.0.0-beta.1']
               && array_column($document['releases'], 'commit') === [$commits['v2.0.0'], $commits['v1.0.0'], $commits['v1.0.0-beta.2'], $commits['v1.0.0-beta.1']],
            description: 'the releases are the canonical tags, newest first by SemVer, each with its commit'
         );
         yield assert(
            assertion: is_array($document) && $document['current'] === [
               'tag' => 'v1.0.0-beta.1', 'version' => '1.0.0-beta.1', 'commit' => $commits['v1.0.0-beta.1'], 'distance' => 0, 'source' => 'tag',
            ] && array_column($document['releases'], 'current') === [false, false, false, true],
            description: 'a kit on a release is located on it, distance 0'
         );

         [$result, $output] = $probe($bind($kit), ['list'], []);

         yield assert(
            assertion: $result === true && str_contains($output, 'v1.0.0-beta.1') && str_contains($output, 'current')
               && str_contains($output, 'newer') && str_contains($output, 'Release'),
            description: 'the human list is a table marking the current release and the newer ones'
         );

         // # A kit one commit past v1.0.0
         $past = $fixture['clone']('kit-past', $commits['past']);
         [, $output] = $probe($bind($past), ['list'], ['json' => true]);
         $document = json_decode($output, true);

         yield assert(
            assertion: is_array($document) && $document['current']['tag'] === 'v1.0.0'
               && $document['current']['distance'] === 1 && $document['current']['source'] === 'describe',
            description: 'a kit past a release is located on that release with its distance'
         );

         // # ...even when a tag of another shape sits nearer
         $fixture['run']($past, 'tag nightly HEAD');
         [, $output] = $probe($bind($past), ['list'], ['json' => true]);
         $document = json_decode($output, true);

         yield assert(
            assertion: is_array($document) && $document['current']['tag'] === 'v1.0.0' && $document['current']['distance'] === 1,
            description: 'a `nightly` tag on HEAD does not hide the release the kit is past'
         );

         // # A kit generated from the template: no tag reaches its squashed commit
         $template = $fixture['template']('kit-template', 'v1.0.0-beta.2');
         [, $output] = $probe($bind($template), ['list'], ['json' => true]);
         $document = json_decode($output, true);
         $remote = $fixture['run']($template, 'remote get-url bootgly');

         yield assert(
            assertion: is_array($document) && $document['current']['tag'] === 'v1.0.0-beta.2'
               && $document['current']['source'] === 'pin' && $document['current']['distance'] === null
               && $document['remote'] === 'bootgly' && $document['added'] === true && $remote === $canon,
            description: 'a template kit gets the canonical remote added (and says so) and is located by its Bootgly pin'
         );

         // # Not a kit
         $plain = "{$base}/plain";
         mkdir($plain, 0775, true);
         $fixture['run']($plain, 'init --quiet -b main');
         [$result, $output] = $probe($bind($plain), ['list'], ['json' => true]);
         $document = json_decode($output, true);

         yield assert(
            assertion: $result === false && is_array($document) && $document['status'] === 'refused'
               && str_contains($document['reason'], 'not a Bootgly kit'),
            description: 'a repository without the Bootgly submodule is refused as not a kit'
         );

         // # The verbs and the options are checked before anything is read
         [$result] = $probe($bind($kit), ['list'], ['yes' => true]);
         [$again, $output] = $probe($bind($kit), ['nope'], []);
         [$bare, $help] = $probe($bind($kit), [], []);

         yield assert(
            assertion: $result === false && $again === false && str_contains($output, 'Unknown kit verb')
               && $bare === true && str_contains($help, 'Kit verbs') && str_contains($help, 'downgrade'),
            description: '`--yes` is refused on `list`, an unknown verb is refused with the help, no verb is the help'
         );

         // # ...and under --json each of those is one refused document, not an alert
         [$result, $output] = $probe($bind($kit), ['list'], ['json' => true, 'yes' => true]);
         $option = json_decode($output, true);
         [$again, $output] = $probe($bind($kit), ['nope'], ['json' => true]);
         $verb = json_decode($output, true);
         [$bare, $output] = $probe($bind($kit), [], ['json' => true]);
         $none = json_decode($output, true);

         yield assert(
            assertion: $result === false && is_array($option) && $option['status'] === 'refused' && str_contains($option['reason'], '--yes')
               && $again === false && is_array($verb) && $verb['status'] === 'refused' && str_contains($verb['reason'], 'Unknown kit verb')
               && $bare === false && is_array($none) && $none['status'] === 'refused' && str_contains($none['reason'], 'No kit verb'),
            description: 'under --json an unknown option, an unknown verb and a missing verb each yield one refused document'
         );
      }
      finally {
         $erase($base);
      }
   }
);
