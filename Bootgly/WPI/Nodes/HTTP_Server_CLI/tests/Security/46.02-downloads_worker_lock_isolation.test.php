<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * M12 regression — each worker must reopen the aggregate-controller lockfile.
 *
 * Linux associates flock() ownership with the open file description, which is
 * shared when a master descriptor is inherited across fork(). Without a
 * per-process reopen, sibling workers can both acquire LOCK_EX and race the
 * counter read-modify-write sequence. Two forked children first run the same
 * idempotent Downloads::init() path used by a worker, then this test proves
 * that B cannot acquire while A holds the exclusive lock and that the parent
 * can acquire it after A releases it.
 */
return new Specification(
   description: 'Forked workers must own independent aggregate-download lock descriptors',
   Separator: new Separator(line: true),

   request: static fn (): string => "GET /m12/worker-lock HTTP/1.1\r\n"
      . "Host: localhost\r\n"
      . "Connection: close\r\n\r\n",

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m12/worker-lock', static function (
         Request $Request,
         Response $Response,
      ): Response {
         if (! extension_loaded('pcntl')) {
            return $Response->JSON->send(['skip' => true]);
         }
         if (Downloads::init() === false) {
            return $Response->JSON->send([
               'fixture_error' => 'aggregate_controller_unavailable',
            ]);
         }

         $CounterProperty = new ReflectionProperty(Downloads::class, 'counter');
         $CounterfileProperty = new ReflectionProperty(Downloads::class, 'counterfile');
         $counter = $CounterProperty->getValue(null);
         $counterfile = $CounterfileProperty->getValue(null);
         $opened = is_resource($counter) ? @fstat($counter) : false;
         $current = is_string($counterfile) && $counterfile !== ''
            ? @lstat($counterfile)
            : false;
         $controllerPinned = is_array($opened) && is_array($current)
            && $opened['dev'] === $current['dev']
            && $opened['ino'] === $current['ino'];
         $controllerMode = is_array($current) ? ($current['mode'] & 0777) : -1;
         $baseline = Downloads::peek();

         $pairA = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         $pairB = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if (! is_array($pairA) || ! is_array($pairB)) {
            foreach ([$pairA, $pairB] as $pair) {
               if (is_array($pair)) {
                  foreach ($pair as $stream) {
                     fclose($stream);
                  }
               }
            }

            return $Response->JSON->send(['fixture_error' => 'stream_socket_pair']);
         }

         foreach ([$pairA[0], $pairA[1], $pairB[0], $pairB[1]] as $stream) {
            stream_set_timeout($stream, 3);
         }

         $PID_A = pcntl_fork();
         if ($PID_A === 0) {
            fclose($pairA[0]);
            fclose($pairB[0]);
            fclose($pairB[1]);

            $initialized = Downloads::init();
            $CounterProperty = new ReflectionProperty(Downloads::class, 'counter');
            $counter = $CounterProperty->getValue(null);
            fwrite($pairA[1], $initialized && is_resource($counter) ? 'R' : 'E');

            $command = fread($pairA[1], 1);
            $acquired = $command === 'L' && is_resource($counter)
               && @flock($counter, LOCK_EX);
            fwrite($pairA[1], $acquired ? 'A' : 'E');

            $command = fread($pairA[1], 1);
            if ($acquired && $command === 'U') {
               $released = @flock($counter, LOCK_UN);
               fwrite($pairA[1], $released ? 'U' : 'E');
            }
            else {
               fwrite($pairA[1], 'E');
            }
            fclose($pairA[1]);
            exit(0);
         }
         if ($PID_A === -1) {
            foreach ([$pairA[0], $pairA[1], $pairB[0], $pairB[1]] as $stream) {
               fclose($stream);
            }

            return $Response->JSON->send(['fixture_error' => 'fork_a']);
         }

         $PID_B = pcntl_fork();
         if ($PID_B === 0) {
            fclose($pairB[0]);
            fclose($pairA[0]);
            fclose($pairA[1]);

            $initialized = Downloads::init();
            $CounterProperty = new ReflectionProperty(Downloads::class, 'counter');
            $counter = $CounterProperty->getValue(null);
            fwrite($pairB[1], $initialized && is_resource($counter) ? 'R' : 'E');

            $command = fread($pairB[1], 1);
            $acquired = $command === 'T' && is_resource($counter)
               && @flock($counter, LOCK_EX | LOCK_NB);
            if ($acquired) {
               @flock($counter, LOCK_UN);
            }
            fwrite($pairB[1], $acquired ? 'X' : 'B');

            $command = fread($pairB[1], 1);
            $acquiredAfter = $command === 'R' && is_resource($counter)
               && @flock($counter, LOCK_EX | LOCK_NB);
            if ($acquiredAfter) {
               @flock($counter, LOCK_UN);
            }
            fwrite($pairB[1], $acquiredAfter ? 'A' : 'E');

            // @ Teardown runs before the base server drains workers. Closing a
            //   child descriptor must not reset the shared aggregate record.
            $reserved = Downloads::reserve(1);
            Downloads::destroy();
            fwrite($pairB[1], $reserved ? 'D' : 'E');
            fclose($pairB[1]);
            exit(0);
         }

         fclose($pairA[1]);
         fclose($pairB[1]);

         if ($PID_B === -1) {
            fwrite($pairA[0], 'L');
            fread($pairA[0], 1);
            fwrite($pairA[0], 'U');
            fread($pairA[0], 1);
            pcntl_waitpid($PID_A, $statusA);
            fclose($pairA[0]);
            fclose($pairB[0]);

            return $Response->JSON->send(['fixture_error' => 'fork_b']);
         }

         $readyA = fread($pairA[0], 1) === 'R';
         $readyB = fread($pairB[0], 1) === 'R';

         fwrite($pairA[0], 'L');
         $acquiredA = fread($pairA[0], 1) === 'A';

         fwrite($pairB[0], 'T');
         $blockedB = fread($pairB[0], 1) === 'B';

         fwrite($pairA[0], 'U');
         $releasedA = fread($pairA[0], 1) === 'U';
         fwrite($pairB[0], 'R');
         $acquiredBAfter = fread($pairB[0], 1) === 'A';
         $destroyedB = fread($pairB[0], 1) === 'D';

         pcntl_waitpid($PID_A, $statusA);
         pcntl_waitpid($PID_B, $statusB);
         fclose($pairA[0]);
         fclose($pairB[0]);

         $afterDestroy = Downloads::peek();
         $destroyPreserved = $destroyedB && $afterDestroy === $baseline + 1;
         if ($afterDestroy > $baseline) {
            Downloads::release($afterDestroy - $baseline);
         }
         $counterRestored = Downloads::peek() === $baseline;

         $CounterProperty = new ReflectionProperty(Downloads::class, 'counter');
         $counter = $CounterProperty->getValue(null);
         $acquiredAfter = is_resource($counter) && @flock($counter, LOCK_EX | LOCK_NB);
         if ($acquiredAfter) {
            @flock($counter, LOCK_UN);
         }

         return $Response->JSON->send([
            'skip' => false,
            'ready_a' => $readyA,
            'ready_b' => $readyB,
            'acquired_a' => $acquiredA,
            'blocked_b' => $blockedB,
            'released_a' => $releasedA,
            'acquired_b_after_release' => $acquiredBAfter,
            'acquired_after_release' => $acquiredAfter,
            'controller_pinned' => $controllerPinned,
            'controller_mode' => $controllerMode,
            'controller_size' => is_array($opened) ? $opened['size'] : -1,
            'child_destroy_preserved' => $destroyPreserved,
            'counter_restored' => $counterRestored,
            'status_a' => $statusA,
            'status_b' => $statusB,
         ]);
      }, GET);
   },

   test: static function (string $response): bool|string {
      if (! str_contains($response, 'HTTP/1.1 200 OK')) {
         return 'M12 worker-lock regression handler did not return HTTP 200: ' . substr($response, 0, 200);
      }

      $separator = strpos($response, "\r\n\r\n");
      $decoded = $separator === false
         ? null
         : json_decode(substr($response, $separator + 4), true);
      if (! is_array($decoded)) {
         Vars::$labels = ['M12 worker-lock response'];
         dump($response);
         return 'M12 worker-lock regression did not return JSON evidence.';
      }
      if (($decoded['skip'] ?? false) === true) {
         return true;
      }

      if (
         ($decoded['ready_a'] ?? false) === true
         && ($decoded['ready_b'] ?? false) === true
         && ($decoded['acquired_a'] ?? false) === true
         && ($decoded['blocked_b'] ?? false) === true
         && ($decoded['released_a'] ?? false) === true
         && ($decoded['acquired_b_after_release'] ?? false) === true
         && ($decoded['acquired_after_release'] ?? false) === true
         && ($decoded['controller_pinned'] ?? false) === true
         && ($decoded['controller_mode'] ?? -1) === 0600
         && ($decoded['controller_size'] ?? -1) === 40
         && ($decoded['child_destroy_preserved'] ?? false) === true
         && ($decoded['counter_restored'] ?? false) === true
         && ($decoded['status_a'] ?? -1) === 0
         && ($decoded['status_b'] ?? -1) === 0
      ) {
         return true;
      }

      Vars::$labels = ['M12 cross-worker flock isolation'];
      dump(json_encode($decoded));
      return 'Forked workers did not serialize the aggregate-download lock independently.';
   },
);
