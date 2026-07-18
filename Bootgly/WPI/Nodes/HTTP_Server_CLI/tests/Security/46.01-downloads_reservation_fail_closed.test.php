<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M12 — a configured aggregate upload-disk ceiling must fail
 * closed when its aggregate controller or advisory lock is unavailable.
 *
 * Reflection only injects the two infrastructure failure preconditions. The
 * evidence itself uses three identical multipart requests through the live
 * HTTP decoder: a working counter is the rejection control, then an absent
 * controller and a live stream that deterministically cannot be flock()ed
 * exercise the two fail-open branches. A truncated-record fault additionally
 * verifies that corrupt controller state is rejected. Every temporary file and
 * static handle is restored before the case ends so the retained pre-fix
 * failure cannot poison later Security cases.
 */
$payload = str_repeat('M12!', 1024);
$payloadSize = strlen($payload);
$state = [
   'available' => false,
   'counter' => null,
   'counterfile' => '',
   'memory' => null,
   'cap' => Downloads::$maxBytesOnDisk,
   'baseline' => 0,
   'error' => '',
];

$Upload = static function (string $mode) use ($payload): string {
   $boundary = 'Bootgly-M12-Aggregate-Cap';
   $body = "--{$boundary}\r\n"
      . "Content-Disposition: form-data; name=\"upload\"; filename=\"m12.bin\"\r\n"
      . "Content-Type: application/octet-stream\r\n"
      . "\r\n"
      . $payload . "\r\n"
      . "--{$boundary}--\r\n";

   return "POST /m12/upload HTTP/1.1\r\n"
      . "Host: localhost\r\n"
      . "X-M12-Mode: {$mode}\r\n"
      . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
      . 'Content-Length: ' . strlen($body) . "\r\n"
      . "Connection: close\r\n\r\n"
      . $body;
};

