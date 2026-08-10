<?php


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Connections\Peer;
use Bootgly\WPI\Endpoints\Servers\Decoder as ServerDecoder;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Encoder as ServerEncoder;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;

/**
 * Regression (TCP-3) — `reject()` must deliver its error bytes through the
 * ordered nonblocking writer, never around it. A raw `fwrite` ahead of an
 * undrained `pendingBuffer` splices the error into (or loses it behind) a
 * response body the transport already promised the peer, and the immediate
 * `close()` discards the owed tail. The error must drain BEHIND every owed
 * byte, and the close must wait for the drain.
 */

if (! class_exists('U129Stream', false)) {
   class U129Stream
   {
      public static string $input = '';
      public static int $offset = 0;
      public static int $zeros = 0;
      public static string $written = '';

      public mixed $context;

      public static function reset (string $input, int $zeros): void
      {
         self::$input = $input;
         self::$offset = 0;
         self::$zeros = $zeros;
         self::$written = '';
      }

      public function stream_open (string $path, string $mode, int $options, null|string &$opened_path): bool
      {
         return true;
      }

      public function stream_read (int $count): string
      {
         $chunk = substr(self::$input, self::$offset, $count);
         self::$offset += strlen($chunk);

         return $chunk;
      }

      public function stream_write (string $data): int
      {
         if (self::$zeros > 0) {
            self::$zeros--;
            return 0;
         }

         $length = strlen($data);
         self::$written .= substr($data, 0, $length);

         return $length;
      }

      public function stream_eof (): bool
      {
         return self::$offset >= strlen(self::$input);
      }

      /** @return array<string,mixed> */
      public function stream_stat (): array
      {
         return [];
      }
   }
}

