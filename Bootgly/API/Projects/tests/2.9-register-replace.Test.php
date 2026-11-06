<?php

use function Bootgly\ABI\remove_recursively;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Projects;


return new Test(
   description: 'Projects::register() installs the registry through File->replace(): plain-write mode, link replaced, no temporary left',
   test: function () {
      // ! The permission bits of an entry
      $mode = static function (string $path): int {
         clearstatcache(true);

         return (int) fileperms($path) & 0777;
      };
      // ! Every entry of a directory but the dot entries
      $list = static function (string $directory): array {
         $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
         sort($entries);

         return $entries;
      };
      $directory = Temporaries::reserve('projects-register-replace');
      $previous = umask(0027);

      try {
         // @ A fresh registry gets the mode a plain write gives under the umask
         $file = "{$directory}/Bootgly.projects.php";
         $registered = Projects::register('App/API', ['interfaces' => ['WPI']], $file);
         yield assert(
            assertion: $registered === true && $mode($file) === 0640,
            description: 'a fresh registry is written with 0666 minus the umask (0640 under 0027), got: '
               . (is_file($file) ? decoct($mode($file)) : 'no file')
         );

         // @ An existing registry is swapped for a new inode with the same mode rule
         chmod($file, 0600);
         $inode = fileinode($file);
         $updated = Projects::register('App/Console', ['interfaces' => ['CLI']], $file);
         clearstatcache(true);
         $registry = include $file;
         yield assert(
            assertion: $updated === true
               && fileinode($file) !== $inode
               && $mode($file) === 0640
               && array_keys($registry) === ['App/API', 'App/Console'],
            description: 'a rewrite installs a new inode holding both entries, with the plain-write mode'
         );
         yield assert(
            assertion: $list($directory) === ['Bootgly.projects.php'],
            description: 'no temporary is left beside the registry, got: ' . json_encode($list($directory))
         );

         // @ A link planted at the registry path is replaced, never written through
         $victim = "{$directory}/victim.php";
         file_put_contents($victim, "<?php\nreturn [];\n");
         $linked = "{$directory}/Linked.projects.php";
         symlink($victim, $linked);
         $replaced = Projects::register('App/Web', ['interfaces' => ['WPI']], $linked);
         clearstatcache(true);
         $registry = include $linked;
         yield assert(
            assertion: $replaced === true
               && is_link($linked) === false
               && file_get_contents($victim) === "<?php\nreturn [];\n"
               && array_keys($registry) === ['App/Web'],
            description: 'a symlink at the registry path is replaced by the registry, its target is left untouched'
         );

         // @ A registry whose directory does not exist is refused, nothing is created
         $missing = "{$directory}/missing/Bootgly.projects.php";
         yield assert(
            assertion: Projects::register('App/API', ['interfaces' => ['WPI']], $missing) === false
               && file_exists("{$directory}/missing") === false,
            description: 'register() answers false when the registry directory does not exist'
         );
      }
      finally {
         umask($previous);
         remove_recursively($directory);
      }

      yield assert(
         assertion: is_dir($directory) === false,
         description: 'the temporary directory is cleaned up'
      );
   }
);