return new Specification(
   description: 'Aggregate upload reservations must fail closed without a controller or usable lock',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /m12/control HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
      static fn (): string => $Upload('control'),
      static fn (): string => "GET /m12/missing-controller HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
      static fn (): string => $Upload('missing-controller'),
      static fn (): string => "GET /m12/failed-lock HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
      static fn (): string => $Upload('failed-lock'),
      static fn (): string => "GET /m12/cleanup HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
   ],

   response: static function (Request $Request, Response $Response, Router $Router) use (
      &$state,
      $payload,
      $payloadSize,
   ) {
      yield $Router->route('/m12/control', static function (
         Request $Request,
         Response $Response,
      ) use (&$state): Response {
         $CounterProperty = new ReflectionProperty(Downloads::class, 'counter');
         $CounterfileProperty = new ReflectionProperty(Downloads::class, 'counterfile');
         $counter = $CounterProperty->getValue(null);
         $counterfile = $CounterfileProperty->getValue(null);

         if (! is_resource($counter) || ! is_string($counterfile) || $counterfile === '') {
            return $Response(body: json_encode([
               'kind' => 'control-setup',
               'fixture_error' => 'live aggregate controller is unavailable',
            ]));
         }

         $state['available'] = true;
         $state['counter'] = $counter;
         $state['counterfile'] = $counterfile;
         $state['cap'] = Downloads::$maxBytesOnDisk;
         $state['baseline'] = Downloads::peek();
         $initialized = Downloads::init();
         Downloads::$maxBytesOnDisk = $state['baseline'];

         return $Response(body: json_encode([
            'kind' => 'control-setup',
            'available' => true,
            'initialized' => $initialized,
            'baseline' => $state['baseline'],
            'cap' => Downloads::$maxBytesOnDisk,
         ]));
      }, GET);

      yield $Router->route('/m12/missing-controller', static function (
         Request $Request,
         Response $Response,
      ) use (&$state): Response {
         if ($state['available'] !== true) {
            return $Response(body: json_encode(['kind' => 'missing-controller-setup', 'fixture_error' => true]));
         }

         $CounterProperty = new ReflectionProperty(Downloads::class, 'counter');
         $CounterfileProperty = new ReflectionProperty(Downloads::class, 'counterfile');
         $CounterProperty->setValue(null, null);
         $CounterfileProperty->setValue(null, '');
         $accepted = Downloads::reserve(1);

         return $Response(body: json_encode([
            'kind' => 'missing-controller-setup',
            'accepted_over_cap' => $accepted,
         ]));
      }, GET);

      yield $Router->route('/m12/failed-lock', static function (
         Request $Request,
         Response $Response,
      ) use (&$state): Response {
         if ($state['available'] !== true) {
            return $Response(body: json_encode(['kind' => 'failed-lock-setup', 'skip' => true]));
         }

         $CounterProperty = new ReflectionProperty(Downloads::class, 'counter');
         $CounterfileProperty = new ReflectionProperty(Downloads::class, 'counterfile');
         $CounterfileProperty->setValue(null, $state['counterfile']);
         $CounterProperty->setValue(null, $state['counter']);

         $memory = fopen('php://memory', 'w+');
         if ($memory === false) {
            $state['error'] = 'Could not allocate the non-flockable memory stream.';
            return $Response(body: json_encode(['kind' => 'failed-lock-setup', 'error' => $state['error']]));
         }

         $flockFailed = @flock($memory, LOCK_EX) === false;
         if ($flockFailed === false) {
            @flock($memory, LOCK_UN);
            fclose($memory);
            $state['error'] = 'The lock-failure fixture unexpectedly acquired an advisory lock.';
            return $Response(body: json_encode(['kind' => 'failed-lock-setup', 'error' => $state['error']]));
         }

         $state['memory'] = $memory;
         $CounterProperty->setValue(null, $memory);
         $before = Downloads::peek();
         $accepted = Downloads::reserve(1);
         $after = Downloads::peek();

         return $Response(body: json_encode([
            'kind' => 'failed-lock-setup',
            'flock_failed' => true,
            'accepted_over_cap' => $accepted,
            'counter_stable' => $before === $after,
         ]));
      }, GET);

      yield $Router->route('/m12/upload', static function (
         Request $Request,
         Response $Response,
      ) use (&$state, $payload, $payloadSize): Response {
         if ($state['available'] !== true) {
            return $Response(body: json_encode(['kind' => 'upload', 'skip' => true]));
         }

         $mode = $Request->Header->get('X-M12-Mode') ?? 'unknown';
         $file = $Request->download('upload');
         $path = is_array($file) && is_string($file['tmp_name'] ?? null)
            ? $file['tmp_name']
            : '';
         $size = is_array($file) ? (int) ($file['size'] ?? -1) : -1;
         $error = is_array($file) ? (int) ($file['error'] ?? -1) : -1;
         $exists = $path !== '' && is_file($path);
         $content = $exists ? file_get_contents($path) : false;
         $counter = $mode === 'missing-controller' ? null : Downloads::peek();

         // ! The injected failure branches never incremented the counter.
         //   Remove the tracked path while the same unavailable controller is
         //   installed, then unlink it so later cases cannot inherit residue.
         if ($path !== '') {
            if ($exists) {
               @unlink($path);
            }
            Downloads::discard($path);
         }

         return $Response(body: json_encode([
            'kind' => 'upload',
            'mode' => $mode,
            'handler' => true,
            'has_file' => $Request->hasFiles,
            'size' => $size,
            'error' => $error,
            'exists_before_cleanup' => $exists,
            'exact_payload' => $content === $payload,
            'exceeded_cap' => $state['baseline'] + $size > Downloads::$maxBytesOnDisk,
            'expected_size' => $payloadSize,
            'counter_delta' => $counter === null ? null : $counter - $state['baseline'],
            'removed' => $path === '' || ! is_file($path),
         ]));
      }, POST);

      yield $Router->route('/m12/cleanup', static function (
         Request $Request,
         Response $Response,
      ) use (&$state): Response {
         if ($state['available'] !== true) {
            return $Response(body: json_encode(['kind' => 'cleanup', 'skip' => true]));
         }

         $CounterProperty = new ReflectionProperty(Downloads::class, 'counter');
         $CounterfileProperty = new ReflectionProperty(Downloads::class, 'counterfile');

         // @ Restore the real controller before closing the synthetic stream.
         $CounterfileProperty->setValue(null, $state['counterfile']);
         $CounterProperty->setValue(null, $state['counter']);
         if (is_resource($state['memory'])) {
            fclose($state['memory']);
         }
         $state['memory'] = null;

         $before = Downloads::peek();

         // ! A truncated controller record is an infrastructure failure, not
         //   a synthetic zero. Corrupt it under the real lock, prove reserve()
         //   fails closed, then restore the exact integrity record.
         $counter = $state['counter'];
         $corrupted = false;
         $corruptionUnlocked = false;
         if (is_resource($counter) && @flock($counter, LOCK_EX)) {
            try {
               $corrupted = @rewind($counter)
                  && @ftruncate($counter, 0)
                  && @fwrite($counter, 'short') === 5
                  && @fflush($counter);
            }
            finally {
               $corruptionUnlocked = @flock($counter, LOCK_UN);
            }
         }
         $truncatedClosed = $corrupted
            && $corruptionUnlocked
            && Downloads::reserve(1) === false;

         $raw = pack('P', $before);
         $record = $raw . hash('sha256', $raw, true);
         $restored = false;
         $restorationUnlocked = false;
         if (is_resource($counter) && @flock($counter, LOCK_EX)) {
            try {
               $restored = @rewind($counter)
                  && @ftruncate($counter, 0)
                  && @fwrite($counter, $record) === 40
                  && @fflush($counter);
            }
            finally {
               $restorationUnlocked = @flock($counter, LOCK_UN);
            }
         }
         $recordRestored = $restored
            && $restorationUnlocked
            && Downloads::peek() === $before;

         Downloads::$maxBytesOnDisk = $before + 1;
         $operationalReserve = Downloads::reserve(1) === true;
         $counterIncremented = Downloads::peek() === $before + 1;
         if ($operationalReserve) {
            Downloads::release(1);
         }
         $counterRestored = Downloads::peek() === $before;
         Downloads::$maxBytesOnDisk = $state['cap'];

         return $Response(body: json_encode([
            'kind' => 'cleanup',
            'operational_reserve' => $operationalReserve,
            'counter_incremented' => $counterIncremented,
            'counter_released' => $counterRestored,
            'counter_restored' => $counterRestored && $before === $state['baseline'],
            'truncated_fixture' => $corrupted && $corruptionUnlocked,
            'truncated_reserve_closed' => $truncatedClosed,
            'truncated_record_restored' => $recordRestored,
            'baseline' => $state['baseline'],
            'counter' => $before,
            'error' => $state['error'],
         ]));
      }, GET);
   },

   test: static function (array $responses) use ($payloadSize): bool|string {
      if (count($responses) !== 7) {
         return 'M12 probe did not receive all seven control, fault, upload, and cleanup responses.';
      }

      $Bodies = [];
      foreach ($responses as $index => $response) {
         $separator = strpos($response, "\r\n\r\n");
         if ($separator === false || ! str_contains($response, 'HTTP/1.1 200 OK')) {
            Vars::$labels = ['M12 incomplete live response'];
            dump(json_encode(['request' => $index + 1, 'wire' => $response]));
            return 'M12 fixture failed before every live handler returned HTTP 200.';
         }

         $body = substr($response, $separator + 4);
         $decoded = json_decode($body, true);
         if (! is_array($decoded)) {
            Vars::$labels = ['M12 invalid JSON evidence'];
            dump(json_encode(['request' => $index + 1, 'body' => $body]));
            return 'M12 fixture could not decode one live handler result.';
         }
         $Bodies[] = $decoded;
      }

      [$controlSetup, $controlUpload, $missingSetup, $missingUpload, $lockSetup, $lockUpload, $cleanup] = $Bodies;

      if (isset($controlSetup['fixture_error'])) {
         return 'M12 fixture failed: the live aggregate controller was unavailable before fault injection.';
      }

      if (
         ($controlSetup['available'] ?? false) !== true
         || ($controlSetup['initialized'] ?? false) !== true
         || ($controlSetup['baseline'] ?? null) !== ($controlSetup['cap'] ?? null)
         || ($controlUpload['handler'] ?? false) !== true
         || ($controlUpload['mode'] ?? null) !== 'control'
         || ($controlUpload['has_file'] ?? false) !== true
         || ($controlUpload['error'] ?? null) !== UPLOAD_ERR_CANT_WRITE
         || ($controlUpload['size'] ?? null) !== 0
         || ($controlUpload['counter_delta'] ?? null) !== 0
         || ($controlUpload['removed'] ?? false) !== true
      ) {
         Vars::$labels = ['M12 working-controller multipart control'];
         dump(json_encode($Bodies));
         return 'M12 control failed: the live multipart path did not enforce the cap with a working controller and lock.';
      }

      if (
         ($lockSetup['flock_failed'] ?? false) !== true
         || ($lockSetup['counter_stable'] ?? false) !== true
         || ($cleanup['operational_reserve'] ?? false) !== true
         || ($cleanup['counter_incremented'] ?? false) !== true
         || ($cleanup['counter_released'] ?? false) !== true
         || ($cleanup['counter_restored'] ?? false) !== true
         || ($cleanup['truncated_fixture'] ?? false) !== true
         || ($cleanup['truncated_reserve_closed'] ?? false) !== true
         || ($cleanup['truncated_record_restored'] ?? false) !== true
         || ($cleanup['error'] ?? '') !== ''
      ) {
         Vars::$labels = ['M12 fault-injection or cleanup controls'];
         dump(json_encode($Bodies));
         return 'M12 fixture failed: the lock fault or final aggregate-controller restoration was not proved.';
      }

      $missingAccepted = ($missingSetup['accepted_over_cap'] ?? false) === true
         && ($missingUpload['handler'] ?? false) === true
         && ($missingUpload['mode'] ?? null) === 'missing-controller'
         && ($missingUpload['error'] ?? null) === 0
         && ($missingUpload['size'] ?? null) === $payloadSize
         && ($missingUpload['exact_payload'] ?? false) === true
         && ($missingUpload['exceeded_cap'] ?? false) === true
         && ($missingUpload['removed'] ?? false) === true;
      $lockAccepted = ($lockSetup['accepted_over_cap'] ?? false) === true
         && ($lockUpload['handler'] ?? false) === true
         && ($lockUpload['mode'] ?? null) === 'failed-lock'
         && ($lockUpload['error'] ?? null) === 0
         && ($lockUpload['size'] ?? null) === $payloadSize
         && ($lockUpload['exact_payload'] ?? false) === true
         && ($lockUpload['exceeded_cap'] ?? false) === true
         && ($lockUpload['counter_delta'] ?? null) === 0
         && ($lockUpload['removed'] ?? false) === true;

      if ($missingAccepted && $lockAccepted) {
         Vars::$labels = ['M12 fail-open aggregate upload evidence'];
         dump(json_encode([
            'control' => $controlUpload,
            'missing_controller' => $missingUpload,
            'failed_lock' => $lockUpload,
            'cleanup' => $cleanup,
         ]));

         return 'CONFIRMED M12: working controller rejected error='
            . $controlUpload['error'] . ' size=' . $controlUpload['size']
            . '; missing controller accepted reserve and wrote size=' . $missingUpload['size']
            . ' error=' . $missingUpload['error']
            . '; failed flock accepted reserve and wrote size=' . $lockUpload['size']
            . ' error=' . $lockUpload['error']
            . ' counter_delta=' . $lockUpload['counter_delta']
            . '; controller_restored=' . ($cleanup['counter_restored'] ? 'yes' : 'no') . '.';
      }

      $missingClosed = ($missingSetup['accepted_over_cap'] ?? true) === false
         && ($missingUpload['handler'] ?? false) === true
         && ($missingUpload['mode'] ?? null) === 'missing-controller'
         && ($missingUpload['has_file'] ?? false) === true
         && ($missingUpload['error'] ?? null) === UPLOAD_ERR_CANT_WRITE
         && ($missingUpload['size'] ?? null) === 0
         && ($missingUpload['exact_payload'] ?? true) === false
         && ($missingUpload['removed'] ?? false) === true;
      $lockClosed = ($lockSetup['accepted_over_cap'] ?? true) === false
         && ($lockUpload['handler'] ?? false) === true
         && ($lockUpload['mode'] ?? null) === 'failed-lock'
         && ($lockUpload['has_file'] ?? false) === true
         && ($lockUpload['error'] ?? null) === UPLOAD_ERR_CANT_WRITE
         && ($lockUpload['size'] ?? null) === 0
         && ($lockUpload['exact_payload'] ?? true) === false
         && ($lockUpload['removed'] ?? false) === true;

      if ($missingClosed && $lockClosed) {
         return true;
      }

      Vars::$labels = ['M12 incomplete fail-closed evidence'];
      dump(json_encode($Bodies));
      return 'M12 probe produced neither complete fail-open reproduction nor fail-closed behavior for both unavailable controllers.';
   },
);