if (! class_exists('U129Connection', false)) {
   class U129Connection extends Connection
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

if (! class_exists('U129Decoder', false)) {
   class U129Decoder implements ServerDecoder
   {
      public const string RAW = "HTTP/1.1 400 Bad Request\r\n\r\n";

      public function decode (ServerPackages $Package, string $buffer, int $size): States
      {
         // @ 4-byte fixed framing: a `BAD!` request is rejected mid-cycle —
         //   the deterministic single-read pipelining case of the TCP-3 entry.
         if (str_starts_with($buffer, 'BAD!')) {
            $Package->consumed = 0;
            if ($Package instanceof TCPPackages) {
               $Package->reject(self::RAW);
            }

            return States::Rejected;
         }

         $Package->consumed = 4;

         return States::Complete;
      }
   }
}

if (! class_exists('U129Encoder', false)) {
   class U129Encoder implements ServerEncoder
   {
      public static int $responses = 0;

      public static function encode (ServerPackages $Package, null|int &$length): string
      {
         $body = 'R' . ++self::$responses;
         $length = strlen($body);

         return $body;
      }
   }
}


return new Specification(
   description: 'It should drain reject() bytes behind the owed response tail before closing',
   test: new Assertions(Case: function (): Generator {
      $scheme = 'bootgly-u129-reject';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, U129Stream::class);
      }

      $raw = U129Decoder::RAW;

      $OldDecoder = TCPServer::$Decoder;
      $OldEncoder = TCPServer::$Encoder;
      $OldEvent = isset(TCPServer::$Event) ? TCPServer::$Event : null;

      TCPServer::$Decoder = new U129Decoder;
      TCPServer::$Encoder = new U129Encoder;
      // ! Deterministic zero writes come from a non-selectable user-space
      //   stream (1.10 pattern); a focused double keeps registration alive
      //   and records removals so the rejected-read detach is observable.
      $Event = new class extends Select {
         /** @var array<int,int> */
         public array $removed = [];

         public function __construct () {}

         public function add ($Socket, int $flag, mixed $payload): bool
         {
            return true;
         }

         public function del ($Socket, int $flag): bool
         {
            $this->removed[] = $flag;

            return true;
         }
      };
      TCPServer::$Event = $Event;

      /**
       * @return array{0: resource, 1: U129Connection, 2: TCPPackages}
       */
      $drive = static function (string $input, int $zeros) use ($scheme, $Event): array {
         U129Stream::reset($input, $zeros);
         U129Encoder::$responses = 0;
         $Event->removed = [];

         $Socket = fopen("{$scheme}://probe", 'w+');
         if (! is_resource($Socket)) {
            throw new RuntimeException('U129 probe stream failed to open.');
         }

         $Connection = new U129Connection($Socket);
         $Package = new class($Connection) extends TCPPackages {};

         $Package->reading($Socket);

         return [$Socket, $Connection, $Package];
      };

      try {
         // # Case A — reject while the socket refuses bytes (owed tail deferred).
         //   Three zero writes: R1 stalls in the fast lane, again in the state
         //   machine (tail owned by pendingBuffer), and the routed reject write
         //   itself defers once — everything must drain on the write event.
         [$SocketA, $ConnectionA, $PackageA] = $drive('REQ1BAD!', 3);

         $observedA = json_encode([
            'wire' => U129Stream::$written,
            'pendingBuffer' => $PackageA->pendingBuffer,
            'closed' => $ConnectionA->closed,
            'closeAfterDrain' => $PackageA->closeAfterDrain,
         ]);

         yield new Assertion(
            description: 'A: the rejected pipeline keeps the connection open with the error queued behind the owed tail (observed: ' . $observedA . ')',
         )
            ->expect([
               $ConnectionA->closed,
               $PackageA->pendingBuffer,
               $PackageA->closeAfterDrain,
               $PackageA->rejected,
               U129Stream::$written,
            ])
            ->to->be([
               false,
               "R1{$raw}",
               true,
               true,
               '',
            ])
            ->assert();

         yield new Assertion(
            description: 'A: a rejected connection stops reading while the error drains',
         )
            ->expect(in_array(Select::EVENT_READ, $Event->removed, true))
            ->to->be(true)
            ->assert();

         // @ Event loop signals writable: the drain delivers tail + error in
         //   order, then the deferred close fires.
         $PackageA->writing($SocketA);

         $drainedA = json_encode([
            'wire' => U129Stream::$written,
            'closed' => $ConnectionA->closed,
         ]);

         yield new Assertion(
            description: 'A: the write-ready resume drains the owed tail, then the error, then closes (observed: ' . $drainedA . ')',
         )
            ->expect([
               U129Stream::$written,
               $PackageA->pendingBuffer,
               $PackageA->closeAfterDrain,
               $ConnectionA->closed,
            ])
            ->to->be([
               "R1{$raw}",
               '',
               false,
               true,
            ])
            ->assert();

         // # Case B — reject with room on the socket while the tail is owed.
         //   Two zero writes: R1 defers, then the socket accepts bytes again —
         //   at HEAD the raw fwrite splices the 400 ahead of the owed R1.
         [, $ConnectionB, $PackageB] = $drive('REQ1BAD!', 2);

         $observedB = json_encode([
            'wire' => U129Stream::$written,
            'closed' => $ConnectionB->closed,
         ]);

         yield new Assertion(
            description: 'B: with socket room the error still lands behind the owed response, never ahead of it (observed: ' . $observedB . ')',
         )
            ->expect([
               U129Stream::$written,
               $PackageB->pendingBuffer,
               $ConnectionB->closed,
            ])
            ->to->be([
               "R1{$raw}",
               '',
               true,
            ])
            ->assert();

         // # Case C — idle-writer parity pin: with nothing owed the reject is
         //   written whole and the close stays immediate (the common case of
         //   all 55 reject() call sites must not regress).
         [, $ConnectionC, $PackageC] = $drive('BAD!', 0);

         yield new Assertion(
            description: 'C: an idle-writer reject writes the error whole and closes immediately',
         )
            ->expect([
               U129Stream::$written,
               $PackageC->pendingBuffer,
               $PackageC->closeAfterDrain,
               $PackageC->rejected,
               $ConnectionC->closed,
            ])
            ->to->be([
               $raw,
               '',
               false,
               true,
               true,
            ])
            ->assert();
      }
      finally {
         TCPServer::$Decoder = $OldDecoder;
         TCPServer::$Encoder = $OldEncoder;
         if ($OldEvent !== null) {
            TCPServer::$Event = $OldEvent;
         }

         foreach ([$SocketA ?? null] as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
      }

      // # Case D — teardown truncation snapshot: a close that abandons owed
      //   bytes must record it, so best-effort teardown writes (HTTP/2 GOAWAY)
      //   can refuse to land mid-frame on the wire.
      $Connection = null;
      $Twin = null;
      $Client = null;
      $Accepted = null;
      $Listener = null;
      $OldLoopEvent = isset(TCPServer::$Event) ? TCPServer::$Event : null;
      $oldContext = isset(TCPServer::$context) ? TCPServer::$context : null;

      try {
         // ! Hermetic timer/server statics (1.4 pattern)
         Timer::del();
         TCPServer::$context = [];

         $Server = new TCPServer;
         $Connections = new Connections($Server);
         TCPServer::$Event = new Select($Connections);

         $Listener = stream_socket_server('tcp://127.0.0.1:0');
         if ($Listener === false) {
            throw new RuntimeException('Could not create the loopback listener.');
         }
         $address = stream_socket_get_name($Listener, false);
         if ($address === false) {
            throw new RuntimeException('Could not resolve the loopback listener address.');
         }

         $open = static function () use ($Listener, $address): array {
            $Client = stream_socket_client("tcp://{$address}", $code, $message, 2.0);
            if ($Client === false) {
               throw new RuntimeException("Could not connect the loopback client: {$code} {$message}");
            }
            $Accepted = stream_socket_accept($Listener, 2.0);
            if ($Accepted === false) {
               throw new RuntimeException('Could not accept the loopback client.');
            }
            $peer = stream_socket_get_name($Accepted, true);
            if ($peer === false) {
               throw new RuntimeException('Could not resolve the accepted peer identity.');
            }
            [$IP, $port] = Peer::parse($peer);

            return [$Client, $Accepted, new Connection($Accepted, $IP, $port)];
         };

         [$Client, $Accepted, $Connection] = $open();
         $Connection->pendingBuffer = 'XYZ';
         $Connection->close();

         [$twinClient, $twinAccepted, $Twin] = $open();
         $Twin->close();

         $observedD = json_encode([
            'truncated' => $Connection->truncated ?? null,
            'clean' => $Twin->truncated ?? null,
         ]);

         yield new Assertion(
            description: 'D: closing over an undrained pendingBuffer records the truncation; a clean close does not (observed: ' . $observedD . ')',
         )
            ->expect([
               $Connection->truncated ?? null,
               $Twin->truncated ?? null,
            ])
            ->to->be([
               true,
               false,
            ])
            ->assert();

         foreach ([$twinClient, $twinAccepted] as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
      }
      finally {
         $Connection?->close();
         $Twin?->close();
         foreach ([$Accepted, $Client, $Listener] as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
         Timer::del();
         if ($oldContext !== null) {
            TCPServer::$context = $oldContext;
         }
         if ($OldLoopEvent !== null) {
            TCPServer::$Event = $OldLoopEvent;
         }
      }
   })
);
