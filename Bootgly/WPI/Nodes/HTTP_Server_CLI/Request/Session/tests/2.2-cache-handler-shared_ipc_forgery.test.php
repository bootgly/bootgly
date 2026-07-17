<?php

use Bootgly\ABI\Resources\Cache as CacheResource;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handler;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Session as SessionGuard;


/**
 * H5 PoC/regression — raw SysV access may enumerate encrypted records, but it
 * must not disclose state, forge a session, replay another ID's ciphertext,
 * or reach PHP object construction.
 */

$available = extension_loaded('sysvshm')
   && extension_loaded('sysvsem')
   && function_exists('pcntl_fork')
   && function_exists('openssl_encrypt')
   && is_file('/proc/sysvipc/shm')
   && is_file('/proc/sysvipc/sem');


return new Specification(
   description: 'Shared session IPC is private and raw records are authenticated ciphertext',
   skip: $available === false,

   test: function () {
      $Find = static function (string $path, int $key): null|array {
         $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
         if (is_array($lines) === false) {
            return null;
         }

         foreach ($lines as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (
               is_array($fields)
               && isset($fields[0], $fields[1], $fields[2])
               && (int) $fields[0] === $key
            ) {
               return [
                  'id' => (int) $fields[1],
                  'permissions' => $fields[2],
               ];
            }
         }

         return null;
      };

      $PreviousHandler = Handler::$instance;
      $previousConfig = Handler::$config;
      $DefaultDriver = null;
      $Driver = null;
      $Requests = [];
      $Sessions = [];
      $Evidence = [];
      $childReaped = false;

      try {
         // @ Actual default: per-install key, derived segment/namespace and
         //   owner-only IPC. The default segment is never enumerated or written
         //   by the attacker portion of this test.
         $DefaultHandler = new Cache();
         $HandlerReflection = new ReflectionObject($DefaultHandler);
         $CacheProperty = $HandlerReflection->getProperty('Cache');
         /** @var CacheResource $DefaultCache */
         $DefaultCache = $CacheProperty->getValue($DefaultHandler);
         $defaultKey = $DefaultCache->Config->segment;
         $defaultSHMBefore = $Find('/proc/sysvipc/shm', $defaultKey);
         $defaultSEMBefore = $Find('/proc/sysvipc/sem', $defaultKey);
         $ownsDefault = $defaultSHMBefore === null && $defaultSEMBefore === null;
         $DefaultHandler->read(bin2hex(random_bytes(16)));
         $DefaultDriver = $DefaultCache->Driver;

         $keyPath = BOOTGLY_STORAGE_DIR . 'sessions/.cache.key';
         $keyState = @lstat($keyPath);
         $Evidence['default'] = [
            'key' => $defaultKey,
            'prefix' => $DefaultCache->Config->prefix,
            'shm' => $Find('/proc/sysvipc/shm', $defaultKey),
            'sem' => $Find('/proc/sysvipc/sem', $defaultKey),
            'key_mode' => is_array($keyState)
               ? ((int) $keyState['mode'] & 0777)
               : null,
         ];

         // @ Collision-free test-owned segment. The attacker is forked before
         //   the session secret, handler, victim ID and victim data exist.
         do {
            $segment = random_int(0x20000000, 0x6fffffff);
         }
         while (
            $Find('/proc/sysvipc/shm', $segment) !== null
            || $Find('/proc/sysvipc/sem', $segment) !== null
         );

         $rawForgedID = bin2hex(random_bytes(16));
         $replayID = bin2hex(random_bytes(16));
         $tamperedID = bin2hex(random_bytes(16));
         $rawForgedPayload = serialize([
            'identity' => 'admin',
            'scope' => ['root'],
         ]);

         $Sockets = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
         );
         if ($Sockets === false) {
            throw new RuntimeException('Could not create the attacker evidence channel.');
         }

         $PID = pcntl_fork();
         if ($PID === -1) {
            throw new RuntimeException('Could not fork the attacker process.');
         }

         if ($PID === 0) {
            @fclose($Sockets[0]);
            $result = [];
            $exit = 0;

            try {
               if (@fread($Sockets[1], 1) !== 'G') {
                  throw new RuntimeException('Attacker did not receive the victim-ready signal.');
               }

               $AttackerSegment = shm_attach($segment, 262_144, 0666);
               $AttackerSemaphore = sem_get($segment, 1, 0666, true);
               if ($AttackerSegment === false || $AttackerSemaphore === false) {
                  throw new RuntimeException('Raw attacker could not attach to SysV IPC.');
               }
               if (sem_acquire($AttackerSemaphore) === false) {
                  throw new RuntimeException('Raw attacker could not acquire the semaphore.');
               }

               try {
                  $records = [];
                  $captured = null;

                  for ($bucket = 0; $bucket < 256; $bucket++) {
                     $bucketID = 4_294_967_296 + $bucket;
                     if (shm_has_var($AttackerSegment, $bucketID) === false) {
                        continue;
                     }

                     $index = shm_get_var($AttackerSegment, $bucketID);
                     if (is_array($index) === false) {
                        continue;
                     }

                     foreach (array_keys($index) as $recordID) {
                        $recordID = (int) $recordID;
                        if (shm_has_var($AttackerSegment, $recordID) === false) {
                           continue;
                        }

                        $record = shm_get_var($AttackerSegment, $recordID);
                        if (is_array($record) === false) {
                           continue;
                        }

                        $recordKey = $record['k'] ?? null;
                        $recordValue = $record['v'] ?? null;
                        if (is_string($recordKey) && is_string($recordValue)) {
                           $records[$recordKey] = base64_encode($recordValue);
                           $captured ??= $recordValue;
                        }
                     }
                  }

                  if (is_string($captured) === false || $captured === '') {
                     throw new RuntimeException('Raw attacker found no victim ciphertext.');
                  }

                  $Inject = static function (
                     SysvSharedMemory $Segment,
                     string $key,
                     string $value
                  ): void {
                     $recordID = crc32($key);
                     shm_put_var($Segment, $recordID, [
                        'k' => $key,
                        'e' => time() + 3_600,
                        'v' => $value,
                     ]);

                     $bucketID = 4_294_967_296 + ($recordID % 256);
                     $index = shm_has_var($Segment, $bucketID)
                        ? shm_get_var($Segment, $bucketID)
                        : [];
                     if (is_array($index) === false) {
                        $index = [];
                     }
                     $index[$recordID] = true;
                     shm_put_var($Segment, $bucketID, $index);
                  };

                  $Inject(
                     $AttackerSegment,
                     "session:{$rawForgedID}",
                     $rawForgedPayload
                  );
                  $Inject(
                     $AttackerSegment,
                     "session:{$replayID}",
                     $captured
                  );

                  $offset = intdiv(strlen($captured), 2);
                  $tampered = $captured;
                  $tampered[$offset] = chr(ord($tampered[$offset]) ^ 1);
                  $Inject(
                     $AttackerSegment,
                     "session:{$tamperedID}",
                     $tampered
                  );

                  $result = [
                     'records' => $records,
                     'raw_forged_key' => "session:{$rawForgedID}",
                     'replay_key' => "session:{$replayID}",
                     'tampered_key' => "session:{$tamperedID}",
                  ];
               }
               finally {
                  sem_release($AttackerSemaphore);
                  shm_detach($AttackerSegment);
               }
            }
            catch (Throwable $Throwable) {
               $result = ['error' => $Throwable->getMessage()];
               $exit = 1;
            }

            $JSON = json_encode($result);
            @fwrite($Sockets[1], $JSON === false ? '{}' : $JSON);
            @fclose($Sockets[1]);
            exit($exit);
         }

         @fclose($Sockets[1]);

         // @ Created after fork: the attacker process never inherits this key
         //   or any victim identifier/data from parent memory.
         $secret = bin2hex(random_bytes(32));
         $Handler = new Cache([
            'driver' => 'shared',
            'prefix' => 'session:',
            'segment' => $segment,
            'size' => 262_144,
            'secret' => $secret,
         ]);
         $HandlerReflection = new ReflectionObject($Handler);
         $CacheProperty = $HandlerReflection->getProperty('Cache');
         /** @var CacheResource $CacheResource */
         $CacheResource = $CacheProperty->getValue($Handler);
         $Driver = $CacheResource->Driver;

         $victimID = bin2hex(random_bytes(16));
         $victimData = [
            'secret' => bin2hex(random_bytes(16)),
            'owner' => 'victim',
         ];
         $victimPayload = serialize($victimData);
         $Handler->write($victimID, $victimPayload);

         $Evidence['isolated'] = [
            'shm' => $Find('/proc/sysvipc/shm', $segment),
            'sem' => $Find('/proc/sysvipc/sem', $segment),
         ];

         if (@fwrite($Sockets[0], 'G') !== 1) {
            throw new RuntimeException('Could not release the attacker process.');
         }
         $attackerJSON = stream_get_contents($Sockets[0]);
         @fclose($Sockets[0]);
         pcntl_waitpid($PID, $status);
         $childReaped = true;
         $Evidence['attacker_json'] = $attackerJSON;
         $Evidence['attacker_status'] = $status;
         $Evidence['attacker_exit'] = pcntl_wifexited($status)
            ? pcntl_wexitstatus($status)
            : -1;
         $Attacker = is_string($attackerJSON)
            ? json_decode($attackerJSON, true)
            : null;
         $Evidence['attacker'] = $Attacker;

         $victimKey = "session:{$victimID}";
         $observed = is_array($Attacker)
            ? ($Attacker['records'][$victimKey] ?? null)
            : null;
         $ciphertext = is_string($observed)
            ? base64_decode($observed, true)
            : false;
         $Evidence['ciphertext_differs'] = is_string($ciphertext)
            && $ciphertext !== $victimPayload
            && str_contains($ciphertext, $victimData['secret']) === false;
         $Evidence['handler_roundtrip'] = $Handler->read($victimID) === $victimPayload;

         Handler::$instance = $Handler;
         $VictimSession = new Session($victimID);
         $RawForgedSession = new Session($rawForgedID);
         $ReplaySession = new Session($replayID);
         $TamperedSession = new Session($tamperedID);
         $Sessions = [
            $VictimSession,
            $RawForgedSession,
            $ReplaySession,
            $TamperedSession,
         ];

         $RawRequest = new stdClass();
         $RawRequest->Session = $RawForgedSession;
         $Requests = [$RawRequest];
         $Guard = new SessionGuard();
         $Evidence['victim_loaded'] = $VictimSession->loaded
            && $VictimSession->get('secret') === $victimData['secret'];
         $Evidence['raw_loaded'] = $RawForgedSession->loaded;
         $Evidence['raw_authenticated'] = $Guard->authenticate($RawRequest);
         $Evidence['replay_loaded'] = $ReplaySession->loaded;
         $Evidence['tampered_loaded'] = $TamperedSession->loaded;

         // @ Even a correctly encrypted application value cannot instantiate
         //   classes when Session decodes it.
         $objectID = bin2hex(random_bytes(16));
         $Probe = new stdClass();
         $Probe->marker = 'must-not-load';
         $Handler->write($objectID, serialize(['probe' => $Probe]));
         $ObjectSession = new Session($objectID);
         $Sessions[] = $ObjectSession;
         $Evidence['object_loaded'] = $ObjectSession->loaded;
         $Evidence['object_value'] = $ObjectSession->get('probe');

         $Evidence['victim_key'] = $victimKey;
      }
      finally {
         if (isset($Sockets) && is_array($Sockets)) {
            foreach ($Sockets as $Socket) {
               if (is_resource($Socket)) {
                  @fclose($Socket);
               }
            }
         }
         if (isset($PID) && $PID > 0 && $childReaped === false) {
            pcntl_waitpid($PID, $status);
            $childReaped = true;
         }

         unset(
            $Guard,
            $ObjectSession,
            $Probe,
            $RawRequest,
            $TamperedSession,
            $ReplaySession,
            $RawForgedSession,
            $VictimSession,
            $Requests,
            $Sessions
         );
         Handler::$instance = $PreviousHandler;
         Handler::$config = $previousConfig;

         if ($Driver instanceof Shared) {
            $Driver->destroy();
         }
         $Evidence['isolated_removed'] = isset($segment)
            && $Find('/proc/sysvipc/shm', $segment) === null
            && $Find('/proc/sysvipc/sem', $segment) === null;

         if (($ownsDefault ?? false) && $DefaultDriver instanceof Shared) {
            $DefaultDriver->destroy();
         }
      }

      $Attacker = $Evidence['attacker'] ?? null;
      yield assert(
         assertion: ($Evidence['default']['key'] ?? 0) > 0
            && ($Evidence['default']['prefix'] ?? '') !== 'session:'
            && ($Evidence['default']['shm']['permissions'] ?? null) === '600'
            && ($Evidence['default']['sem']['permissions'] ?? null) === '600'
            && ($Evidence['default']['key_mode'] ?? null) === 0600,
         description: 'Default sessions use a derived namespace, owner-only IPC and an owner-only key: '
            . json_encode($Evidence['default'] ?? null)
      );
      yield assert(
         assertion: ($Evidence['isolated']['shm']['permissions'] ?? null) === '600'
            && ($Evidence['isolated']['sem']['permissions'] ?? null) === '600',
         description: 'A fresh Session Shared backend creates both IPC objects with mode 0600: '
            . json_encode($Evidence['isolated'] ?? null)
      );
      yield assert(
         assertion: ($Evidence['attacker_exit'] ?? -1) === 0
            && is_array($Attacker)
            && array_key_exists($Evidence['victim_key'] ?? '', $Attacker['records'] ?? [])
            && ($Evidence['ciphertext_differs'] ?? false) === true
            && ($Evidence['handler_roundtrip'] ?? false) === true,
         description: 'Raw SysV enumeration reveals only authenticated ciphertext while the valid handler round-trips: '
            . json_encode([
               'exit' => $Evidence['attacker_exit'] ?? null,
               'status' => $Evidence['attacker_status'] ?? null,
               'raw' => $Evidence['attacker_json'] ?? null,
               'ciphertext_differs' => $Evidence['ciphertext_differs'] ?? null,
               'roundtrip' => $Evidence['handler_roundtrip'] ?? null,
            ])
      );
      yield assert(
         assertion: ($Evidence['victim_loaded'] ?? false) === true,
         description: 'A valid encrypted scalar/array session still loads normally'
      );
      yield assert(
         assertion: ($Evidence['raw_loaded'] ?? true) === false
            && ($Evidence['raw_authenticated'] ?? true) === false,
         description: 'Raw serialized identity injection is rejected before Session and SessionGuard'
      );
      yield assert(
         assertion: ($Evidence['replay_loaded'] ?? true) === false
            && ($Evidence['tampered_loaded'] ?? true) === false,
         description: 'Cross-ID ciphertext replay and one-bit tampering both fail authentication'
      );
      yield assert(
         assertion: ($Evidence['object_loaded'] ?? true) === false
            && ($Evidence['object_value'] ?? null) === null,
         description: 'Even authenticated session data cannot instantiate serialized PHP objects'
      );
      yield assert(
         assertion: ($Evidence['isolated_removed'] ?? false) === true,
         description: 'The test-owned shared-memory segment and semaphore are removed'
      );
   }
);
