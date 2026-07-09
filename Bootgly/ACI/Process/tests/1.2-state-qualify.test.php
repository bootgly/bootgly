<?php

use const BOOTGLY_STORAGE_DIR;
use function str_ends_with;
use function unlink;

use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Process\State: instance qualifier naming — constructor, qualify() re-qualification and save/read on qualified paths',
   test: function () {
      $pidsDir = BOOTGLY_STORAGE_DIR . 'pids/';

      // @ Constructor with an instance qualifier
      $State = new State('StateQualifyTest', '8080');

      yield assert(
         assertion: $State->pidFile === "{$pidsDir}StateQualifyTest.8080.json"
            && $State->pidLockFile === "{$pidsDir}StateQualifyTest.8080.lock"
            && $State->commandFile === "{$pidsDir}StateQualifyTest.8080.command",
         description: 'Constructor qualifies all three state files with the instance'
      );

      // @ FQCN ids are stripped to the short class name
      $Stripped = new State('Bootgly\\WPI\\Nodes\\HTTP_Server_CLI');

      yield assert(
         assertion: str_ends_with($Stripped->pidFile, 'pids/HTTP_Server_CLI.json'),
         description: 'FQCN id is stripped to the short class name; null instance stays unqualified'
      );

      // @ qualify() rebuilds from the base id — no qualifier accumulation
      $State->qualify('8081');

      yield assert(
         assertion: $State->pidFile === "{$pidsDir}StateQualifyTest.8081.json",
         description: 'qualify() replaces the previous qualifier instead of appending to it'
      );

      // @ qualify(null) returns to the unqualified name
      $State->qualify(null);

      yield assert(
         assertion: $State->pidFile === "{$pidsDir}StateQualifyTest.json",
         description: 'qualify(null) restores the unqualified file names'
      );

      // @ save/read/check roundtrip on a qualified path
      $State->qualify('8082');
      $State->save(['master' => 12345, 'port' => 8082]);

      yield assert(
         assertion: $State->check() === true
            && $State->read() === ['master' => 12345, 'port' => 8082],
         description: 'save()/read()/check() operate on the qualified PID file'
      );

      // @ clean() removes the qualified files
      $State->clean();

      yield assert(
         assertion: $State->check() === false,
         description: 'clean() unlinks the qualified PID file'
      );

      // ! Cleanup
      @unlink("{$pidsDir}StateQualifyTest.8082.lock");
   }
);
