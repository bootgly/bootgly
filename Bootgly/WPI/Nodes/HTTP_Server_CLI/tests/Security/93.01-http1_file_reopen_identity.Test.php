<?php

use const Bootgly\WPI;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


if (! class_exists('M1FileIdentityStream', false)) {
   /**
    * Deterministic socket stand-in with two ways to produce the zero write
    * that `Packages::transmit()` treats as a short write and parks in
    * `pendingBuffer`: `$stalled` holds every write, and `$zeroAt` holds
    * exactly one numbered write. The first parks a response head with zero
    * reopens performed; the second parks a file part between two pump quanta.
    */
   class M1FileIdentityStream
   {
      public static int $calls = 0;
      public static bool $stalled = false;
      public static int $zeroAt = 0;
      public static string $written = '';

      public mixed $context;

      public static function reset (): void
      {
         self::$calls = 0;
         self::$stalled = false;
         self::$zeroAt = 0;
         self::$written = '';
      }

      public function stream_open (
         string $path,
         string $mode,
         int $options,
         null|string &$opened_path
      ): bool {
         return true;
      }

      public function stream_write (string $data): int
      {
         self::$calls++;

         if (self::$stalled || self::$zeroAt === self::$calls) {
            return 0;
         }

         self::$written .= $data;

         return strlen($data);
      }

      public function stream_eof (): bool
      {
         return false;
      }

      /** @return array<string,mixed> */
      public function stream_stat (): array
      {
         return [];
      }
   }
}

if (! class_exists('M1FileIdentityConnection', false)) {
   class M1FileIdentityConnection extends Connection
   {
      public bool $closed = false;

      /** @param resource $Socket */
      public function __construct (mixed &$Socket)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 15;
         $this->ip = '127.0.0.1';
         $this->port = 12345;
         $this->encrypted = false;
         $this->handshaking = false;
         $this->handshakeTimer = 0;
         $this->status = Connections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 0;
      }

      public function close (): true
      {
         $this->closed = true;
         $this->status = Connections::STATUS_CLOSED;

         if (is_resource($this->Socket)) {
            @fclose($this->Socket);
         }

         return true;
      }
   }
}

/**
 * M1 PoC — an HTTP/1 file response must validate EVERY reopen, the first one
 * included, against the identity captured before its head was built.
 *
 * `Response::upload()` stats the target once and queues a six-field identity
 * (device, inode, mode, size, modified, changed). The transport deliberately
 * keeps no descriptor — 82.02 asserts zero parked target descriptors — so it
 * reopens the pathname per quantum. `Packages::uploading()` compares that
 * reopen against a baseline it seeds from its OWN first open, so the first
 * reopen adopts whatever the pathname now names. The queued identity is never
 * read.
 *
 * The window is not sub-millisecond: a short-written response head parks the
 * queue entry with zero fopens performed, and a pipelined file response waits
 * in `pendingResponses` until the previous body fully drains.
 *
 * Legs 0 and F are controls that must hold on BOTH trees. A, A', B, C and E
 * must fail on the vulnerable tree with their own diagnostic. D is the
 * later-reopen case the self-baseline already covered and must keep passing
 * once that baseline is deleted.
 *
 * Mutation matrix, measured against the patched sources:
 *
 *   drop the six-field comparison ................. B
 *   narrow it to device+inode ..................... B
 *   narrow it to size ............................. B
 *   restore the self-seeded baseline .............. A, A', B, C, E
 *   queue the symbolic path instead of the resolved one ... F
 *   make the identity shape guard tolerant ........ G3
 *   drop the part-vs-size range check ............. G2
 *   rename `identity` in `Response::upload()` ..... IDENTITY-SHAPE-DRIFT
 *
 * Not covered here, deliberately:
 * - No FIFO leg, and consequently the pre-open `lstat` gate is the one guard
 *   no leg here kills on its own: every case it refuses, the post-open `fstat`
 *   comparison also refuses. Its unique value is the case that cannot be
 *   tested — `@fopen($fifo, 'rb')` BLOCKS a single-threaded worker before any
 *   stat can object, so a leg proving it would hang the suite on the very tree
 *   it is meant to indict. What the matrix does prove is that the gate is
 *   load-bearing in combination: dropping the resolved path alone breaks leg F
 *   (the gate then refuses a legitimate symlinked asset), while dropping both
 *   together restores the old behaviour. They are a pair.
 * - No "which guard refused it" assertion. `abort()` is protected and produces
 *   one indistinguishable outcome; such a field could only restate the PoC's
 *   own guess.
 * - The invariant is coarse-grained identity, not content integrity: PHP's
 *   `fstat` reports mtime/ctime in whole seconds, so a same-size in-place
 *   rewrite inside the same second as the last metadata change is undetectable.
 *   Leg B therefore forces an observable timestamp change before resuming.
 */
$probe = [
   'error' => '',
   'harness' => '',
   'legs' => [],
];

