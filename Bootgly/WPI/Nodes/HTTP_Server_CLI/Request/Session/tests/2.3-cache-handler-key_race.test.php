<?php

use function base64_decode;
use function bin2hex;
use function extension_loaded;
use function function_exists;
use function is_array;
use function is_file;
use function is_object;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function lstat;
use function pcntl_fork;
use function pcntl_waitpid;
use function random_bytes;
use function random_int;
use function rmdir;
use function stream_get_contents;
use function stream_socket_pair;
use function unlink;

use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ABI\Resources\Cache as CacheResource;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;


return new Specification(
   description: 'Session Cache workers atomically converge on one owner-only key',
   skip: extension_loaded('sysvshm') === false
      || extension_loaded('sysvsem') === false
      || function_exists('pcntl_fork') === false,

   test: function () {
      $directory = BOOTGLY_STORAGE_DIR . 'sessions/.cache-race-'
         . bin2hex(random_bytes(12));
      $path = "{$directory}/key";
      $children = 4;
      $Channels = [];
      $PIDs = [];
      $IDs = [];
      $payloads = [];
      $results = [];
      $statuses = [];
      $Parent = null;
      $Driver = null;

      $Find = static function (string $path, int $key): bool {
         foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ((int) trim($line) === $key) {
               return true;
            }
         }

         return false;
      };

      do {
         $segment = random_int(0x20000000, 0x6fffffff);
      }
      while (
         $Find('/proc/sysvipc/shm', $segment)
         || $Find('/proc/sysvipc/sem', $segment)
      );

      $config = [
         'driver' => 'shared',
         'segment' => $segment,
         'size' => 262_144,
         'secret_path' => $path,
      ];

      try {
         for ($worker = 0; $worker < $children; $worker++) {
            $Sockets = stream_socket_pair(
               STREAM_PF_UNIX,
               STREAM_SOCK_STREAM,
               STREAM_IPPROTO_IP
            );
            if ($Sockets === false) {
               throw new RuntimeException('Could not create a worker synchronization channel.');
            }

            $ID = bin2hex(random_bytes(16));
            $payload = "worker-{$worker}-" . bin2hex(random_bytes(8));
            $PID = pcntl_fork();
            if ($PID === -1) {
               throw new RuntimeException('Could not fork a Session Cache worker.');
            }

            if ($PID === 0) {
               @fclose($Sockets[0]);
               $result = ['ok' => false];
               $exit = 1;

               try {
                  if (@fread($Sockets[1], 1) !== 'G') {
                     throw new RuntimeException('Worker did not receive the start signal.');
                  }

                  $Handler = new Cache($config);
                  $result = [
                     'ok' => $Handler->write($ID, $payload),
                     'read' => $Handler->read($ID),
                  ];
                  $exit = $result['ok'] === true && $result['read'] === $payload ? 0 : 1;
               }
               catch (Throwable $Throwable) {
                  $result['error'] = $Throwable->getMessage();
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
            if (@fwrite($Channel, 'G') !== 1) {
               throw new RuntimeException('Could not release a Session Cache worker.');
            }
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

         $Parent = new Cache($config);
         $HandlerReflection = new ReflectionObject($Parent);
         $CacheProperty = $HandlerReflection->getProperty('Cache');
         /** @var CacheResource $CacheResource */
         $CacheResource = $CacheProperty->getValue($Parent);
         $Driver = $CacheResource->Driver;

         $roundtrips = [];
         foreach ($IDs as $worker => $ID) {
            $roundtrips[$worker] = $Parent->read($ID) === $payloads[$worker];
         }

         $keyState = @lstat($path);
         $directoryState = @lstat($directory);
         $encoded = is_file($path) ? @file_get_contents($path) : false;
         $material = is_string($encoded)
            ? base64_decode(trim($encoded), true)
            : false;
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

         if ($Driver instanceof Shared) {
            $Driver->destroy();
         }
         else {
            $Segment = @shm_attach($segment, 262_144, 0600);
            if (is_object($Segment)) {
               @shm_remove($Segment);
            }
            $Semaphore = @sem_get($segment, 1, 0600, true);
            if (is_object($Semaphore)) {
               @sem_remove($Semaphore);
            }
         }

         @unlink($path);
         @rmdir($directory);
         $cleaned = is_file($path) === false && is_dir($directory) === false;
      }

      yield assert(
         assertion: count($statuses) === $children
            && array_filter($statuses, static fn (int $status): bool => $status !== 0) === []
            && count($results) === $children
            && array_filter(
               $results,
               static fn (mixed $result): bool => is_array($result) === false
                  || ($result['ok'] ?? false) !== true
            ) === [],
         description: 'All concurrent workers create/read sessions without key divergence: '
            . json_encode(['statuses' => $statuses, 'results' => $results])
      );
      yield assert(
         assertion: is_array($keyState ?? null)
            && ((int) $keyState['mode'] & 0777) === 0600
            && is_array($directoryState ?? null)
            && ((int) $directoryState['mode'] & 0777) === 0700
            && is_string($material ?? null)
            && strlen($material) === 32,
         description: 'The atomically published key and its directory have owner-only metadata'
      );
      yield assert(
         assertion: count($roundtrips ?? []) === $children
            && array_filter(
               $roundtrips ?? [],
               static fn (bool $roundtrip): bool => $roundtrip === false
            ) === [],
         description: 'A later worker decrypts every record written during the creation race'
      );
      yield assert(
         assertion: ($cleaned ?? false) === true,
         description: 'The race test removes its key material and directory'
      );
   }
);
