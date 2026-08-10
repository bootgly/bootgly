<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\File;


return new Test(
   description: 'File sessions serialize conditional commit against cross-process destroy',
   skip: extension_loaded('pcntl') === false
      || function_exists('stream_socket_pair') === false,
   test: function () {
      $root = sys_get_temp_dir() . '/bootgly-session-commit-' . bin2hex(random_bytes(12));
      $Handler = null;

      $Cleanup = null;
      $Cleanup = static function (string $path) use (&$Cleanup): void {
         if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
         }
         if (is_dir($path) === false) {
            return;
         }

         @chmod($path, 0700);
         foreach (@scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            $Cleanup($path . DIRECTORY_SEPARATOR . $entry);
         }
         @rmdir($path);
      };

      /**
       * Run one deterministic interprocess ordering around the real File
       * handler. The one-byte channel is the barrier: the child cannot enter
       * commit() until the parent releases it.
       *
       * @return array<string,mixed>
       */
      $Run = static function (
         File $Handler,
         string $sessionID,
         string $replacement,
         string $expectedRevision,
         bool $destroyFirst,
      ): array {
         $channels = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
         );
         if ($channels === false) {
            return ['error' => 'Could not create the commit/destroy barrier.'];
         }

         $PID = pcntl_fork();
         if ($PID === -1) {
            @fclose($channels[0]);
            @fclose($channels[1]);

            return ['error' => 'Could not fork the commit worker.'];
         }

         if ($PID === 0) {
            @fclose($channels[0]);
            @stream_set_timeout($channels[1], 5);
            $result = [
               'error' => '',
               'committed' => false,
               'read' => null,
            ];
            $exit = 1;

            try {
               if (@fread($channels[1], 1) !== 'G') {
                  throw new RuntimeException('Commit worker did not receive the barrier release.');
               }

               $revision = $expectedRevision;
               $result['committed'] = $Handler->commit($sessionID, $replacement, $revision);
               $result['revision_changed'] = $revision !== $expectedRevision;
               $result['read'] = $Handler->read($sessionID);
               $exit = 0;
            }
            catch (Throwable $Throwable) {
               $result['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
            }

            $JSON = json_encode($result);
            @fwrite($channels[1], ($JSON === false ? '{}' : $JSON) . "\n");
            @fclose($channels[1]);
            exit($exit);
         }

         @fclose($channels[1]);
         @stream_set_timeout($channels[0], 5);
         $destroyed = null;
         $waited = false;

         try {
            if ($destroyFirst) {
               $destroyed = $Handler->destroy($sessionID);
            }

            $released = @fwrite($channels[0], 'G') === 1;
            if ($released === false) {
               throw new RuntimeException('Could not release the commit worker barrier.');
            }
            $JSON = @stream_get_contents($channels[0]);
            pcntl_waitpid($PID, $status);
            $waited = true;

            if ($destroyFirst === false) {
               $destroyed = $Handler->destroy($sessionID);
            }

            $child = is_string($JSON)
               ? json_decode(trim($JSON), true)
               : null;

            return [
               'error' => '',
               'released' => $released,
               'destroyed' => $destroyed,
               'child_exited' => pcntl_wifexited($status),
               'child_status' => pcntl_wifexited($status)
                  ? pcntl_wexitstatus($status)
                  : -1,
               'child' => is_array($child) ? $child : [],
               'final' => $Handler->read($sessionID),
            ];
         }
         finally {
            @fclose($channels[0]);
            if ($waited === false) {
               pcntl_waitpid($PID, $status);
            }
         }
      };

      $FileReflection = new ReflectionClass(File::class);
      $PathProperty = $FileReflection->getProperty('path');
      $SecretProperty = $FileReflection->getProperty('secret');
      $SecretPathProperty = $FileReflection->getProperty('secretPath');
      $previousPath = (string) $PathProperty->getValue();
      $previousSecret = (string) $SecretProperty->getValue();
      $previousSecretPath = (string) $SecretPathProperty->getValue();

      try {
         $PathProperty->setValue(null, '');
         $SecretProperty->setValue(null, '');
         $SecretPathProperty->setValue(null, '');

         $sessionPath = $root . '/sessions';
         $Handler = new File(['save_path' => $sessionPath]);

         $replaceFirstID = bin2hex(random_bytes(16));
         $replaceFirstSeed = serialize(['order' => 'seed-before-replace']);
         $replaceFirstPayload = serialize(['order' => 'replace-before-destroy']);
         $replaceFirstRevision = null;
         $replaceFirstCreated = $Handler->commit(
            $replaceFirstID,
            $replaceFirstSeed,
            $replaceFirstRevision,
         );
         $replaceFirstSeeded = $Handler->read($replaceFirstID) === $replaceFirstSeed;
         $replaceFirst = $Run(
            $Handler,
            $replaceFirstID,
            $replaceFirstPayload,
            (string) $replaceFirstRevision,
            destroyFirst: false,
         );

         $destroyFirstID = bin2hex(random_bytes(16));
         $destroyFirstSeed = serialize(['order' => 'seed-before-destroy']);
         $destroyFirstPayload = serialize(['order' => 'destroy-before-replace']);
         $destroyFirstRevision = null;
         $destroyFirstCreated = $Handler->commit(
            $destroyFirstID,
            $destroyFirstSeed,
            $destroyFirstRevision,
         );
         $destroyFirstSeeded = $Handler->read($destroyFirstID) === $destroyFirstSeed;
         $destroyFirst = $Run(
            $Handler,
            $destroyFirstID,
            $destroyFirstPayload,
            (string) $destroyFirstRevision,
            destroyFirst: true,
         );

         yield assert(
            assertion: $replaceFirstCreated && $replaceFirstSeeded
               && $destroyFirstCreated && $destroyFirstSeeded,
            description: 'Both orderings begin with live records carrying opaque commit revisions'
         );

         $replaceChild = $replaceFirst['child'] ?? [];
         yield assert(
            assertion: ($replaceFirst['error'] ?? '') === ''
               && ($replaceFirst['released'] ?? false) === true
               && ($replaceFirst['destroyed'] ?? false) === true
               && ($replaceFirst['child_exited'] ?? false) === true
               && ($replaceFirst['child_status'] ?? -1) === 0
               && ($replaceChild['error'] ?? '') === ''
               && ($replaceChild['committed'] ?? false) === true
               && ($replaceChild['revision_changed'] ?? false) === true
               && ($replaceChild['read'] ?? null) === $replaceFirstPayload,
            description: 'Child replacement completes before the parent destroy barrier'
         );
         yield assert(
            assertion: ($replaceFirst['final'] ?? null) === false,
            description: 'Destroy ordered after replacement wins and leaves the record absent'
         );

         $destroyChild = $destroyFirst['child'] ?? [];
         yield assert(
            assertion: ($destroyFirst['error'] ?? '') === ''
               && ($destroyFirst['released'] ?? false) === true
               && ($destroyFirst['destroyed'] ?? false) === true
               && ($destroyFirst['child_exited'] ?? false) === true
               && ($destroyFirst['child_status'] ?? -1) === 0
               && ($destroyChild['error'] ?? '') === ''
               && ($destroyChild['committed'] ?? true) === false
               && ($destroyChild['read'] ?? null) === false,
            description: 'Parent destroy completes before releasing the stale child replacement'
         );
         yield assert(
            assertion: ($destroyFirst['final'] ?? null) === false,
            description: 'Replacement ordered after destroy fails closed and cannot recreate the record'
         );

         // # Same-ID update: existence alone is not authorization to write or
         // revoke. Only the revision returned by the newest commit may win.
         $CASID = bin2hex(random_bytes(16));
         $CASRevision = null;
         $CASCreated = $Handler->commit(
            $CASID,
            serialize(['identity' => 'victim', 'role' => 'admin', 'cart' => 'sku-42']),
            $CASRevision,
         );
         $staleRevision = (string) $CASRevision;
         $CASUpdated = $Handler->commit(
            $CASID,
            serialize(['cart' => 'sku-42', 'auth_state' => 'anonymous']),
            $CASRevision,
         );
         $expected = serialize(['cart' => 'sku-42', 'auth_state' => 'anonymous']);
         $stalePayload = serialize([
            'identity' => 'victim',
            'role' => 'admin',
            'cart' => 'sku-42',
            'late' => true,
         ]);
         $staleCommitted = $Handler->commit($CASID, $stalePayload, $staleRevision);
         $staleRevoked = $Handler->revoke($CASID, $staleRevision);

         yield assert(
            assertion: $CASCreated
               && $CASUpdated
               && is_string($CASRevision)
               && $CASRevision !== $staleRevision,
            description: 'Each successful File session commit advances the opaque revision'
         );
         yield assert(
            assertion: $staleCommitted === false
               && $staleRevoked === false
               && $Handler->read($CASID) === $expected,
            description: 'An old revision cannot overwrite or revoke a newer same-ID logout state'
         );
         yield assert(
            assertion: $Handler->revoke($CASID, (string) $CASRevision) === true
               && $Handler->read($CASID) === false,
            description: 'The current revision can revoke the exact File session snapshot'
         );

         // # Compatibility: deployed HMAC-only records gain a deterministic
         // legacy revision and migrate on their first successful CAS update.
         $legacyID = bin2hex(random_bytes(16));
         $legacyPayload = serialize(['identity' => 'legacy', 'role' => 'member']);
         $secret = (string) @file_get_contents($sessionPath . '/.secret');
         $legacyWire = hash_hmac('sha256', $legacyPayload, $secret) . $legacyPayload;
         $legacyFile = $sessionPath . '/session_' . $legacyID;
         $legacyWritten = @file_put_contents($legacyFile, $legacyWire) !== false
            && @chmod($legacyFile, 0600);
         $legacyRevision = null;
         $legacyFetched = $Handler->fetch($legacyID, $legacyRevision);
         $migratedPayload = serialize(['identity' => 'legacy', 'role' => 'viewer']);
         $legacyCommitted = $Handler->commit(
            $legacyID,
            $migratedPayload,
            $legacyRevision,
         );

         yield assert(
            assertion: $legacyWritten
               && $legacyFetched === $legacyPayload
               && is_string($legacyRevision)
               && $legacyCommitted
               && $Handler->read($legacyID) === $migratedPayload,
            description: 'A legacy HMAC-only File session migrates through its first revision-bound commit'
         );

         $recordsLock = $sessionPath . '/.records.lock';
         $lockState = @lstat($recordsLock);
         yield assert(
            assertion: is_array($lockState)
               && (((int) $lockState['mode']) & 0170000) === 0100000
               && (((int) $lockState['mode']) & 0777) === 0600
               && (int) $lockState['nlink'] === 1
               && (glob($sessionPath . '/session_.tmp.*') ?: []) === [],
            description: 'The stable records lock is private and both commit paths reclaim temporaries'
         );
      }
      finally {
         $Handler = null;
         $PathProperty->setValue(null, $previousPath);
         $SecretProperty->setValue(null, $previousSecret);
         $SecretPathProperty->setValue(null, $previousSecretPath);
         $Cleanup($root);
      }
   }
);
