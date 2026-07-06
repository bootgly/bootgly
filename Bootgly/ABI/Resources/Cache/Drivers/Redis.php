<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache\Drivers;


use const SOL_TCP;
use const STREAM_CLIENT_CONNECT;
use const STREAM_CLIENT_PERSISTENT;
use const TCP_NODELAY;
use function array_chunk;
use function array_shift;
use function count;
use function extension_loaded;
use function fread;
use function fwrite;
use function is_array;
use function is_int;
use function is_resource;
use function is_scalar;
use function is_string;
use function serialize;
use function socket_import_stream;
use function socket_set_option;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function substr;
use function unserialize;
use Redis as RedisClient;
use RuntimeException;

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ABI\Data\RESP\Encoder;
use Bootgly\ABI\Resources\Cache\Driver;


/**
 * Blocking Redis cache driver.
 *
 * Native by default: a blocking socket speaking RESP via the shared
 * ABI\Data\RESP codec — zero dependencies, works on any PHP 8.4 install. When
 * ext-redis is loaded it is used as a faster C-path transport behind the same
 * command() interface (via Redis::rawCommand), so command semantics stay
 * identical. TTL and expiry are native (SET ... EX, TTL); tags use Redis sets.
 *
 * Blocking by design: inside the async HTTP worker use APCu/Shared-memory, or
 * the event-loop Redis driver under ADI/Databases/KV, to avoid stalling the
 * loop.
 */
class Redis extends Driver
{
   // * Metadata
   private bool $ext;
   private bool $connected = false;
   private RedisClient $Client;
   /** @var resource|null */
   private $Socket = null;
   private Encoder $Encoder;
   private Decoder $Decoder;


   public function fetch (string $key): mixed
   {
      $raw = $this->command(['GET', $key]);

      // :
      return is_string($raw) === true ? $this->unpack($raw) : null;
   }

   /**
    * @param array<int,string> $tags
    */
   public function store (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      $packed = $this->pack($value);

      $set = $TTL > 0
         ? ['SET', $key, $packed, 'EX', $TTL]
         : ['SET', $key, $packed];

      // ?: No tags — single command, single round-trip
      if ($tags === []) {
         $reply = $this->command($set);

         return $reply === true || $reply === 'OK';
      }

      // @ Pipeline the SET and the tag SADDs in one round-trip
      $commands = [$set];
      foreach ($tags as $tag) {
         $commands[] = ['SADD', $this->index($tag), $key];
      }

      $replies = $this->flush($commands);
      $reply = $replies[0] ?? null;

      return $reply === true || $reply === 'OK';
   }

   public function delete (string $key): bool
   {
      $this->command(['DEL', $key]);

      return true;
   }

   public function clear (): bool
   {
      $prefix = $this->Config->prefix;

      // ?: No prefix — flush the whole selected database
      if ($prefix === '') {
         $this->command(['FLUSHDB']);

         return true;
      }

      // @ Prefix-scoped flush via SCAN + one variadic UNLINK per batch
      $cursor = '0';
      do {
         $reply = $this->command(['SCAN', $cursor, 'MATCH', "{$prefix}*", 'COUNT', 512]);
         if (is_array($reply) === false || count($reply) < 2) {
            break;
         }

         $cursor = is_scalar($reply[0]) === true ? (string) $reply[0] : '0';
         $keys = $reply[1];
         if (is_array($keys) === true && $keys !== []) {
            $batch = [];
            foreach ($keys as $key) {
               if (is_string($key) === true) {
                  $batch[] = $key;
               }
            }

            if ($batch !== []) {
               $this->command(['UNLINK', ...$batch]);
            }
         }
      }
      while ($cursor !== '0');

      return true;
   }

   public function check (string $key): bool
   {
      return $this->command(['EXISTS', $key]) === 1;
   }

   public function increment (string $key, int $by = 1, int $TTL = 0): int
   {
      $value = $this->command(['INCRBY', $key, $by]);
      // ?
      if (is_int($value) === false) {
         return 0;
      }

      // @ Set expiry only when the counter was just created
      if ($TTL > 0 && $value === $by) {
         $this->command(['EXPIRE', $key, $TTL]);
      }

      return $value;
   }

   public function remain (string $key): int
   {
      $TTL = $this->command(['TTL', $key]);

      // : Redis already returns -2 (missing), -1 (no expiry) or remaining seconds
      return is_int($TTL) === true ? $TTL : -2;
   }

   public function invalidate (string $tag): bool
   {
      $index = $this->index($tag);

      $members = $this->command(['SMEMBERS', $index]);

      // ! Collect members + the tag set itself for removal
      $keys = [];
      if (is_array($members) === true) {
         foreach ($members as $member) {
            if (is_string($member) === true) {
               $keys[] = $member;
            }
         }
      }
      $keys[] = $index;

      // @ Unlink in chunked variadic commands (bounds command size)
      foreach (array_chunk($keys, 512) as $chunk) {
         $this->command(['UNLINK', ...$chunk]);
      }

      return true;
   }

   public function purge (): int
   {
      // Redis evicts expired keys natively; nothing to scan.
      return 0;
   }

   // ---

   /**
    * Build the tag set key for a tag.
    */
   private function index (string $tag): string
   {
      return "{$this->Config->prefix}@tag:{$tag}";
   }

   /**
    * Encode a value for storage: integers stay raw (so INCRBY works), everything
    * else is serialized behind a marker byte.
    */
   private function pack (mixed $value): string
   {
      if (is_int($value) === true) {
         return (string) $value;
      }

      return "\x01" . serialize($value);
   }

