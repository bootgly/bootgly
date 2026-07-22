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
 * Invariant pin — the miss path's `$Package->decoded ??= new Request` is
 * sound WITHOUT an instanceof guard because every non-Request occupant of
 * the polymorphic `decoded` slot (Decoder_HTTP2, SSE, WS Session) also
 * installs a per-connection `$Package->Decoder`: the transport then always
 * dispatches to that decoder and the shared entry decoder never meets the
 * foreign slot. This test pins the dispatch half of that invariant.
 */

if (! class_exists('U118Stream', false)) {
   class U118Stream
   {
      /** @var array<int,string> */
      public static array $chunks = [];
      public static string $written = '';

      public mixed $context;

      public function stream_open (string $path, string $mode, int $options, null|string &$opened_path): bool
      {
         return true;
      }

      public function stream_read (int $count): string
      {
         $chunk = array_shift(self::$chunks);

         return $chunk === null ? '' : substr($chunk, 0, $count);
      }

      public function stream_write (string $data): int
      {
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

if (! class_exists('U118Connection', false)) {
   class U118Connection extends Connection
   {
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
         $this->writes = 1;
      }
   }
}

if (! class_exists('U118Entry', false)) {
   class U118Entry implements ServerDecoder
   {
      public static int $calls = 0;

      public function decode (ServerPackages $Package, string $buffer, int $size): States
      {
         self::$calls++;
         $Package->consumed = $size;

         return States::Complete;
      }
   }
}

if (! class_exists('U118Installed', false)) {
   class U118Installed implements ServerDecoder
   {
      public int $calls = 0;
      public string $seen = '';

      public function decode (ServerPackages $Package, string $buffer, int $size): States
      {
         $this->calls++;
         $this->seen .= $buffer;
         $Package->consumed = $size;

         return States::Complete;
      }
   }
}

if (! class_exists('U118Encoder', false)) {
   class U118Encoder implements ServerEncoder
   {
      public static function encode (ServerPackages $Package, null|int &$length): string
      {
         $length = 2;

         return 'OK';
      }
   }
}


return new Specification(
   description: 'It should always dispatch to an installed per-connection decoder, never to the shared entry decoder',
   test: new Assertions(Case: function (): Generator {
      $scheme = 'bootgly-u118-dispatch';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, U118Stream::class);
      }

      U118Stream::$chunks = [];
      U118Stream::$written = '';
      U118Entry::$calls = 0;

      $Socket = fopen($scheme . '://probe', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U118 probe stream opens')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      $OldDecoder = TCPServer::$Decoder;
      $OldEncoder = TCPServer::$Encoder;

      try {
         $Connection = new U118Connection($Socket);
         $Package = new class($Connection) extends TCPPackages {};

         // # A protocol-switched connection: foreign object in `decoded`,
         //   per-connection decoder installed (the invariant's premise).
         $Installed = new U118Installed;
         $Package->Decoder = $Installed;
         $Package->decoded = new stdClass;

         TCPServer::$Decoder = new U118Entry;
         TCPServer::$Encoder = new U118Encoder;

         $payload = "binary-frame-bytes";
         U118Stream::$chunks[] = $payload;
         $Package->reading($Socket);

         yield new Assertion(
            description: 'The installed decoder received the event; the shared entry decoder never ran',
         )
            ->expect([
               $Installed->calls,
               $Installed->seen,
               U118Entry::$calls,
               $Package->decoded instanceof stdClass,
            ])
            ->to->be([1, $payload, 0, true])
            ->assert();
      }
      finally {
         TCPServer::$Decoder = $OldDecoder;
         TCPServer::$Encoder = $OldEncoder;
         if (is_resource($Socket)) {
            @fclose($Socket);
         }
      }
   })
);
