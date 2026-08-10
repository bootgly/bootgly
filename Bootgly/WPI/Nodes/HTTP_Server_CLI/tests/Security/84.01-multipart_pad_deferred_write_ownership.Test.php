<?php

use const Bootgly\WPI;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


if (! class_exists('L2PadStream', false)) {
   /** Deterministic short/zero writer for one generated multipart pad. */
   class L2PadStream
   {
      public static string $target = '';
      public static int $accepted = 0;
      public static int $phase = 0;
      public static bool $reached = false;
      public static bool $retryMatched = false;
      public static string $written = '';
      /** @var array<int, array{length:int,returned:int,phase:int}> */
      public static array $writes = [];
      public static null|Buffers $Contender = null;
      public static int $contenderBytes = 0;
      public static bool $contenderAccepted = false;
      public static int $contenderTotal = 0;

      public mixed $context;


      public static function reset (
         string $target,
         int $accepted,
         null|Buffers $Contender = null,
         int $contenderBytes = 0,
      ): void {
         self::$target = $target;
         self::$accepted = $accepted;
         self::$phase = 0;
         self::$reached = false;
         self::$retryMatched = false;
         self::$written = '';
         self::$writes = [];
         self::$Contender = $Contender;
         self::$contenderBytes = $contenderBytes;
         self::$contenderAccepted = false;
         self::$contenderTotal = 0;
      }

      public function stream_open (
         string $path,
         string $mode,
         int $options,
         null|string &$opened_path,
      ): bool {
         return true;
      }

      public function stream_write (string $data): int
      {
         $length = strlen($data);

         if (
            self::$target !== ''
            && self::$phase === 0
            && $data === self::$target
         ) {
            self::$reached = true;
            if (self::$Contender !== null && self::$contenderBytes > 0) {
               self::$contenderAccepted = self::$Contender->reserve(
                  self::$contenderBytes
               );
               self::$contenderTotal = TCPServer::$pendingBytes;
            }

            if (self::$accepted <= 0) {
               self::$phase = 2;
               self::$writes[] = [
                  'length' => $length,
                  'returned' => 0,
                  'phase' => 0,
               ];

               return 0;
            }

            $sent = min(self::$accepted, max(0, $length - 1));
            self::$written .= substr($data, 0, $sent);
            self::$phase = 1;
            self::$writes[] = [
               'length' => $length,
               'returned' => $sent,
               'phase' => 0,
            ];

            return $sent;
         }

         // PHP may immediately call a userland wrapper again after a positive
         // partial return. Force that suffix to zero so outer fwrite() exposes
         // the intended short write to production transmit(). If PHP defers the
         // retry until the next production call, one extra resume round is safe.
         if (self::$phase === 1) {
            self::$retryMatched = $data === substr(
               self::$target,
               self::$accepted,
            );
            self::$phase = 2;
            self::$writes[] = [
               'length' => $length,
               'returned' => 0,
               'phase' => 1,
            ];

            return 0;
         }

         self::$written .= $data;
         self::$writes[] = [
            'length' => $length,
            'returned' => $length,
            'phase' => self::$phase,
         ];

         return $length;
      }

      public function stream_eof (): bool
      {
         return false;
      }

      /** @return array<string, mixed> */
      public function stream_stat (): array
      {
         return [];
      }
   }
}

if (! class_exists('L2PadConnection', false)) {
   class L2PadConnection extends Connection
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

         return true;
      }
   }
}

if (! class_exists('L2PadPackage', false)) {
   class L2PadPackage extends TCPPackages
   {
      public function inspect (): int
      {
         return $this->measure();
      }

      public function purge (): void
      {
         $this->release();
      }
   }
}

/**
 * L2 native PoC — transferring an already-reserved multipart pad to deferred
 * write ownership must not project the source pad and its unsent suffix twice.
 *
 * Every leg builds the queue through Request Range -> Response::upload() ->
 * the real HTTP/1 encoder -> TCP Packages::writing(). The deterministic writer
 * blocks the exact generated prepend or final append, never a reconstructed pad.
 */
$probe = [
   'error' => '',
   'baseline' => null,
   'scenarios' => [],
   'final' => null,
];

