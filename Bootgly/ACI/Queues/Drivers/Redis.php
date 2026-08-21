<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues\Drivers;


use const SOL_TCP;
use const STREAM_CLIENT_CONNECT;
use const STREAM_CLIENT_PERSISTENT;
use const TCP_NODELAY;
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
use function is_string;
use function random_bytes;
use function serialize;
use function socket_import_stream;
use function socket_set_option;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function substr;
use function time;
use function unserialize;
use Redis as RedisClient;
use RuntimeException;
use Throwable;

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ABI\Data\RESP\Encoder;
use Bootgly\ACI\Queues\Driver;
use Bootgly\ACI\Queues\Job;


/**
 * Blocking Redis queue driver.
 *
 * Native by default: a blocking socket speaking RESP via the shared
 * ABI\Data\RESP codec — zero dependencies. When ext-redis is loaded it is used
 * as a faster C-path transport behind the same command() interface.
 *
 * Each queue is two sorted sets and a list: `queue:<q>` (ready, score =
 * availability ts), `queue:<q>:reserved` (in-flight, score = visibility
 * deadline) and `queue:<q>:failed` (dead-letter). A job is claimed by `ZREM`
 * from the ready set — only the worker whose `ZREM` removes it wins, so
 * competing workers never claim the same job. Delay/backoff is just a future
 * score; the reaper re-readies reserved members whose deadline has passed.
 *
 * Blocking by design: drive it from the `queue run` worker process, not the
 * async HTTP loop.
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

   /**
    * Current Unix timestamp (honours the Config clock override).
    */
   private int $now {
      get {
         $clock = $this->Config->clock;

         return $clock === null ? time() : (int) $clock();
      }
   }


   /**
    * Add the job to the ready set scored by its availability timestamp.
    *
    * @param string $queue Target queue name.
    * @param Job $Job Job to enqueue.
    */
   public function enqueue (string $queue, Job $Job): bool
   {
      // ! Stamp availability so delayed jobs sort after due ones
      if ($Job->available <= 0) {
         $Job->postpone($this->now);
      }

      $this->command(['ZADD', $this->key($queue), $Job->available, serialize($Job)]);

      // :
      return true;
   }

   /**
    * Claim the earliest due job by removing it from the ready set (atomic via ZREM).
    *
    * @param string $queue Queue to claim from.
    */
   public function reserve (string $queue): null|Job
   {
      $now = $this->now;
      $ready = $this->key($queue);

      // @ Up to N earliest due candidates; the ZREM winner owns the job
      $candidates = $this->command(['ZRANGEBYSCORE', $ready, '-inf', $now, 'LIMIT', 0, 16]);
      // ?
      if (is_array($candidates) === false) {
         return null;
      }

      foreach ($candidates as $payload) {
         if (is_string($payload) === false) {
            continue;
         }

         // ? Lost the race — another worker already removed it
         if ($this->command(['ZREM', $ready, $payload]) !== 1) {
            continue;
         }

         // @ Won the claim — mark it in-flight with a visibility deadline
         $this->command(['ZADD', $this->key($queue, 'reserved'), $now + $this->Config->visibility, $payload]);

         $Job = $this->load($payload);
         if ($Job === null) {
            continue;
         }

         // :
         return $Job;
      }

      // :
      return null;
   }

   /**
    * Remove the job from the reserved set.
    *
    * @param string $queue Queue the job belongs to.
    * @param Job $Job Reserved job to acknowledge.
    */
   public function complete (string $queue, Job $Job): bool
   {
      $this->command(['ZREM', $this->key($queue, 'reserved'), serialize($Job)]);

      // :
      return true;
   }

   /**
    * Move the job back to the ready set with a later availability and one more attempt.
    *
    * @param string $queue Queue the job belongs to.
    * @param Job $Job Reserved job to requeue.
    * @param int $delay Seconds until the job becomes due again.
    */
   public function release (string $queue, Job $Job, int $delay = 0): bool
   {
      // ! Remove the in-flight entry by its original bytes before mutating
      $this->command(['ZREM', $this->key($queue, 'reserved'), serialize($Job)]);

      $Job->attempt();
      $Job->postpone($this->now + $delay);

      $this->command(['ZADD', $this->key($queue), $Job->available, serialize($Job)]);

      // :
      return true;
   }

   /**
    * Remove the job from the reserved set and push it to the dead-letter list.
    *
    * @param string $queue Queue the job belongs to.
    * @param Job $Job Reserved job to dead-letter.
    */
   public function bury (string $queue, Job $Job): bool
   {
      $payload = serialize($Job);

      $this->command(['ZREM', $this->key($queue, 'reserved'), $payload]);
      $this->command(['RPUSH', $this->key($queue, 'failed'), $payload]);

      // :
      return true;
   }

   /**
    * Re-ready reserved members whose visibility deadline has passed.
    *
    * @param string $queue Queue to recover stale claims for.
    */
   public function recover (string $queue): int
   {
      $now = $this->now;
      $reserved = $this->key($queue, 'reserved');

      $expired = $this->command(['ZRANGEBYSCORE', $reserved, '-inf', $now]);
      // ?
      if (is_array($expired) === false) {
         return 0;
      }

      $count = 0;
      foreach ($expired as $payload) {
         if (is_string($payload) === false) {
            continue;
         }

         // ? Win the removal before re-readying (avoids double recovery)
         if ($this->command(['ZREM', $reserved, $payload]) !== 1) {
            continue;
         }

         $this->command(['ZADD', $this->key($queue), $now, $payload]);
         $count++;
      }

      // :
      return $count;
   }

   /**
    * Number of jobs in the ready set (due or scheduled).
    *
    * @param string $queue Queue to count.
    */
   public function count (string $queue): int
   {
      $count = $this->command(['ZCARD', $this->key($queue)]);

      // :
      return is_int($count) === true ? $count : 0;
   }

   /**
    * Delete the ready, reserved and failed structures for a queue.
    *
    * @param string $queue Queue to clear.
    */
   public function clear (string $queue): bool
   {
      $this->command([
         'DEL',
         $this->key($queue),
         $this->key($queue, 'reserved'),
         $this->key($queue, 'failed'),
      ]);

      // :
      return true;
   }

   // ---

   /**
    * Build a queue key (optionally a sub-structure), honouring the Config prefix.
    *
    * @param string $queue Queue name.
    * @param string $sub Sub-structure suffix (`reserved`, `failed`) or empty for ready.
    */
   private function key (string $queue, string $sub = ''): string
   {
      $key = "{$this->Config->prefix}queue:{$queue}";

      // :
      return $sub === '' ? $key : "{$key}:{$sub}";
   }

   /**
    * Decode a stored job; null on a corrupt record.
    *
    * @param string $payload Serialized job bytes.
    */
   private function load (string $payload): null|Job
   {
      // ! Restrict deserialization to Job only — never run object-injection gadgets
      //   from a tampered store; payloads are scalars/arrays by contract
      $Job = @unserialize($payload, ['allowed_classes' => [Job::class]]);

      // :
      return $Job instanceof Job ? $Job : null;
   }

   /**
    * Run a command through the active transport (ext-redis or native socket).
    *
    * @param array<int,int|string> $arguments Command and its arguments.
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
    * Send one command over the native socket and read its single reply (no connect).
    *
    * @param array<int,int|string> $arguments Command and its arguments.
    */
   private function dispatch (array $arguments): mixed
   {
      $this->write($this->Encoder->encode($arguments));

      // :
      return $this->read(1)[0];
   }

   /**
    * Write the full payload to the socket (fwrite may short-write).
    *
    * @param string $payload Encoded RESP bytes.
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
    * later reply from the command that asked for it — in `reserve()` that costs jobs,
    * because the ZREM reply is then read as the candidate list. Dropping the socket is
    * enough to clear both: `connect()` builds a fresh `Decoder` for every new socket.
    * The Decoder is deliberately not touched here — on the ext-redis path it is never
    * assigned.
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
    * @param int $count Number of replies to read.
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
      //   success — every mutating method here hard-codes `return true`. The frame is
      //   complete and the stream stays aligned, so this is raised without dropping the
      //   connection, unlike every throw above.
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
         //   (dispatch, not command — connect() has not finished yet)
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