   /**
    * Decode a stored value: marker byte means serialized, otherwise it is a
    * raw integer (from pack() or INCRBY).
    */
   private function unpack (string $raw): mixed
   {
      if (($raw[0] ?? '') === "\x01") {
         return unserialize(substr($raw, 1));
      }

      return (int) $raw;
   }

   /**
    * Run a command through the active transport (ext-redis or native socket).
    *
    * @param array<int,int|string> $arguments
    */
   private function command (array $arguments): mixed
   {
      $this->connect();

      // # ext-redis fast path
      if ($this->ext === true) {
         $command = (string) array_shift($arguments);

         return $this->Client->rawCommand($command, ...$arguments);
      }

      // # Native RESP path
      return $this->dispatch($arguments);
   }

   /**
    * Send a batch of commands in one round-trip and return one reply per command.
    *
    * Native transport concatenates the encoded commands into a single socket
    * write and reads until every reply arrived; ext-redis uses pipeline mode.
    *
    * @param array<int,array<int,int|string>> $commands
    *
    * @return array<int,mixed>
    */
   private function flush (array $commands): array
   {
      // ?
      if ($commands === []) {
         return [];
      }

      $this->connect();

      // # ext-redis fast path (pipeline mode)
      if ($this->ext === true) {
         $Pipe = $this->Client->multi(RedisClient::PIPELINE);
         foreach ($commands as $arguments) {
            $command = (string) array_shift($arguments);
            $Pipe->rawCommand($command, ...$arguments);
         }

         $replies = $Pipe->exec();

         // :
         return is_array($replies) === true ? $replies : [];
      }

      // # Native RESP path: one write, N replies
      $payload = '';
      foreach ($commands as $arguments) {
         $payload .= $this->Encoder->encode($arguments);
      }

      $this->write($payload);

      // :
      return $this->read(count($commands));
   }

   /**
    * Send a command over the native socket and read one RESP reply.
    *
    * @param array<int,int|string> $arguments
    */
   private function dispatch (array $arguments): mixed
   {
      $this->write($this->Encoder->encode($arguments));

      return $this->read(1)[0];
   }

   /**
    * Write the full payload to the socket (fwrite may short-write).
    */
   private function write (string $payload): void
   {
      $Socket = $this->Socket;
      if (is_resource($Socket) === false) {
         throw new RuntimeException('Redis socket is not connected.');
      }

      $length = strlen($payload);
      $offset = 0;

      while ($offset < $length) {
         $written = @fwrite($Socket, $offset === 0 ? $payload : substr($payload, $offset));
         if ($written === false || $written === 0) {
            throw new RuntimeException('Redis socket write failed.');
         }

         $offset += $written;
      }
   }

   /**
    * Read RESP replies from the socket until $count replies arrived.
    *
    * @return array<int,mixed>
    */
   private function read (int $count): array
   {
      $Socket = $this->Socket;
      if (is_resource($Socket) === false) {
         throw new RuntimeException('Redis socket is not connected.');
      }

      // ! Drain any replies already buffered
      $replies = $this->Decoder->decode();

      while (count($replies) < $count) {
         $chunk = @fread($Socket, 16384);
         if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Redis connection closed or timed out.');
         }

         foreach ($this->Decoder->decode($chunk) as $reply) {
            $replies[] = $reply;
         }
      }

      // :
      return $replies;
   }

   /**
    * Lazily establish the connection (ext-redis when available, else a socket).
    */
   private function connect (): void
   {
      // ?
      if ($this->connected === true) {
         return;
      }

      $Config = $this->Config;

      if (extension_loaded('redis') === true) {
         $Client = new RedisClient();
         $host = $Config->secure === true ? "tls://{$Config->host}" : $Config->host;
         if ($Config->persistent === true) {
            $Client->pconnect($host, $Config->port, $Config->timeout);
         }
         else {
            $Client->connect($host, $Config->port, $Config->timeout);
         }

         if ($Config->password !== '') {
            $Client->auth($Config->password);
         }
         if ($Config->database !== 0) {
            $Client->select($Config->database);
         }

         $this->Client = $Client;
         $this->ext = true;
      }
      else {
         $scheme = $Config->secure === true ? 'tls' : 'tcp';
         $flags = $Config->persistent === true
            ? STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT
            : STREAM_CLIENT_CONNECT;
         $Socket = @stream_socket_client(
            "{$scheme}://{$Config->host}:{$Config->port}",
            $errno,
            $error,
            $Config->timeout,
            $flags
         );
         if ($Socket === false) {
            throw new RuntimeException("Redis connect failed ({$Config->host}:{$Config->port}): {$error}");
         }

         stream_set_timeout($Socket, (int) $Config->timeout);

         // @ Disable Nagle: commands and replies are small — latency dominates
         if (extension_loaded('sockets') === true) {
            $Raw = socket_import_stream($Socket);
            if ($Raw !== false) {
               @socket_set_option($Raw, SOL_TCP, TCP_NODELAY, 1);
            }
         }

         $this->Socket = $Socket;
         $this->Encoder = new Encoder();
         $this->Decoder = new Decoder();
         $this->ext = false;

         // @ Authenticate and select the database over the native protocol
         //   (always — a reused persistent stream may carry another SELECT state)
         if ($Config->password !== '') {
            $this->dispatch(['AUTH', $Config->password]);
         }
         if ($Config->database !== 0) {
            $this->dispatch(['SELECT', $Config->database]);
         }
      }

      $this->connected = true;
   }
}
