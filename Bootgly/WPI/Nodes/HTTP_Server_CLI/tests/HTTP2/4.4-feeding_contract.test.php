<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Endpoints\Servers\Decoder as ServerDecoder;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Encoder as ServerEncoder;
use Bootgly\WPI\Endpoints\Servers\Feeding;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;


if (! class_exists('HTTP2S6FeedingStream', false)) {
   class HTTP2S6FeedingStream
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

if (! class_exists('HTTP2S6Connection', false)) {
   class HTTP2S6Connection extends Connection
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

if (! class_exists('HTTP2S6Decoder', false)) {
   class HTTP2S6Decoder implements ServerDecoder, Feeding
   {
      /** @var array<int,string> */
      public array $decoded = [];
      public string $fed = '';

      public function decode (ServerPackages $Package, string $buffer, int $size): States
      {
         $this->decoded[] = $buffer;

         if (count($this->decoded) === 1) {
            $Package->consumed = 3;
            return States::Complete;
         }

         $Package->consumed = 1;
         return States::Incomplete;
      }

      public function feed (string $buffer): void
      {
         $this->fed .= $buffer;
      }
   }
}

if (! class_exists('HTTP2S6Encoder', false)) {
   class HTTP2S6Encoder implements ServerEncoder
   {
      public static function encode (ServerPackages $Package, null|int &$length): string
      {
         $body = 'HTTP2-S6';
         $length = strlen($body);

         return $body;
      }
   }
}


return new Specification(
   description: 'It should feed undispatched bytes to Feeding decoders when pipelining stops on an incomplete tail',
   test: new Assertions(Case: function (): Generator {
      $scheme = 'bootgly-h2-s6-feed';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, HTTP2S6FeedingStream::class);
      }

      HTTP2S6FeedingStream::reset('ABCDEF', 2);
      $Socket = fopen($scheme . '://probe', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'S6 probe stream opens')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      $OldDecoder = TCPServer::$Decoder;
      $OldEncoder = TCPServer::$Encoder;

      $Decoder = new HTTP2S6Decoder;
      $Connection = new HTTP2S6Connection($Socket);
      $Package = new class($Connection) extends TCPPackages {};
      $Package->Decoder = $Decoder;

      TCPServer::$Decoder = $Decoder;
      TCPServer::$Encoder = new HTTP2S6Encoder;

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
         description: 'Feeding decoder receives only the unconsumed incomplete tail',
      )
         ->expect([$Decoder->decoded, $Decoder->fed])
         ->to->be([['ABCDEF', 'DEF'], 'EF'])
         ->assert();
   })
);
