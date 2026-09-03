<?php

namespace Bootgly\commands;


use function array_diff;
use function assert;
use function bin2hex;
use function chmod;
use function clearstatcache;
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function json_decode;
use function mkdir;
use function posix_geteuid;
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
 * The kit checked out but its submodules could not follow: a distinct state
 * — `partial` — reported as such, never as a refusal, with the way out.
 */

return new Test(
   description: 'a kit whose submodules could not follow the checkout is reported as `partial`, with the way out',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-kit-partial-' . getmypid() . '-' . bin2hex(random_bytes(4));
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
         $framework = $fixture['framework'];
         $shas = $fixture['shas'];
         $run = $fixture['run'];

         // # `partial` is sticky on BOTH no-op branches: a kit on the newest release whose
         //   submodule never followed says so again when `upgrade` names nothing
         $apex = $fixture['clone']('apex', 'refs/tags/v1.0.0');
         $run($apex, 'config submodule.Bootgly.update none');
         $Apex = new class ($apex, $canon) extends KitCommand {
            public function __construct (string $kit, string $repository)
            {
               parent::__construct();
               $this->kit = $kit;
               $this->repository = $repository;
            }

            protected function scan (): array
            {
               return [];
            }
         };
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $verdicts = [];
         foreach ([['upgrade', 'v2.0.0'], ['upgrade']] as $arguments) {
            $Host = new Output('php://memory');
            $Terminal->Output = $Host;
            try {
               $verdicts[] = $Apex->run($arguments, ['json' => true, 'yes' => true]);
            }
            finally {
               $Terminal->Output = $Restore;
            }
            rewind($Host->stream);
            $verdicts[] = json_decode((string) stream_get_contents($Host->stream), true);
         }

         yield assert(
            assertion: $verdicts[0] === false && ($verdicts[1]['status'] ?? null) === 'partial'
               && $verdicts[2] === false && ($verdicts[3]['status'] ?? null) === 'partial'
               && str_contains($verdicts[3]['reason'] ?? '', 'off the pin')
               && $run($apex, 'rev-parse HEAD') === $fixture['commits']['v2.0.0'],
            description: 'on the newest release with a submodule off the pin, a bare `kit upgrade` is `partial` again — never "already on the newest release"'
         );

         // # A kit cloned at v1.0.0-beta.2 — its framework clone knows f1..f4 only
         $kit = $fixture['clone']('kit', 'refs/tags/v1.0.0-beta.2');

         // # Then a release the kit's framework cannot fetch: f5 arrives upstream,
         //   v3.0.0 pins it, and the kit's submodule remote is unreachable
         file_put_contents("{$framework}/autoboot.php", "<?php // v3.0.0\n");
         $run($framework, 'add autoboot.php');
         $run($framework, 'commit --quiet -m v3.0.0');
         $f5 = $run($framework, 'rev-parse HEAD');
         $run($framework, 'tag -a v3.0.0 -m v3.0.0');
         $run($canon, "update-index --add --cacheinfo 160000,{$f5},Bootgly");
         $run($canon, 'commit --quiet -m "bump Bootgly to v3.0.0"');
         $v3 = $run($canon, 'rev-parse HEAD');
         $run($canon, 'tag -a v3.0.0 -m "Three"');
         $run($kit, 'config submodule.Bootgly.url ' . escapeshellarg("{$base}/nowhere"));

         $Kit = new class ($kit, $canon) extends KitCommand {
            public function __construct (string $kit, string $repository)
            {
               parent::__construct();
               $this->kit = $kit;
               $this->repository = $repository;
            }

            protected function scan (): array
            {
               return [];
            }
         };
         $Host = new Output('php://memory');
         $Terminal->Output = $Host;
         try {
            $result = $Kit->run(['upgrade'], ['json' => true, 'yes' => true]);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         $document = json_decode((string) stream_get_contents($Host->stream), true);
         $document = is_array($document) ? $document : [];

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'partial'
               && str_contains($document['reason'] ?? '', 'submodules did not follow')
               && str_contains($document['detail'] ?? '', 'git submodule update'),
            description: 'the run fails with status `partial` — not `refused` — naming the way out'
         );
         yield assert(
            assertion: $run($kit, 'rev-parse HEAD') === $v3 && $run("{$kit}/Bootgly", 'rev-parse HEAD') === $shas['v1.0.0-beta.2'],
            description: 'the kit is on the release while the framework submodule stayed on the old pin — the mixed state the document describes'
         );

         // # `git submodule update` exits 0 yet leaves a submodule where it was:
         //   `submodule.<name>.update = none` is a user's own config — the pin moved,
         //   the checkout did not, and only the re-inspection can tell
         $frozen = $fixture['clone']('frozen', 'refs/tags/v1.0.0-beta.1');
         $run($frozen, 'config submodule.Bootgly.update none');
         $Frozen = new class ($frozen, $canon) extends KitCommand {
            public function __construct (string $kit, string $repository)
            {
               parent::__construct();
               $this->kit = $kit;
               $this->repository = $repository;
            }

            protected function scan (): array
            {
               return [];
            }
         };
         $Host = new Output('php://memory');
         $Terminal->Output = $Host;
         try {
            $result = $Frozen->run(['upgrade', 'v1.0.0-beta.2'], ['json' => true]);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         $document = json_decode((string) stream_get_contents($Host->stream), true);
         $document = is_array($document) ? $document : [];

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'partial'
               && str_contains($document['reason'] ?? '', 'submodules did not follow')
               && $run($frozen, 'rev-parse HEAD') === $fixture['commits']['v1.0.0-beta.2']
               && $run("{$frozen}/Bootgly", 'rev-parse HEAD') === $shas['v1.0.0-beta.1'],
            description: 'a submodule update that exits 0 but leaves the submodule off its pin is `partial` — the state, not the exit code, is the verdict'
         );

         // # ...and `partial` is sticky: the retry says so again, never `noop`, and `list` marks it
         $Host = new Output('php://memory');
         $Terminal->Output = $Host;
         try {
            $again = $Frozen->run(['upgrade', 'v1.0.0-beta.2'], ['json' => true]);
            rewind($Host->stream);
            $retry = json_decode((string) stream_get_contents($Host->stream), true);
            $Host = new Output('php://memory');
            $Terminal->Output = $Host;
            $listed = $Frozen->run(['list'], ['json' => true]);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         $listing = json_decode((string) stream_get_contents($Host->stream), true);

         yield assert(
            assertion: $again === false && is_array($retry) && $retry['status'] === 'partial'
               && str_contains($retry['reason'], 'off the pin') && str_contains($retry['detail'], 'git submodule update')
               && $listed === true && is_array($listing) && $listing['mixed'] === true,
            description: 'a kit on its release with a submodule off the pin stays `partial` on retry and `list` reports it mixed — never a `noop` that reads as "landed"'
         );

         // # `git checkout` exits 0 with part of the tree unwritten: a read-only
         //   directory where v1.0.0 wants docs/notes.md — the kit must not call that `moved`
         if (posix_geteuid() === 0) {
            yield assert(assertion: true, description: 'Skipped: root writes into a read-only directory');
         }
         else {
            $sealed = $fixture['clone']('sealed', 'refs/tags/v1.0.0-beta.2');
            mkdir("{$sealed}/docs", 0555);
            $Sealed = new class ($sealed, $canon) extends KitCommand {
               public function __construct (string $kit, string $repository)
               {
                  parent::__construct();
                  $this->kit = $kit;
                  $this->repository = $repository;
               }

               protected function scan (): array
               {
                  return [];
               }
            };
            $Host = new Output('php://memory');
            $Terminal->Output = $Host;
            try {
               $result = $Sealed->run(['upgrade', 'v1.0.0'], ['json' => true]);
            }
            finally {
               $Terminal->Output = $Restore;
            }
            rewind($Host->stream);
            $document = json_decode((string) stream_get_contents($Host->stream), true);
            $document = is_array($document) ? $document : [];
            chmod("{$sealed}/docs", 0775);

            yield assert(
               assertion: $result === false && ($document['status'] ?? null) === 'partial'
                  && str_contains($document['reason'] ?? '', 'did not fully apply')
                  && is_file("{$sealed}/docs/notes.md") === false && $run($sealed, 'rev-parse HEAD') === $fixture['commits']['v1.0.0'],
               description: 'a checkout git reports as done but left unwritten is `partial`, never `moved`'
            );

            // # ...and the other direction: a file of the OUTGOING release git could not
            //   unlink (`warning: unable to unlink`, exit 0) is a leftover, not a move
            $stuck = $fixture['clone']('stuck', 'refs/tags/v1.0.0');
            chmod("{$stuck}/docs", 0555);
            $Stuck = new class ($stuck, $canon) extends KitCommand {
               public function __construct (string $kit, string $repository)
               {
                  parent::__construct();
                  $this->kit = $kit;
                  $this->repository = $repository;
               }

               protected function scan (): array
               {
                  return [];
               }
            };
            $Host = new Output('php://memory');
            $Terminal->Output = $Host;
            try {
               $result = $Stuck->run(['downgrade', 'v1.0.0-beta.2'], ['json' => true]);
            }
            finally {
               $Terminal->Output = $Restore;
            }
            rewind($Host->stream);
            $document = json_decode((string) stream_get_contents($Host->stream), true);
            $document = is_array($document) ? $document : [];
            chmod("{$stuck}/docs", 0775);

            yield assert(
               assertion: $result === false && ($document['status'] ?? null) === 'partial'
                  && str_contains($document['reason'] ?? '', 'did not fully apply')
                  && is_file("{$stuck}/docs/notes.md") === true && $run($stuck, 'rev-parse HEAD') === $fixture['commits']['v1.0.0-beta.2'],
               description: 'a file of the outgoing release that git could not unlink makes the move `partial` — the leftover is on disk, HEAD moved'
            );
         }

         // # A release that DROPS a submodule: its checkout stays as a directory, and
         //   that is not a leftover — the move is complete
         $run($canon, 'rm --quiet --cached Bootgly');
         $run($canon, 'rm --quiet -f .gitmodules');
         $run($canon, 'commit --quiet -m "drop the framework submodule"');
         $v4 = $run($canon, 'rev-parse HEAD');
         $run($canon, 'tag -a v4.0.0 -m "Four"');
         $dropped = $fixture['clone']('dropped', 'refs/tags/v1.0.0-beta.2');
         $Dropped = new class ($dropped, $canon) extends KitCommand {
            public function __construct (string $kit, string $repository)
            {
               parent::__construct();
               $this->kit = $kit;
               $this->repository = $repository;
            }

            protected function scan (): array
            {
               return [];
            }
         };
         $Host = new Output('php://memory');
         $Terminal->Output = $Host;
         try {
            $result = $Dropped->run(['upgrade', 'v4.0.0'], ['json' => true, 'yes' => true]);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         $document = json_decode((string) stream_get_contents($Host->stream), true);
         $document = is_array($document) ? $document : [];

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'moved'
               && $run($dropped, 'rev-parse HEAD') === $v4 && is_dir("{$dropped}/Bootgly") === true,
            description: 'a release that drops a submodule moves cleanly — the checkout left behind is a directory, never mistaken for an un-unlinked file'
         );

         // # A blob that became a directory is not a leftover either (v6.0.0 turns README.md into README.md/index.md)
         $run($canon, 'checkout --quiet refs/tags/v2.0.0');
         $run($canon, 'rm --quiet README.md');
         // ! PHP's realpath cache still knows README.md as a file — a directory of the same name is unreachable through it
         clearstatcache(true);
         mkdir("{$canon}/README.md", 0775, true);
         file_put_contents("{$canon}/README.md/index.md", "# Kit v6.0.0\n");
         $run($canon, 'add README.md');
         $run($canon, 'commit --quiet -m "README.md becomes a directory"');
         $v6 = $run($canon, 'rev-parse HEAD');
         $run($canon, 'tag -a v6.0.0 -m "Six"');
         $flipped = $fixture['clone']('flipped', 'refs/tags/v1.0.0-beta.2');
         $Flipped = new class ($flipped, $canon) extends KitCommand {
            public function __construct (string $kit, string $repository)
            {
               parent::__construct();
               $this->kit = $kit;
               $this->repository = $repository;
            }

            protected function scan (): array
            {
               return [];
            }
         };
         $Host = new Output('php://memory');
         $Terminal->Output = $Host;
         try {
            $result = $Flipped->run(['upgrade', 'v6.0.0'], ['json' => true, 'yes' => true]);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         $document = json_decode((string) stream_get_contents($Host->stream), true);
         $document = is_array($document) ? $document : [];

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'moved'
               && $run($flipped, 'rev-parse HEAD') === $v6 && is_dir("{$flipped}/README.md") === true,
            description: 'a path that was a file and became a directory is a complete move, not a leftover'
         );

         // # A release that ADDS a submodule where the user already keeps a repository of
         //   their own: the checkout leaves the directory alone, `submodule update` (no
         //   --init) cannot register it — `partial`, with the remedy that actually works
         $run($canon, 'submodule add --quiet ' . escapeshellarg($fixture['framework']) . ' Extra');
         $run($canon, 'commit --quiet -m "add the Extra submodule"');
         $v7 = $run($canon, 'rev-parse HEAD');
         $run($canon, 'tag -a v7.0.0 -m "Seven"');
         $occupied = $fixture['clone']('occupied', 'refs/tags/v1.0.0-beta.2');
         mkdir("{$occupied}/Extra", 0775, true);
         $run("{$occupied}/Extra", 'init --quiet -b main');
         file_put_contents("{$occupied}/Extra/mine.txt", "mine\n");
         $run("{$occupied}/Extra", 'add mine.txt');
         $run("{$occupied}/Extra", 'commit --quiet -m mine');
         $Occupied = new class ($occupied, $canon) extends KitCommand {
            public function __construct (string $kit, string $repository)
            {
               parent::__construct();
               $this->kit = $kit;
               $this->repository = $repository;
            }

            protected function scan (): array
            {
               return [];
            }
         };
         $Host = new Output('php://memory');
         $Terminal->Output = $Host;
         try {
            $result = $Occupied->run(['upgrade', 'v7.0.0'], ['json' => true, 'yes' => true]);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         $document = json_decode((string) stream_get_contents($Host->stream), true);
         $document = is_array($document) ? $document : [];

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'partial'
               && str_contains($document['detail'] ?? '', 'git submodule update --init -- Extra')
               && str_contains($document['detail'] ?? '', 'is not that submodule')
               && file_get_contents("{$occupied}/Extra/mine.txt") === "mine\n" && $run($occupied, 'rev-parse HEAD') === $v7,
            description: 'a new submodule landing on the user\'s own directory is `partial` with the `--init` remedy — the directory and its files are untouched'
         );
      }
      finally {
         $erase($base);
      }
   }
);
