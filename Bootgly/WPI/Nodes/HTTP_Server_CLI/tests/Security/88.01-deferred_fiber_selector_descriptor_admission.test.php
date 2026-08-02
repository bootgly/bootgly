<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC H3 (2026-08-01 selector readiness) — deferred Fiber I/O
 * admission must apply the same exact-descriptor guard as Select::add().
 *
 * The controller dynamically discovers one live UNIX socket that the local
 * select backend rejects in both read and write mode. Guard controls prove
 * Select::add() rejects that exact resource, and independent low-descriptor
 * Fibers prove real readiness dispatch remains live under the same process-wide
 * descriptor pressure. The attack then submits the rejected resource through
 * the production Select::schedule(Readiness) path for read and write.
 */
$probe = [
   'fixture_error' => '',
   'child' => [],
   'source' => [],
   'discovery' => [],
   'guard' => [],
   'control' => [],
   'rejection' => [],
   'attack' => [],
];

$Probe = static function () use (&$probe): void {
      /** @var array<int,resource> $fillers */
      $fillers = [];
      /** @var array<int,array{0:resource,1:resource}> $lowPairs */
      $lowPairs = [];
      /** @var null|array{0:resource,1:resource} $highPair */
      $highPair = null;

      try {
         // # Four low-descriptor controls are allocated before pressure:
         // guarded read/write, positive read/write, and poisoned-loop liveness.
         for ($index = 0; $index < 4; $index++) {
            $pair = stream_socket_pair(
               STREAM_PF_UNIX,
               STREAM_SOCK_STREAM,
               STREAM_IPPROTO_IP,
            );
            if ($pair === false) {
               throw new RuntimeException('Failed to create a low-descriptor control pair.');
            }
            $lowPairs[] = $pair;
         }

         $warnings = [];
         $currentWarnings = [];
         set_error_handler(
            static function (
               int $severity,
               string $message,
               string $file,
            ) use (&$currentWarnings): bool {
               $captured = str_contains($message, 'stream_select')
                  && str_contains($message, 'FD_SETSIZE');
               if ($captured) {
                  $currentWarnings[] = [
                     'severity' => $severity,
                     'message' => $message,
                     'file' => $file,
                  ];
               }

               return $captured;
            },
         );
         try {
            // @ Resource IDs are not OS descriptors. Discover the boundary by
            // asking the real backend about each newly allocated socket.
            for ($attempt = 0; $attempt < 2048; $attempt++) {
               $filler = fopen('/dev/null', 'rb');
               if ($filler === false) {
                  break;
               }
               $fillers[] = $filler;

               $pair = stream_socket_pair(
                  STREAM_PF_UNIX,
                  STREAM_SOCK_STREAM,
                  STREAM_IPPROTO_IP,
               );
               if ($pair === false) {
                  break;
               }

               $currentWarnings = [];
               $reads = [$pair[0]];
               $writes = [];
               $excepts = [];
               $readResult = stream_select($reads, $writes, $excepts, 0, 0);

               $reads = [];
               $writes = [$pair[1]];
               $excepts = [];
               $writeResult = stream_select($reads, $writes, $excepts, 0, 0);

               if ($readResult === false && $writeResult === false) {
                  $highPair = $pair;
                  $warnings = $currentWarnings;
                  $probe['discovery'] = [
                     'attempts' => $attempt + 1,
                     'fillers' => count($fillers),
                     'read_result' => $readResult,
                     'write_result' => $writeResult,
                     'warnings' => $warnings,
                  ];
                  break;
               }

               $fillers[] = $pair[0];
               $fillers[] = $pair[1];
            }
         }
         finally {
            restore_error_handler();
         }

         if ($highPair === null) {
            throw new RuntimeException(
               'The local stream_select backend did not expose an unrepresentable descriptor.',
            );
         }

         $Connections = new class implements Connections {
            public function connect (): bool
            {
               return false;
            }
         };

         // # Guard control: the public persistent-event path must accept each
         // low socket and reject the exact high socket in both directions.
         $ReadGuard = new Select($Connections);
         try {
            $readLow = $ReadGuard->add(
               $lowPairs[0][0],
               Select::EVENT_READ,
               null,
            );
            $readHigh = $ReadGuard->add(
               $highPair[0],
               Select::EVENT_READ,
               null,
            );
         }
         finally {
            $ReadGuard->destroy();
         }

         $WriteGuard = new Select($Connections);
         try {
            $writeLow = $WriteGuard->add(
               $lowPairs[1][0],
               Select::EVENT_WRITE,
               null,
            );
            $writeHigh = $WriteGuard->add(
               $highPair[1],
               Select::EVENT_WRITE,
               null,
            );
         }
         finally {
            $WriteGuard->destroy();
         }
         $probe['guard'] = [
            'read_low' => $readLow,
            'read_high' => $readHigh,
            'write_low' => $writeLow,
            'write_high' => $writeHigh,
         ];

         /**
          * Run one low-descriptor readiness control under the retained pressure.
          *
          * @param array{0:resource,1:resource} $pair
          * @return array<string,mixed>
          */
         $Control = static function (
            string $kind,
            array $pair,
            Connections $Connections,
         ): array {
            $Selector = new Select($Connections);
            $scheduled = false;
            $progress = false;
            $triggered = $kind === 'write';
            $watchdog = false;
            $wakeNS = 0;
            $error = '';

            $Fiber = new Fiber(
               static function () use (
                  $kind,
                  $pair,
                  $Selector,
                  &$progress,
                  &$wakeNS,
               ): void {
                  $Readiness = $kind === 'read'
                     ? Readiness::read($pair[0])
                     : Readiness::write($pair[0]);
                  Fiber::suspend($Readiness);

                  $wakeNS = $Selector->wakeNS;
                  $progress = $kind === 'read'
                     ? fread($pair[0], 1) === 'C'
                     : fwrite($pair[0], 'C') === 1;
                  $Selector->loop = false;
               },
            );

            try {
               /** @var Readiness $Wait */
               $Wait = $Fiber->start();
               $scheduled = $Selector->schedule($Fiber, $Wait);

               if ($kind === 'read') {
                  $Selector->defer(
                     microtime(true) + 0.01,
                     static function () use ($pair, &$triggered): void {
                        $triggered = fwrite($pair[1], 'C') === 1;
                     },
                  );
               }
               $Selector->defer(
                  microtime(true) + 0.5,
                  static function () use ($Selector, &$watchdog): void {
                     $watchdog = true;
                     $Selector->loop = false;
                  },
               );
               $Selector->loop();
            }
            catch (Throwable $Throwable) {
               $error = $Throwable::class . ': ' . $Throwable->getMessage();
            }
            finally {
               $Selector->destroy();
            }

            return [
               'scheduled' => $scheduled,
               'triggered' => $triggered,
               'progress' => $progress,
               'wake_ns' => $wakeNS,
               'watchdog' => $watchdog,
               'error' => $error,
            ];
         };

         $probe['control'] = [
            'read' => $Control('read', $lowPairs[0], $Connections),
            'write' => $Control('write', $lowPairs[1], $Connections),
         ];

         /**
          * Exercise bounded rejection and the scheduler's retention contract.
          *
          * @param resource $highSocket
          * @return array<string,mixed>
          */
         $Reject = static function (
            string $mode,
            mixed $highSocket,
            Connections $Connections,
         ): array {
            $Selector = new Select($Connections);
            $scheduled = false;
            $catches = [];
            $suspensions = 0;
            $entered = 0;
            $left = 0;
            $error = '';

            $Fiber = new Fiber(
               static function () use (
                  $mode,
                  $highSocket,
                  &$catches,
                  &$suspensions,
               ): void {
                  if ($mode === 'tick') {
                     $suspensions++;
                     try {
                        Fiber::suspend(Readiness::read($highSocket));
                     }
                     catch (RuntimeException $Error) {
                        $catches[] = $Error->getMessage();
                        Fiber::suspend();
                     }

                     return;
                  }

                  while (count($catches) < 3) {
                     $suspensions++;
                     try {
                        Fiber::suspend(Readiness::read($highSocket));
                     }
                     catch (RuntimeException $Error) {
                        $catches[] = $Error->getMessage();
                     }
                  }
               },
            );

            try {
               /** @var Readiness $Wait */
               $Wait = $Fiber->start();
               $Selector->bind(
                  $Fiber,
                  static function () use (&$entered): void {
                     $entered++;
                  },
                  static function () use (&$left): void {
                     $left++;
                  },
               );
               $scheduled = $Selector->schedule($Fiber, $Wait);
            }
            catch (Throwable $Throwable) {
               $error = $Throwable::class . ': '
                  . $Throwable->getMessage();
            }
            finally {
               $Reflection = new ReflectionClass($Selector);
               $highID = (int) $highSocket;
               $FiberID = spl_object_id($Fiber);
               $highRetained = false;
               foreach ([
                  'reads',
                  'writes',
                  'awaitingReads',
                  'awaitingWrites',
                  'awaitingReadDeadlines',
                  'awaitingWriteDeadlines',
               ] as $property) {
                  $Property = $Reflection->getProperty($property);
                  $Values = $Property->getValue($Selector);
                  if (is_array($Values) && isset($Values[$highID])) {
                     $highRetained = true;
                  }
               }
               $Property = $Reflection->getProperty('Fibers');
               $Fibers = $Property->getValue($Selector);
               $tickRetained = is_array($Fibers)
                  && in_array($Fiber, $Fibers, true);
               $Property = $Reflection->getProperty('Bindings');
               $Bindings = $Property->getValue($Selector);
               $bindingRetained = is_array($Bindings)
                  && isset($Bindings[$FiberID]);

               $Selector->destroy();
            }

            return [
               'scheduled' => $scheduled,
               'catches' => $catches,
               'suspensions' => $suspensions,
               'suspended' => $Fiber->isSuspended(),
               'terminated' => $Fiber->isTerminated(),
               'entered' => $entered,
               'left' => $left,
               'high_retained' => $highRetained,
               'tick_retained' => $tickRetained,
               'binding_retained' => $bindingRetained,
               'error' => $error,
            ];
         };

         $probe['rejection'] = [
            'tick' => $Reject('tick', $highPair[0], $Connections),
            'repeat' => $Reject('repeat', $highPair[0], $Connections),
         ];

         /**
          * Admit a high resource beside a ready low Fiber and run the selector.
          *
          * @param array{0:resource,1:resource} $lowPair
          * @param resource $highSocket
          * @return array<string,mixed>
          */
         $Attack = static function (
            string $kind,
            array $lowPair,
            mixed $highSocket,
            Connections $Connections,
         ): array {
            $Selector = new Select($Connections);
            $lowScheduled = false;
            $highScheduled = false;
            $lowReady = false;
            $lowProgress = false;
            $highResumed = false;
            $highRejection = '';
            $watchdog = false;
            $lowWakeNS = 0;
            $admissionError = '';
            $selectorError = '';
            $warnings = [];

            $LowFiber = new Fiber(
               static function () use (
                  $kind,
                  $lowPair,
                  $Selector,
                  &$lowProgress,
                  &$lowWakeNS,
               ): void {
                  $Readiness = $kind === 'read'
                     ? Readiness::read($lowPair[0])
                     : Readiness::write($lowPair[0]);
                  Fiber::suspend($Readiness);

                  $lowWakeNS = $Selector->wakeNS;
                  $lowProgress = $kind === 'read'
                     ? fread($lowPair[0], 1) === 'L'
                     : fwrite($lowPair[0], 'L') === 1;
                  $Selector->loop = false;
               },
            );
            $HighFiber = new Fiber(
               static function () use (
                  $kind,
                  $highSocket,
                  $Selector,
                  &$highResumed,
                  &$highRejection,
               ): void {
                  $Readiness = $kind === 'read'
                     ? Readiness::read($highSocket)
                     : Readiness::write($highSocket);
                  try {
                     Fiber::suspend($Readiness);
                  }
                  catch (RuntimeException $Error) {
                     $highRejection = $Error->getMessage();

                     return;
                  }

                  $highResumed = true;
                  $Selector->loop = false;
               },
            );

            try {
               /** @var Readiness $LowWait */
               $LowWait = $LowFiber->start();
               $lowScheduled = $Selector->schedule($LowFiber, $LowWait);

               /** @var Readiness $HighWait */
               $HighWait = $HighFiber->start();
               try {
                  $highScheduled = $Selector->schedule($HighFiber, $HighWait);
               }
               catch (Throwable $Throwable) {
                  $admissionError = $Throwable::class . ': '
                     . $Throwable->getMessage();
               }

               $reads = [];
               $writes = [];
               $excepts = [];
               if ($kind === 'read') {
                  $triggered = fwrite($lowPair[1], 'L') === 1;
                  $reads[] = $lowPair[0];
                  $lowReady = $triggered
                     && stream_select($reads, $writes, $excepts, 0, 0) === 1;
               }
               else {
                  $writes[] = $lowPair[0];
                  $lowReady = stream_select(
                     $reads,
                     $writes,
                     $excepts,
                     0,
                     0,
                  ) === 1;
               }
               $Selector->defer(
                  microtime(true) + 0.5,
                  static function () use ($Selector, &$watchdog): void {
                     $watchdog = true;
                     $Selector->loop = false;
                  },
               );

               set_error_handler(
                  static function (
                     int $severity,
                     string $message,
                     string $file,
                  ) use (&$warnings): bool {
                     $captured = str_contains($message, 'stream_select')
                        && str_contains($message, 'FD_SETSIZE');
                     if ($captured) {
                        $warnings[] = [
                           'severity' => $severity,
                           'message' => $message,
                           'file' => $file,
                        ];
                     }

                     return $captured;
                  },
               );
               try {
                  $Selector->loop();
               }
               catch (Throwable $Throwable) {
                  $selectorError = $Throwable::class . ': '
                     . $Throwable->getMessage();
               }
               finally {
                  restore_error_handler();
               }
            }
            catch (Throwable $Throwable) {
               $selectorError = $Throwable::class . ': '
                  . $Throwable->getMessage();
            }
            finally {
               $Reflection = new ReflectionClass($Selector);
               $highID = (int) $highSocket;
               $FiberID = spl_object_id($HighFiber);
               $retained = [];
               foreach ([
                  'reads',
                  'writes',
                  'awaitingReads',
                  'awaitingWrites',
                  'awaitingReadDeadlines',
                  'awaitingWriteDeadlines',
               ] as $property) {
                  $Property = $Reflection->getProperty($property);
                  $Values = $Property->getValue($Selector);
                  $retained[$property] = is_array($Values)
                     && isset($Values[$highID]);
               }
               $Property = $Reflection->getProperty('Fibers');
               $Fibers = $Property->getValue($Selector);
               $retained['tick'] = is_array($Fibers)
                  && in_array($HighFiber, $Fibers, true);
               $Property = $Reflection->getProperty('Bindings');
               $Bindings = $Property->getValue($Selector);
               $retained['binding'] = is_array($Bindings)
                  && isset($Bindings[$FiberID]);

               $Selector->destroy();
            }

            return [
               'low_scheduled' => $lowScheduled,
               'high_scheduled' => $highScheduled,
               'low_ready' => $lowReady,
               'low_progress' => $lowProgress,
               'low_wake_ns' => $lowWakeNS,
               'high_resumed' => $highResumed,
               'high_rejection' => $highRejection,
               'high_terminated' => $HighFiber->isTerminated(),
               'watchdog' => $watchdog,
               'admission_error' => $admissionError,
               'selector_error' => $selectorError,
               'warnings' => $warnings,
               'retained' => $retained,
            ];
         };

         $probe['attack'] = [
            'read' => $Attack(
               'read',
               $lowPairs[2],
               $highPair[0],
               $Connections,
            ),
            'write' => $Attack(
               'write',
               $lowPairs[3],
               $highPair[1],
               $Connections,
            ),
         ];
      }
      catch (Throwable $Throwable) {
         $probe['fixture_error'] = $Throwable::class . ': '
            . $Throwable->getMessage();
      }
      finally {
         if (is_array($highPair)) {
            foreach ($highPair as $socket) {
               if (is_resource($socket)) {
                  fclose($socket);
               }
            }
         }
         foreach ($lowPairs as $pair) {
            foreach ($pair as $socket) {
               if (is_resource($socket)) {
                  fclose($socket);
               }
            }
         }
         foreach ($fillers as $filler) {
            if (is_resource($filler)) {
               fclose($filler);
            }
         }
      }

};

