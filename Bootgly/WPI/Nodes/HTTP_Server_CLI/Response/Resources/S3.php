<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use function array_key_first;
use function array_shift;
use function count;
use function feof;
use function fread;
use function in_array;
use function intdiv;
use function is_array;
use function is_resource;
use function is_string;
use function max;
use function min;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use Closure;
use Fiber;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ABI\Resources\Storage;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Operations;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Reader;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Uploads;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Writer;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;


/**
 * HTTP response resource for an S3 disk, inside a deferred request.
 *
 * It speaks to the same bucket, under the same prefix and with the same signatures as the S3
 * Storage driver of that disk — every request is composed by the disk's `S3\Writer` and every
 * answer judged by `S3\Reader` — but it sends them through an `HTTP_Client_CLI` embedded on the
 * worker reactor: each wait parks the deferred Fiber, so the worker keeps answering its other
 * requests while the store answers. Outside a request (a command, a job) the blocking driver is
 * the way; this resource is for a handler.
 *
 * Register it from the disk definition and call it from `defer()`:
 *
 * ```php
 * $Storage = new Storage(['disks' => ['uploads' => ['driver' => 's3', 'bucket' => 'assets', ...]]]);
 *
 * $HTTP_Server_CLI->configure(new HTTP_Server_CLI\Response\Configs(Resources: [
 *    'S3' => S3::provide($Storage, 'uploads')
 * ]));
 *
 * $Response->defer(function (Response $Response) {
 *    $saved = $Response->S3->write('avatars/1.png', $bytes, ['type' => 'image/png']);
 *    $Response->JSON->send(['saved' => $saved, 'code' => $Response->S3->Failure?->code]);
 * });
 * ```
 *
 * Every call fails like the driver's: `false` with the reason on `$error` and the failure
 * (HTTP status, the store's code, kind) on `$Failure`.
 *
 * Bounds: a request body never reaches the client past the worker's in-flight budget (refused by
 * name, never truncated); a write larger than one part goes as a Multipart Upload whose parts
 * travel up to `concurrency` at a time; a read is buffered whole and fails past
 * `maxResponseBytes`; every request is bounded by `connectTimeout` and `timeout`.
 */
class S3 extends Resource implements Scheduling
{
   use Redactable;


   /** The largest answer read for any operation but a read (an S3 XML answer is far smaller). */
   private const int ANSWER = 1024 * 1024;
   /** The most uploads left open by a destroyed request that one worker remembers. */
   private const int ORPHANS = 64;
   /** The most uploads created through `$Uploads` whose headers one worker remembers for their completion. */
   private const int HEADERS = 256;
   /** The most of them one claim aborts: an unrelated request pays for no more. */
   private const int DRAIN = 2;
   /** The aborts one of them gets before the worker forgets it (the bucket's lifecycle rule is the backstop). */
   private const int ATTEMPTS = 3;
   /**
    * Seconds the abort of an upload may take to connect, and to be answered: it runs after a
    * failure or a deferral timeout, often while the store stalls — past it, the upload is left to
    * the next claim.
    */
   private const int ABORT = 3;
   /** Seconds the completion of a Multipart Upload may take at least: the store joins every part before it answers. */
   private const int COMPLETION = 120;
   /**
    * The S3 error codes that refuse an abort for good — a credential, a bucket or a signature the
    * same request would carry again; every other refusal (`RequestTimeTooSkewed`, a throttle, a
    * proxy) is retried.
    */
   private const array FINAL = ['AccessDenied', 'AllAccessDisabled', 'InvalidAccessKeyId', 'InvalidBucketName', 'NoSuchBucket', 'SignatureDoesNotMatch'];

   // * Config
   /** The size of each part of a Multipart Upload, in bytes; a write larger than one part goes multipart. */
   public private(set) int $part;
   /**
    * The worker's in-flight budget: the request-body bytes all S3 resources of the worker may
    * have handed to their clients at once. A write or a part past it is refused by name.
    */
   public private(set) int $budget;
   /** The parts of one Multipart Upload sent at the same time (the client's pool size). */
   public private(set) int $concurrency;
   /** Seconds one request may take from its dispatch to its last answer byte. */
   public private(set) int|float $timeout;
   /** Seconds one connection (and its TLS handshake) may take to open. */
   public private(set) int|float $connectTimeout;
   /** The largest answer `read()` buffers, in bytes, its head included. */
   public private(set) int $maxResponseBytes;

   // * Data
   /** The request composer of the disk — the one source of what is sent. */
   private Writer $Writer;

   // * Metadata
   /** The Multipart Upload handle: `create`, `sign`, `put`, `list`, `complete`, `abort`. */
   public private(set) Uploads $Uploads;
   /** The failure of the last call (the first request that failed in it); null after a success. */
   public private(set) null|Failure $Failure = null;
   /** The reason of the last failure, in words; `''` after a success. */
   public private(set) string $error = '';
   /** The embedded client: its pending requests carry the signed headers. */
   #[Secret]
   private HTTP_Client_CLI $Client;
   // ! The deferred context that owns this resource for one generation
   /** @var null|Fiber<mixed,mixed,mixed,mixed> */
   private null|Fiber $Fiber = null;
   // ! Whether a bridge was refused while a context owned this resource
   private bool $stale = false;
   // ! Public calls entered and not yet left: only the outermost one clears the failure
   private int $depth = 0;
   // ! Budget bytes this instance holds (part of `$inflight`)
   private int $held = 0;
   // ! Budget bytes every S3 resource of the worker holds
   private static int $inflight = 0;
   /**
    * Uploads left open — by a destroyed request, or whose abort failed — `[Writer, key, upload
    * id, aborts tried]`: the next claims of a resource of the same disk abort them.
    *
    * @var array<int,array{0:Writer,1:string,2:string,3:int}>
    */
   private static array $Orphans = [];
   /**
    * The headers `Uploads->create()` sent, by upload id — `[Writer, headers]` — until the upload
    * is completed or aborted through a resource of the same disk in the worker (the oldest go past
    * `HEADERS`): what `Uploads->complete()` checks a lost completion's object against.
    *
    * @var array<string,array{0:Writer,1:array<string,string>}>
    */
   private static array $Headers = [];


