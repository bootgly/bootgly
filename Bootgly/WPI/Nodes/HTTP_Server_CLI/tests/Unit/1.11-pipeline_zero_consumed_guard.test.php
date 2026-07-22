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
 * Regression — a `States::Complete` decode that consumed zero bytes can never
 * advance the pipeline offset. Without the forward-progress guard the worker
 * relives the same bytes forever (livelock); with it, the transport treats
 * the zero-consumed complete as a decoder defect and closes the connection
 * after exactly one extra decode call and no extra response.
 */

if (! class_exists('U111Stream', false)) {
   class U111Stream
   {
      public static string $input = '';
      public static int $offset = 0;
      public static string $written = '';

      public mixed $context;

      public static function reset (string $input): void
      {
         self::$input = $input;
         self::$offset = 0;
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

if (! class_exists('U111Connection', false)) {
   class U111Connection extends Connection
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

if (! class_exists('U111Decoder', false)) {
   class U111Decoder implements ServerDecoder
   {
      public int $calls = 0;

      public function decode (ServerPackages $Package, string $buffer, int $size): States
      {
         $this->calls++;

         // # First request: ordinary complete.
         if ($this->calls === 1) {
            $Package->consumed = 3;
            return States::Complete;
         }

         // ? Livelock fuse: with a regressed guard the loop would spin on
         //   zero-consumed completes forever — cap it so the test still ends.
         if ($this->calls > 50) {
            $Package->consumed = $size;
            return States::Complete;
         }

         // # Defective decoder behavior under test: complete, nothing consumed.
         $Package->consumed = 0;
         return States::Complete;
      }
   }
}

if (! class_exists('U111Encoder', false)) {
   class U111Encoder implements ServerEncoder
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
   description: 'It should close the connection when a pipelined decode completes without consuming bytes',
   test: new Assertions(Case: function (): Generator {
      $scheme = 'bootgly-u111-progress';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, U111Stream::class);
      }

      U111Stream::reset('ABCDEF');
      U111Encoder::$responses = 0;
      $Socket = fopen($scheme . '://probe', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U111 probe stream opens')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      $OldDecoder = TCPServer::$Decoder;
      $OldEncoder = TCPServer::$Encoder;

      // ! Transport statics normally initialized at server boot — prime them
      //   so this case is self-sufficient in single-case runs.
      if (! isset(Connections::$stats)) {
         Connections::$stats = false;
      }

      $Decoder = new U111Decoder;
      $Connection = new U111Connection($Socket);
      $Package = new class($Connection) extends TCPPackages {};

      TCPServer::$Decoder = $Decoder;
      TCPServer::$Encoder = new U111Encoder;

      try {
         $Package->reading($Socket);
      }
      finally {
         TCPServer::$Decoder = $OldDecoder;
         TCPServer::$Encoder = $OldEncoder;

         if (is_resource($Socket)) {
            @fclose($Socket);
         }
      }

      yield new Assertion(
         description: 'The zero-consumed complete is detected on its first occurrence',
      )
         ->expect($Decoder->calls)
         ->to->be(2)
         ->assert();

      yield new Assertion(
         description: 'Only the legitimate first response is written',
      )
         ->expect(U111Stream::$written)
         ->to->be('R1')
         ->assert();

      yield new Assertion(
         description: 'The connection is closed instead of livelocking the worker',
      )
         ->expect($Connection->closed)
         ->to->be(true)
         ->assert();
   })
);
