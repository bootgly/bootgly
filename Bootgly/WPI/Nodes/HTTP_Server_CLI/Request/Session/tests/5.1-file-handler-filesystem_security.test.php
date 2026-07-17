<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\File;


return new Specification(
   description: 'File sessions enforce private metadata and converge on one secret',

   test: static function () {
      $root = sys_get_temp_dir() . '/bootgly-file-session-'
         . bin2hex(random_bytes(12));
      $previousUmask = umask(0000);
      $Reflection = new ReflectionClass(File::class);
      $PathProperty = $Reflection->getProperty('path');
      $SecretProperty = $Reflection->getProperty('secret');
      $SecretPathProperty = $Reflection->getProperty('secretPath');
      $previousPath = (string) $PathProperty->getValue();
      $previousSecret = (string) $SecretProperty->getValue();
      $previousSecretPath = (string) $SecretPathProperty->getValue();
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
      $Mode = static function (string $path): null|int {
         $state = @lstat($path);

         return is_array($state) ? ((int) $state['mode'] & 0777) : null;
      };
      $LinkCount = static function (string $path): null|int {
         $state = @lstat($path);

         return is_array($state) ? (int) $state['nlink'] : null;
      };

      $Channels = [];
      $PIDs = [];
      $statuses = [];
      $results = [];
      $IDs = [];
      $payloads = [];
      $raceSupported = extension_loaded('pcntl')
         && function_exists('stream_socket_pair');

      try {
         @mkdir($root, 0700, true);

         // # Public-method and legacy-mode contract.
         $roundtripPath = $root . '/roundtrip';
         $Roundtrip = new File(['save_path' => $roundtripPath]);
         $roundtripID = bin2hex(random_bytes(16));
         $roundtripPayload = serialize(['file' => 'roundtrip']);
         $roundtripWrite = $Roundtrip->write($roundtripID, $roundtripPayload);
         $roundtripFile = $roundtripPath . '/session_' . $roundtripID;
         $roundtripRead = $Roundtrip->read($roundtripID);
         $directoryMode = $Mode($roundtripPath);
         $secretMode = $Mode($roundtripPath . '/.secret');
         $lockMode = $Mode($roundtripPath . '/.secret.lock');
         $sessionMode = $Mode($roundtripFile);

         @chmod($roundtripFile, 0644);
         $legacyRead = $Roundtrip->read($roundtripID);
         $legacyMode = $Mode($roundtripFile);
         $touched = $Roundtrip->touch($roundtripID);

         $purgeID = bin2hex(random_bytes(16));
         $purgePayload = serialize(['file' => 'expired']);
         $Roundtrip->write($purgeID, $purgePayload);
         $purgeFile = $roundtripPath . '/session_' . $purgeID;
         @touch($purgeFile, time() - 120);
         $purged = $Roundtrip->purge(60) && is_file($purgeFile) === false;

         $destroyed = $Roundtrip->destroy($roundtripID)
            && $Roundtrip->read($roundtripID) === false;
         $invalid = $Roundtrip->write('../invalid', $roundtripPayload) === false
            && $Roundtrip->read('../invalid') === false
            && $Roundtrip->touch('../invalid') === false
            && $Roundtrip->destroy('../invalid');

         $tamperedID = bin2hex(random_bytes(16));
         $tamperedPayload = serialize(['file' => 'tampered']);
         $Roundtrip->write($tamperedID, $tamperedPayload);
         $tamperedFile = $roundtripPath . '/session_' . $tamperedID;
         $tamperedWire = (string) file_get_contents($tamperedFile);
         file_put_contents($tamperedFile, str_repeat('0', 64) . substr($tamperedWire, 64));
         @chmod($tamperedFile, 0600);
         $tamperedRejected = $Roundtrip->read($tamperedID) === false;

         // # Existing subclasses may retain the original protected signatures.
         $compatibilityPath = $root . '/compatibility';
         $Compatibility = new class(['save_path' => $compatibilityPath]) extends File {
            public static bool $resolved = false;
            public static bool $signed = false;
            public static string $blockedID = '';

            protected static function resolve (string $sessionId): string
            {
               self::$resolved = true;
               if ($sessionId === self::$blockedID) {
                  return '';
               }

               return parent::resolve($sessionId);
            }

            protected static function prepare (string $path): void
            {
               parent::prepare($path);
            }

            protected static function secret (): string
            {
               self::$signed = true;

               return str_repeat('d', 64);
            }
         };
         $compatibilityClass = $Compatibility::class;
         $compatibilityID = bin2hex(random_bytes(16));
         $compatibilityBlockedID = bin2hex(random_bytes(16));
         $compatibilityClass::$blockedID = $compatibilityBlockedID;
         $compatibilityPayload = serialize(['file' => 'compatible-subclass']);
         $compatibilityPreserved = $Compatibility->write(
            $compatibilityID,
            $compatibilityPayload
         )
            && $Compatibility->read($compatibilityID) === $compatibilityPayload
            && $Compatibility->write($compatibilityBlockedID, $compatibilityPayload) === false
            && $compatibilityClass::$resolved
            && $compatibilityClass::$signed
            && (glob($compatibilityPath . '/.secret*') ?: []) === [];

         // # Rename failure must throw and reclaim the exclusive temporary.
         $conflictID = bin2hex(random_bytes(16));
         $conflictTarget = $roundtripPath . '/session_' . $conflictID;
         @mkdir($conflictTarget, 0700);
         $renameRejected = false;
         try {
            $Roundtrip->write($conflictID, serialize(['conflict' => true]));
         }
         catch (RuntimeException $Exception) {
            $renameRejected = $Exception->getMessage()
               === 'Failed to publish a File session record atomically.';
         }
         $renameClean = (glob($roundtripPath . '/session_.tmp.*') ?: []) === [];
         @rmdir($conflictTarget);

         // # Unsafe directory and secret metadata fail closed without mutation.
         $unsafePath = $root . '/unsafe';
         @mkdir($unsafePath, 0777);
         @chmod($unsafePath, 0777);
         $unsafeRejected = false;
         try {
            new File(['save_path' => $unsafePath]);
         }
         catch (RuntimeException $Exception) {
            $unsafeRejected = $Exception->getMessage()
               === 'File session save directory has unsafe metadata.';
         }
         $unsafeUnchanged = $Mode($unsafePath) === 0777;

         $replaceablePath = $root . '/replaceable';
         $replaceableFinal = $replaceablePath . '/final';
         @mkdir($replaceableFinal, 0700, true);
         @chmod($replaceablePath, 0777);
         @chmod($replaceableFinal, 0700);
         $replaceableRejected = false;
         try {
            new File(['save_path' => $replaceableFinal]);
         }
         catch (RuntimeException $Exception) {
            $replaceableRejected = $Exception->getMessage()
               === 'File session save path ancestor is replaceable.';
         }
         $replaceableUnchanged = $Mode($replaceablePath) === 0777
            && $Mode($replaceableFinal) === 0700;

         $directoryTarget = $root . '/directory-target';
         $directoryLink = $root . '/directory-link';
         @mkdir($directoryTarget, 0700);
         $directoryLinkCreated = @symlink($directoryTarget, $directoryLink);
         $directoryLinkRejected = false;
         if ($directoryLinkCreated) {
            try {
               new File(['save_path' => $directoryLink]);
            }
            catch (RuntimeException $Exception) {
               $directoryLinkRejected = $Exception->getMessage()
                  === 'File session save paths must not contain symbolic links.';
            }
         }
         $directoryTargetClean = (glob($directoryTarget . '/.secret*') ?: []) === [];

         $symlinkPath = $root . '/symlink-secret';
         $symlinkTarget = $root . '/symlink-target';
         @mkdir($symlinkPath, 0700);
         $symlinkMaterial = str_repeat('a', 64);
         file_put_contents($symlinkTarget, $symlinkMaterial);
         @chmod($symlinkTarget, 0600);
         $symlinkCreated = @symlink($symlinkTarget, $symlinkPath . '/.secret');
         $symlinkRejected = false;
         if ($symlinkCreated) {
            try {
               $Symlink = new File(['save_path' => $symlinkPath]);
               $Symlink->write(bin2hex(random_bytes(16)), serialize(['unsafe' => 'symlink']));
            }
            catch (RuntimeException $Exception) {
               $symlinkRejected = $Exception->getMessage()
                  === 'File session secret path must not be a symbolic link.';
            }
         }
         $symlinkTargetSafe = file_get_contents($symlinkTarget) === $symlinkMaterial
            && $Mode($symlinkTarget) === 0600;

         $hardlinkPath = $root . '/hardlink-secret';
         $hardlinkTarget = $root . '/hardlink-target';
         @mkdir($hardlinkPath, 0700);
         file_put_contents($hardlinkPath . '/.secret', str_repeat('b', 64));
         @chmod($hardlinkPath . '/.secret', 0600);
         $hardlinkCreated = @link($hardlinkPath . '/.secret', $hardlinkTarget);
         $hardlinkRejected = false;
         if ($hardlinkCreated) {
            try {
               $Hardlink = new File(['save_path' => $hardlinkPath]);
               $Hardlink->write(bin2hex(random_bytes(16)), serialize(['unsafe' => 'hardlink']));
            }
            catch (RuntimeException $Exception) {
               $hardlinkRejected = $Exception->getMessage()
                  === 'File session secret has unsafe metadata.';
            }
         }
         $hardlinkPreserved = $LinkCount($hardlinkPath . '/.secret') === 2;

         $modePath = $root . '/mode-secret';
         $modeSecret = $modePath . '/.secret';
         $modeMaterial = str_repeat('c', 64);
         @mkdir($modePath, 0700);
         file_put_contents($modeSecret, $modeMaterial);
         @chmod($modeSecret, 0644);
         $modeRejected = false;
         try {
            $ModeHandler = new File(['save_path' => $modePath]);
            $ModeHandler->write(bin2hex(random_bytes(16)), serialize(['unsafe' => 'mode']));
         }
         catch (RuntimeException $Exception) {
            $modeRejected = $Exception->getMessage()
               === 'File session secret has unsafe metadata.';
         }
         $modePreserved = file_get_contents($modeSecret) === $modeMaterial
            && $Mode($modeSecret) === 0644
            && (glob($modePath . '/session_*') ?: []) === [];

         $invalidPath = $root . '/invalid-secret';
         @mkdir($invalidPath, 0700);
         file_put_contents($invalidPath . '/.secret', 'short');
         @chmod($invalidPath . '/.secret', 0600);
         $invalidSecretRejected = false;
         try {
            $Invalid = new File(['save_path' => $invalidPath]);
            $Invalid->write(bin2hex(random_bytes(16)), serialize(['unsafe' => 'invalid']));
         }
         catch (RuntimeException $Exception) {
            $invalidSecretRejected = $Exception->getMessage()
               === 'File session secret contents are invalid.';
         }

         $lockModePath = $root . '/lock-mode';
         $lockModeFile = $lockModePath . '/.secret.lock';
         @mkdir($lockModePath, 0700);
         file_put_contents($lockModeFile, 'lock');
         @chmod($lockModeFile, 0644);
         $lockModeRejected = false;
         try {
            $LockMode = new File(['save_path' => $lockModePath]);
            $LockMode->write(bin2hex(random_bytes(16)), serialize(['unsafe' => 'lock-mode']));
         }
         catch (RuntimeException $Exception) {
            $lockModeRejected = $Exception->getMessage()
               === 'File session secret lock has unsafe metadata.';
         }
         $lockModePreserved = file_get_contents($lockModeFile) === 'lock'
            && $Mode($lockModeFile) === 0644;

         $lockSymlinkPath = $root . '/lock-symlink';
         $lockSymlinkTarget = $root . '/lock-symlink-target';
         @mkdir($lockSymlinkPath, 0700);
         file_put_contents($lockSymlinkTarget, 'external-lock');
         @chmod($lockSymlinkTarget, 0600);
         $lockSymlinkCreated = @symlink(
            $lockSymlinkTarget,
            $lockSymlinkPath . '/.secret.lock'
         );
         $lockSymlinkRejected = false;
         if ($lockSymlinkCreated) {
            try {
               $LockSymlink = new File(['save_path' => $lockSymlinkPath]);
               $LockSymlink->write(
                  bin2hex(random_bytes(16)),
                  serialize(['unsafe' => 'lock-symlink'])
               );
            }
            catch (RuntimeException $Exception) {
               $lockSymlinkRejected = $Exception->getMessage()
                  === 'File session secret lock has unsafe metadata.';
            }
         }
         $lockSymlinkPreserved = file_get_contents($lockSymlinkTarget) === 'external-lock';

         $lockHardlinkPath = $root . '/lock-hardlink';
         $lockHardlinkTarget = $root . '/lock-hardlink-target';
         $lockHardlinkFile = $lockHardlinkPath . '/.secret.lock';
         @mkdir($lockHardlinkPath, 0700);
         file_put_contents($lockHardlinkFile, 'lock');
         @chmod($lockHardlinkFile, 0600);
         $lockHardlinkCreated = @link($lockHardlinkFile, $lockHardlinkTarget);
         $lockHardlinkRejected = false;
         if ($lockHardlinkCreated) {
            try {
               $LockHardlink = new File(['save_path' => $lockHardlinkPath]);
               $LockHardlink->write(
                  bin2hex(random_bytes(16)),
                  serialize(['unsafe' => 'lock-hardlink'])
               );
            }
            catch (RuntimeException $Exception) {
               $lockHardlinkRejected = $Exception->getMessage()
                  === 'File session secret lock has unsafe metadata.';
            }
         }
         $lockHardlinkPreserved = $LinkCount($lockHardlinkFile) === 2;

         // # A configured path switch must not redirect an existing handler.
         $pathA = $root . '/path-a';
         $pathB = $root . '/path-b';
         $HandlerA = new File(['save_path' => $pathA]);
         $IDA = bin2hex(random_bytes(16));
         $payloadA = serialize(['path' => 'A']);
         $HandlerA->write($IDA, $payloadA);
         $HandlerB = new File(['save_path' => $pathB]);
         $IDB = bin2hex(random_bytes(16));
         $payloadB = serialize(['path' => 'B']);
         $HandlerB->write($IDB, $payloadB);
         $pathsPinned = $HandlerA->read($IDA) === $payloadA
            && $HandlerB->read($IDB) === $payloadB
            && is_file($pathA . '/session_' . $IDA)
            && is_file($pathB . '/session_' . $IDB)
            && file_get_contents($pathA . '/.secret') !== file_get_contents($pathB . '/.secret');

         // # Concurrent first initialization: every worker must import the one
         // published secret and every record must remain readable by the parent.
         if ($raceSupported) {
            $racePath = $root . '/race';
            @mkdir($racePath, 0700);
            $children = 4;
            for ($worker = 0; $worker < $children; $worker++) {
               $Sockets = stream_socket_pair(
                  STREAM_PF_UNIX,
                  STREAM_SOCK_STREAM,
                  STREAM_IPPROTO_IP
               );
               if ($Sockets === false) {
                  throw new RuntimeException('Could not create a File session race channel.');
               }

               $ID = bin2hex(random_bytes(16));
               $payload = serialize(['worker' => $worker]);
               $PID = pcntl_fork();
               if ($PID === -1) {
                  throw new RuntimeException('Could not fork a File session race worker.');
               }
               if ($PID === 0) {
                  @fclose($Sockets[0]);
                  $result = ['ok' => false];
                  $exit = 1;
                  try {
                     if (@fread($Sockets[1], 1) !== 'G') {
                        throw new RuntimeException(
                           'File session worker did not receive the start signal.'
                        );
                     }
                     $Worker = new File(['save_path' => $racePath]);
                     $result = [
                        'ok' => $Worker->write($ID, $payload),
                        'read' => $Worker->read($ID),
                     ];
                     $exit = $result['ok'] === true && $result['read'] === $payload ? 0 : 1;
                  }
                  catch (Throwable $Throwable) {
                     $result['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
                  }

                  $JSON = json_encode($result);
                  @fwrite($Sockets[1], $JSON === false ? '{}' : $JSON);
                  @fclose($Sockets[1]);
                  exit($exit);
               }

               @fclose($Sockets[1]);
               $Channels[$worker] = $Sockets[0];
               $PIDs[$worker] = $PID;
               $IDs[$worker] = $ID;
               $payloads[$worker] = $payload;
            }

            foreach ($Channels as $Channel) {
               @fwrite($Channel, 'G');
            }
            foreach ($Channels as $worker => $Channel) {
               $JSON = stream_get_contents($Channel);
               @fclose($Channel);
               $Channels[$worker] = null;
               pcntl_waitpid($PIDs[$worker], $status);
               $statuses[$worker] = pcntl_wifexited($status)
                  ? pcntl_wexitstatus($status)
                  : -1;
               $results[$worker] = is_string($JSON)
                  ? json_decode($JSON, true)
                  : null;
            }

            $Parent = new File(['save_path' => $racePath]);
            $raceReads = [];
            foreach ($IDs as $worker => $ID) {
               $raceReads[$worker] = $Parent->read($ID) === $payloads[$worker];
            }
            $raceMetadata = $Mode($racePath) === 0700
               && $Mode($racePath . '/.secret') === 0600
               && $Mode($racePath . '/.secret.lock') === 0600
               && $LinkCount($racePath . '/.secret') === 1
               && $LinkCount($racePath . '/.secret.lock') === 1
               && strlen((string) file_get_contents($racePath . '/.secret')) === 64;
            $raceClean = (glob($racePath . '/.secret.tmp.*') ?: []) === []
               && (glob($racePath . '/session_.tmp.*') ?: []) === [];
         }
         else {
            $children = 0;
            $raceReads = [];
            $raceMetadata = false;
            $raceClean = false;
         }
      }
      finally {
         foreach ($Channels as $Channel) {
            if (is_resource($Channel)) {
               @fclose($Channel);
            }
         }
         foreach ($PIDs as $worker => $PID) {
            if (array_key_exists($worker, $statuses) === false) {
               pcntl_waitpid($PID, $status);
               $statuses[$worker] = pcntl_wifexited($status)
                  ? pcntl_wexitstatus($status)
                  : -1;
            }
         }

         $PathProperty->setValue(null, $previousPath);
         $SecretProperty->setValue(null, $previousSecret);
         $SecretPathProperty->setValue(null, $previousSecretPath);
         umask($previousUmask);
         $Cleanup($root);
         $cleaned = is_dir($root) === false;
      }

      yield assert(
         assertion: ($roundtripWrite ?? false) === true
            && ($roundtripRead ?? null) === ($roundtripPayload ?? null)
            && ($directoryMode ?? null) === 0700
            && ($secretMode ?? null) === 0600
            && ($lockMode ?? null) === 0600
            && ($sessionMode ?? null) === 0600
            && ($legacyRead ?? null) === ($roundtripPayload ?? null)
            && ($legacyMode ?? null) === 0600
            && ($touched ?? false) === true
            && ($purged ?? false) === true
            && ($destroyed ?? false) === true
            && ($invalid ?? false) === true
            && ($tamperedRejected ?? false) === true,
         description: 'File public methods preserve owner-only records and migrate safe legacy mode'
      );
      yield assert(
         assertion: ($compatibilityPreserved ?? false) === true,
         description: 'The original protected File extension signatures remain source-compatible'
      );
      yield assert(
         assertion: ($renameRejected ?? false) === true
            && ($renameClean ?? false) === true,
         description: 'A failed File session publication throws and leaves no temporary record'
      );
      yield assert(
         assertion: ($unsafeRejected ?? false) === true
            && ($unsafeUnchanged ?? false) === true
            && ($replaceableRejected ?? false) === true
            && ($replaceableUnchanged ?? false) === true
            && ($directoryLinkCreated ?? false) === true
            && ($directoryLinkRejected ?? false) === true
            && ($directoryTargetClean ?? false) === true,
         description: 'Unsafe directory metadata and redirected ancestors fail closed'
      );
      yield assert(
         assertion: ($symlinkCreated ?? false) === true
            && ($symlinkRejected ?? false) === true
            && ($symlinkTargetSafe ?? false) === true
            && ($hardlinkCreated ?? false) === true
            && ($hardlinkRejected ?? false) === true
            && ($hardlinkPreserved ?? false) === true
            && ($modeRejected ?? false) === true
            && ($modePreserved ?? false) === true
            && ($invalidSecretRejected ?? false) === true,
         description: 'Unsafe secret metadata and contents fail closed without target mutation'
      );
      yield assert(
         assertion: ($lockModeRejected ?? false) === true
            && ($lockModePreserved ?? false) === true
            && ($lockSymlinkCreated ?? false) === true
            && ($lockSymlinkRejected ?? false) === true
            && ($lockSymlinkPreserved ?? false) === true
            && ($lockHardlinkCreated ?? false) === true
            && ($lockHardlinkRejected ?? false) === true
            && ($lockHardlinkPreserved ?? false) === true,
         description: 'Unsafe permanent lock metadata is rejected without replacing its inode'
      );
      yield assert(
         assertion: ($pathsPinned ?? false) === true,
         description: 'Each File handler remains pinned to its configured path and path-bound secret'
      );
      yield assert(
         assertion: $raceSupported
            && count($statuses) === ($children ?? 0)
            && array_filter($statuses, static fn (int $status): bool => $status !== 0) === []
            && count($results) === ($children ?? 0)
            && array_filter(
               $results,
               static fn (mixed $result): bool => is_array($result) === false
                  || ($result['ok'] ?? false) !== true
            ) === []
            && array_filter(
               $raceReads ?? [],
               static fn (bool $read): bool => $read === false
            ) === []
            && ($raceMetadata ?? false) === true
            && ($raceClean ?? false) === true,
         description: 'Concurrent File session workers converge on one private published secret: '
            . json_encode(['statuses' => $statuses, 'results' => $results])
      );
      yield assert(
         assertion: ($cleaned ?? false) === true,
         description: 'The File session security test removes every isolated artifact'
      );
   }
);