return new Test(
   description: 'Multipart pad deferral must transfer one retained owner at exact cap',
   Separator: new Separator(line: true),

   request: static function () use (&$probe): string {
      $scheme = 'bootgly-l2-pad-owner';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, L2PadStream::class);
      }

      $baseline = TCPServer::$pendingBytes;
      $probe['baseline'] = $baseline;
      $savedPendingCap = TCPServer::$maxPendingBytes;
      $savedWorkerCap = TCPServer::$maxWorkerPendingBytes;
      $OldEvent = TCPServer::$Event;
      $WPI = WPI;
      $OldRequest = $WPI->Request;
      $savedMultiparts = Request::$multiparts;

      TCPServer::$Event = new class extends Select {
         public function __construct () {}

         public function add ($Socket, int $flag, mixed $payload): bool
         {
            return true;
         }

         public function del ($Socket, int $flag): bool
         {
            return true;
         }
      };

      $Run = static function (
         string $name,
         string $targetKind,
         int $accepted,
         string $capMode,
         bool $contend,
      ) use ($scheme, $baseline, $WPI): array {
         $Socket = fopen("{$scheme}://{$name}/probe", 'w+');
         if (! is_resource($Socket)) {
            throw new RuntimeException('Could not allocate the L2 writer fixture.');
         }

         $Connection = new L2PadConnection($Socket);
         $Package = new L2PadPackage($Connection);
         $Contender = $contend ? new Buffers : null;
         $OldRequest = $WPI->Request;

         try {
            $Request = new Request;
            $Request->method = 'GET';
            $Request->protocol = 'HTTP/1.1';
            $Request->Header->adopt([
               'range' => 'bytes=1-2,4-5',
            ]);
            $WPI->Request = $Request;

            $Response = new Response;
            $Response->reset($Package, $Request);
            $Response->upload('statics/alphanumeric.txt', close: false);
            $headLength = null;
            $head = $Response->encode($Package, $headLength);

            $upload = $Package->uploading[0] ?? null;
            if (! is_array($upload)) {
               throw new RuntimeException('Public upload did not install one file queue.');
            }
            $parts = $upload['parts'] ?? null;
            $pads = $upload['pads'] ?? null;
            if (! is_array($parts) || ! is_array($pads) || count($parts) !== 2) {
               throw new RuntimeException('Range upload did not produce two multipart records.');
            }

            $fixture = file_get_contents(
               BOOTGLY_PROJECT->path . 'statics/alphanumeric.txt'
            );
            if (! is_string($fixture) || strlen($fixture) !== 62) {
               throw new RuntimeException('Could not read the 62-byte upload fixture.');
            }

            $padBytes = 0;
            $expected = $head;
            $target = '';
            $targetOffset = -1;
            $finalKey = array_key_last($parts);

            foreach ($parts as $key => $part) {
               $pad = $pads[$key] ?? null;
               if (! is_array($part) || ! is_array($pad)) {
                  throw new RuntimeException('Multipart record shape was invalid.');
               }
               $prepend = $pad['prepend'] ?? '';
               $append = $pad['append'] ?? '';
               if (! is_string($prepend) || ! is_string($append)) {
                  throw new RuntimeException('Multipart pad was not a string.');
               }
               $offset = $part['offset'] ?? null;
               $length = $part['length'] ?? null;
               if (! is_int($offset) || ! is_int($length)) {
                  throw new RuntimeException('Multipart range cursor was invalid.');
               }

               if ($targetKind === 'prepend' && $key === array_key_first($parts)) {
                  $target = $prepend;
                  $targetOffset = strlen($expected);
               }
               $expected .= $prepend;
               $expected .= substr($fixture, $offset, $length);
               if ($targetKind === 'append' && $key === $finalKey) {
                  $target = $append;
                  $targetOffset = strlen($expected);
               }
               $expected .= $append;
               $padBytes += strlen($prepend) + strlen($append);
            }

            if (
               ! str_starts_with($head, 'HTTP/1.1 206 ')
               || ! str_contains($head, 'multipart/byteranges')
               || $headLength !== strlen($head)
               || $padBytes <= 0
            ) {
               throw new RuntimeException('Public multipart encoder control was invalid.');
            }
            if ($targetKind !== '' && ($target === '' || $targetOffset < 0)) {
               throw new RuntimeException('Requested generated pad target was unavailable.');
            }

            $cap = match ($capMode) {
               'minus-one' => $padBytes - 1,
               'slack' => $padBytes + strlen($target) - max(0, $accepted),
               default => $padBytes,
            };
            TCPServer::$maxPendingBytes = $cap;
            TCPServer::$maxWorkerPendingBytes = $baseline + $cap;

            $contenderBytes = $contend ? $padBytes - strlen($target) : 0;
            L2PadStream::reset(
               $target,
               $accepted,
               $Contender,
               $contenderBytes,
            );

            $firstResult = $Package->writing(
               $Socket,
               length: $headLength,
               buffer: $head,
            );
            $source = null;
            if ($Package->uploading !== []) {
               $activePads = $Package->uploading[0]['pads'] ?? [];
               if (is_array($activePads)) {
                  if ($targetKind === 'prepend') {
                     $source = $activePads[array_key_first($activePads)]['prepend']
                        ?? null;
                  }
                  else if ($targetKind === 'append') {
                     $source = $activePads[array_key_last($activePads)]['append']
                        ?? null;
                  }
               }
            }

            $pending = $Package->pendingOffset === 0
               ? $Package->pendingBuffer
               : substr($Package->pendingBuffer, $Package->pendingOffset);
            $first = [
               'result' => $firstResult,
               'closed' => $Connection->closed,
               'pending' => $pending,
               'source' => $source,
               'uploading' => count($Package->uploading),
               'retained' => $Package->Buffers->retained,
               'measured' => $Package->inspect(),
               'total' => TCPServer::$pendingBytes,
               'registered' => $Package->writeRegistered,
               'deadline' => $Package->pendingDeadline,
               'wire' => L2PadStream::$written,
               'reached' => L2PadStream::$reached,
               'retry_matched' => L2PadStream::$retryMatched,
               'phase' => L2PadStream::$phase,
               'contender_accepted' => L2PadStream::$contenderAccepted,
               'contender_retained' => $Contender?->retained ?? 0,
               'contender_total' => L2PadStream::$contenderTotal,
            ];

            $rounds = 0;
            while (
               ! $Connection->closed
               && (
                  $Package->pendingBuffer !== ''
                  || $Package->uploading !== []
                  || $Package->pendingResponses !== []
                  || $Package->stagedUploading !== []
               )
               && $rounds < 16
            ) {
               $rounds++;
               $Package->writing($Socket, buffer: '');
            }

            $final = [
               'closed' => $Connection->closed,
               'pending' => $Package->pendingBuffer,
               'uploading' => count($Package->uploading),
               'responses' => count($Package->pendingResponses),
               'retained' => $Package->Buffers->retained,
               'measured' => $Package->inspect(),
               'total' => TCPServer::$pendingBytes,
               'registered' => $Package->writeRegistered,
               'deadline' => $Package->pendingDeadline,
               'wire' => L2PadStream::$written,
               'rounds' => $rounds,
               'contender_retained' => $Contender?->retained ?? 0,
            ];

            $result = [
               'name' => $name,
               'target_kind' => $targetKind,
               'accepted' => max(0, $accepted),
               'cap_mode' => $capMode,
               'cap' => $cap,
               'pad_bytes' => $padBytes,
               'target_bytes' => strlen($target),
               'target_offset' => $targetOffset,
               'contender_bytes' => $contenderBytes,
               'head_bytes' => strlen($head),
               'expected_bytes' => strlen($expected),
               'expected_hash' => hash('sha256', $expected),
               'expected_prefix_hash' => hash(
                  'sha256',
                  substr($expected, 0, max(0, $targetOffset) + max(0, $accepted)),
               ),
               'first' => $first,
               'final' => $final,
               'wire_hash' => hash('sha256', L2PadStream::$written),
               'writes' => L2PadStream::$writes,
            ];

            $Package->purge();
            $Contender?->release();
            $result['after_cleanup'] = TCPServer::$pendingBytes;

            return $result;
         }
         finally {
            $Package->purge();
            $Contender?->release();
            $WPI->Request = $OldRequest;
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
      };

      try {
         $probe['scenarios'] = [
            'control' => $Run('control', '', 0, 'exact', false),
            'cap_minus_one' => $Run('cap-minus-one', '', 0, 'minus-one', false),
            'prepend_zero_slack' => $Run(
               'prepend-zero-slack', 'prepend', 0, 'slack', false
            ),
            'prepend_short_slack' => $Run(
               'prepend-short-slack', 'prepend', 7, 'slack', false
            ),
            'append_zero_control' => $Run(
               'append-zero-control', 'append', 0, 'exact', false
            ),
            'append_short_control' => $Run(
               'append-short-control', 'append', 7, 'exact', false
            ),
            'prepend_zero' => $Run(
               'prepend-zero', 'prepend', 0, 'exact', false
            ),
            'prepend_short' => $Run(
               'prepend-short', 'prepend', 7, 'exact', false
            ),
            'append_zero' => $Run(
               'append-zero', 'append', 0, 'exact', true
            ),
            'append_short' => $Run(
               'append-short', 'append', 7, 'exact', true
            ),
         ];
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         TCPServer::$Event = $OldEvent;
         TCPServer::$maxPendingBytes = $savedPendingCap;
         TCPServer::$maxWorkerPendingBytes = $savedWorkerCap;
         Request::$multiparts = $savedMultiparts;
         $WPI->Request = $OldRequest;
         $probe['final'] = TCPServer::$pendingBytes;
      }

      return "GET /l2-multipart-pad-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) {
      yield $Router->route(
         '/l2-multipart-pad-harness',
         static function (Request $Request, Response $Response): Response {
            return $Response(code: 200, body: 'L2-PAD-HARNESS-OK');
         },
         GET,
      );

      yield $Router->route(
         '/*',
         static function (Request $Request, Response $Response): Response {
            return $Response(code: 404, body: 'Not Found');
         },
      );
   },

   test: static function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['L2 multipart pad fixture'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'L2 native fixture failed before reaching the production path: '
            . $probe['error'];
      }
      if (! str_contains($response, 'L2-PAD-HARNESS-OK')) {
         return 'L2 native fixture did not reach its control route.';
      }

      $baseline = $probe['baseline'];
      $scenarios = $probe['scenarios'];

      $Complete = static function (array $scenario, bool $stalled): bool {
         $first = $scenario['first'] ?? [];
         $final = $scenario['final'] ?? [];

         return ($first['result'] ?? null) === true
            && ($first['closed'] ?? null) === false
            && ($stalled === false || ($first['reached'] ?? null) === true)
            && ($final['closed'] ?? null) === false
            && ($final['pending'] ?? null) === ''
            && ($final['uploading'] ?? null) === 0
            && ($final['responses'] ?? null) === 0
            && ($final['retained'] ?? null) === 0
            && ($final['measured'] ?? null) === 0
            && ($final['registered'] ?? null) === false
            && ($final['deadline'] ?? null) === 0.0
            && ($scenario['wire_hash'] ?? null) === ($scenario['expected_hash'] ?? null)
            && ($scenario['after_cleanup'] ?? null) === ($scenario['baseline'] ?? null);
      };

      // Add the common baseline into each compact scenario before applying the
      // reusable complete-wire oracle.
      foreach ($scenarios as &$scenario) {
         $scenario['baseline'] = $baseline;
      }
      unset($scenario);

      $control = $scenarios['control'] ?? [];
      $negative = $scenarios['cap_minus_one'] ?? [];

      $controls = [
         $scenarios['prepend_zero_slack'] ?? [],
         $scenarios['prepend_short_slack'] ?? [],
         $scenarios['append_zero_control'] ?? [],
         $scenarios['append_short_control'] ?? [],
      ];
      $controlsSafe = $Complete($control, false);
      foreach ($controls as $scenario) {
         $controlsSafe = $controlsSafe && $Complete($scenario, true);
      }

      $negativeSafe = ($negative['first']['result'] ?? null) === false
         && ($negative['first']['closed'] ?? null) === true
         && ($negative['first']['wire'] ?? null) === ''
         && ($negative['first']['reached'] ?? null) === false
         && ($negative['first']['retained'] ?? null) === 0
         && ($negative['after_cleanup'] ?? null) === $baseline;

      if ($controlsSafe === false || $negativeSafe === false) {
         Vars::$labels = ['L2 multipart pad controls'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'L2 native fixture was inconclusive: its no-stall/deferred '
            . 'positive controls or cap-minus-one rejection did not hold.';
      }

      $attacks = [
         $scenarios['prepend_zero'] ?? [],
         $scenarios['prepend_short'] ?? [],
         $scenarios['append_zero'] ?? [],
         $scenarios['append_short'] ?? [],
      ];
      $secure = true;
      $vulnerable = true;

      foreach ($attacks as $scenario) {
         $first = $scenario['first'] ?? [];
         $kind = $scenario['target_kind'] ?? '';
         $accepted = $scenario['accepted'] ?? -1;
         $padBytes = $scenario['pad_bytes'] ?? -1;
         $targetBytes = $scenario['target_bytes'] ?? -1;
         $expectedPending = $targetBytes >= $accepted
            ? $targetBytes - $accepted
            : -1;
         $expectedPackage = $kind === 'append'
            ? $expectedPending
            : $padBytes - $accepted;

         $secure = $secure
            && ($first['result'] ?? null) === true
            && ($first['closed'] ?? null) === false
            && ($first['reached'] ?? null) === true
            && is_string($first['pending'] ?? null)
            && strlen($first['pending']) === $expectedPending
            && ($first['source'] ?? null) === ''
            && ($first['retained'] ?? null) === $expectedPackage
            && ($first['measured'] ?? null) === $expectedPackage
            && ($first['registered'] ?? null) === true
            && ($first['deadline'] ?? 0.0) > 0.0
            && ($kind !== 'append'
               || (
                  2 * $targetBytes <= $padBytes
                  && ($first['contender_total'] ?? null)
                     === $baseline + ($scenario['cap'] ?? 0)
                  && ($first['contender_accepted'] ?? null) === true
                  && ($first['contender_retained'] ?? null)
                     === ($scenario['contender_bytes'] ?? null)
               ))
            && $Complete($scenario, true);

         $expectedVulnerableTotal = $kind === 'append'
            ? $baseline + ($scenario['contender_bytes'] ?? 0)
            : $baseline;
         $vulnerable = $vulnerable
            && ($first['result'] ?? null) === false
            && ($first['closed'] ?? null) === true
            && ($first['reached'] ?? null) === true
            && ($first['pending'] ?? null) === ''
            && ($first['uploading'] ?? null) === 0
            && ($first['retained'] ?? null) === 0
            && ($first['total'] ?? null) === $expectedVulnerableTotal
            && hash('sha256', (string) ($first['wire'] ?? ''))
               === ($scenario['expected_prefix_hash'] ?? null)
            && ($scenario['after_cleanup'] ?? null) === $baseline
            && ($kind !== 'append'
               || (
                  2 * $targetBytes <= $padBytes
                  && ($first['contender_total'] ?? null)
                     === $baseline + ($scenario['cap'] ?? 0)
                  && ($first['contender_accepted'] ?? null) === true
                  && ($first['contender_retained'] ?? null)
                     === ($scenario['contender_bytes'] ?? null)
               ));
      }

      if ($secure && $probe['final'] === $baseline) {
         return true;
      }

      Vars::$labels = ['L2 multipart pad ownership evidence'];
      dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

      if ($vulnerable && $probe['final'] === $baseline) {
         return 'CONFIRMED L2: the public multipart Range upload projected an '
            . 'already-reserved prepend or append together with its identical '
            . 'deferred suffix at the exact retained-byte cap. Zero and short '
            . 'writes therefore aborted four otherwise admissible transfers; '
            . 'no-stall, slack-cap deferral, cap-minus-one, exact wire-order, '
            . 'and worker-contention controls all behaved as expected.';
      }

      return 'L2 native regression produced an unexpected ownership, wire, or '
         . 'ledger state; review the captured evidence before assigning status.';
   },
);
