<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_WORKING_DIR;
use function array_diff;
use function assert;
use function bin2hex;
use function getenv;
use function getmypid;
use function is_array;
use function is_dir;
use function is_link;
use function json_decode;
use function mkdir;
use function putenv;
use function random_bytes;
use function rewind;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_contents;
use function touch;
use function unlink;
use function var_export;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;


/**
 * A container ships the kit LAYOUT, not a git checkout: the tag is baked in and
 * the filesystem dies with the container. Telling that user to run
 * `curl … | bash` sends them to install a kit the next `docker run` throws
 * away, so the refusal has to name the move that exists there — pulling
 * another image tag.
 *
 * Outside a container the original guard must survive untouched, and a kit
 * MOUNTED into a container still moves normally (the trigger is the failed kit
 * guard, never the container itself).
 */

return new Test(
   description: 'inside a container the kit verbs refuse naming `docker pull`, not `curl | bash`',
   test: function () {
      // ! The framework's own scratch area, not `/tmp` — `storage/tests/` is the
      //   convention, and the fixture eraser below only ever walks what it made.
      $base = BOOTGLY_WORKING_DIR . 'storage/tests/kit-container-'
         . getmypid() . '-' . bin2hex(random_bytes(4));
      $made = @mkdir($base, 0775, true);

      yield assert(
         assertion: $made === true && is_dir($base) === true,
         description: "The fixture directory was created — {$base}"
      );

      // ! The container signal comes through the seam, never the real process:
      //   Docker creates `/.dockerenv` in EVERY container, so a test that drove
      //   it by environment would invert inside `docker run … bootgly test`.
      $Bind = static function (string $kit, string $place): KitCommand {
         return new class ($kit, $place) extends KitCommand {
            private string $place;

            public function __construct (string $kit, string $place)
            {
               parent::__construct();
               $this->kit = $kit;
               $this->place = $place;
            }

            protected function stand (): string
            {
               return $this->place;
            }

            // ! The registry and the pid files belong to the process's own kit
            protected function scan (): array
            {
               return [];
            }
         };
      };
      $Probe = static function (KitCommand $Command, array $arguments): array {
         $Host = new Output('php://memory');
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Host;
         try {
            $Command->run($arguments, ['json' => true]);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         $document = json_decode((string) stream_get_contents($Host->stream), true);

         return is_array($document) ? $document : [];
      };

      try {
         // @@ A) Outside a container: the original guard, unchanged
         $host = $Probe($Bind($base, 'host'), ['list']);

         yield assert(
            assertion: ($host['status'] ?? null) === 'refused'
               && str_contains((string) ($host['reason'] ?? ''), 'not a Bootgly kit')
               && str_contains((string) ($host['detail'] ?? ''), 'curl'),
            description: 'outside a container the refusal still points at the installer — '
               . var_export([$host['reason'] ?? null, $host['detail'] ?? null], true)
         );

         // @@ B) Inside the KIT image: every verb names the image move instead
         foreach (['list', 'upgrade', 'downgrade'] as $verb) {
            $contained = $Probe($Bind($base, 'kit'), [$verb]);

            yield assert(
               assertion: ($contained['status'] ?? null) === 'refused'
                  && str_contains((string) ($contained['reason'] ?? ''), 'ships the kit')
                  && str_contains((string) ($contained['detail'] ?? ''), 'docker pull')
                  // ! TAGGED, always: `bootgly/bootgly.kit` untagged resolves
                  //   `latest`, which exists only from the first stable release
                  //   — an untagged pull command printed by the product is one
                  //   the user cannot run
                  && str_contains((string) ($contained['detail'] ?? ''), 'bootgly/bootgly.kit:')
                  && str_contains((string) ($contained['detail'] ?? ''), 'curl') === false,
               description: "`kit {$verb}` in a container names the image move — "
                  . var_export([$contained['reason'] ?? null, $contained['detail'] ?? null], true)
            );
         }

         // @@ B2) Inside the FRAMEWORK image there is no kit at all, so telling
         //        the user to pull a newer kit tag would be a lie
         $ingredient = $Probe($Bind($base, 'framework'), ['list']);

         yield assert(
            assertion: str_contains((string) ($ingredient['reason'] ?? ''), 'not a kit')
               && str_contains((string) ($ingredient['detail'] ?? ''), 'bootgly/bootgly.kit:')
               && str_contains((string) ($ingredient['detail'] ?? ''), 'docker pull') === false,
            description: 'the framework image says it has no kit, and names the kit image — '
               . var_export([$ingredient['reason'] ?? null, $ingredient['detail'] ?? null], true)
         );

         // @@ C) A kit MOUNTED into a container still moves: the image wording
         //       is for a tree with no checkout, never for the container alone
         mkdir("{$base}/.git", 0775, true);

         $mounted = $Probe($Bind($base, 'kit'), ['list']);

         yield assert(
            assertion: ($mounted['status'] ?? null) === 'refused'
               && str_contains((string) ($mounted['reason'] ?? ''), 'not a Bootgly kit')
               && str_contains((string) ($mounted['reason'] ?? ''), 'ships the kit') === false,
            description: 'a mounted kit gets the ordinary refusal, not the image one — '
               . var_export($mounted['reason'] ?? null, true)
         );

         // @@ D) The detector itself: the env is the switch and the container
         //       marker files are the fallback. Both are driven through the
         //       MARKERS seam, so the case proves the same thing on a host and
         //       inside a container — and a refactor dropping either marker
         //       fails here instead of shipping.
         $docker = "{$base}/dockerenv";
         $podman = "{$base}/containerenv";
         $Detector = new class ($base, [$docker, $podman]) extends KitCommand {
            /**
             * @param array<int,string> $markers
             */
            public function __construct (string $kit, array $markers)
            {
               parent::__construct();
               $this->kit = $kit;
               // ! Only the marker PATHS are replaced — `check()` itself is the
               //   production one, or this case would prove nothing.
               $this->markers = $markers;
            }

            public function detect (): bool
            {
               return $this->check();
            }
         };
         $restore = getenv('BOOTGLY_DOCKER');

         try {
            putenv('BOOTGLY_DOCKER');
            $bare = $Detector->detect();

            putenv('BOOTGLY_DOCKER=');
            $blank = $Detector->detect();

            putenv('BOOTGLY_DOCKER=1');
            $set = $Detector->detect();

            putenv('BOOTGLY_DOCKER');
            touch($docker);
            $dockerMarked = $Detector->detect();
            unlink($docker);

            touch($podman);
            $podmanMarked = $Detector->detect();
            unlink($podman);
         }
         finally {
            if ($restore === false) {
               putenv('BOOTGLY_DOCKER');
            }
            else {
               putenv("BOOTGLY_DOCKER={$restore}");
            }
         }

         // @@ E) `stand()` itself, production code, with only its two inputs
         //       replaced: the container signal and the LAYOUT. Both image
         //       branches are driven here — a checkout can only ever reproduce
         //       one of them, which is how a mutant that always answers
         //       `framework` used to live through a container run too.
         $Standing = new class ('/nonexistent') extends KitCommand {
            public bool $contained = false;

            public function __construct (string $kit)
            {
               parent::__construct();
               $this->kit = $kit;
            }

            /** The framework root and the kit root, as an image would lay them out. */
            public function lay (string $framework, string $kit): void
            {
               $this->templates = $framework;
               $this->kit = $kit;
            }

            // ! Widened, not renamed: this IS `stand()`, so a rename would let
            //   the production method drift away from what is asserted here.
            public function stand (): string
            {
               return parent::stand();
            }

            protected function check (): bool
            {
               return $this->contained;
            }
         };

         // # Outside a container the layout is irrelevant — `host` either way
         $Standing->contained = false;
         $Standing->lay('/bootgly/Bootgly/', '/bootgly');
         $loose = $Standing->stand();

         // # The FRAMEWORK image: the framework IS the working base
         $Standing->contained = true;
         $Standing->lay('/bootgly/', '/bootgly');
         $ingot = $Standing->stand();

         // # The KIT image: the framework is nested under `Bootgly/`
         $Standing->lay('/bootgly/Bootgly/', '/bootgly');
         $nested = $Standing->stand();

         yield assert(
            assertion: $loose === 'host' && $ingot === 'framework' && $nested === 'kit',
            description: 'stand() answers host outside a container and names the image by layout '
               . 'inside one — ' . var_export([$loose, $ingot, $nested], true)
         );

         // ! The seam proves the MECHANISM; production reads those two roots
         //   from the constants, so pin that wiring too — pointing either one
         //   somewhere else silently reclassifies every container.
         $Rooted = new class extends KitCommand {
            /** @return array<int,string> */
            public function reveal (): array
            {
               return [$this->templates, $this->kit];
            }
         };

         yield assert(
            assertion: $Rooted->reveal() === [BOOTGLY_ROOT_DIR, BOOTGLY_WORKING_DIR],
            description: 'the shipped roots are the framework root and the working base — '
               . var_export($Rooted->reveal(), true)
         );

         // ! The seam above proves the MECHANISM; the default list is what
         //   ships, so pin it too — dropping Podman's marker is otherwise a
         //   silent regression for every non-Docker runtime.
         $Shipped = new class ('/nonexistent') extends KitCommand {
            public function __construct (string $kit)
            {
               parent::__construct();
               $this->kit = $kit;
            }

            /** @return array<int,string> */
            public function ship (): array
            {
               return $this->markers;
            }
         };

         yield assert(
            assertion: $Shipped->ship() === ['/.dockerenv', '/run/.containerenv'],
            description: 'the shipped markers cover Docker and Podman — '
               . var_export($Shipped->ship(), true)
         );

         yield assert(
            assertion: $set === true
               && $bare === false && $blank === false
               && $dockerMarked === true && $podmanMarked === true,
            description: 'BOOTGLY_DOCKER=1 marks a container, BLANK and unset do not, and each '
               . 'container marker file alone does — '
               . var_export([$set, $bare, $blank, $dockerMarked, $podmanMarked], true)
         );
      }
      finally {
         // ! Scrub whatever a verb may have written, never just the dir itself
         $Erase = function (string $target) use (&$Erase): void {
            // ? A symlink is removed, never walked — a scrubber that follows one
            //   can delete outside the fixture it owns
            if (is_link($target) === true || is_dir($target) === false) {
               unlink($target);

               return;
            }
            foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
               $Erase("{$target}/{$entry}");
            }
            rmdir($target);
         };
         $Erase($base);
      }
   }
);
