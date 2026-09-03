<?php

namespace Bootgly\ACI\VCS;

use function array_diff;
use function assert;
use function bin2hex;
use function escapeshellarg;
use function exec;
use function file_exists;
use function file_put_contents;
use function getmypid;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\VCS;

/**
 * `Submodules` — what `.gitmodules` declares, the three commits a submodule
 * lives between (index pin, HEAD tree, checkout) and the update that moves
 * every initialized one onto the pin.
 */

return new Test(
   description: 'Submodules lists the declared submodules, inspects pin/tree/checkout agreement and updates the initialized ones',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-vcs-sub-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($base, 0775, true);

      $G = '-c user.name=Bootgly -c user.email=tests@bootgly.local -c commit.gpgsign=false -c protocol.file.allow=always';
      $Run = static function (string $directory, string $command): string {
         $output = [];
         exec('git -C ' . escapeshellarg($directory) . " {$command} 2>/dev/null", $output);

         return $output[0] ?? '';
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

      try {
         // # A submodule with three commits
         $sub = "{$base}/sub";
         mkdir($sub, 0775, true);
         $Run($sub, 'init --quiet -b main');
         $shas = [];
         for ($index = 1; $index <= 3; $index++) {
            file_put_contents("{$sub}/f.txt", "c{$index}\n");
            file_put_contents("{$sub}/g.txt", "constant\n");
            $Run($sub, 'add f.txt g.txt');
            $Run($sub, "{$G} commit --quiet -m c{$index}");
            $shas[$index] = $Run($sub, 'rev-parse HEAD');
         }

         // # A superproject pinning it twice: first at c2, then at c3
         $super = "{$base}/super";
         mkdir($super, 0775, true);
         $Run($super, 'init --quiet -b main');
         $Run($super, "{$G} commit --quiet --allow-empty -m base");
         $Run($super, "{$G} submodule add --quiet " . escapeshellarg($sub) . ' Sub');
         $Run("{$super}/Sub", "checkout --quiet {$shas[2]}");
         $Run($super, 'add Sub');
         $Run($super, "{$G} commit --quiet -m pin-c2");
         $older = $Run($super, 'rev-parse HEAD');
         $Run("{$super}/Sub", "checkout --quiet {$shas[3]}");
         $Run($super, 'add Sub');
         $Run($super, "{$G} commit --quiet -m pin-c3");

         $VCS = new VCS($super);

         yield assert(
            assertion: $VCS->Submodules->list() === ['Sub' => 'Sub'] && new VCS($sub)->Submodules->list() === [],
            description: 'list() reads the declared submodules from .gitmodules — none where there is no file'
         );

         $state = $VCS->Submodules->inspect('Sub');

         yield assert(
            assertion: $state['pinned'] === $shas[3] && $state['committed'] === $shas[3] && $state['head'] === $shas[3]
               && $state['initialized'] === true && $state['changes'] === [] && $state['path'] === 'Sub',
            description: 'inspect(): index pin, HEAD tree and checkout agree on an untouched submodule'
         );

         // # Changes inside the submodule
         file_put_contents("{$super}/Sub/g.txt", "edited\n");
         $state = $VCS->Submodules->inspect('Sub');
         $Run("{$super}/Sub", 'checkout --quiet -- g.txt');

         yield assert(
            assertion: ($state['changes']['g.txt'] ?? null) === ' M',
            description: 'inspect() reports the submodule\'s own changes by path'
         );

         // # A submodule moved away from the pin
         $Run("{$super}/Sub", "checkout --quiet {$shas[1]}");
         $state = $VCS->Submodules->inspect('Sub');

         yield assert(
            assertion: $state['head'] === $shas[1] && $state['pinned'] === $shas[3] && $state['committed'] === $shas[3],
            description: 'inspect() shows a checkout that left the pin (head ≠ pinned)'
         );

         // # A staged gitlink — the release workflow itself
         $Run($super, 'add Sub');
         $state = $VCS->Submodules->inspect('Sub');
         $Run($super, 'reset --quiet -- Sub');

         yield assert(
            assertion: $state['pinned'] === $shas[1] && $state['committed'] === $shas[3],
            description: 'inspect() shows a staged gitlink (pinned ≠ committed)'
         );

         // # update() follows the index
         $status = $VCS->Submodules->update();
         $state = $VCS->Submodules->inspect('Sub');

         yield assert(
            assertion: $status === 0 && $state['head'] === $shas[3],
            description: 'update() moves an initialized submodule back onto the pin'
         );

         $VCS->Git->checkout($older);
         $lines = [];
         $status = $VCS->Submodules->update(function (string $line) use (&$lines): void {
            $lines[] = $line;
         });
         $state = $VCS->Submodules->inspect('Sub');

         yield assert(
            assertion: $status === 0 && $state['head'] === $shas[2] && $state['pinned'] === $shas[2] && $lines !== [],
            description: 'after the superproject checks out an older pin, update() lands the submodule there and streams its report'
         );

         // # An uninitialized submodule stays that way
         $clone = "{$base}/clone";
         exec('git clone --quiet ' . escapeshellarg($super) . ' ' . escapeshellarg($clone) . ' 2>/dev/null');
         $Clone = new VCS($clone);
         $state = $Clone->Submodules->inspect('Sub');
         $status = $Clone->Submodules->update();

         yield assert(
            assertion: $state['initialized'] === false && $state['head'] === null && $state['changes'] === []
               && $state['pinned'] === $shas[2] && $status === 0
               && file_exists("{$clone}/Sub/.git") === false,
            description: 'inspect() reports an uninitialized submodule (pin known, no checkout) and update() leaves it alone'
         );
      }
      finally {
         $Erase($base);
      }
   }
);
