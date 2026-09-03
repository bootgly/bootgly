<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use function array_diff;
use function array_values;
use function assert;
use function bin2hex;
use function chmod;
use function copy;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function function_exists;
use function getmypid;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function json_decode;
use function mkdir;
use function posix_geteuid;
use function posix_mkfifo;
use function preg_replace;
use function random_bytes;
use function rewind;
use function rmdir;
use function scandir;
use function sort;
use function str_contains;
use function stream_get_contents;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;


/**
 * `kit boot`: the resource directories a kit runs on — `projects/` with an
 * empty registry, the framework's `scripts/` and `storage/` templates — laid
 * down once, never over what is already there, and never in the framework
 * checkout itself.
 */

return new Test(
   description: '`kit boot` lays down projects/, scripts/ and storage/ once, leaves what exists alone and refuses the framework checkout',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-kit-boot-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($base, 0775, true);
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
         // ! A miniature framework checkout as the template source — never the live one,
         //   whose storage/ carries whatever the developer's runs left there
         $templates = "{$base}/templates";
         mkdir("{$templates}/Bootgly/commands/stubs", 0775, true);
         copy(BOOTGLY_ROOT_BASE . '/Bootgly/commands/stubs/Bootgly.projects.php', "{$templates}/Bootgly/commands/stubs/Bootgly.projects.php");
         mkdir("{$templates}/scripts", 0775, true);
         file_put_contents("{$templates}/scripts/autoboot.php", "<?php return [];\n");

         $Bind = static function (string $kit) use ($templates): KitCommand {
            return new class ($kit, $templates) extends KitCommand {
               public function __construct (string $kit, string $templates)
               {
                  parent::__construct();
                  $this->kit = $kit;
                  $this->templates = $templates;
               }
            };
         };
         $Probe = static function (KitCommand $Command, array $arguments, array $options): array {
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

            // ! Plain text: the alerts colour their paths, and a sentence is asserted whole
            return [$result, (string) preg_replace('/\e\[[0-9;]*m/', '', (string) stream_get_contents($Host->stream))];
         };

         // # A bare kit directory
         $kit = "{$base}/kit";
         mkdir($kit, 0775, true);
         [$result, $output] = $Probe($Bind($kit), ['boot'], []);

         yield assert(
            assertion: $result === true && is_file("{$kit}/projects/Bootgly.projects.php")
               && is_file("{$kit}/scripts/autoboot.php")
               && is_dir("{$kit}/storage/logs") && is_dir("{$kit}/storage/pids") && is_dir("{$kit}/storage/cache") && is_dir("{$kit}/storage/tests")
               && is_dir("{$kit}/storage/sessions") === false && is_dir("{$kit}/storage/security") === false
               && str_contains($output, 'Resource dir created: projects/') && str_contains($output, 'Resource dir copied: scripts/')
               && str_contains($output, 'Resource dir created: storage/'),
            description: '`kit boot` creates projects/ (with the empty registry), copies the scripts/ template and creates the storage layout — never sessions/ or security/, whose owners create them at 0700 — naming each honestly'
         );

         // # A template root that carries a storage/ of its own (a checkout that has been
         //   run: sessions, pid files, key material) is NEVER mirrored — the layout is created
         mkdir("{$templates}/storage/sessions", 0775, true);
         file_put_contents("{$templates}/storage/sessions/sess_abc", 'secret');
         $shipped = "{$base}/shipped";
         mkdir($shipped, 0775, true);
         [$result, $output] = $Probe($Bind($shipped), ['boot'], []);

         yield assert(
            assertion: $result === true && is_file("{$shipped}/storage/sessions/sess_abc") === false && is_dir("{$shipped}/storage/pids")
               && str_contains($output, 'Resource dir created: storage/'),
            description: 'a storage/ found under the template root is never copied — the kit gets the bare layout, never another checkout\'s runtime data'
         );
         $Erase("{$templates}/storage");
         yield assert(
            assertion: file_get_contents("{$kit}/projects/Bootgly.projects.php") === file_get_contents(BOOTGLY_ROOT_BASE . '/Bootgly/commands/stubs/Bootgly.projects.php'),
            description: 'the registry is the empty stub — the framework\'s own projects are never listed in a kit'
         );

         // # What exists is left alone
         file_put_contents("{$kit}/projects/Bootgly.projects.php", "<?php return ['App' => []];\n");
         file_put_contents("{$kit}/storage/mine.txt", "mine\n");
         [$result, $output] = $Probe($Bind($kit), ['boot'], ['resources' => true]);

         yield assert(
            assertion: $result === true && file_get_contents("{$kit}/projects/Bootgly.projects.php") === "<?php return ['App' => []];\n"
               && is_file("{$kit}/storage/mine.txt")
               && str_contains($output, 'Resource dir') === false && str_contains($output, 'OK'),
            description: 'a second boot changes nothing — the registry and the user\'s files stay as they are'
         );

         // # Only part missing: only that part is laid down
         $Erase("{$kit}/scripts");
         [$result, $output] = $Probe($Bind($kit), ['boot'], []);

         yield assert(
            assertion: $result === true && is_dir("{$kit}/scripts") && str_contains($output, 'scripts/')
               && str_contains($output, 'storage/') === false && str_contains($output, 'projects/') === false,
            description: 'a missing resource directory is laid down without touching the others'
         );

         // # A template file that cannot be read fails the boot by name — no "OK" over a hole
         if (posix_geteuid() !== 0) {
            file_put_contents("{$templates}/scripts/secret.php", "<?php\n");
            chmod("{$templates}/scripts/secret.php", 0000);
            $holed = "{$base}/holed";
            mkdir($holed, 0775, true);
            [$result, $output] = $Probe($Bind($holed), ['boot'], []);
            $registered = is_file("{$holed}/projects/Bootgly.projects.php");
            $leftovers = array_diff((array) scandir($holed), ['.', '..']);
            chmod("{$templates}/scripts/secret.php", 0644);
            unlink("{$templates}/scripts/secret.php");

            yield assert(
               assertion: $result === false && str_contains($output, 'Could not lay down scripts/')
                  && $leftovers === [] && is_dir("{$holed}/storage") === false && $registered === false,
               description: 'a template file the copy cannot read fails the boot by name and leaves NOTHING behind — no partial scripts/, no staging, no storage/, no registry'
            );

            // # ...so the next boot lays everything down by itself — nothing to remove by hand
            [$result, $output] = $Probe($Bind($holed), ['boot'], []);

            yield assert(
               assertion: $result === true && is_file("{$holed}/scripts/autoboot.php") && is_dir("{$holed}/storage/pids")
                  && is_file("{$holed}/projects/Bootgly.projects.php"),
               description: 'a boot that failed leaves the kit unprepared, and the next one completes it — never a half-booted kit that reads as prepared'
            );
         }

         // # An entry no copy can carry — a FIFO in the template — fails the mirror honestly
         if (function_exists('posix_mkfifo') === true) {
            posix_mkfifo("{$templates}/scripts/pipe", 0644);
            $piped = "{$base}/piped";
            mkdir($piped, 0775, true);
            [$result, $output] = $Probe($Bind($piped), ['boot'], []);
            unlink("{$templates}/scripts/pipe");

            yield assert(
               assertion: $result === false && str_contains($output, 'Could not lay down scripts/') && is_dir("{$piped}/scripts") === false,
               description: 'a FIFO in the template fails the boot instead of a copy that silently skipped it'
            );
         }

         // # storage/ that cannot be laid down: a file in its place — failure by name, scripts/ left as copied
         $Blocked = "{$base}/blocked";
         mkdir($Blocked, 0775, true);
         file_put_contents("{$Blocked}/storage", "not a directory\n");
         [$result, $output] = $Probe($Bind($Blocked), ['boot'], []);

         yield assert(
            assertion: $result === false && str_contains($output, 'Could not lay down storage/')
               && is_file("{$Blocked}/storage") && is_dir("{$Blocked}/scripts") && is_file("{$Blocked}/projects/Bootgly.projects.php") === false,
            description: 'a storage/ that cannot be created fails by name; the registry is not written'
         );

         // # projects/ that cannot be created: a file in its place
         $filed = "{$base}/filed";
         mkdir($filed, 0775, true);
         file_put_contents("{$filed}/projects", "not a directory\n");
         [$result, $output] = $Probe($Bind($filed), ['boot'], []);

         yield assert(
            assertion: $result === false && str_contains($output, 'Could not create projects/') && is_file("{$filed}/projects"),
            description: 'a projects/ that cannot be created fails by name'
         );

         // # The layout is exactly the documented set, group- and other-unwritable
         $layout = "{$base}/layout";
         mkdir($layout, 0775, true);
         $Probe($Bind($layout), ['boot'], []);
         $laid = array_values(array_diff((array) scandir("{$layout}/storage"), ['.', '..']));
         sort($laid);
         $mode = fileperms("{$layout}/storage/logs") & 0777;

         yield assert(
            assertion: $laid === ['cache', 'locks', 'logs', 'pids', 'queues', 'schedule', 'temp', 'tests']
               && ($mode & 0700) === 0700 && ($mode & 0022) === 0,
            description: 'the storage layout is exactly cache, locks, logs, pids, queues, schedule, temp, tests — owner-writable, never group/other-writable'
         );

         // # A boot that stopped halfway is repaired by the next: projects/ without its registry
         $halfway = "{$base}/halfway";
         mkdir("{$halfway}/projects/App", 0775, true);
         [$result, $output] = $Probe($Bind($halfway), ['boot'], []);

         yield assert(
            assertion: $result === true && is_file("{$halfway}/projects/Bootgly.projects.php") && is_dir("{$halfway}/projects/App")
               && str_contains($output, 'Resource dir created: projects/'),
            description: 'a projects/ directory without the registry gets the registry — the boot repairs what is missing instead of skipping the directory'
         );

         // # A kit that cannot be written to is a failure, not three successes
         if (posix_geteuid() !== 0) {
            $sealed = "{$base}/sealed";
            mkdir($sealed, 0555);
            [$result, $output] = $Probe($Bind($sealed), ['boot'], []);
            chmod($sealed, 0775);

            yield assert(
               assertion: $result === false && str_contains($output, 'Could not lay down scripts/')
                  && is_dir("{$sealed}/scripts") === false && is_dir("{$sealed}/projects") === false,
               description: 'a kit directory that refuses writes fails the boot by name at its first step — nothing is claimed that did not happen'
            );
         }

         // # The framework checkout is not a kit — through a symlink or a trailing slash too
         symlink(BOOTGLY_ROOT_BASE, "{$base}/link");
         [$result, $output] = $Probe($Bind(BOOTGLY_ROOT_BASE), ['boot'], []);
         [$linked, $spoken] = $Probe($Bind("{$base}/link/"), ['boot'], []);

         yield assert(
            assertion: $result === false && str_contains($output, 'No resources to boot')
               && $linked === false && str_contains($spoken, 'No resources to boot'),
            description: 'the framework checkout refuses: its resources are the templates — by its real path, symlinked or not'
         );

         // # The verb's contract
         [$result] = $Probe($Bind($kit), ['boot'], ['yes' => true]);
         [$again, $output] = $Probe($Bind($kit), ['boot'], ['json' => true]);
         $document = json_decode($output, true);

         yield assert(
            assertion: $result === false && $again === false && is_array($document)
               && $document['status'] === 'refused' && str_contains($document['reason'], 'no JSON form'),
            description: '`--yes` is refused on `boot`; `--json` yields a refused document saying `boot` has no JSON form'
         );
      }
      finally {
         $Erase($base);
      }
   }
);
