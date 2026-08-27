<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
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
use function bin2hex;
use function count;
use function extension_loaded;
use function fclose;
use function fmod;
use function fread;
use function fwrite;
use function is_array;
use function is_int;
use function is_resource;
use function is_scalar;
use function is_string;
use function random_bytes;
use function serialize;
use function socket_import_stream;
use function socket_set_option;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function substr;
use Redis as RedisClient;
use RuntimeException;
use Throwable;

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ABI\Data\RESP\Encoder;
use Bootgly\ABI\Resources\Cache\Atomic;
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
class Redis extends Driver implements Atomic
{
   private const string SWAP_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then
   return 0
end
if tonumber(ARGV[3]) > 0 then
   redis.call('SET', KEYS[1], ARGV[2], 'EX', ARGV[3])
else
   redis.call('SET', KEYS[1], ARGV[2])
end
return 1
LUA;
   private const string EVICT_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then
   return 0
end
return redis.call('DEL', KEYS[1])
LUA;
   private const string RENEW_SCRIPT = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then
   return 0
end
if tonumber(ARGV[1]) > 0 then
   return redis.call('EXPIRE', KEYS[1], ARGV[1])
end
redis.call('PERSIST', KEYS[1])
return 1
LUA;

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
      return $this->persist($key, $value, $TTL, $tags);
   }

   /** @param array<int,string> $tags */
   public function create (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      return $this->persist($key, $value, $TTL, $tags, 'NX');
   }

   /** @param array<int,string> $tags */
   public function swap (
      string $key,
      mixed $expected,
      mixed $value,
      int $TTL = 0,
      array $tags = [],
   ): bool
   {
      $reply = $this->command([
         'EVAL',
         self::SWAP_SCRIPT,
         1,
         $key,
         $this->pack($expected),
         $this->pack($value),
         $TTL,
      ]);
      if ($reply !== 1) {
         return false;
      }

      $this->bind($key, $tags);

      return true;
   }

   public function evict (string $key, mixed $expected): bool
   {
      return $this->command([
         'EVAL',
         self::EVICT_SCRIPT,
         1,
         $key,
         $this->pack($expected),
      ]) === 1;
   }

   public function renew (string $key, int $TTL = 0): bool
   {
      return $this->command([
         'EVAL',
         self::RENEW_SCRIPT,
         1,
         $key,
         $TTL,
      ]) === 1;
   }

   /**
    * @param array<int,string> $tags
    */
   private function persist (
      string $key,
      mixed $value,
      int $TTL,
      array $tags,
      null|string $condition = null,
   ): bool
   {
      $packed = $this->pack($value);

      $set = $TTL > 0
         ? ['SET', $key, $packed, 'EX', $TTL]
         : ['SET', $key, $packed];
      if ($condition !== null) {
         $set[] = $condition;
      }

      // ?: No tags — single command, single round-trip
      if ($tags === []) {
         $reply = $this->command($set);

         return $reply === true || $reply === 'OK';
      }

      // ! A conditional SET must succeed before tag membership is published.
      //   Ordinary store keeps its single-round-trip pipeline.
      if ($condition !== null) {
         $reply = $this->command($set);
         if ($reply !== true && $reply !== 'OK') {
            return false;
         }
         $commands = [];
      }
      else {
         $commands = [$set];
      }
      foreach ($tags as $tag) {
         $commands[] = ['SADD', $this->index($tag), $key];
      }

      $replies = $this->flush($commands);
      $reply = $condition !== null ? true : ($replies[0] ?? null);

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

   /** @param array<int,string> $tags */
   private function bind (string $key, array $tags): void
   {
      if ($tags === []) {
         return;
      }

      $commands = [];
      foreach ($tags as $tag) {
         $commands[] = ['SADD', $this->index($tag), $key];
      }
      $this->flush($commands);
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
    *
    * There is no record wrapper on this path — the blob IS the application's
    * value — so nothing is reconstructed unless the application declared it in
    * `Config::$classes`, and a value this driver cannot hand back is a miss.
    */
   private function unpack (string $raw): mixed
   {
      // ?: Raw integer
      if (($raw[0] ?? '') !== "\x01") {
         return (int) $raw;
      }

      $payload = substr($raw, 1);
      $value = $this->decode($payload);

      // ? Undecodable bytes — malformed, over-deep, or naming a class the
      //   allow-list refused anywhere in the graph. `false` is also a
      //   legitimate stored value, so the one payload that encodes it is the
      //   only `false` accepted here
      if ($value === false && $payload !== 'b:0;') {
         return null;
      }
      // :
      return $value;
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
         $this->drop();

         throw new RuntimeException('Redis socket is not connected.');
      }

      $length = strlen($payload);
      $offset = 0;

      while ($offset < $length) {
         $written = @fwrite($Socket, $offset === 0 ? $payload : substr($payload, $offset));
         if ($written === false || $written === 0) {
            $this->drop();

            throw new RuntimeException('Redis socket write failed.');
         }

         $offset += $written;
      }
   }

   /**
    * Abandon the connection so the next command reconnects on a known-clean stream.
    *
    * A command that fails mid-exchange leaves the socket holding replies nobody asked
    * for and the Decoder holding a half-parsed frame; reusing either offsets every
    * later reply from the command that asked for it. Dropping the socket is enough to
    * clear both: `connect()` builds a fresh `Decoder` for every new socket. The Decoder
    * is deliberately not touched here — on the ext-redis path it is never assigned.
    */
   private function drop (): void
   {
      $Socket = $this->Socket;

      if (is_resource($Socket) === true) {
         @fclose($Socket);
      }

      $this->Socket = null;
      $this->connected = false;
   }

   /**
    * Resynchronize a reused persistent stream against a token only this connection knows.
    *
    * A stream PHP hands back may still be carrying a reply its previous owner abandoned —
    * possibly one that has not arrived yet, which no amount of draining can see. `ECHO`
    * plants a landmark instead: everything read before our own token belongs to someone
    * else and is discarded, so the first real command is guaranteed to be aligned.
    */
   private function resync (): void
   {
      $Socket = $this->Socket;

      if (is_resource($Socket) === false) {
         $this->drop();

         throw new RuntimeException('Redis socket is not connected.');
      }

      $token = 'bootgly:' . bin2hex(random_bytes(8));

      $this->write($this->Encoder->encode(['ECHO', $token]));

      try {
         // @@ Read past whatever the previous owner left behind
         while (true) {
            $chunk = @fread($Socket, 16384);

            if ($chunk === false || $chunk === '') {
               throw new RuntimeException('Redis connection closed or timed out.');
            }

            foreach ($this->Decoder->decode($chunk) as $reply) {
               // ?:
               if ($reply === $token) {
                  return;
               }
            }
         }
      }
      catch (Throwable $Throwable) {
         $this->drop();

         throw $Throwable;
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
         $this->drop();

         throw new RuntimeException('Redis socket is not connected.');
      }

      try {
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
      }
      catch (Throwable $Throwable) {
         // ! Whatever went wrong — a timeout, a closed peer, an undecodable byte — the
         //   stream is no longer known to be aligned with the commands sent on it
         $this->drop();

         throw $Throwable;
      }

      // ? More replies than commands means the stream was already offset
      if (count($replies) > $count) {
         $this->drop();

         throw new RuntimeException(
            'Redis protocol desync: ' . count($replies) . " replies for {$count} command(s)."
         );
      }

      // ? The Decoder hands `-`/`!` replies back as RuntimeException VALUES, never
      //   thrown, so an unexamined one turns a command Redis refused into a silent
      //   success. The frame is complete and the stream stays aligned — so this is
      //   raised without dropping the connection, unlike every throw above.
      foreach ($replies as $reply) {
         if ($reply instanceof Throwable) {
            throw $reply;
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

         // ? A persistent stream is pooled by target alone: PHP keys it on
         //   "tcp://host:port" and nothing else, so every client aimed at this
         //   endpoint is handed the same socket — the database, the password and
         //   the TLS context are all invisible to that key. A connection that
         //   needs a session of its own would then carry its state for strangers
         //   and theirs for us: one SELECT moves everybody, silently, because the
         //   reply counts stay aligned and no desync guard fires. Only a
         //   connection whose session IS the server default may share one.
         $poolable = $Config->persistent === true
            && $Config->database === 0
            && $Config->password === ''
            && $Config->secure === false;
         $flags = $poolable
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

         // ! The configured timeout is a float in seconds — pass the fractional part as
         //   microseconds, or any sub-second value collapses to "no wait at all"
         $timeout = $Config->timeout;
         stream_set_timeout($Socket, (int) $timeout, (int) (fmod($timeout, 1) * 1000000));

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
         try {
            // ? A stream handed over by a previous owner is not known to be
            //   aligned, and it must be realigned BEFORE anything else reads a
            //   reply: read() refuses debris — it rethrows a stranger's error
            //   frame and raises desync on a surplus one — while resync() is
            //   built to skip past exactly that. A stream this driver just
            //   opened is aligned by definition and owes no round trip here.
            if ($poolable) {
               $this->resync();
            }

            if ($Config->password !== '') {
               $this->dispatch(['AUTH', $Config->password]);
            }
            // ? Selecting only a non-zero index left a driver on db 0 — the
            //   default — trusting whatever the previous owner of a borrowed
            //   stream had selected. A pooled connect states its database.
            if ($Config->database !== 0 || $poolable) {
               $this->dispatch(['SELECT', $Config->database]);
            }
         }
         catch (Throwable $Throwable) {
            // ! A refused handshake leaves a socket that is perfectly aligned and
            //   perfectly useless — keeping it would let the next command run
            //   unauthenticated, or against the wrong database
            $this->drop();

            throw $Throwable;
         }
      }

      $this->connected = true;
   }
}