   /**
    * @param Writer $Writer The request composer of the S3 disk (built by `provide()`).
    * @param int $part Part size in bytes, 5 MiB..5 GiB and at most `$budget`.
    * @param int $budget The worker's in-flight budget in bytes (shared by every S3 resource of the
    *    worker; each call compares against its own resource's budget).
    * @param int $concurrency Parts of one upload in flight at once (at least 1; never the
    *    budget's last part).
    * @param int|float $timeout Seconds per request, from its dispatch to its last answer byte (> 0).
    * @param int|float $connectTimeout Seconds per connection, TLS included (> 0).
    * @param int $maxResponseBytes The largest answer `read()` buffers, head included (at least 1).
    *
    * @throws InvalidArgumentException When a bound is out of range.
    * @throws RuntimeException When constructed outside the HTTP server reactor.
    */
   public function __construct (
      Writer $Writer,
      int $part = 8 * 1024 * 1024,
      int $budget = 32 * 1024 * 1024,
      int $concurrency = 4,
      int|float $timeout = 30,
      int|float $connectTimeout = 10,
      int $maxResponseBytes = 16 * 1024 * 1024
   )
   {
      parent::__construct();

      self::validate($part, $budget, $concurrency, $timeout, $connectTimeout, $maxResponseBytes);

      // ? The client parks on the worker reactor — outside a server there is nothing to park on
      if (isSet(TCP_Server_CLI::$Event) === false) {
         throw new RuntimeException('S3 response resource requires the HTTP server reactor — construct it from a Response\Configs(Resources:) factory.');
      }

      // * Config
      $this->part = $part;
      $this->budget = $budget;
      $this->concurrency = $concurrency;
      $this->timeout = $timeout;
      $this->connectTimeout = $connectTimeout;
      $this->maxResponseBytes = $maxResponseBytes;

      // * Data
      $this->Writer = $Writer;

      // * Metadata
      // ! HTTP/1.1 only (S3 speaks it; the `Host` sent is the authority signed), no redirect
      //   (a signed request is never re-sent elsewhere) and no retry (a failure is the caller's)
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_EMBEDDED);
      $Client->react(TCP_Server_CLI::$Event);
      $Client->configure(new HTTP_Client_CLI\Configs(
         host: $Writer->host,
         port: $Writer->port,
         secure: $Writer->secure,
         pool: ['min' => 0, 'max' => $concurrency],
         enableHTTP2: false
      ));
      $Client->timeout = $timeout;
      $Client->connectTimeout = $connectTimeout;
      $Client->maxRedirects = 0;
      $Client->maxRetries = 0;
      $Client->pin();
      $this->Client = $Client;

