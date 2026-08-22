<?php

namespace Bootgly\API\Environment\Configs\Tests\ScopeIdentity;


use function assert;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function get_debug_type;
use function is_dir;
use function is_file;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function strlen;
use function sys_get_temp_dir;
use function unlink;
use function var_export;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environment\Configs;
use Bootgly\API\Environment\Configs\Config;


return new Test(
   description: 'Configs: requested and declared scope identities must match',
   test: function () {
      $directory = sys_get_temp_dir() . '/bootgly-env2-' . bin2hex(random_bytes(8));
      $basedir = "{$directory}/";
      $expectedDirectory = "{$basedir}expected";
      $matchingDirectory = "{$basedir}matching";
      $expectedFile = "{$expectedDirectory}/expected.Config.php";
      $matchingFile = "{$matchingDirectory}/matching.Config.php";
      $counterFile = "{$directory}/executions.count";
      $counterLiteral = var_export($counterFile, true);
      $mismatchFixture = <<<PHP
      <?php

      use Bootgly\API\Environment\Configs\Config;

      \\file_put_contents({$counterLiteral}, 'x', FILE_APPEND | LOCK_EX);

      return (new Config(scope: 'foreign'))->Marker->bind(default: 'mismatched');
      PHP;
      $failedRetryFixture = <<<PHP
      <?php

      \\file_put_contents({$counterLiteral}, 'x', FILE_APPEND | LOCK_EX);

      return null;
      PHP;
      $recoveryFixture = <<<PHP
      <?php

      use Bootgly\API\Environment\Configs\Config;

      \\file_put_contents({$counterLiteral}, 'x', FILE_APPEND | LOCK_EX);

      return (new Config(scope: 'expected'))->Marker->bind(default: 'recovered');
      PHP;
      $matchingFixture = <<<'PHP'
      <?php

      use Bootgly\API\Environment\Configs\Config;

      return (new Config(scope: 'matching'))->Marker->bind(default: 'matching');
      PHP;

      $Count = static function (string $file): int {
         $contents = file_get_contents($file);

         return $contents === false ? -1 : strlen($contents);
      };

      $protectedLoaded = null;
      $mismatchLoaded = null;
      $failedRetry = null;
      $recoveryLoaded = null;
      $matchingLoaded = null;
      $protectedExecutions = null;
      $afterMismatchExecutions = null;
      $afterFailedRetryExecutions = null;
      $afterLazyExecutions = null;
      $afterRecoveryExecutions = null;
      $ProtectedRequested = null;
      $ProtectedForeign = null;
      $LatchedForeign = null;
      $First = null;
      $Second = null;
      $Recovered = null;
      $RecoveredByGet = null;
      $Matched = null;
      $Requested = new Config(scope: 'expected');
      $Requested->Marker->bind(default: 'requested-original');
      $Foreign = new Config(scope: 'foreign');
      $Foreign->Marker->bind(default: 'foreign-original');

      try {
         if (mkdir($expectedDirectory, 0700, true) === false
            || mkdir($matchingDirectory, 0700, true) === false
         ) {
            throw new RuntimeException('ENV-2 fixture directories could not be created.');
         }

         if (file_put_contents($expectedFile, $mismatchFixture) !== strlen($mismatchFixture)
            || file_put_contents($matchingFile, $matchingFixture) !== strlen($matchingFixture)
         ) {
            throw new RuntimeException('ENV-2 fixture configs could not be written.');
         }

         // ! ENV-2 source-to-sink: the requested file declares another scope.
         //   An explicit failed load must preserve incumbents under both keys.
         $Protected = new Configs($basedir);
         $Protected->Scopes->add($Requested);
         $Protected->Scopes->add($Foreign);
         $protectedLoaded = $Protected->load('expected');
         $ProtectedRequested = $Protected->Scopes->get('expected');
         $ProtectedForeign = $Protected->Scopes->get('foreign');
         $protectedExecutions = $Count($counterFile);

         // ! Lazy get() must latch a confirmed identity mismatch. Repeated
         //   lookups return their defaults without re-executing trusted PHP.
         if (file_put_contents($counterFile, '') !== 0) {
            throw new RuntimeException('ENV-2 execution counter could not be reset.');
         }
         $Latched = new Configs($basedir);
         $mismatchLoaded = $Latched->load('expected');
         $afterMismatchExecutions = $Count($counterFile);

         // ! A failed explicit retry must not clear the mismatch latch. This
         //   file executes once but returns no Config; subsequent get() calls
         //   must not execute it again.
         if (file_put_contents($expectedFile, $failedRetryFixture) !== strlen($failedRetryFixture)) {
            throw new RuntimeException('ENV-2 failed-retry config could not be written.');
         }
         $failedRetry = $Latched->load('expected');
         $afterFailedRetryExecutions = $Count($counterFile);
         $First = $Latched->get('expected', 'first-default');
         $Second = $Latched->get('expected', 'second-default');
         $afterLazyExecutions = $Count($counterFile);
         $LatchedForeign = $Latched->Scopes->get('foreign');

         // @ An explicit load is the recovery path: after the operator fixes
         //   the declaration, it retries, registers the requested scope and
         //   clears the failed-identity latch for subsequent get() calls.
         if (file_put_contents($expectedFile, $recoveryFixture) !== strlen($recoveryFixture)) {
            throw new RuntimeException('ENV-2 recovery config could not be written.');
         }
         $recoveryLoaded = $Latched->load('expected');
         $Recovered = $Latched->Scopes->get('expected');
         $RecoveredByGet = $Latched->get('expected', 'recovery-default');
         $afterRecoveryExecutions = $Count($counterFile);

         // @ Positive control: a file whose declared scope matches the request
         //   remains loadable and is registered under that identity.
         $Matching = new Configs($basedir);
         $matchingLoaded = $Matching->load('matching');
         $Matched = $Matching->Scopes->get('matching');
      }
      finally {
         if (is_file($expectedFile)) {
            unlink($expectedFile);
         }
         if (is_file($matchingFile)) {
            unlink($matchingFile);
         }
         if (is_file($counterFile)) {
            unlink($counterFile);
         }
         if (is_dir($expectedDirectory)) {
            rmdir($expectedDirectory);
         }
         if (is_dir($matchingDirectory)) {
            rmdir($matchingDirectory);
         }
         if (is_dir($directory)) {
            rmdir($directory);
         }
      }

      yield assert(
         assertion: $protectedLoaded === false
            && $ProtectedRequested === $Requested
            && $ProtectedRequested->Marker->get() === 'requested-original'
            && $ProtectedForeign === $Foreign
            && $ProtectedForeign->Marker->get() === 'foreign-original'
            && $protectedExecutions === 1,
         description: 'ENV-2 CONFIRMED: a mismatched declaration must preserve incumbents under '
            . 'both the requested and declared identities; evidence=' . json_encode([
               'loaded' => $protectedLoaded,
               'requestedPreserved' => $ProtectedRequested === $Requested,
               'foreignPreserved' => $ProtectedForeign === $Foreign,
               'executions' => $protectedExecutions,
            ])
      );

      yield assert(
         assertion: $mismatchLoaded === false
            && $afterMismatchExecutions === 1
            && $failedRetry === false
            && $afterFailedRetryExecutions === 2
            && $First === 'first-default'
            && $Second === 'second-default'
            && $afterLazyExecutions === 2
            && $LatchedForeign === null,
         description: 'ENV-2 CONFIRMED: a failed explicit retry must preserve the identity latch, '
            . 'so two lazy lookups do not re-execute or register it; evidence=' . json_encode([
               'loaded' => $mismatchLoaded,
               'afterMismatch' => $afterMismatchExecutions,
               'retry' => $failedRetry,
               'afterRetry' => $afterFailedRetryExecutions,
               'afterLazy' => $afterLazyExecutions,
               'first' => get_debug_type($First),
               'second' => get_debug_type($Second),
               'foreign' => get_debug_type($LatchedForeign),
            ])
      );

      yield assert(
         assertion: $recoveryLoaded === true
            && $Recovered instanceof Config
            && $Recovered->scope === 'expected'
            && $Recovered->Marker->get() === 'recovered'
            && $RecoveredByGet === $Recovered
            && $afterRecoveryExecutions === 3,
         description: 'an explicit load retries a fixed declaration, clears the mismatch latch '
            . 'and restores ordinary requested-scope lookups'
      );

      yield assert(
         assertion: $matchingLoaded === true
            && $Matched instanceof Config
            && $Matched->scope === 'matching'
            && $Matched->Marker->get() === 'matching',
         description: 'matching requested and declared scope identities still load and register'
      );
   }
);