return new Test(
   description: 'HTTP/1 file reopens must match the identity captured before the response head',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $scheme = 'bootgly-m1-file-identity';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, M1FileIdentityStream::class);
      }

      $token = bin2hex(random_bytes(8));
      $statics = BOOTGLY_PROJECT->path . 'statics/';
      $outside = sys_get_temp_dir() . '/bootgly-m1-outside-' . $token . '.bin';
      $artifacts = [$outside];

      $OldEvent = TCPServer::$Event;
      $WPI = WPI;
      $OldRequest = $WPI->Request;

      // ! The deterministic wrapper is not a selectable OS socket, and the
      //   real Select::add() probes the resource with stream_select(). Without
      //   this double `defer()` fails and every leg aborts for the wrong
      //   reason — the exact vacuity vector the park assertion also guards.
      TCPServer::$Event = new class extends Select {
         public function __construct () {}

         public function add ($Socket, int $flag, mixed $payload): bool
         {
            return true;
         }
      };

      try {
         // ! Fixture factory. Everything lands inside the project jail, which
         //   `Response::upload()` requires, and everything is registered for
         //   the unconditional unlink below — statics/ is NOT gitignored.
         $Make = static function (
            string $slug,
            int $repeats = 512
         ) use ($statics, $token, &$artifacts): array {
            $name = "m1-identity-{$token}-{$slug}.bin";
            $target = $statics . $name;
            $content = str_repeat('ORIGINAL', $repeats);

            @unlink($target);
            $artifacts[] = $target;
            $written = @file_put_contents($target, $content, LOCK_EX);
            if ($written !== strlen($content)) {
               throw new RuntimeException("Could not create the {$slug} fixture.");
            }
            clearstatcache(true, $target);

            return ['relative' => "statics/{$name}", 'path' => $target, 'content' => $content];
         };

         $Boot = static function () use ($scheme): array {
            M1FileIdentityStream::reset();

            $Socket = fopen($scheme . '://probe', 'w+');
            if (! is_resource($Socket)) {
               throw new RuntimeException('Could not allocate the M1 transport fixture.');
            }

            $Connection = new M1FileIdentityConnection($Socket);
            $Package = new class($Connection) extends TCPPackages {
               public function __construct (Connection $Connection)
               {
                  $this->Connection = $Connection;

                  $this->cache = true;
                  $this->changed = true;
                  $this->input = '';
                  $this->output = '';
                  $this->callbacks = [&$this->input];
                  $this->expired = false;
                  $this->consumed = 0;
                  $this->rejected = false;

                  $this->downloading = [];
                  $this->uploading = [];
                  $this->closeAfterWrite = false;
               }
            };

            return [$Socket, $Connection, $Package];
         };

         // ! Build the head through the public response API and the real
         //   HTTP/1 encoder, so the transport queue is populated exactly as an
         //   application route would populate it.
         $Queue = static function (
            TCPPackages $Package,
            Request $Request,
            string $relative,
            null|int $length = null
         ): array {
            $Response = new Response;
            $Response->reset($Package, $Request);
            // ! close: false is load-bearing. With the default close: true a
            //   SUCCESSFUL upload also closes the connection, so `closed`
            //   could never discriminate an abort from a completed response.
            $Response->upload($relative, length: $length, close: false);

            $headerLength = null;
            $header = $Response->encode($Package, $headerLength);

            return [$header, $headerLength];
         };

         // ! Guard 1 — pre-flight. Any early return inside upload() (403 on an
         //   unreadable path, 500 on a bad stat, 204 on Purpose: prefetch)
         //   queues nothing, and then every "no replacement bytes reached the
         //   wire" predicate is trivially true. This is a HARNESS ERROR, never
         //   a pass.
         $Preflight = static function (TCPPackages $Package, string $leg): void {
            if (count($Package->uploading) !== 1) {
               throw new RuntimeException(
                  "HARNESS {$leg}: upload() queued " . count($Package->uploading)
                  . ' entries instead of 1 — it returned early and nothing was streamed.'
               );
            }

            $identity = $Package->uploading[0]['identity'] ?? null;
            $expected = ['device', 'inode', 'mode', 'size', 'modified', 'changed'];
            if (! is_array($identity) || array_keys($identity) !== $expected) {
               throw new RuntimeException(
                  'IDENTITY-SHAPE-DRIFT ' . $leg . ': the queued identity keys are '
                  . json_encode(is_array($identity) ? array_keys($identity) : $identity)
                  . ' instead of ' . json_encode($expected)
                  . ' — the comparison this PoC measures cannot be expressed.'
               );
            }
            foreach ($expected as $key) {
               if (! is_int($identity[$key])) {
                  throw new RuntimeException(
                     "IDENTITY-SHAPE-DRIFT {$leg}: identity[{$key}] is not an int."
                  );
               }
            }
         };

         // ! Guard 2 — the park assertion. Proves ZERO reopens have happened
         //   before the swap. Without it a mis-constructed park lets the swap
         //   land on the SECOND reopen, which the self-baseline already
         //   catches, and the leg reports SECURE against the vulnerable build.
         //   It also discriminates the unrelated aborts (the retained-byte
         //   ledger, a broken Event double) that otherwise produce evidence
         //   byte-identical to a successful identity check.
         $Park = static function (
            TCPPackages $Package,
            string $header,
            string $leg,
            int $queued = 1
         ): void {
            if (
               M1FileIdentityStream::$calls !== 1
               || M1FileIdentityStream::$written !== ''
               || strlen($Package->pendingBuffer) !== strlen($header)
               || count($Package->uploading) !== $queued
            ) {
               throw new RuntimeException(
                  "HARNESS {$leg}: the response head did not park with zero reopens ("
                  . 'calls=' . M1FileIdentityStream::$calls
                  . ' written=' . strlen(M1FileIdentityStream::$written)
                  . ' pending=' . strlen($Package->pendingBuffer)
                  . '/' . strlen($header)
                  . ' queued=' . count($Package->uploading)
                  . ') — an abort fired before any file was opened.'
               );
            }
         };

         // ! Guard 3 — the swap assertion. A failed rename() is otherwise
         //   indistinguishable from a successful defense.
         $Swap = static function (
            string $target,
            string $content,
            string $leg
         ) use (&$artifacts): string {
            $replacement = strrev($content);
            if ($replacement === $content || strlen($replacement) !== strlen($content)) {
               throw new RuntimeException(
                  "HARNESS {$leg}: the replacement is not a same-size, different-content file."
               );
            }

            $sibling = $target . '.replacement';
            $artifacts[] = $sibling;
            clearstatcache(true, $target);
            $before = @stat($target);
            if (@file_put_contents($sibling, $replacement, LOCK_EX) !== strlen($replacement)) {
               throw new RuntimeException("HARNESS {$leg}: could not stage the replacement.");
            }
            clearstatcache(true, $sibling);
            $candidate = @stat($sibling);
            if (@rename($sibling, $target) === false) {
               throw new RuntimeException("HARNESS {$leg}: could not atomically replace the fixture.");
            }
            clearstatcache(true, $target);
            $after = @stat($target);

            if (
               ! is_array($before) || ! is_array($after) || ! is_array($candidate)
               || $after['size'] !== $before['size']
               || $after['ino'] === $before['ino']
               || $candidate['ino'] !== $after['ino']
            ) {
               throw new RuntimeException(
                  "HARNESS {$leg}: the swap did not produce a same-size, different-inode file."
               );
            }

            return $replacement;
         };

         // ! Drain a parked generation. Bounded, and it stops the moment the
         //   transport closes the connection.
         $Drain = static function (
            TCPPackages $Package,
            M1FileIdentityConnection $Connection,
            mixed &$Socket
         ): int {
            M1FileIdentityStream::$stalled = false;

            $rounds = 0;
            while (
               ($Package->pendingBuffer !== '' || $Package->uploading !== [] || $Package->pendingResponses !== [])
               && $rounds < 32
               && ! $Connection->closed
            ) {
               $rounds++;
               $Package->writing($Socket, buffer: '');
            }

            return $rounds;
         };

         // ! Leg E, over a real socket. Kept as a closure so the inline legs
         //   above read as one sequence; it runs last, after they have already
         //   established the defect deterministically.
         $E = static function (
            string $hostPort,
            int $testIndex,
            string $token,
            callable $Make
         ) use (&$artifacts): array {
            $report = ['stage' => 'start', 'error' => '', 'responses' => []];

            $Open = static function (string $hostPort) {
               $Socket = @stream_socket_client(
                  "tcp://{$hostPort}",
                  $errorNumber,
                  $errorMessage,
                  timeout: 3,
               );
               if (! is_resource($Socket)) {
                  throw new RuntimeException(
                     "Could not open the M1 wire socket: {$errorNumber} {$errorMessage}"
                  );
               }
               stream_set_blocking($Socket, true);
               stream_set_timeout($Socket, 3);

               return $Socket;
            };

            $Write = static function ($Socket, string $wire): void {
               $offset = 0;
               $length = strlen($wire);
               while ($offset < $length) {
                  $written = @fwrite($Socket, substr($wire, $offset));
                  if ($written === false || $written === 0) {
                     throw new RuntimeException("Short M1 wire write at {$offset}/{$length}.");
                  }
                  $offset += $written;
               }
            };

            // ! `close: false` keeps the connection alive, so EOF never
            //   arrives — without a predicate every read burns its full
            //   timeout. `$Until` returns true once the caller has what it
            //   needs; a refusal (abort) still ends on EOF.
            $Read = static function (
               $Socket,
               float $timeout,
               null|callable $Until = null
            ): string {
               stream_set_blocking($Socket, false);

               $wire = '';
               $deadline = microtime(true) + $timeout;
               while (microtime(true) < $deadline) {
                  $chunk = @fread($Socket, 65535);
                  if ($chunk !== false && $chunk !== '') {
                     $wire .= $chunk;
                     if ($Until !== null && $Until($wire) === true) {
                        break;
                     }
                     continue;
                  }
                  if (@feof($Socket)) {
                     break;
                  }
                  usleep(5_000);
               }

               return $wire;
            };

            // ! Walk a pipelined HTTP/1 byte stream by Content-Length. An
            //   incomplete tail is reported rather than dropped — a truncated
            //   final response is exactly what a refusal looks like.
            $Parse = static function (string $wire): array {
               $Responses = [];
               $offset = 0;
               $length = strlen($wire);

               while ($offset < $length) {
                  $separator = strpos($wire, "\r\n\r\n", $offset);
                  if ($separator === false) {
                     break;
                  }

                  $head = substr($wire, $offset, $separator - $offset);
                  if (
                     preg_match('/\r\nContent-Length:\s*([0-9]+)\r\n/i', $head . "\r\n", $matches) !== 1
                  ) {
                     break;
                  }

                  $bytes = (int) $matches[1];
                  $start = $separator + 4;
                  $available = $length - $start;

                  $Responses[] = [
                     'advertised' => $bytes,
                     'body' => substr($wire, $start, min($bytes, max(0, $available))),
                     'complete' => $available >= $bytes,
                  ];

                  if ($available < $bytes) {
                     break;
                  }

                  $offset = $start + $bytes;
               }

               return $Responses;
            };

            $Socket = null;
            try {
               // ! 4 MiB is comfortably past any default socket send buffer,
               //   so the first response blocks the writer with the target
               //   request already decoded and queued behind it.
               $Big = $Make('wire-big', 4 * 1024 * 1024 / 8);
               $Target = $Make('wire-target', 512);
               // ! The replacement is LONGER than the original on purpose.
               //   `pump()` reads exactly the queued part length, so a
               //   vulnerable serve emits `strrev($original)` under a
               //   Content-Length taken from the original — which is what
               //   proves the swap landed AFTER the response was queued
               //   rather than before it.
               $replacement = strrev($Target['content']) . str_repeat('X', 64);
               $sibling = $Target['path'] . '.replacement';
               $artifacts[] = $sibling;

               $report['stage'] = 'setup';
               $Setup = $Open($hostPort);
               $Write(
                  $Setup,
                  "GET /m1-wire-setup HTTP/1.1\r\n"
                  . "X-Bootgly-Test: {$testIndex}\r\n"
                  . "X-M1-Token: {$token}\r\n"
                  . "Host: localhost\r\n"
                  . "Connection: close\r\n\r\n"
               );
               $setup = $Read($Setup, 3.0);
               @fclose($Setup);
               $separator = strpos($setup, "\r\n\r\n");
               $decoded = $separator === false
                  ? null
                  : json_decode(substr($setup, $separator + 4), true);
               if (! is_array($decoded) || ($decoded['phase'] ?? null) !== 'setup') {
                  throw new RuntimeException('The M1 wire setup route did not answer.');
               }
               $report['setup'] = $decoded;

               $report['stage'] = 'pipeline';
               $Socket = $Open($hostPort);
               $Head = static function (string $path) use ($testIndex, $token): string {
                  return "GET {$path} HTTP/1.1\r\n"
                     . "X-Bootgly-Test: {$testIndex}\r\n"
                     . "X-M1-Token: {$token}\r\n"
                     . "Host: localhost\r\n"
                     . "Connection: keep-alive\r\n\r\n";
               };
               $Write($Socket, $Head('/m1-wire-big') . $Head('/m1-wire-target'));

               // ! Withhold every read. The worker fills the send buffer with
               //   the first body, parks, and queues the target response with
               //   no file opened. 82.02 uses PING barriers for this; HTTP/1
               //   has no equivalent in-band signal, so the settle is a wait
               //   and its success is proved after the fact by the advertised
               //   Content-Length below.
               usleep(600_000);

               $report['stage'] = 'swap';
               clearstatcache(true, $Target['path']);
               $before = @stat($Target['path']);
               if (@file_put_contents($sibling, $replacement, LOCK_EX) !== strlen($replacement)) {
                  throw new RuntimeException('Could not stage the M1 wire replacement.');
               }
               if (@rename($sibling, $Target['path']) === false) {
                  throw new RuntimeException('Could not atomically replace the M1 wire target.');
               }
               clearstatcache(true, $Target['path']);
               $after = @stat($Target['path']);
               if (
                  ! is_array($before) || ! is_array($after)
                  || $after['ino'] === $before['ino']
                  || $after['size'] === $before['size']
               ) {
                  throw new RuntimeException('The M1 wire swap did not change inode and size.');
               }

               $report['stage'] = 'drain';
               $wire = $Read(
                  $Socket,
                  8.0,
                  static function (string $wire) use ($Parse): bool {
                     $Responses = $Parse($wire);

                     return count($Responses) === 2 && $Responses[1]['complete'] === true;
                  }
               );
               @fclose($Socket);
               $Socket = null;

               $Responses = $Parse($wire);
               $report['count'] = count($Responses);
               $report['responses'] = array_map(
                  static fn(array $R): array => [
                     'advertised' => $R['advertised'],
                     'bytes' => strlen($R['body']),
                     'complete' => $R['complete'],
                  ],
                  $Responses
               );

               $Last = $Responses[1] ?? null;
               $report['original_size'] = strlen($Target['content']);
               $report['served_replacement'] = is_array($Last)
                  && $Last['advertised'] === strlen($Target['content'])
                  && $Last['body'] === substr($replacement, 0, strlen($Target['content']));
               $report['served_original'] = is_array($Last)
                  && $Last['body'] === $Target['content'];
               $report['first_complete'] = ($Responses[0]['complete'] ?? false) === true
                  && ($Responses[0]['advertised'] ?? -1) === strlen($Big['content']);
               $report['stage'] = 'done';
            }
            catch (Throwable $Throwable) {
               $report['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
            }
            finally {
               if (is_resource($Socket)) {
                  @fclose($Socket);
               }
            }

            return $report;
         };

         $Request = new Request;
         $Request->method = 'GET';
         $Request->protocol = 'HTTP/1.1';
         $WPI->Request = $Request;

         // # Leg 0 — control. No mutation at all. Must hold on BOTH trees:
         //   it proves the harness serves a file correctly across a park, so
         //   the attack legs measure the swap and not the harness.
         $Fixture = $Make('control');
         [$Socket, $Connection, $Package] = $Boot();
         [$header, $headerLength] = $Queue($Package, $Request, $Fixture['relative']);
         $Preflight($Package, '0');
         M1FileIdentityStream::$stalled = true;
         $Package->writing($Socket, length: $headerLength, buffer: $header);
         $Park($Package, $header, '0');
         $rounds = $Drain($Package, $Connection, $Socket);
         $probe['legs']['0'] = [
            'wire' => M1FileIdentityStream::$written,
            'expected' => $header . $Fixture['content'],
            'closed' => $Connection->closed,
            'queued' => count($Package->uploading),
            'rounds' => $rounds,
         ];
         if (is_resource($Socket)) { @fclose($Socket); }

         // # Leg A — the finding. The head parks with zero reopens, the
         //   pathname is atomically replaced by a same-size different inode,
         //   and only then does the first reopen happen.
         $Fixture = $Make('rename');
         [$Socket, $Connection, $Package] = $Boot();
         [$header, $headerLength] = $Queue($Package, $Request, $Fixture['relative']);
         $Preflight($Package, 'A');
         M1FileIdentityStream::$stalled = true;
         $Package->writing($Socket, length: $headerLength, buffer: $header);
         $Park($Package, $header, 'A');
         $replacement = $Swap($Fixture['path'], $Fixture['content'], 'A');
         $rounds = $Drain($Package, $Connection, $Socket);
         $probe['legs']['A'] = [
            'wire' => M1FileIdentityStream::$written,
            'header' => $header,
            'original' => $Fixture['content'],
            'replacement' => $replacement,
            'closed' => $Connection->closed,
            'queued' => count($Package->uploading),
            'rounds' => $rounds,
         ];
         if (is_resource($Socket)) { @fclose($Socket); }

         // # Leg A' — the auditor's own shape: the parked pathname becomes a
         //   symlink to an out-of-jail file of the same size. Refusing this
         //   requires looking at the name BEFORE fopen() follows it, so this
         //   leg is what discriminates the pre-open gate from the post-open
         //   comparison alone.
         $Fixture = $Make('symlink');
         $secret = strrev($Fixture['content']);
         if (@file_put_contents($outside, $secret, LOCK_EX) !== strlen($secret)) {
            throw new RuntimeException("HARNESS A': could not create the out-of-jail file.");
         }
         clearstatcache(true, $outside);
         [$Socket, $Connection, $Package] = $Boot();
         [$header, $headerLength] = $Queue($Package, $Request, $Fixture['relative']);
         $Preflight($Package, "A'");
         M1FileIdentityStream::$stalled = true;
         $Package->writing($Socket, length: $headerLength, buffer: $header);
         $Park($Package, $header, "A'");
         @unlink($Fixture['path']);
         if (@symlink($outside, $Fixture['path']) === false) {
            throw new RuntimeException("HARNESS A': could not plant the symlink.");
         }
         clearstatcache(true, $Fixture['path']);
         if (is_link($Fixture['path']) === false) {
            throw new RuntimeException("HARNESS A': the planted path is not a symlink.");
         }
         $rounds = $Drain($Package, $Connection, $Socket);
         $probe['legs']["A'"] = [
            'wire' => M1FileIdentityStream::$written,
            'header' => $header,
            'original' => $Fixture['content'],
            'secret' => $secret,
            'closed' => $Connection->closed,
            'rounds' => $rounds,
         ];
         if (is_resource($Socket)) { @fclose($Socket); }

         // # Leg B — same inode, same size, different content. The
         //   discriminator that stops a device+inode-only comparison from
         //   shipping. `fstat` timestamps are whole seconds, so the rewrite is
         //   only usable once the stat actually moved — otherwise all six
         //   fields stay byte-identical and this leg would report CONFIRMED
         //   against the FIXED build.
         $Fixture = $Make('inplace');
         [$Socket, $Connection, $Package] = $Boot();
         [$header, $headerLength] = $Queue($Package, $Request, $Fixture['relative']);
         $Preflight($Package, 'B');
         M1FileIdentityStream::$stalled = true;
         $Package->writing($Socket, length: $headerLength, buffer: $header);
         $Park($Package, $header, 'B');
         clearstatcache(true, $Fixture['path']);
         $before = @stat($Fixture['path']);
         $rewrite = strrev($Fixture['content']);
         $deadline = microtime(true) + 3.0;
         $moved = false;
         while (microtime(true) < $deadline) {
            if (@file_put_contents($Fixture['path'], $rewrite, LOCK_EX) !== strlen($rewrite)) {
               throw new RuntimeException('HARNESS B: could not rewrite the fixture in place.');
            }
            clearstatcache(true, $Fixture['path']);
            $after = @stat($Fixture['path']);
            if (
               is_array($before) && is_array($after)
               && $after['ino'] === $before['ino']
               && $after['size'] === $before['size']
               && ($after['mtime'] !== $before['mtime'] || $after['ctime'] !== $before['ctime'])
            ) {
               $moved = true;
               break;
            }
            usleep(120_000);
         }
         if ($moved === false) {
            throw new RuntimeException(
               'HARNESS B: the in-place rewrite never produced an observable stat change; '
               . 'whole-second timestamps make this leg vacuous.'
            );
         }
         $rounds = $Drain($Package, $Connection, $Socket);
         $probe['legs']['B'] = [
            'wire' => M1FileIdentityStream::$written,
            'header' => $header,
            'original' => $Fixture['content'],
            'replacement' => $rewrite,
            'closed' => $Connection->closed,
            'rounds' => $rounds,
         ];
         if (is_resource($Socket)) { @fclose($Socket); }

         // # Leg C — the pipelined window. A second file response encoded
         //   while the first head is parked is staged, re-homed into
         //   `pendingResponses`, and its first reopen waits for the previous
         //   body to drain. The promotion MUST be driven with the second
         //   head: an empty buffer takes the not-owner branch, which drains
         //   `stagedUploading` into a local nothing consumes.
         $First = $Make('pipeline-first', 8);
         $Second = $Make('pipeline-second');
         [$Socket, $Connection, $Package] = $Boot();
         [$headA, $headALength] = $Queue($Package, $Request, $First['relative']);
         $Preflight($Package, 'C/1');
         M1FileIdentityStream::$stalled = true;
         $Package->writing($Socket, length: $headALength, buffer: $headA);
         $Park($Package, $headA, 'C/1');
         [$headB, $headBLength] = $Queue($Package, $Request, $Second['relative']);
         $staged = count($Package->stagedUploading);
         $Package->writing($Socket, length: $headBLength, buffer: $headB);
         $promoted = $Package->pendingResponses[0]['uploads'][0]['identity'] ?? null;
         if (count($Package->pendingResponses) !== 1 || ! is_array($promoted)) {
            throw new RuntimeException(
               'HARNESS C: the second file response did not reach pendingResponses ('
               . 'staged=' . $staged
               . ' pending=' . count($Package->pendingResponses) . ').'
            );
         }
         $replacement = $Swap($Second['path'], $Second['content'], 'C');
         $rounds = $Drain($Package, $Connection, $Socket);
         $probe['legs']['C'] = [
            'wire' => M1FileIdentityStream::$written,
            'staged' => $staged,
            'identity' => $promoted,
            'expected' => $headA . $First['content'] . $headB . $Second['content'],
            'vulnerable' => $headA . $First['content'] . $headB . $replacement,
            'secure' => $headA . $First['content'] . $headB,
            'closed' => $Connection->closed,
            'rounds' => $rounds,
         ];
         if (is_resource($Socket)) { @fclose($Socket); }

         // # Leg D — multi-quantum streaming. `pump()` reads at most 1 MiB per
         //   iteration, so a fixture larger than that is the only way to make
         //   a part survive a park and be REOPENED. Both D legs must hold on
         //   BOTH trees: D1 is the plain multi-reopen control, D2 is the
         //   between-quanta replacement the deleted self-baseline already
         //   caught and the queued identity must keep catching.
         $quantum = 1024 * 1024;
         $Large = $Make('multiquantum', 2 * $quantum / 8);

         [$Socket, $Connection, $Package] = $Boot();
         [$header, $headerLength] = $Queue($Package, $Request, $Large['relative']);
         $Preflight($Package, 'D1');
         // ! Call 1 is the head, call 2 is the first 1 MiB quantum. Holding
         //   call 2 parks the part mid-flight with its cursor advanced.
         M1FileIdentityStream::$zeroAt = 2;
         $Package->writing($Socket, length: $headerLength, buffer: $header);
         $parked = $Package->uploading[0]['parts'][0]['length'] ?? null;
         $rounds = $Drain($Package, $Connection, $Socket);
         $probe['legs']['D1'] = [
            'wire' => M1FileIdentityStream::$written,
            'expected' => $header . $Large['content'],
            'parked' => $parked,
            'remaining' => 2 * $quantum - $quantum,
            'closed' => $Connection->closed,
            'queued' => count($Package->uploading),
            'rounds' => $rounds,
         ];
         if (is_resource($Socket)) { @fclose($Socket); }

         $Large = $Make('multiquantum-swap', 2 * $quantum / 8);
         [$Socket, $Connection, $Package] = $Boot();
         [$header, $headerLength] = $Queue($Package, $Request, $Large['relative']);
         $Preflight($Package, 'D2');
         M1FileIdentityStream::$zeroAt = 2;
         $Package->writing($Socket, length: $headerLength, buffer: $header);
         $parkedSwap = $Package->uploading[0]['parts'][0]['length'] ?? null;
         if ($parkedSwap !== $quantum) {
            throw new RuntimeException(
               'HARNESS D2: the part did not park between two pump quanta ('
               . 'remaining=' . json_encode($parkedSwap) . ') — no later reopen is validated.'
            );
         }
         $Swap($Large['path'], $Large['content'], 'D2');
         $rounds = $Drain($Package, $Connection, $Socket);
         $probe['legs']['D2'] = [
            'wire_length' => strlen(M1FileIdentityStream::$written),
            'ceiling' => strlen($header) + $quantum,
            'closed' => $Connection->closed,
            'rounds' => $rounds,
         ];
         if (is_resource($Socket)) { @fclose($Socket); }

         // # Leg F — policy control. An in-jail symlink to an in-jail regular
         //   file is servable today; a pre-open S_IFREG gate that inspects the
         //   symbolic name would silently make it unservable, and nothing else
         //   in the suite would notice.
         $FTarget = $Make('symlinked-target');
         $fName = "m1-identity-{$token}-symlinked.bin";
         $fPath = $statics . $fName;
         $artifacts[] = $fPath;
         @unlink($fPath);
         if (@symlink($FTarget['path'], $fPath) === false) {
            throw new RuntimeException('HARNESS F: could not create the in-jail symlink.');
         }
         clearstatcache(true, $fPath);
         [$Socket, $Connection, $Package] = $Boot();
         [$header, $headerLength] = $Queue($Package, $Request, "statics/{$fName}");
         $Preflight($Package, 'F');
         M1FileIdentityStream::$stalled = true;
         $Package->writing($Socket, length: $headerLength, buffer: $header);
         $Park($Package, $header, 'F');
         $rounds = $Drain($Package, $Connection, $Socket);
         $probe['legs']['F'] = [
            'wire' => M1FileIdentityStream::$written,
            'expected' => $header . $FTarget['content'],
            'closed' => $Connection->closed,
            'rounds' => $rounds,
         ];
         if (is_resource($Socket)) { @fclose($Socket); }

         // # Legs G — the queue is a bare public array taken by reference, so
         //   a record is an input to validate, not a fact to trust. Each
         //   sub-leg hand-builds one and drives the writer directly, and each
         //   is aimed at a guard NO other guard can stand in for:
         //     G1 — no identity at all;
         //     G2 — a truthful identity, but a part longer than the size it
         //          records (only the range check can refuse this before disk
         //          is read; without it the writer emits a prefix and only
         //          then dies on EOF);
         //     G3 — no identity on a record with nothing to read, which never
         //          reaches the file phase, so only the hoisted shape guard
         //          can see it.
         $GFixture = $Make('handbuilt');
         clearstatcache(true, $GFixture['path']);
         $Gstat = @stat($GFixture['path']);
         if (! is_array($Gstat)) {
            throw new RuntimeException('HARNESS G: could not stat the hand-built fixture.');
         }
         $Gidentity = [
            'device' => $Gstat['dev'],
            'inode' => $Gstat['ino'],
            'mode' => $Gstat['mode'],
            'size' => $Gstat['size'],
            'modified' => $Gstat['mtime'],
            'changed' => $Gstat['ctime'],
         ];
         $Malformed = [
            'G1' => [
               'file' => $GFixture['path'],
               'parts' => [['offset' => 0, 'length' => $Gstat['size']]],
               'pads' => [],
               'close' => false,
            ],
            'G2' => [
               'file' => $GFixture['path'],
               'identity' => $Gidentity,
               'parts' => [['offset' => 0, 'length' => $Gstat['size'] * 2]],
               'pads' => [],
               'close' => false,
            ],
            'G3' => [
               'file' => $GFixture['path'],
               'parts' => [],
               'pads' => [['prepend' => 'LEAK', 'append' => 'LEAK']],
               'close' => false,
            ],
         ];
         foreach ($Malformed as $leg => $record) {
            [$Socket, $Connection, $Package] = $Boot();
            $Package->uploading = [$record];
            $Package->writing($Socket, buffer: '');
            $probe['legs'][$leg] = [
               'wire' => M1FileIdentityStream::$written,
               'closed' => $Connection->closed,
               'queued' => count($Package->uploading),
            ];
            if (is_resource($Socket)) { @fclose($Socket); }
         }

         // # Leg E — reachability over a real socket against the live worker.
         //   The inline legs prove the defect; this one answers "does a real
         //   kernel socket ever produce that park?". It drives the
         //   pendingResponses window rather than the head stall: a large file
         //   request the client refuses to read blocks the writer, and the
         //   pipelined target request behind it sits queued with ZERO reopens
         //   until that body drains.
         $probe['wire'] = $E($hostPort, $testIndex, $token, $Make);
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $WPI->Request = $OldRequest;
         TCPServer::$Event = $OldEvent;

         foreach ($artifacts as $artifact) {
            @unlink($artifact);
         }
      }

      return "GET /m1-file-identity-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      // ! Leg E fixtures are named by the client's random token. Resolve it
      //   from the request header so the worker never serves an arbitrary
      //   caller-supplied path.
      $Relative = static function (Request $Request, string $slug): null|string {
         $token = $Request->Header->get('X-M1-Token') ?? '';
         if (preg_match('/^[a-f0-9]{16}$/D', $token) !== 1) {
            return null;
         }

         return "statics/m1-identity-{$token}-{$slug}.bin";
      };

      yield $Router->route(
         '/m1-wire-setup',
         static function (Request $Request, Response $Response) use ($Relative): Response {
            $relative = $Relative($Request, 'wire-target');

            return $Response->JSON->send([
               'phase' => 'setup',
               'pid' => getmypid(),
               'resolved' => $relative !== null,
            ]);
         },
         GET
      );

      yield $Router->route(
         '/m1-wire-big',
         static function (Request $Request, Response $Response) use ($Relative): Response {
            $relative = $Relative($Request, 'wire-big');
            if ($relative === null) {
               return $Response->code(400);
            }

            return $Response->upload($relative, close: false);
         },
         GET
      );

      yield $Router->route(
         '/m1-wire-target',
         static function (Request $Request, Response $Response) use ($Relative): Response {
            $relative = $Relative($Request, 'wire-target');
            if ($relative === null) {
               return $Response->code(400);
            }

            return $Response->upload($relative, close: false);
         },
         GET
      );

      yield $Router->route(
         '/m1-file-identity-harness',
         static function (Request $Request, Response $Response): Response {
            return $Response(code: 200, body: 'HARNESS-OK');
         },
         GET
      );

      yield $Router->route('/*', static function (Request $Request, Response $Response): Response {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['M1 PoC state'];
         dump(json_encode([
            'error' => $probe['error'],
            'legs' => array_keys($probe['legs']),
         ], JSON_UNESCAPED_SLASHES));

         return 'M1 PoC harness failed before reaching a verdict: ' . $probe['error'];
      }

      if (! str_contains($response, 'HARNESS-OK')) {
         return 'M1 PoC harness request did not reach its control route.';
      }

      $Evidence = static function (string $label, array $data): void {
         Vars::$labels = [$label];
         dump(json_encode($data, JSON_UNESCAPED_SLASHES));
      };

      // @ Controls first. A scrub-too-early or refuse-everything fix must fail
      //   HERE rather than pass the attack legs by serving nothing at all.
      foreach (['0' => 'unmodified file', 'F' => 'in-jail symlinked asset'] as $leg => $what) {
         $Leg = $probe['legs'][$leg] ?? null;
         if (
            ! is_array($Leg)
            || $Leg['wire'] !== $Leg['expected']
            || $Leg['closed'] !== false
            || ($Leg['queued'] ?? 0) !== 0
         ) {
            $Evidence("M1 control {$leg}", [
               'wire_length' => strlen($Leg['wire'] ?? ''),
               'expected_length' => strlen($Leg['expected'] ?? ''),
               'closed' => $Leg['closed'] ?? null,
               'rounds' => $Leg['rounds'] ?? null,
            ]);

            return "M1 control {$leg} failed: a complete, unattacked {$what} was not served "
               . 'across a backpressure park. The attack legs cannot be trusted.';
         }
      }

      // @ Leg D1 — a part that survives a park must still be reopened and
      //   finish. If it did not park mid-part the later-reopen regression is
      //   not being exercised at all.
      $D1 = $probe['legs']['D1'] ?? null;
      if (
         ! is_array($D1)
         || $D1['parked'] !== $D1['remaining']
         || $D1['wire'] !== $D1['expected']
         || $D1['closed'] !== false
         || $D1['queued'] !== 0
      ) {
         $Evidence('M1 leg D1', [
            'parked' => $D1['parked'] ?? null,
            'remaining' => $D1['remaining'] ?? null,
            'wire_length' => strlen($D1['wire'] ?? ''),
            'expected_length' => strlen($D1['expected'] ?? ''),
            'closed' => $D1['closed'] ?? null,
            'queued' => $D1['queued'] ?? null,
         ]);

         return 'M1 leg D1 failed: a file part parked between two pump quanta was not '
            . 'reopened and completed. Multi-quantum streaming is broken, so leg D2 and the '
            . 'attack legs cannot be trusted.';
      }

      // @ Leg D2 — a replacement landing BETWEEN quanta was already caught by
      //   the self-baseline, and must stay caught once it is deleted. Nothing
      //   past the first quantum may reach the wire.
      $D2 = $probe['legs']['D2'] ?? null;
      if (
         ! is_array($D2)
         || $D2['wire_length'] > $D2['ceiling']
         || $D2['closed'] !== true
      ) {
         $Evidence('M1 leg D2', $D2 ?? []);

         return 'M1 leg D2 failed: a representation replaced between two pump quanta was not '
            . 'refused — this is the case the self-baseline already covered.';
      }

      // @ Legs G — a hand-built queue record must be refused, not trusted.
      //   These are what keep the record contract from decaying into a
      //   comment: `$uploading` is public and taken by reference.
      foreach ([
         'G1' => 'a record with no identity at all',
         'G2' => 'a record whose part is longer than the size its identity records',
         'G3' => 'a pad-only record with no identity, which never reaches the disk phase',
      ] as $leg => $what) {
         $G = $probe['legs'][$leg] ?? null;
         if (
            ! is_array($G)
            || $G['wire'] !== ''
            || $G['closed'] !== true
            || $G['queued'] !== 0
         ) {
            $Evidence("M1 leg {$leg}", $G ?? []);

            return "M1 leg {$leg} failed: {$what} was not refused before any byte was read "
               . 'from disk.';
         }
      }

      // @ The attack legs.
      $A = $probe['legs']['A'];
      $As = $probe['legs']["A'"];
      $B = $probe['legs']['B'];
      $C = $probe['legs']['C'];

      $served = static function (array $Leg, string $key): bool {
         return $Leg['wire'] === $Leg['header'] . $Leg[$key];
      };
      $refused = static function (array $Leg): bool {
         return $Leg['wire'] === $Leg['header'] && $Leg['closed'] === true;
      };

      $findings = [];
      if ($served($A, 'replacement')) {
         $findings[] = 'a same-size replacement inode was served in full under the original '
            . 'response head (leg A: first reopen, zero prior opens)';
      }
      if ($served($As, 'secret')) {
         $findings[] = 'an OUT-OF-JAIL file was served through a symlink planted after the '
            . "response was queued (leg A': " . strlen($As['secret']) . ' bytes)';
      }
      if ($served($B, 'replacement')) {
         $findings[] = 'a same-inode in-place rewrite was served in full (leg B)';
      }
      if ($C['wire'] === $C['vulnerable']) {
         $findings[] = 'a pipelined file response parked in pendingResponses served its '
            . 'replacement when it was finally promoted (leg C)';
      }

      // @ Leg E — reachability over a real socket. It only ever ADDS to the
      //   verdict: a wire leg that could not set itself up must never be able
      //   to turn a confirmed finding into a pass, and must never be the sole
      //   evidence either.
      $E = $probe['wire'] ?? [];
      $wired = is_array($E) && $E['error'] === '' && ($E['stage'] ?? '') === 'done';
      if ($wired && ($E['served_replacement'] ?? false) === true) {
         $findings[] = 'a LIVE worker served the replacement for a request that was pipelined '
            . 'behind an unread large file and sat in pendingResponses with no file opened '
            . '(leg E: advertised Content-Length ' . $E['original_size']
            . ' from the original, body from the replacement)';
      }

      if ($findings !== []) {
         $Evidence('M1 PoC evidence', [
            'A' => ['wire' => strlen($A['wire']), 'header' => strlen($A['header']), 'closed' => $A['closed']],
            "A'" => ['wire' => strlen($As['wire']), 'header' => strlen($As['header']), 'closed' => $As['closed']],
            'B' => ['wire' => strlen($B['wire']), 'header' => strlen($B['header']), 'closed' => $B['closed']],
            'C' => ['wire' => strlen($C['wire']), 'secure' => strlen($C['secure']), 'closed' => $C['closed']],
         ]);

         return 'CONFIRMED M1: the HTTP/1 writer never consults the identity captured before '
            . 'the response head, so the first reopen adopts whatever the pathname now names — '
            . implode('; ', $findings) . '.';
      }

      $secure = $refused($A)
         && $refused($As)
         && $refused($B)
         && $C['wire'] === $C['secure']
         && $C['closed'] === true;

      if ($secure) {
         // ? Leg E cannot flip a secure verdict on its own, but it must not
         //   rot into decoration either. Secure over the wire means: the
         //   pipeline genuinely ran (the unread large file arrived whole, so
         //   the target really was queued behind it) AND not one replacement
         //   byte followed it. The target response itself is expected to be
         //   missing or truncated — a refusal closes the connection.
         if ($wired === false) {
            $Evidence('M1 leg E', $E);

            return 'M1 leg E did not run: ' . ($E['error'] ?? 'unknown')
               . ' (stage: ' . ($E['stage'] ?? '?') . '). Reachability is unproven, so the '
               . 'inline verdict stands alone.';
         }
         if (($E['first_complete'] ?? false) !== true) {
            $Evidence('M1 leg E', $E);

            return 'M1 leg E is vacuous: the unread large file did not arrive whole, so the '
               . 'target request was never actually parked behind it and the wire leg proves '
               . 'nothing about the pendingResponses window.';
         }

         return true;
      }

      $Evidence('M1 PoC inconclusive', [
         'A' => ['wire' => strlen($A['wire']), 'header' => strlen($A['header']), 'closed' => $A['closed']],
         "A'" => ['wire' => strlen($As['wire']), 'header' => strlen($As['header']), 'closed' => $As['closed']],
         'B' => ['wire' => strlen($B['wire']), 'header' => strlen($B['header']), 'closed' => $B['closed']],
         'C' => ['wire' => strlen($C['wire']), 'secure' => strlen($C['secure']), 'closed' => $C['closed']],
      ]);

      return 'M1 PoC reached neither verdict: no replacement bytes were served, but the '
         . 'transport also did not refuse the swapped representation cleanly. Review the '
         . 'captured lengths before assigning finding status.';
   }
);
