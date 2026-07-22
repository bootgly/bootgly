<?php


use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Endpoints\Servers\Encoder as ServerEncoder;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;

/**
 * Regression — a fragmented ordinary HTTP head must be retained by the
 * connection's receive carry and reassembled on the next read event. The
 * shared decoder has no per-connection state, so before the transport
 * carry a split head was silently dropped (the follow-up fragment then
 * parsed as garbage). The carry belongs to ONE connection: an interleaved
 * complete request on another connection must neither see nor disturb it.
 */

if (! class_exists('U115Stream', false)) {
   class U115Stream
   {
      /** @var array<string,array<int,string>> */
      public static array $chunks = [];
      /** @var array<string,string> */
      public static array $written = [];

      public mixed $context;
      private string $key = '';

      public static function reset (): void
      {
         self::$chunks = [];
         self::$written = [];
      }

      public function stream_open (string $path, string $mode, int $options, null|string &$opened_path): bool
      {
         $this->key = parse_url($path, PHP_URL_HOST) ?: 'default';
         self::$chunks[$this->key] ??= [];
         self::$written[$this->key] ??= '';

         return true;
      }

      public function stream_read (int $count): string
      {
         $chunk = array_shift(self::$chunks[$this->key]);

         return $chunk === null ? '' : substr($chunk, 0, $count);
      }

      public function stream_write (string $data): int
      {
         self::$written[$this->key] .= $data;

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

if (! class_exists('U115Connection', false)) {
   class U115Connection extends Connection
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
         $this->writes = 1;
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

if (! class_exists('U115Encoder', false)) {
   class U115Encoder implements ServerEncoder
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
   description: 'It should reassemble a fragmented request head from the per-connection receive carry',
   test: new Assertions(Case: function (): Generator {
      $scheme = 'bootgly-u115-carry';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, U115Stream::class);
      }

      U115Stream::reset();
      U115Encoder::$responses = 0;

      $SocketA = fopen($scheme . '://a/probe', 'w+');
      $SocketB = fopen($scheme . '://b/probe', 'w+');
      if (! is_resource($SocketA) || ! is_resource($SocketB)) {
         yield new Assertion(description: 'U115 probe streams open')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      $WPI = WPI;
      $OldRequest = $WPI->Request ?? null;
      $OldDecoder = TCPServer::$Decoder;
      $OldEncoder = TCPServer::$Encoder;

      $ConnectionA = new U115Connection($SocketA);
      $PackageA = new class($ConnectionA) extends TCPPackages {};
      $ConnectionB = new U115Connection($SocketB);
      $PackageB = new class($ConnectionB) extends TCPPackages {};

      TCPServer::$Decoder = new Decoder_;
      TCPServer::$Encoder = new U115Encoder;

      try {
         $WPI->Request = new Request;

         $fragment1 = 'GET /u115-a HTT';
         $fragment2 = "P/1.1\r\nHost: localhost\r\n";
         $fragment3 = "\r\n";

         // @ Connection A: first head fragment arrives alone.
         U115Stream::$chunks['a'][] = $fragment1;
         $PackageA->reading($SocketA);

         yield new Assertion(
            description: 'The first fragment is retained whole, with no response',
         )
            ->expect([$PackageA->carry, U115Stream::$written['a'], $PackageA->carried])
            ->to->be([$fragment1, '', false])
            ->assert();

         // @ Connection B interleaves a COMPLETE request meanwhile.
         U115Stream::$chunks['b'][] = "GET /u115-b HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $PackageB->reading($SocketB);

         yield new Assertion(
            description: 'The interleaved connection completes without seeing the other carry',
         )
            ->expect([U115Stream::$written['b'], $PackageB->carry, $PackageB->carried, $PackageA->carry])
            ->to->be(['R1', '', false, $fragment1])
            ->assert();

         // @ Connection A: second fragment — still incomplete, carry grows.
         U115Stream::$chunks['a'][] = $fragment2;
         $PackageA->reading($SocketA);

         yield new Assertion(
            description: 'The reassembled-but-incomplete head is re-retained whole',
         )
            ->expect([$PackageA->carry, U115Stream::$written['a'], $PackageA->carried])
            ->to->be(["{$fragment1}{$fragment2}", '', true])
            ->assert();

         // @ Connection A: final fragment completes the head.
         U115Stream::$chunks['a'][] = $fragment3;
         $PackageA->reading($SocketA);

         yield new Assertion(
            description: 'The reassembled head decodes and responds; the carry is released',
         )
            ->expect([
               U115Stream::$written['a'],
               $PackageA->carry,
               $WPI->Request->method,
               $ConnectionA->closed,
            ])
            ->to->be(['R2', '', 'GET', false])
            ->assert();
      }
      finally {
         TCPServer::$Decoder = $OldDecoder;
         TCPServer::$Encoder = $OldEncoder;
         if ($OldRequest !== null) {
            $WPI->Request = $OldRequest;
         }

         if (is_resource($SocketA)) {
            @fclose($SocketA);
         }
         if (is_resource($SocketB)) {
            @fclose($SocketB);
         }
      }
   })
);
