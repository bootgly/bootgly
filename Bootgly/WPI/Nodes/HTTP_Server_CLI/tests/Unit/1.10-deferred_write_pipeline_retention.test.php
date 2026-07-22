<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Endpoints\Servers\Decoder as ServerDecoder;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Encoder as ServerEncoder;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;

/**
 * Regression — a deferred (backpressured) write must not drop the pipeline
 * tail. The peer may have sent every pipelined request in one read: no future
 * read event would ever revisit the unserved requests, so stopping after the
 * stashed response silently drops them. Follow-up responses must append to
 * `pendingBuffer` in order and drain on the next write event.
 */

if (! class_exists('U110Stream', false)) {
   class U110Stream
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

if (! class_exists('U110Connection', false)) {
   class U110Connection extends Connection
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

if (! class_exists('U110Decoder', false)) {
   class U110Decoder implements ServerDecoder
   {
      /** @var array<int,string> */
      public array $decoded = [];

      public function decode (ServerPackages $Package, string $buffer, int $size): States
      {
         $this->decoded[] = $buffer;
         $Package->consumed = 4;

         return States::Complete;
      }
   }
}

if (! class_exists('U110Encoder', false)) {
   class U110Encoder implements ServerEncoder
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
   description: 'It should keep pipelining behind a deferred write and drain the queued responses in order',
   test: new Assertions(Case: function (): Generator {
      $scheme = 'bootgly-u110-deferred';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, U110Stream::class);
      }

      // ! Three zero-writes: response 1 stalls (fast lane + state machine),
      //   then response 2's resume-append attempt stalls once more — both
      //   responses are queued when reading() returns.
      U110Stream::reset('REQ1REQ2', 3);
      U110Encoder::$responses = 0;
      $Socket = fopen($scheme . '://probe', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U110 probe stream opens')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      $OldDecoder = TCPServer::$Decoder;
      $OldEncoder = TCPServer::$Encoder;

      $Decoder = new U110Decoder;
      $Connection = new U110Connection($Socket);
      $Package = new class($Connection) extends TCPPackages {};

      TCPServer::$Decoder = $Decoder;
      TCPServer::$Encoder = new U110Encoder;

      try {
         $Package->reading($Socket);

         $decoded = $Decoder->decoded;
         $pending = $Package->pendingBuffer;
         $writtenWhileStalled = U110Stream::$written;

         // @ Event loop signals writable: resume drains the queue in order.
         $Package->writing($Socket);
      }
      finally {
         TCPServer::$Decoder = $OldDecoder;
         TCPServer::$Encoder = $OldEncoder;

         if (is_resource($Socket)) {
            @fclose($Socket);
         }
      }

      yield new Assertion(
         description: 'Both pipelined requests are decoded despite the stalled first response',
      )
         ->expect($decoded)
         ->to->be(['REQ1REQ2', 'REQ2'])
         ->assert();

      yield new Assertion(
         description: 'Both responses are queued in order while the socket stalls',
      )
         ->expect([$pending, $writtenWhileStalled])
         ->to->be(['R1R2', ''])
         ->assert();

      yield new Assertion(
         description: 'The write-ready resume drains the queued responses in order',
      )
         ->expect([U110Stream::$written, $Package->pendingBuffer, $Connection->closed])
         ->to->be(['R1R2', '', false])
         ->assert();
   })
);