// ! The native HTTP client invokes request factories from a connection
// constructor callback. PHP forbids Fiber switching in that execution context,
// so the exact selector probe runs in a clean PHP child process. The child uses
// this worktree's source files through a narrow PSR-style autoloader.
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--h3-selector-probe'
) {
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   if ($root === '' || is_dir($root) === false) {
      fwrite(STDERR, "H3 probe received an invalid repository root.\n");
      exit(2);
   }

   defined('BOOTGLY_ROOT_BASE') || define('BOOTGLY_ROOT_BASE', $root);
   defined('BOOTGLY_ROOT_DIR') || define('BOOTGLY_ROOT_DIR', $root . '/');
   defined('BOOTGLY_WORKING_BASE') || define('BOOTGLY_WORKING_BASE', $root);
   defined('BOOTGLY_WORKING_DIR') || define('BOOTGLY_WORKING_DIR', $root . '/');
   spl_autoload_register(
      static function (string $class) use ($root): void {
         $file = $root . '/' . str_replace('\\', '/', $class) . '.php';
         if (is_file($file)) {
            include $file;
         }
      },
   );

   $SelectReflection = new ReflectionClass(Select::class);
   $selectFile = $SelectReflection->getFileName();
   $probe['source'] = [
      'php_version' => PHP_VERSION,
      'select_file' => is_string($selectFile) ? $selectFile : '',
      'select_sha256' => is_string($selectFile)
         ? hash_file('sha256', $selectFile)
         : false,
   ];
   $Probe();
   $encoded = json_encode($probe, JSON_UNESCAPED_SLASHES);
   if (is_string($encoded) === false) {
      fwrite(STDERR, "H3 probe could not encode its evidence.\n");
      exit(3);
   }
   fwrite(STDOUT, $encoded);
   exit(0);
}