      $this->Uploads = new Uploads(
         Create: $this->open(...),
         Sign: $this->stamp(...),
         List: $this->enumerate(...),
         Complete: $this->close(...),
         Abort: $this->cancel(...),
         Put: $this->put(...)
      );
   }

   /**
    * Provide a lazy factory that builds this resource from an S3 disk of a Storage layer.
    *
    * The disk definition (`'driver' => 's3'`, `root`, options) is read once, here, and its
    * request composer built — a disk that cannot be built fails at registration, not at the
    * first request. The factory keeps only the composer (never the options array, which holds
    * the credentials) and builds a fresh resource for each deferred job.
    *
    * @param Storage $Storage The storage layer whose `Config` defines the disk.
    * @param string $disk The disk name (`''` = the layer's default disk).
    *
    * @return Closure(object):self
    *
    * @throws InvalidArgumentException When the disk is not configured, is not an S3 disk, or a
    *    bound is out of range.
    */
   public static function provide (
      Storage $Storage,
      string $disk = '',
      int $part = 8 * 1024 * 1024,
      int $budget = 32 * 1024 * 1024,
      int $concurrency = 4,
      int|float $timeout = 30,
      int|float $connectTimeout = 10,
      int $maxResponseBytes = 16 * 1024 * 1024
   ): Closure
   {
      self::validate($part, $budget, $concurrency, $timeout, $connectTimeout, $maxResponseBytes);

      $name = $disk !== '' ? $disk : $Storage->Config->default;
      // ?
      if (isSet($Storage->Config->disks[$name]) === false) {
         throw new InvalidArgumentException("Storage disk is not configured: {$name}.");
      }
      $Writer = self::build($Storage->Config->disks[$name], $name);

      // :
      return static function (object $Context) use ($Writer, $part, $budget, $concurrency, $timeout, $connectTimeout, $maxResponseBytes): self {
         // ?
         if ($Context instanceof Response === false) {
            throw new RuntimeException('S3 response resource expects a Response context.');
         }

         // :
         return new self($Writer, $part, $budget, $concurrency, $timeout, $connectTimeout, $maxResponseBytes);
      };
   }

   /**
    * Bind the response wait bridge.
    *
    * A carried instance is re-attached by every Response clone, so a bridge is only accepted
    * while no deferred context owns this resource.
    */
   public function schedule (Closure $Wait): static
   {
      // ? The owner parks on the bridge it claimed under
      if ($this->Fiber !== null) {
         $this->stale = true;

         return $this;
      }

      $this->stale = false;
      $this->Client->schedule($Wait);

      return $this;
   }

   /**
    * Upload an object from a string or a readable local stream (`PutObject`, or a Multipart
    * Upload when it is larger than one part).
    *
    * A multipart write reserves its parts in the worker's in-flight budget before it reads the
    * first one and holds them until its last part answered — then only the completion body,
    * while the store joins the parts —, so it is refused at its start — never halfway; it
    * never takes the budget's last part (when the budget holds two), which a write of one part
    * or less (a stream's first part, until a second shows) may take. Its parts travel up to
    * `concurrency` at a time; the next ones leave when that group answered. A stream must block:
    * an empty read before its end fails the write, never a truncated object.
    *
    * A failed part, a failed completion or a throw on the way (the deferral's `Timeout`) aborts
    * the upload before the call returns or the throw leaves it, within `ABORT` seconds to connect
    * and `ABORT` to be answered. After a throw that lands once the call runs (the deferral's
    * `Timeout`) — whose budget and bodies are given back at once —, `$Failure` says what it left
    * of the object: null only when the store shows it stored (a completion the store took before
    * the throw: one more `HeadObject`, bounded like the abort), else nothing stored or its outcome
    * unknown (a single PUT in flight, or a completion the store did not abort or will not show).
    * An upload whose abort fails, or that a request destroyed while it waits (its client left)
    * opened, is left to the next claims of a resource of this disk in the worker. A completion
    * whose answer is lost is judged by the store: an upload still listed fails and is aborted;
    * else an object at the key that the parts make (`Reader::fold()`), of the written size and
    * headers, is a success; else it fails with its outcome unknown.
    *
    * @param string $path The object path, inside the disk's root.
    * @param mixed $source A string, or a readable stream (a local file: reading it blocks).
    * @param array<string,mixed> $options `type` (Content-Type) and `meta` (a `x-amz-meta-*` map).
    *
    * @throws LogicException When called outside a live deferred context, or while another one owns this resource
    *    — thrown before the call runs: `$Failure` is left as it was.
    */
   public function write (string $path, mixed $source, array $options = []): bool
   {
      $claimed = $this->own();
      $this->enter();
      // ! The upload this call opened and has not closed yet: [key, id]
      $open = null;
      // ! The aborts it had here: one once tried (a destroyed request tries none)
      $tries = 0;
      // ! What a throw cuts: the single PUT in flight, or the completion — `[parts, size, headers]`
      //   — whose outcome the store holds
      $storing = false;
      $completing = null;
      $key = $path;
      try {
         // @ The context's first call aborts the uploads left open: a throw there is this write's
         if ($claimed === true) {
            $this->sweep();
         }
         $key = $this->Writer->locate($path);

         // ?
         if (is_string($source) === false && is_resource($source) === false) {
            return $this->fail("S3 write {$key}: the source is a string or a readable stream");
         }
         $extra = $this->Writer->describe($options);
         if (is_string($extra) === true) {
            return $this->fail("S3 write {$key}: {$extra} in write options");
         }

         // ?: A string of one part at most — a single PUT holding its own size
         if (is_string($source) === true && strlen($source) <= $this->part) {
            if ($this->reserve(strlen($source)) === false) {
               return $this->fail("S3 write {$key}: the worker's in-flight budget ({$this->budget} bytes) cannot take " . strlen($source) . ' more bytes');
            }
            $storing = true;

            return $this->store($key, $source, $extra);
         }

         // ! The slots of the parts: reserved before a byte is read, held until the call ends —
         //   never the budget's last part (when it holds two), so a write of one part or less
         //   always finds room while only large writes run; a stream, whose size shows only as it
         //   is read, may hold one part of the free budget until a second part shows
         $free = max(0, $this->budget - self::$inflight);
         $room = $this->budget >= 2 * $this->part ? $free - $this->part : $free;
         $slots = min($this->concurrency, intdiv(max(0, $room), $this->part));
         $held = $slots > 0 || is_string($source) === true ? $slots : min(1, intdiv($free, $this->part));
         if ($held < 1 || $this->reserve($held * $this->part) === false) {
            return $this->fail("S3 write {$key}: the worker's in-flight budget ({$this->budget} bytes) cannot take one more part");
         }

         // @ The first part, then one more byte: is there a second part?
         $offset = 0;
         $first = $this->take($source, $offset, $this->part);
         $peek = $first !== false && strlen($first) === $this->part ? $this->take($source, $offset, 1) : '';
         if ($first === false || $peek === false) {
            return $this->fail("S3 write {$key}: source stream read error");
         }

         // ?: A stream of one part — a single PUT
         if ($peek === '') {
            $this->refund($this->held - strlen($first));
            $storing = true;

            return $this->store($key, $first, $extra);
         }

         // ? A stream larger than the part it held: a multipart write never takes the last part
         if ($slots < 1) {
            return $this->fail("S3 write {$key}: the worker's in-flight budget ({$this->budget} bytes) cannot take one more part");
         }

         // @ Large object — a Multipart Upload
         $id = $this->initiate($key, $extra);
         if ($id === false) {
            return false;
         }
         $open = [$key, $id];
         // ! The bytes of the object: what the completion's outcome is checked against
         $total = strlen($first);

         $ETags = [];
         $number = 1;
         /** @var array<int,string> $bodies */
         $bodies = [1 => $first];
         // ! The byte read ahead: the start of the second part
         $carry = $peek;
         unset($first);
         // @@ One group of parts per round
         while (true) {
            while (count($bodies) < $slots) {
               $chunk = $this->take($source, $offset, $this->part - strlen($carry));
               if ($chunk === false) {
                  $this->fail('S3 multipart: source stream read error');
                  $open = $this->terminate($open) > 0 ? null : $open;
                  $tries = 1;

                  return false;
               }
               $chunk = "{$carry}{$chunk}";
               $carry = '';
               if ($chunk === '') {
                  break;
               }
               if (++$number > Uploads::PARTS) {
                  $this->fail('S3 multipart: more than ' . Uploads::PARTS . " parts at a part size of {$this->part} bytes");
                  $open = $this->terminate($open) > 0 ? null : $open;
                  $tries = 1;

                  return false;
               }
               $bodies[$number] = $chunk;
               $total += strlen($chunk);
            }
            // ?: Every part sent
            if ($bodies === []) {
               break;
            }

            $Failure = $this->pump($key, $id, $bodies, $ETags);
            $bodies = [];
            if ($Failure !== null) {
               $this->record($Failure);
               $open = $this->terminate($open) > 0 ? null : $open;
               $tries = 1;

               return false;
            }
         }

         // @ Join the parts into the object
         $completion = $this->Writer->join($ETags);
         if ($completion instanceof Failure) {
            $this->record($completion);
            $open = $this->terminate($open) > 0 ? null : $open;
            $tries = 1;

            return false;
         }
         // ! Every part answered: their slots go back, and the completion body — far smaller than
         //   one part, so it always fits what was just given back — is what the write holds while
         //   the store joins the parts
         $this->refund();
         $this->reserve(strlen($completion));
         $completing = [$ETags, $total, $extra];
         $concluded = $this->conclude($key, $id, $completion, $ETags, $total, $extra);
         // ? Not completed, or not shown gone: abort it (harmless to a completed upload) — the
         //   completion stays the one a throw during that abort is judged by
         if ($concluded === false) {
            $open = $this->terminate($open) > 0 ? null : $open;
            $tries = 1;

            return false;
         }
         $completing = null;
         // ! Completed — or its outcome unknown with the upload gone: nothing left to abort
         $open = null;
         if ($concluded === null) {
            return false;
         }

         // :
         return true;
      }
      catch (Throwable $Throwable) {
         // ? A throw while requests were in flight (the deferral's Timeout): nothing stays in
         //   flight and its budget is given back with the bodies it held, then the open upload is
         //   aborted on this Fiber, still live — within the `ABORT` bounds, or left to the next claim
         $this->Client->abort();
         unset($bodies, $chunk, $first);
         $this->refund();
         $aborted = 0;
         try {
            if ($open !== null) {
               $tries = 1;
               $aborted = $this->terminate($open);
               $open = $aborted > 0 ? null : $open;
            }

            // @ What the throw left of the object, on `$Failure`
            $this->settle($key, $storing, $completing, $aborted);
         }
         catch (Throwable) {
            // ? Cut again: the upload is left to the next claim (below), and the outcome is
            //   what the store may already hold
            $this->record(new Failure(0, '', Kinds::Transport, $storing === true || $completing !== null
               ? "S3 write {$key}: cut while the store took the object — its outcome is unknown"
               : "S3 write {$key}: cut before the object was completed — nothing stored"));
         }

         throw $Throwable;
      }
      finally {
         // ? A destroyed request runs no catch, and an abort may fail: the upload is left to the next claim
         if ($open !== null) {
            $this->abandon($open, $tries);
         }
         $this->refund();
         $this->leave();
      }
   }

   /**
    * Download an object, or a window of it (`GetObject`, with a `Range` when a window is given),
    * buffered whole: false when it is missing, past `maxResponseBytes`, or the answer is not that
    * object or that window.
    *
    * @param string $path The object path, inside the disk's root.
    * @param int $offset First byte of the window (0-based).
    * @param null|int $length Size of the window in bytes; null reads to the end of the object.
    *
    * @throws LogicException When called outside a live deferred context, or while another one owns this resource.
    */
   public function read (string $path, int $offset = 0, null|int $length = null): string|false
   {
      if ($this->own() === true) {
         $this->sweep();
      }
      $this->enter();
      try {
         $key = $this->Writer->locate($path);
         $range = $this->Writer->range($offset, $length);
         // ?
         if ($range === null) {
            return $this->fail("S3 GET {$key}: invalid range (offset >= 0, length >= 1)");
         }

         $window = $offset > 0 || $length !== null ? [$offset, $length] : null;
         $extra = $window !== null ? ['Range' => $range] : [];

         [$code, $headers, $body, $status] = $this->send('GET', $key, [], '', $extra, $this->maxResponseBytes);
         // ? No complete answer
         if ($code === 0 && $status !== '') {
            return $this->record(match ($status) {
               'Response Too Large' => new Failure(0, '', Kinds::Protocol, "S3 GET {$key}: the answer is larger than the read limit ({$this->maxResponseBytes} bytes)"),
               'Truncated Response' => new Failure(0, '', Kinds::Transport, 'S3 GET: incomplete response body'),
               default => new Failure(0, '', Kinds::Transport, "S3 GET {$key}: read failed")
            });
         }

         $Failure = Reader::judge(Operations::GetObject, $code, $headers, $body, "S3 GET {$key}", $window);

         // :
         return $Failure === null ? $body : $this->record($Failure);
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Delete an object (`DeleteObject`); idempotent (a missing key is success).
    *
    * @throws LogicException When called outside a live deferred context, or while another one owns this resource.
    */
   public function delete (string $path): bool
   {
      if ($this->own() === true) {
         $this->sweep();
      }
      $this->enter();
      try {
         [$code, $headers, $body] = $this->send('DELETE', $this->Writer->locate($path), [], '', [], self::ANSWER);
         $Failure = Reader::judge(Operations::DeleteObject, $code, $headers, $body, "S3 DELETE {$path}");

         // :
         return $Failure === null ? true : $this->record($Failure);
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Whether an object exists (`HeadObject`).
    *
    * False both when it does not exist and when the store could not tell: a missing key
    * (`404`) leaves no failure, any other answer leaves one.
    *
    * @throws LogicException When called outside a live deferred context, or while another one owns this resource.
    */
   public function check (string $path): bool
   {
      if ($this->own() === true) {
         $this->sweep();
      }
      $this->enter();
      try {
         $key = $this->Writer->locate($path);
         [$code, $headers, $body] = $this->send('HEAD', $key, [], '', [], self::ANSWER);
         $Failure = Reader::judge(Operations::HeadObject, $code, $headers, $body, "S3 HEAD {$key}");
         // ?: It exists — or it does not, which is an answer too
         if ($Failure === null) {
            return true;
         }
         if ($Failure->status === 404) {
            return false;
         }

         // :
         return $this->record($Failure);
      }
      finally {
         $this->leave();
      }
   }

   // # The steps behind the `$Uploads` handle (disk-relative path, validated input)

   /**
    * `Uploads::create()` — start a Multipart Upload for an object.
    *
    * @param array<string,mixed> $options `type` and `meta`, as in `write()`.
    */
   private function open (string $path, array $options): string|false
   {
      if ($this->own() === true) {
         $this->sweep();
      }
      $this->enter();
      try {
         $key = $this->Writer->target($path);
         // ?
         if ($key === null) {
            return $this->fail('S3 create multipart: an object path is required');
         }
         $extra = $this->Writer->describe($options);
         if (is_string($extra) === true) {
            return $this->fail("S3 create multipart {$key}: {$extra} in the options");
         }

         $id = $this->initiate($key, $extra);
         // @ Remember its headers for its completion
         if ($id !== false) {
            unset(self::$Headers[$id]);
            if (count(self::$Headers) >= self::HEADERS) {
               unset(self::$Headers[array_key_first(self::$Headers)]);
            }
            self::$Headers[$id] = [$this->Writer, $extra];
         }

         // :
         return $id;
      }
      finally {
         $this->leave();
      }
   }

   /**
    * `Uploads::sign()` — presign the `PUT` of one part (no request is sent).
    *
    * @param array<array-key,mixed> $headers `Content-Length` and `Content-MD5`, to bind the part.
    */
   private function stamp (string $path, string $id, int $part, int $expires, array $headers = []): string|false
   {
      $this->enter();
      try {
         // ?
         $Refusal = $this->Writer->validate('sign part', $id, $part);
         if ($Refusal !== null) {
            return $this->record($Refusal);
         }
         $bound = $this->Writer->bind($headers, true);
         if ($bound === null) {
            return $this->fail('S3 sign part: a part binds Content-Length and Content-MD5 only, each once, as a string');
         }

         $URL = $this->Writer->presign('PUT', $path, $expires, ['partNumber' => (string) $part, 'uploadId' => $id], $bound);

         // :
         return $URL instanceof Failure ? $this->record($URL) : $URL;
      }
      finally {
         $this->leave();
      }
   }

   /**
    * `Uploads::put()` — upload one part (`UploadPart`) and return its ETag.
    */
   private function put (string $path, string $id, int $part, string $body): string|false
   {
      if ($this->own() === true) {
         $this->sweep();
      }
      $this->enter();
      try {
         // ?
         $Refusal = $this->Writer->validate('upload part', $id, $part);
         if ($Refusal !== null) {
            return $this->record($Refusal);
         }
         $key = $this->Writer->target($path);
         if ($key === null) {
            return $this->fail('S3 upload part: an object path is required');
         }
         if (strlen($body) > Uploads::MAXIMUM) {
            return $this->fail('S3 upload part: a part is at most 5 GiB');
         }
         if ($this->reserve(strlen($body)) === false) {
            return $this->fail("S3 upload part {$part}: the worker's in-flight budget ({$this->budget} bytes) cannot take " . strlen($body) . ' more bytes');
         }

         [$code, $headers, $answer] = $this->send('PUT', $key, [
            'partNumber' => (string) $part,
            'uploadId' => $id,
         ], $body, [], self::ANSWER);
         $Failure = Reader::judge(Operations::UploadPart, $code, $headers, $answer, "S3 upload part {$part}");
         if ($Failure !== null) {
            return $this->record($Failure);
         }
         $ETag = Reader::tag($headers, "S3 upload part {$part}");

         // :
         return $ETag instanceof Failure ? $this->record($ETag) : $ETag;
      }
      finally {
         $this->refund();
         $this->leave();
      }
   }

   /**
    * `Uploads::list()` — every part the store holds for an upload (`ListParts`, all pages).
    *
    * @return array<int,array{etag:string,size:int}>|false
    */
   private function enumerate (string $path, string $id): array|false
   {
      if ($this->own() === true) {
         $this->sweep();
      }
      $this->enter();
      try {
         // ?
         $Refusal = $this->Writer->validate('list parts', $id);
         if ($Refusal !== null) {
            return $this->record($Refusal);
         }
         $key = $this->Writer->target($path);
         if ($key === null) {
            return $this->fail('S3 list parts: an object path is required');
         }

         $parts = [];
         $marker = 0;
         // @@ One page per request
         while (true) {
            $query = ['uploadId' => $id];
            if ($marker > 0) {
               $query['part-number-marker'] = (string) $marker;
            }

            [$code, $headers, $body] = $this->send('GET', $key, $query, '', [], self::ANSWER);
            $Failure = Reader::judge(Operations::ListParts, $code, $headers, $body, "S3 list parts {$key}");
            if ($Failure !== null) {
               return $this->record($Failure);
            }
            $Page = Reader::enumerate($body, $marker, "S3 list parts {$key}");
            if ($Page instanceof Failure) {
               return $this->record($Page);
            }

            [$listed, $next] = $Page;
            foreach ($listed as $number => $Part) {
               $parts[$number] = $Part;
            }

            // ?: The last page
            if ($next === null) {
               break;
            }
            $marker = $next;
         }

         // :
         return $parts;
      }
      finally {
         $this->leave();
      }
   }

   /**
    * `Uploads::complete()` — join the parts into the object. A lost answer is judged like a
    * write's (`conclude()`), against the headers the upload was created with when it was created
    * through a resource of this disk in the worker; else against its parts alone.
    *
    * @param array<int|string,mixed> $parts `[partNumber => ['etag' => ...]]`.
    */
   private function close (string $path, string $id, array $parts): bool
   {
      if ($this->own() === true) {
         $this->sweep();
      }
      $this->enter();
      try {
         // ?
         $Refusal = $this->Writer->validate('complete multipart', $id);
         if ($Refusal !== null) {
            return $this->record($Refusal);
         }
         $completion = $this->Writer->join($parts);
         if ($completion instanceof Failure) {
            return $this->record($completion);
         }
         $key = $this->Writer->target($path);
         if ($key === null) {
            return $this->fail('S3 complete multipart: an object path is required');
         }

         // ! The headers it was created with, when created through a resource of this disk in the
         //   worker: a lost completion's object is checked against them too
         $Known = self::$Headers[$id] ?? null;
         $extra = $Known !== null && $Known[0] === $this->Writer ? $Known[1] : null;

         $completed = $this->conclude($key, $id, $completion, $parts, null, $extra) === true;
         if ($completed === true) {
            unset(self::$Headers[$id]);
         }

         // :
         return $completed;
      }
      finally {
         $this->leave();
      }
   }

   /**
    * `Uploads::abort()` — abort an upload; one the store no longer knows counts as aborted.
    */
   private function cancel (string $path, string $id): bool
   {
      if ($this->own() === true) {
         $this->sweep();
      }
      $this->enter();
      try {
         // ?
         $Refusal = $this->Writer->validate('abort multipart', $id);
         if ($Refusal !== null) {
            return $this->record($Refusal);
         }
         $key = $this->Writer->target($path);
         if ($key === null) {
            return $this->fail('S3 abort multipart: an object path is required');
         }

         [$code, $headers, $body] = $this->send('DELETE', $key, ['uploadId' => $id], '', [], self::ANSWER);
         $Failure = Reader::judge(Operations::AbortMultipartUpload, $code, $headers, $body, "S3 abort multipart {$key}");
         if ($Failure !== null) {
            return $this->record($Failure);
         }
         unset(self::$Headers[$id]);

         // :
         return true;
      }
      finally {
         $this->leave();
      }
   }

   // ---

   /**
    * Refuse a bound out of range.
    *
    * @throws InvalidArgumentException
    */
   private static function validate (
      int $part,
      int $budget,
      int $concurrency,
      int|float $timeout,
      int|float $connectTimeout,
      int $maxResponseBytes
   ): void
   {
      // ?
      if ($part < Uploads::MINIMUM || $part > Uploads::MAXIMUM) {
         throw new InvalidArgumentException('S3 response resource: the part size is 5 MiB..5 GiB.');
      }
      if ($budget < $part) {
         throw new InvalidArgumentException('S3 response resource: the in-flight budget holds at least one part.');
      }
      if ($concurrency < 1) {
         throw new InvalidArgumentException('S3 response resource: the concurrency is at least 1.');
      }
      if ($timeout <= 0 || $connectTimeout <= 0) {
         throw new InvalidArgumentException('S3 response resource: the timeouts are above 0 — a store that never answers must not park a request forever.');
      }
      if ($maxResponseBytes < 1) {
         throw new InvalidArgumentException('S3 response resource: the read limit is at least 1 byte.');
      }
   }

   /**
    * Build the request composer of an S3 disk definition.
    *
    * @param array<string,mixed> $definition Sensitive: the disk's credentials.
    *
    * @throws InvalidArgumentException When the disk is not an S3 disk or cannot be built.
    */
   private static function build (#[SensitiveParameter] array $definition, string $name): Writer
   {
      // ?
      if (($definition['driver'] ?? null) !== 's3') {
         throw new InvalidArgumentException("Storage disk is not an S3 disk: {$name}.");
      }

      $root = is_string($definition['root'] ?? null) ? $definition['root'] : '';
      unset($definition['driver'], $definition['root']);

      // :
      return new Writer($root, $definition);
   }

   /**
    * Claim this resource for the running deferred context: true when this call made the claim
    * (the context's first), whose caller then aborts the uploads left open (`sweep()`).
    */
   private function own (): bool
   {
      $Fiber = Fiber::getCurrent();

      // ? A parked wait needs a deferred Fiber to park
      if ($Fiber === null) {
         throw new LogicException('S3 response resource must be used inside a live deferred context — call it from defer(), before handing off to SSE or a nested defer().');
      }

      // ?: Already claimed by this context
      if ($this->Fiber === $Fiber) {
         return false;
      }

      // ? One context at a time
      if ($this->Fiber !== null) {
         throw new LogicException('S3 response resource is owned by another deferred context.');
      }
      // ? A context whose attach was refused while another owned this resource
      if ($this->stale) {
         throw new LogicException('S3 response resource was attached to another response while owned — a carried instance cannot serve interleaved deferred contexts; register it as a Response\Configs(Resources:) factory instead.');
      }

      // ! The generation token proves a live deferred context, and fires on finish and cancel
      $Token = Cancellation::fetch($Fiber);
      if ($Token === null || $Token->check()) {
         throw new LogicException('S3 response resource must be used inside a live deferred context — call it from defer(), before handing off to SSE or a nested defer().');
      }

      $this->Fiber = $Fiber;
      $Token->observe(function (Cancellation $Observed, bool $cancelled): void {
         $this->release();
      });

      // :
      return true;
   }

   /**
    * Abort up to `DRAIN` uploads of this disk left open — by a destroyed request, or whose abort
    * failed; one whose abort fails or is cut goes back to the list. Run by a context's first
    * call; inside `write()`'s `try`, so a throw cutting it there is the write's.
    */
   private function sweep (): void
   {
      $Orphans = [];
      foreach (self::$Orphans as $index => $Orphan) {
         if (count($Orphans) >= self::DRAIN) {
            break;
         }
         if ($Orphan[0] === $this->Writer) {
            $Orphans[] = $Orphan;
            unset(self::$Orphans[$index]);
         }
      }
      try {
         while ($Orphans !== []) {
            // ! Counted before it is sent: a cut abort is a try too
            $Orphans[0][3]++;
            [, $key, $id, $tries] = $Orphans[0];
            $settled = $this->terminate([$key, $id]) > 0;
            array_shift($Orphans);
            if ($settled === false) {
               $this->abandon([$key, $id], $tries);
            }
         }
      }
      finally {
         foreach ($Orphans as [, $key, $id, $tries]) {
            $this->abandon([$key, $id], $tries);
         }
      }
   }

   /**
    * Release the deferred context: its budget first (nothing below can keep it), then every
    * connection its client still holds.
    */
   private function release (): void
   {
      $this->refund();
      $this->Fiber = null;
      $this->depth = 0;

      $this->Client->abort();
      // @ The generation has settled and its Fiber is never resumed: retire its episode notifier
      $this->Client->unpark();
   }

   /**
    * Enter a public call: the outermost one clears the failure of the previous call.
    */
   private function enter (): void
   {
      if ($this->depth++ === 0) {
         $this->Failure = null;
         $this->error = '';
      }
   }

   /**
    * Leave a public call (always — a Throwable included — from a `finally`).
    */
   private function leave (): void
   {
      $this->depth = max(0, $this->depth - 1);
   }

   /**
    * Remember an upload left open, for the next claims of this disk — until it had `ATTEMPTS`
    * aborts.
    *
    * @param array{0:string,1:string} $open `[key, upload id]`.
    * @param int $tries The aborts it already had.
    */
   private function abandon (array $open, int $tries = 0): void
   {
      // ? Tried enough, or past the bound: the bucket's lifecycle rule is what aborts it
      if ($tries >= self::ATTEMPTS) {
         return;
      }
      if (count(self::$Orphans) >= self::ORPHANS) {
         array_shift(self::$Orphans);
      }

      self::$Orphans[] = [$this->Writer, $open[0], $open[1], $tries];
   }

   /**
    * Record a failed request: its message becomes `$error`, and it becomes the call's
    * `$Failure` unless an earlier request of the same call already failed.
    */
   private function record (Failure $Failure): false
   {
      $this->error = $Failure->message;
      $this->Failure ??= $Failure;

      // :
      return false;
   }

   /**
    * Record a refusal of the resource itself (an input it does not send) and return false.
    */
   private function fail (string $message): false
   {
      // :
      return $this->record(new Failure(0, '', Kinds::Refusal, $message));
   }

   /**
    * Take bytes of the worker's in-flight budget; false when they do not fit.
    */
   private function reserve (int $bytes): bool
   {
      // ?
      if (self::$inflight + $bytes > $this->budget) {
         return false;
      }

      self::$inflight += $bytes;
      $this->held += $bytes;

      // :
      return true;
   }

   /**
    * Give back budget bytes this instance holds (all of them by default); never more than it holds.
    */
   private function refund (null|int $bytes = null): void
   {
      $bytes = $bytes === null ? $this->held : max(0, min($bytes, $this->held));

      $this->held -= $bytes;
      self::$inflight = max(0, self::$inflight - $bytes);
   }

   /**
    * Read up to `$size` bytes of a source: a string from `$offset` on, or a stream; `''` at its
    * end, false on a read error.
    */
   private function take (mixed $source, int &$offset, int $size): string|false
   {
      // ?: A string
      if (is_string($source) === true) {
         $chunk = (string) substr($source, $offset, $size);
         $offset += strlen($chunk);

         return $chunk;
      }
      // ?
      if (is_resource($source) === false || $size < 1) {
         return $size < 1 ? '' : false;
      }

      $chunk = '';
      // @@ Until the size or the end of the stream
      while (($left = $size - strlen($chunk)) > 0) {
         $read = @fread($source, $left);
         if ($read === false) {
            return false;
         }
         // ? An empty read is the end only at EOF: a stream that has nothing yet (non-blocking, a
         //   timed-out socket) would upload a truncated object
         if ($read === '') {
            if (feof($source) === true) {
               break;
            }

            return false;
         }
         $chunk .= $read;
      }

      // :
      return $chunk;
   }

   /**
    * Compose one request: `[URI, headers]`, `Host` included — the disk's request composer is the
    * one source of the canonical request and its signature.
    *
    * @param array<string,string> $query
    * @param array<string,string> $extra
    * @param null|int $time Unix time of the signature (now when null).
    *
    * @return array{0:string,1:array<string,string>}
    *
    * @throws InvalidArgumentException When the request cannot be put in canonical form.
    */
   private function sign (string $method, string $path, array $query, string $body, array $extra, null|int $time = null): array
   {
      // :
      return $this->Writer->compose($method, $path, $query, $body, $extra, $time);
   }

   /**
    * Send one request and park until its answer: `[code, headers, body, status]` — code 0 with
    * the client's status when no complete answer arrived.
    *
    * @param array<string,string> $query
    * @param array<string,string> $extra
    * @param int $limit The largest answer read, head included.
    *
    * @return array{0:int,1:array<string,string|array<int,string>>,2:string,3:string}
    */
   private function send (string $method, string $path, array $query, string $body, array $extra, int $limit): array
   {
      try {
         [$URI, $headers] = $this->sign($method, $path, $query, $body, $extra);
      }
      catch (InvalidArgumentException) {
         // ? Never sent — a refusal, not a network failure
         $this->record(new Failure(0, '', Kinds::Refusal, "S3 {$method} {$path}: the request cannot be signed (check the bucket and the endpoint)"));

         return [0, [], '', ''];
      }

      $this->Client->maxResponseBytes = $limit;
      $Answer = $this->transmit($method, $URI, $headers, $body);

      // :
      return [$Answer->code, $Answer->headers, $Answer->body, $Answer->status];
   }

   /**
    * Hand one signed request to the client (batched or parked).
    *
    * @param array<string,string> $headers The signed headers.
    */
   private function transmit (string $method, string $URI, #[SensitiveParameter] array $headers, string $body): HTTP_Client_CLI\Request\Response
   {
      $Answer = $this->Client->request($method, $URI, $headers, $body);

      // ? Event-driven mode is barred under reactor adoption
      if ($Answer instanceof HTTP_Client_CLI\Request\Response === false) {
         throw new LogicException('S3 response resource client left synchronous mode.');
      }

      // :
      return $Answer;
   }

   /**
    * Send one group of parts at once and park until all answered; the first failure (in part
    * order), or null with their ETags added.
    *
    * @param array<int,string> $bodies Part bodies by part number.
    * @param array<int,array{etag:string}> $ETags
    */
   private function pump (string $key, string $id, array $bodies, array &$ETags): null|Failure
   {
      /** @var array<int,HTTP_Client_CLI\Request\Response> $Answers */
      $Answers = [];

      $this->Client->maxResponseBytes = self::ANSWER;
      $this->Client->batch();
      foreach ($bodies as $number => $body) {
         try {
            [$URI, $headers] = $this->sign('PUT', $key, ['partNumber' => (string) $number, 'uploadId' => $id], $body, []);
         }
         catch (InvalidArgumentException) {
            $this->Client->abort();

            return new Failure(0, '', Kinds::Refusal, "S3 PUT {$key}: the request cannot be signed (check the bucket and the endpoint)");
         }
         $Answers[$number] = $this->transmit('PUT', $URI, $headers, $body);
      }
      $this->Client->drain();

      foreach ($Answers as $number => $Answer) {
         // ? No answer: the reason the client gave, in the driver's words
         if ($Answer->code === 0) {
            return match ($Answer->status) {
               'Connection Failed' => new Failure(0, '', Kinds::Transport, "S3 upload part {$number}: connect failed"),
               'Timeout' => new Failure(0, '', Kinds::Transport, 'S3 multipart: socket timeout'),
               'Response Too Large' => new Failure(0, '', Kinds::Protocol, "S3 upload part {$number}: the answer is too large to be an S3 answer"),
               default => new Failure(0, '', Kinds::Transport, "S3 upload part {$number}: HTTP 0")
            };
         }
         $Failure = Reader::judge(Operations::UploadPart, $Answer->code, $Answer->headers, $Answer->body, "S3 upload part {$number}");
         $ETag = $Failure === null ? Reader::tag($Answer->headers, "S3 upload part {$number}") : $Failure;
         if ($ETag instanceof Failure) {
            return $ETag;
         }
         $ETags[$number] = ['etag' => $ETag];
      }

      // :
      return null;
   }

   /**
    * Upload a whole object in one request (`PutObject`); its budget is already held.
    *
    * @param array<string,string> $extra
    */
   private function store (string $key, string $body, array $extra): bool
   {
      [$code, $headers, $answer] = $this->send('PUT', $key, [], $body, $extra, self::ANSWER);
      $Failure = Reader::judge(Operations::PutObject, $code, $headers, $answer, "S3 PUT {$key}");

      // :
      return $Failure === null ? true : $this->record($Failure);
   }

   /**
    * Create a multipart upload and return its id; false with the reason recorded.
    *
    * @param array<string,string> $extra
    */
   private function initiate (string $key, array $extra): string|false
   {
      [$code, $headers, $body] = $this->send('POST', $key, ['uploads' => ''], '', $extra, self::ANSWER);
      $Failure = Reader::judge(Operations::CreateMultipartUpload, $code, $headers, $body, "S3 create multipart {$key}");
      if ($Failure !== null) {
         return $this->record($Failure);
      }
      $id = Reader::identify($body, "S3 create multipart {$key}");

      // :
      return $id instanceof Failure ? $this->record($id) : $id;
   }

   /**
    * Complete a multipart upload from its CompleteMultipartUpload body: true when completed;
    * false with the reason recorded when it was not, or when the store will not say whether the
    * upload is still open (the upload is left to the caller's abort — harmless to a completed
    * one); null with the reason recorded when the upload is gone and the object at the key cannot
    * be shown to be the one these parts make — nothing is left to abort.
    *
    * The completion waits at least `COMPLETION` seconds: the store joins every part before it
    * answers. A lost answer — or a `NoSuchUpload`, what a replay of a completion the store handled
    * gets — is judged by the store: an upload still listed was not completed; else the object at
    * the key is the completed one when its ETag is the one the parts fold into (`Reader::fold()`),
    * its size, when known, is `$total` and its headers, when known, are `$extra`.
    *
    * @param array<int|string,mixed> $parts The parts, by part number (`[number => ['etag' => ...]]`).
    * @param null|array<string,string> $extra The headers the upload was created with.
    */
   private function conclude (string $key, string $id, string $completion, array $parts, null|int $total = null, null|array $extra = null): null|bool
   {
      $timeout = $this->Client->timeout;
      $this->Client->timeout = max($this->timeout, self::COMPLETION);
      try {
         [$code, $headers, $body, $status] = $this->send('POST', $key, ['uploadId' => $id], $completion, [
            'Content-Type' => 'application/xml',
         ], self::ANSWER);
      }
      finally {
         $this->Client->timeout = $timeout;
      }

      // ? An answer: the store's word — but for a `NoSuchUpload`, which a completion handled
      //   before gets as well (a replay of a lost answer)
      $Lost = null;
      if ($code !== 0 || $status === '') {
         $Failure = Reader::judge(Operations::CompleteMultipartUpload, $code, $headers, $body, "S3 complete multipart {$key}");
         if ($Failure === null) {
            return true;
         }
         if ($Failure->status !== 404 || $Failure->code !== 'NoSuchUpload') {
            return $this->record($Failure);
         }
         $Lost = $Failure;
      }
      $gone = $Lost !== null;
      $Lost ??= new Failure(0, '', Kinds::Transport, $status === 'Timeout' ? 'S3 multipart: socket timeout' : "S3 complete multipart {$key}: HTTP 0");
      $Unknown = new Failure(0, '', Kinds::Transport, "S3 complete multipart {$key}: the completion's outcome is unknown — the object at the key is not shown to be the one written");

      // @ No answer: the upload still listed was not completed
      $open = false;
      if ($gone === false) {
         [$code, $fields, $body] = $this->send('GET', $key, ['uploadId' => $id], '', [], self::ANSWER);
         $Listed = Reader::judge(Operations::ListParts, $code, $fields, $body, "S3 list parts {$key}");
         if ($Listed === null) {
            return $this->record($Lost);
         }
         // ! Refused: whether it is still open is unknown
         $open = $Listed->status !== 404 || $Listed->code !== 'NoSuchUpload';
      }

      // ?: The object at the key is the one these parts make
      if ($this->confirm($key, $parts, $total, $extra) === true) {
         return true;
      }

      $this->record($Lost);
      $this->record($Unknown);

      // :
      return $open === true ? false : null;
   }

   /**
    * Whether the object at the key is the one these parts make (`HeadObject`): its ETag is the
    * one they fold into (`Reader::fold()`), its size, when known, is `$total` and its headers,
    * when known, are `$extra`. False when the store shows it differs or is missing (`404`); null
    * when the store will not show it (no answer, or a refusal).
    *
    * @param array<int|string,mixed> $parts The parts, by part number (`[number => ['etag' => ...]]`).
    * @param null|array<string,string> $extra The headers the upload was created with.
    */
   private function confirm (string $key, array $parts, null|int $total = null, null|array $extra = null): null|bool
   {
      $expected = Reader::fold($parts);
      [$code, $fields, $body] = $this->send('HEAD', $key, [], '', [], self::ANSWER);
      $Failure = Reader::judge(Operations::HeadObject, $code, $fields, $body, "S3 HEAD {$key}");
      // ?: Not shown
      if ($Failure !== null) {
         return $Failure->status === 404 ? false : null;
      }
      $matched = $this->pick($fields, 'etag') !== null
         && strtolower(trim((string) $this->pick($fields, 'etag'))) === $expected
         && ($total === null || $this->pick($fields, 'content-length') === (string) $total);
      foreach ($extra ?? [] as $name => $value) {
         $matched = $matched && $this->pick($fields, strtolower($name)) === $value;
      }

      // :
      return $matched;
   }

   /**
    * Record what a throw out of `write()` left of the object: no failure when the store shows it
    * stored — a completion the store took before the throw; one the completion recorded is
    * cleared —, else a failure saying nothing was
    * stored (no completion was sent, or the store aborted the upload and shows no such object:
    * missing or another one) or that the outcome is unknown (a single PUT in flight, or a
    * completion the store did not abort or will not show). The store is asked within `ABORT`
    * seconds to connect and `ABORT` to be answered.
    *
    * @param bool $storing Whether a single PUT was in flight.
    * @param null|array{0:array<int|string,mixed>,1:int,2:array<string,string>} $completing The
    *    completion in flight: `[parts, size, headers]`.
    * @param int $aborted The status of the answer that settled the upload's abort; 0 when none did.
    */
   private function settle (string $key, bool $storing, null|array $completing, int $aborted): void
   {
      // ?: No completion was sent
      if ($completing === null) {
         $this->record(new Failure(0, '', Kinds::Transport, $storing === true
            ? "S3 write {$key}: cut while the store took the object — its outcome is unknown"
            : "S3 write {$key}: cut before the object was completed — nothing stored"));

         return;
      }

      // @ Is the object the completion makes at the key?
      [$parts, $total, $extra] = $completing;
      $timeout = $this->Client->timeout;
      $connectTimeout = $this->Client->connectTimeout;
      $this->Client->timeout = min($this->timeout, self::ABORT);
      $this->Client->connectTimeout = min($this->connectTimeout, self::ABORT);
      try {
         $stored = $this->confirm($key, $parts, $total, $extra);
      }
      finally {
         $this->Client->timeout = $timeout;
         $this->Client->connectTimeout = $connectTimeout;
      }
      // ?: Stored — the throw cut only the store's answer: what the completion recorded before
      //   (a lost answer, an unknown outcome) is settled
      if ($stored === true) {
         $this->Failure = null;
         $this->error = '';

         return;
      }

      // :
      $this->record(new Failure(0, '', Kinds::Transport, ($aborted === 200 || $aborted === 204) && $stored === false
         ? "S3 complete multipart {$key}: cut before the store answered — the upload was aborted, nothing stored"
         : "S3 complete multipart {$key}: cut before the store answered — the completion's outcome is unknown"));
   }

   /**
    * One answer header's first value, or null when it is missing.
    *
    * @param array<string,mixed> $fields Answer headers, by lowercase name.
    */
   private function pick (array $fields, string $name): null|string
   {
      $value = $fields[$name] ?? null;
      $value = is_array($value) === true ? ($value[0] ?? null) : $value;

      // :
      return is_string($value) === true ? $value : null;
   }

   /**
    * Abort an upload a write opened, with nothing of it in flight, within `ABORT` seconds to
    * connect and `ABORT` to be answered; the status of the answer when it is settled — the store
    * aborted it (`200`, `204`), says it is gone (`404`), or refuses the abort for good (`FINAL`:
    * the same request would be refused again, and the bucket's lifecycle rule is the backstop) —,
    * else 0. Records nothing (the failure that led here is the call's).
    *
    * @param array{0:string,1:string} $open `[key, upload id]`.
    */
   private function terminate (array $open): int
   {
      [$URI, $headers] = $this->sign('DELETE', $open[0], ['uploadId' => $open[1]], '', []);

      $timeout = $this->Client->timeout;
      $connectTimeout = $this->Client->connectTimeout;
      $this->Client->timeout = min($this->timeout, self::ABORT);
      $this->Client->connectTimeout = min($this->connectTimeout, self::ABORT);
      $this->Client->maxResponseBytes = self::ANSWER;
      try {
         $Answer = $this->transmit('DELETE', $URI, $headers, '');
      }
      finally {
         $this->Client->timeout = $timeout;
         $this->Client->connectTimeout = $connectTimeout;
      }

      $Failure = Reader::judge(Operations::AbortMultipartUpload, $Answer->code, $Answer->headers, $Answer->body, "S3 abort multipart {$open[0]}");

      // :
      return $Failure === null || in_array($Failure->code, self::FINAL, true) ? $Answer->code : 0;
   }
}
