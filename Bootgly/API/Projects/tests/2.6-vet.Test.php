<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'Projects::vet() confines write-path names; the read path stays wider',
   test: function () {
      // @ The alphabet — every shipped registry shape passes…
      $accepted = ['App', 'App/API', 'Demo/CLI', 'Demo/HTTP2-HTTP_Server_CLI', 'Zeta_1/Web-2'];
      $admitted = [];
      foreach ($accepted as $path) {
         if (Projects::vet($path) === false) {
            $admitted[] = $path;
         }
      }
      yield assert(
         assertion: $admitted === [],
         description: 'every conforming path is admitted, refused: ' . json_encode($admitted)
      );

      // @ …and everything outside it is refused
      $rejected = ['bad', 'App/api', 'Ap p', "App'", 'Näme', 'App~Cache', 'App.Web', '1App'];
      $leaked = [];
      foreach ($rejected as $path) {
         if (Projects::vet($path) === true) {
            $leaked[] = $path;
         }
      }
      yield assert(
         assertion: $leaked === [],
         description: 'every non-conforming path is refused, admitted: ' . json_encode($leaked)
      );

      // # The gate is wired into every write-path mutator, BEFORE any write
      $file = sys_get_temp_dir() . '/bootgly-test-vet-' . getmypid() . '.php';
      $base = sys_get_temp_dir() . '/bootgly-test-vet-' . getmypid() . '/';
      $erase = function (string $target) use (&$erase): void {
         if (is_file($target) === true) {
            unlink($target);
            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach ((array) scandir($target) as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            $erase("{$target}/{$entry}");
         }
         rmdir($target);
      };
      @unlink($file);
      $erase(rtrim($base, '/'));
      mkdir($base, 0755, true);

      // @ register()
      yield assert(
         assertion: Projects::register("Bad'Path", ['interfaces' => ['CLI']], $file) === false
            && is_file($file) === false,
         description: 'register() refuses a non-conforming path and writes no registry'
      );

      // @ generate() — nothing may land on disk for a refused path
      $done = Projects::generate(
         BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/CLI',
         "Bad'Path",
         ['interfaces' => ['CLI']],
         $base
      );
      yield assert(
         assertion: $done === false && is_dir("{$base}Bad'Path") === false,
         description: 'generate() refuses before copying anything'
      );

      // @ import() — same no-residue property
      $done = Projects::import(
         __DIR__ . '/fixtures/Sample',
         "bad'name",
         ['interfaces' => ['WPI']],
         $base
      );
      yield assert(
         assertion: $done === false && is_dir("{$base}bad'name") === false,
         description: 'import() refuses before copying anything'
      );

      // # The READ path is deliberately wider — a legacy registered name that
      //   fails the alphabet keeps validating, or it could no longer be
      //   started nor web-autobooted
      $Registry = new ReflectionProperty(Projects::class, 'registry');
      $memo = $Registry->getValue();
      try {
         $Registry->setValue(null, ["Weird'Name" => ['interfaces' => ['CLI']]]);

         yield assert(
            assertion: Projects::validate("Weird'Name") === true,
            description: 'a legacy registered name outside the alphabet still validates'
         );
      }
      finally {
         $Registry->setValue(null, $memo);
      }

      $erase(rtrim($base, '/'));
      @unlink($file);
   }
);
