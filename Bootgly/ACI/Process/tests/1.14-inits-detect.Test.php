<?php

namespace Bootgly\ACI\Process;


use function array_diff;
use function assert;
use function dirname;
use function file_put_contents;
use function is_dir;
use function is_link;
use function mkdir;
use function rmdir;
use function scandir;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * `Inits::detect()` — which init booted the machine, read from a filesystem
 * root so every platform can be laid out and asserted here, never guessed
 * from the host running the suite.
 */

return new Test(
   description: 'Inits::detect() names the booted init by its footprint, then PID 1, then the binaries',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-inits-' . uniqid();

      $lay = static function (array $dirs = [], array $files = [], array $links = []) use ($base): string {
         $root = $base . '/' . uniqid();
         mkdir($root, 0755, true);
         foreach ($dirs as $dir) {
            mkdir("{$root}/{$dir}", 0755, true);
         }
         foreach ($files as $file => $content) {
            $parent = dirname("{$root}/{$file}");
            if (is_dir($parent) === false) {
               mkdir($parent, 0755, true);
            }
            file_put_contents("{$root}/{$file}", $content);
         }
         foreach ($links as $link => $target) {
            $parent = dirname("{$root}/{$link}");
            if (is_dir($parent) === false) {
               mkdir($parent, 0755, true);
            }
            symlink($target, "{$root}/{$link}");
         }
         return $root;
      };
      $wipe = static function (string $dir) use (&$wipe): void {
         foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = "{$dir}/{$entry}";
            if (is_link($path) || is_dir($path) === false) {
               unlink($path);
            }
            else {
               $wipe($path);
            }
         }
         rmdir($dir);
      };

      try {
         // # The running footprint wins over everything else
         yield assert(
            assertion: Inits::detect($lay(['run/systemd/system', 'sbin'], ['proc/1/comm' => "init\n", 'sbin/openrc' => ''])) === Inits::Systemd
               && Inits::detect($lay(['run/openrc'], ['proc/1/comm' => "init\n"])) === Inits::OpenRC
               && Inits::detect($lay(['run/runit'])) === Inits::Runit
               && Inits::detect($lay(['run/s6-rc'])) === Inits::S6,
            description: 'a directory under /run names the init that is running, whatever else is installed'
         );

         // # PID 1 by name — an application as PID 1 is a container, not an init
         yield assert(
            assertion: Inits::detect($lay([], ['proc/1/comm' => "systemd\n"])) === Inits::Systemd
               && Inits::detect($lay(['sbin', 'etc/init.d'], ['proc/1/comm' => "php\n", 'sbin/openrc' => '', 'sbin/init' => ''])) === Inits::None
               && Inits::detect($lay([], ['proc/1/comm' => "bash\n"])) === Inits::None,
            description: 'PID 1 named systemd is systemd; PID 1 named after an application is no init at all, even with inits installed'
         );

         // # A bare `init` as PID 1 is told apart by the binaries
         yield assert(
            assertion: Inits::detect($lay(['sbin'], ['proc/1/comm' => "init\n"], ['sbin/init' => '/bin/busybox'])) === Inits::BusyBox
               && Inits::detect($lay(['sbin'], ['proc/1/comm' => "init\n", 'sbin/openrc-run' => ''])) === Inits::OpenRC
               && Inits::detect($lay(['etc/runit'], ['proc/1/comm' => "init\n"])) === Inits::Runit
               && Inits::detect($lay(['etc/s6'], ['proc/1/comm' => "init\n"])) === Inits::S6
               && Inits::detect($lay(['etc/init.d', 'sbin'], ['proc/1/comm' => "init\n", 'sbin/init' => ''])) === Inits::SysV,
            description: 'BusyBox by the symlink, OpenRC by its binaries, runit and s6 by /etc, SysV by /etc/init.d'
         );

         // # systemd installed behind /sbin/init but not booted — WSL2 without it
         yield assert(
            assertion: Inits::detect($lay(['etc/init.d', 'lib/systemd'], ['proc/1/comm' => "init\n", 'lib/systemd/systemd' => ''], ['sbin/init' => '/lib/systemd/systemd'])) === Inits::None,
            description: 'an unbooted systemd behind /sbin/init is no init, not SysV'
         );

         // # Nothing at all
         yield assert(
            assertion: Inits::detect($lay()) === Inits::None,
            description: 'an empty root has no init'
         );
      }
      finally {
         if (is_dir($base)) {
            $wipe($base);
         }
      }
   }
);
