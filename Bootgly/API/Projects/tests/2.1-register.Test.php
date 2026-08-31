<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'Projects::register() re-emits the registry allow-list deterministically',
   test: function () {
      // ! Scratch registry file
      $file = sys_get_temp_dir() . '/bootgly-test-registry-' . getmypid() . '.php';
      @unlink($file);

      // @ Register into a fresh file
      yield assert(
         assertion: Projects::register('App/API', ['interfaces' => ['WPI']], $file) === true,
         description: 'registers a new path into a fresh registry file'
      );

      $registry = include $file;
      yield assert(
         assertion: ($registry['App/API']['interfaces'] ?? null) === ['WPI'],
         description: 'the emitted registry binds the path to its interfaces'
      );

      // @ Register more entries
      Projects::register('App/Console', ['interfaces' => ['CLI']], $file);
      Projects::register('Zeta/Web', ['interfaces' => ['WPI']], $file);

      $registry = include $file;
      yield assert(
         assertion: array_keys($registry) === ['App/API', 'App/Console', 'Zeta/Web'],
         description: 'entries are kept sorted alphabetically by project path'
      );

      // @ Re-register updates the existing key
      Projects::register('App/API', ['interfaces' => ['CLI', 'WPI']], $file);
      $registry = include $file;
      yield assert(
         assertion: ($registry['App/API']['interfaces'] ?? null) === ['CLI', 'WPI'],
         description: 're-registering a path updates its entry in place'
      );

      // @ A legacy file carrying the retired default flag is scrubbed on rewrite
      file_put_contents(
         $file,
         "<?php\nreturn [\n   'Old/Web' => ['interfaces' => ['WPI'], 'default' => true],\n];\n"
      );
      Projects::register('App/API', ['interfaces' => ['WPI']], $file);
      $registry = include $file;
      yield assert(
         assertion: str_contains((string) file_get_contents($file), "'default'") === false
            && ($registry['Old/Web']['interfaces'] ?? null) === ['WPI'],
         description: 'a legacy default flag is dropped on rewrite and its entry survives'
      );

      // ! Rejections
      yield assert(
         assertion: Projects::register('../Escape', ['interfaces' => ['CLI']], $file) === false,
         description: 'unsafe paths are rejected'
      );
      yield assert(
         assertion: Projects::register('App/Empty', [], $file) === false,
         description: 'entries without interfaces are rejected'
      );

      @unlink($file);
   }
);