return new Specification(
   description: 'Deferred Fiber readiness must reject select-unrepresentable descriptors',
   Separator: new Separator(line: true),

   request: static function () use (&$probe): string {
      $process = null;
      $pipes = [];
      /** @return array<string,mixed> */
      $Terminate = static function (mixed $process): array {
         $status = proc_get_status($process);
         if (is_array($status) === false) {
            return ['running' => false, 'exitcode' => -1];
         }
         if (($status['running'] ?? false) === false) {
            return $status;
         }

         proc_terminate($process);
         for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(10000);
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
               return $status;
            }
         }

         proc_terminate($process, 9);
         for ($attempt = 0; $attempt < 100; $attempt++) {
            usleep(10000);
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
               return $status;
            }
         }

         return $status;
      };

      try {
         if (function_exists('proc_open') === false) {
            throw new RuntimeException('H3 requires proc_open for its Fiber-safe attack leg.');
         }

         $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];
         $process = proc_open(
            [
               PHP_BINARY,
               '-d',
               'opcache.enable_cli=0',
               __FILE__,
               '--h3-selector-probe',
               BOOTGLY_ROOT_DIR,
            ],
            $descriptors,
            $pipes,
            BOOTGLY_ROOT_DIR,
         );
         if (is_resource($process) === false) {
            throw new RuntimeException('H3 could not start its selector attack leg.');
         }

         stream_set_blocking($pipes[1], false);
         stream_set_blocking($pipes[2], false);
         $output = '';
         $error = '';
         $timedOut = false;
         $status = [];
         $deadline = microtime(true) + 10.0;

         do {
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) {
               $output .= $chunk;
            }
            $chunk = stream_get_contents($pipes[2]);
            if ($chunk !== false) {
               $error .= $chunk;
            }

            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
               break;
            }
            usleep(10000);
         }
         while (microtime(true) < $deadline);

         if (($status['running'] ?? false) === true) {
            $timedOut = true;
            $status = $Terminate($process);
         }

         foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if ($chunk !== false) {
               if ($index === 1) {
                  $output .= $chunk;
               }
               else {
                  $error .= $chunk;
               }
            }
            fclose($pipes[$index]);
            unset($pipes[$index]);
         }

         $statusCode = (int) ($status['exitcode'] ?? -1);
         $closedCode = ($status['running'] ?? false) === false
            ? proc_close($process)
            : -1;
         if (($status['running'] ?? false) === false) {
            $process = null;
         }
         $exitCode = $statusCode >= 0 ? $statusCode : $closedCode;

         if ($timedOut) {
            throw new RuntimeException('H3 selector attack leg exceeded 10 seconds.');
         }
         $decoded = json_decode($output, true);
         if (is_array($decoded) === false) {
            throw new RuntimeException(
               'H3 selector attack leg returned unreadable evidence: '
               . trim($error !== '' ? $error : $output),
            );
         }
         $probe = $decoded;
         $probe['child'] = [
            'exit_code' => $exitCode,
            'stderr' => trim($error),
            'timed_out' => false,
         ];
         if ($exitCode !== 0 || $probe['child']['stderr'] !== '') {
            $probe['fixture_error'] = 'Selector attack leg exit/stderr control failed.';
         }
      }
      catch (Throwable $Throwable) {
         $probe['fixture_error'] = $Throwable::class . ': '
            . $Throwable->getMessage();
      }
      finally {
         foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
               fclose($pipe);
            }
         }
         if (is_resource($process)) {
            $status = $Terminate($process);
            if (($status['running'] ?? false) === false) {
               proc_close($process);
            }
         }
      }

      return "GET /h3/select-readiness-admission HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) {
      yield $Router->route(
         '/h3/select-readiness-admission',
         static fn (Request $Request, Response $Response): Response =>
            $Response(code: 200, body: 'H3-CONTROL'),
         GET,
      );
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'H3-CONTROL') === false
      ) {
         Vars::$labels = ['H3 native HTTP harness control'];
         dump(json_encode($response));

         return 'H3 fixture failed: the independent HTTP control did not complete: '
            . json_encode($response, JSON_UNESCAPED_SLASHES);
      }
      if ($probe['fixture_error'] !== '') {
         Vars::$labels = ['H3 fixture error', 'H3 evidence'];
         dump($probe['fixture_error'], json_encode($probe));

         return 'H3 fixture failed before selector admission: '
            . $probe['fixture_error'];
      }

      $expectedSelect = realpath(
         BOOTGLY_ROOT_DIR . 'Bootgly/WPI/Events/Select.php',
      );
      $source = $probe['source'] ?? [];
      $loadedSelect = realpath((string) ($source['select_file'] ?? ''));
      $expectedSHA256 = is_string($expectedSelect)
         ? hash_file('sha256', $expectedSelect)
         : false;
      if (
         is_string($expectedSelect) === false
         || $loadedSelect !== $expectedSelect
         || ($source['select_sha256'] ?? false) !== $expectedSHA256
      ) {
         Vars::$labels = ['H3 exact-worktree source control', 'H3 evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H3 fixture failed: the attack leg did not load the exact '
            . 'Select.php bytes from this isolated worktree.';
      }

      $Warned = static function (array $warnings): bool {
         foreach ($warnings as $warning) {
            if (
               str_contains((string) ($warning['message'] ?? ''), 'stream_select')
               && str_contains((string) ($warning['message'] ?? ''), 'FD_SETSIZE')
            ) {
               return true;
            }
         }

         return false;
      };
      $WarnedFromSelect = static function (array $warnings) use ($Warned): bool {
         if ($Warned($warnings) === false) {
            return false;
         }
         foreach ($warnings as $warning) {
            if (
               str_contains((string) ($warning['message'] ?? ''), 'FD_SETSIZE')
               && str_ends_with(
                  (string) ($warning['file'] ?? ''),
                  '/Bootgly/WPI/Events/Select.php',
               )
            ) {
               return true;
            }
         }

         return false;
      };

      $discovery = $probe['discovery'];
      if (
         ($discovery['read_result'] ?? null) !== false
         || ($discovery['write_result'] ?? null) !== false
         || $Warned($discovery['warnings'] ?? []) === false
      ) {
         Vars::$labels = ['H3 descriptor-boundary precondition', 'H3 evidence'];
         dump(json_encode($probe));

         return 'H3 fixture failed: no live socket was proven unrepresentable '
            . 'for both read and write by this stream_select backend.';
      }

      $guard = $probe['guard'];
      if (
         ($guard['read_low'] ?? false) !== true
         || ($guard['read_high'] ?? true) !== false
         || ($guard['write_low'] ?? false) !== true
         || ($guard['write_high'] ?? true) !== false
      ) {
         Vars::$labels = ['H3 guarded add control', 'H3 evidence'];
         dump(json_encode($probe));

         return 'H3 control failed: Select::add() did not accept the low '
            . 'resources and reject the same high resource in both directions.';
      }

      foreach (['read', 'write'] as $kind) {
         $control = $probe['control'][$kind] ?? [];
         if (
            ($control['scheduled'] ?? false) !== true
            || ($control['triggered'] ?? false) !== true
            || ($control['progress'] ?? false) !== true
            || ($control['wake_ns'] ?? 0) <= 0
            || ($control['watchdog'] ?? true) !== false
            || ($control['error'] ?? '') !== ''
         ) {
            Vars::$labels = [
               "H3 low-descriptor {$kind} readiness control",
               'H3 evidence',
            ];
            dump(json_encode($probe));

            return "H3 control failed: low-descriptor {$kind} Readiness did "
               . 'not progress through a real Select wakeup under pressure: '
               . json_encode($control, JSON_UNESCAPED_SLASHES);
         }
      }

      $Poisoned = static function (
         array $leg,
         Closure $WarnedFromSelect,
      ): bool {
         return ($leg['low_scheduled'] ?? false) === true
            && ($leg['high_scheduled'] ?? false) === true
            && ($leg['low_ready'] ?? false) === true
            && ($leg['low_progress'] ?? true) === false
            && ($leg['low_wake_ns'] ?? -1) === 0
            && ($leg['high_resumed'] ?? true) === false
            && ($leg['watchdog'] ?? true) === false
            && ($leg['admission_error'] ?? '') === ''
            && ($leg['selector_error'] ?? '') ===
               'RuntimeException: stream_select failed on the admitted event set.'
            && $WarnedFromSelect($leg['warnings'] ?? []);
      };

      $readAttack = $probe['attack']['read'] ?? [];
      $writeAttack = $probe['attack']['write'] ?? [];
      if (
         $Poisoned($readAttack, $WarnedFromSelect)
         && $Poisoned($writeAttack, $WarnedFromSelect)
      ) {
         Vars::$labels = ['H3 confirmed selector-admission evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'CONFIRMED H3 (2026-08-01 selector readiness): '
            . 'Select::schedule() admitted the same FD_SETSIZE-unrepresentable '
            . 'socket that guarded add() rejected for Readiness::read() and '
            . 'Readiness::write(); both real Select::loop() calls emitted the '
            . 'Select.php FD_SETSIZE warning, threw "stream_select failed on '
            . 'the admitted event set.", and blocked a ready low-FD Fiber.';
      }

      $rejectionMessage = 'Fiber I/O resource failed selector admission.';
      $tickRejection = $probe['rejection']['tick'] ?? [];
      $repeatRejection = $probe['rejection']['repeat'] ?? [];
      if (
         $tickRejection !== [
            'scheduled' => true,
            'catches' => [$rejectionMessage],
            'suspensions' => 1,
            'suspended' => true,
            'terminated' => false,
            'entered' => 1,
            'left' => 1,
            'high_retained' => false,
            'tick_retained' => true,
            'binding_retained' => true,
            'error' => '',
         ]
         || $repeatRejection !== [
            'scheduled' => false,
            'catches' => [$rejectionMessage],
            'suspensions' => 2,
            'suspended' => true,
            'terminated' => false,
            'entered' => 1,
            'left' => 1,
            'high_retained' => false,
            'tick_retained' => false,
            'binding_retained' => false,
            'error' => '',
         ]
      ) {
         Vars::$labels = ['H3 bounded rejection contract', 'H3 evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H3 rejection was not bounded or returned a scheduler '
            . 'retention state inconsistent with the retained Fiber.';
      }

      $Safe = static function (array $leg): bool {
         return ($leg['low_scheduled'] ?? false) === true
            && ($leg['high_scheduled'] ?? true) === false
            && ($leg['low_ready'] ?? false) === true
            && ($leg['low_progress'] ?? false) === true
            && ($leg['low_wake_ns'] ?? 0) > 0
            && ($leg['high_resumed'] ?? true) === false
            && ($leg['high_rejection'] ?? '') ===
               'Fiber I/O resource failed selector admission.'
            && ($leg['high_terminated'] ?? false) === true
            && ($leg['watchdog'] ?? true) === false
            && ($leg['admission_error'] ?? '') === ''
            && ($leg['selector_error'] ?? '') === ''
            && ($leg['warnings'] ?? []) === []
            && ($leg['retained'] ?? null) === [
               'reads' => false,
               'writes' => false,
               'awaitingReads' => false,
               'awaitingWrites' => false,
               'awaitingReadDeadlines' => false,
               'awaitingWriteDeadlines' => false,
               'tick' => false,
               'binding' => false,
            ];
      };
      if ($Safe($readAttack) && $Safe($writeAttack)) {
         return true;
      }

      Vars::$labels = ['H3 unexpected selector state', 'H3 evidence'];
      dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

      return 'H3 probe reached an unexpected partial state; safe deferred '
         . 'descriptor admission was not established.';
   },
);
