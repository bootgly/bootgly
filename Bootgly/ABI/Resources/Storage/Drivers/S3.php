<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage\Drivers;


use const STREAM_CLIENT_CONNECT;
use function array_map;
use function array_shift;
use function count;
use function explode;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function hexdec;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function ksort;
use function max;
use function preg_match;
use function rtrim;
use function stream_context_create;
use function stream_select;
use function stream_set_timeout;
use function stream_socket_client;
use function stripos;
use function strlen;
use function strpos;
use function strtolower;
use function strtotime;
use function substr;
use function trim;
use Closure;
use Fiber;
use InvalidArgumentException;
use SensitiveParameter;
use WeakMap;

use Bootgly\ABI\Resources\Storage\Driver;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Operations;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Reader;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Uploads;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Writer;


/**
 * Amazon S3 (and S3-compatible) storage driver.
 *
 * Speaks the S3 REST API over a blocking TLS/TCP socket — no SDK, no event
 * loop — with AWS Signature V4 computed from core `hash`/`hash_hmac`. The
 * blocking transport mirrors the Cache Redis driver, so it works the same in
 * scripts, the test runner and async workers. Supports AWS plus S3-compatible
 * services (MinIO, Cloudflare R2, Wasabi) via a custom `endpoint` and
 * `path_style` addressing.
 *
 * Besides the driver contract it offers the direct transfers of S3: `presign()` (an
 * expiring URL someone else uses with no credentials), the `$Uploads` handle (a Multipart
 * Upload whose parts go straight to the bucket, or that the server sends itself) and a
 * ranged `read()`.
 *
 * What it sends is composed by `S3\Writer` and what it receives is read by `S3\Reader`, the
 * transport-free pieces another client can share. Every call that fails leaves its `Failure`
 * (status, the store's code, kind) on `$Failure`, kept per Fiber: requests that share the disk
 * each read their own.
 */
class S3 extends Driver
{
   private const int TIMEOUT = 30;
   private const int PART = 16 * 1024 * 1024;   // 16 MiB (S3 min part = 5 MiB; max 10000 parts)
   private const int CONCURRENCY = 4;           // multipart parts uploaded in parallel
   private const int ANSWER = 32 * 1024 * 1024; // a buffered (XML) answer never legitimately gets near this
   private const int ERROR = 64 * 1024;         // an S3 error document is a few hundred bytes
   private const int HEAD = 64 * 1024;          // the status line and headers of one answer
   private const int MAXIMUM = 5 * 1024 * 1024 * 1024; // the largest part S3 accepts (5 GiB)
   private const int CHUNK = 1024 * 1024;       // a request body is written in slices this large
   /** The response overrides a presigned read may carry (GetObject `response-*`). */
   private const array OVERRIDES = [
      'response-cache-control',
      'response-content-disposition',
      'response-content-encoding',
      'response-content-language',
      'response-content-type',
      'response-expires',
   ];

   // * Metadata
   /** The Multipart Upload handle: `create`, `sign`, `put`, `list`, `complete`, `abort`. */
   public private(set) Uploads $Uploads;
   /**
    * The failure of the last call made in the current Fiber (the main context outside any
    * Fiber); null when that call succeeded.
    *
    * Each outermost call clears it on entry, so it belongs to the call that just returned —
    * even when other requests use the disk in between — and a Fiber reused for another job
    * starts clean at its first call. A call that the driver makes inside another (`move()` →
    * `copy()`, `clear()` → `delete()`, a multipart upload → its abort) never clears it: the first
    * request that failed is the one it names. So does a call made on this disk from inside one of
    * its own calls (from a stream it reads or writes): it belongs to that call. Code that runs
    * after a call and may call the disk again — the Storage event listeners — runs through
    * `preserve()`, which gives the call its failure back.
    */
   public null|Failure $Failure {
      get {
         $Fiber = Fiber::getCurrent();

         // :
         return $Fiber === null
            ? $this->call[1]
            : ($this->Calls[$Fiber][1] ?? null);
      }
   }
   /** The bucket (a snapshot of the disk options: changing it changes no request). */
   protected string $bucket;
   /** The signing region (a snapshot). */
   protected string $region;
   protected string $accessKey;
   protected string $secretKey;
   protected string $token;
   /** The signing service (a snapshot). */
   protected string $service;
   /** `https` or `http` (a snapshot). */
   protected string $scheme;
   /** The host connected to (a snapshot). */
   protected string $host;
   /** The port connected to (a snapshot). */
   protected int $port;
   /** Whether addressing is path-style (a snapshot). */
   protected bool $pathStyle;
   /** Whether TLS peers are verified (a snapshot). */
   protected bool $verify;
   /** The key prefix (a snapshot). */
   protected string $prefix;
   /** The `Host` value (a snapshot). */
   protected string $authority;
   /** The request composer of the disk — the one source of what is sent. */
   private Writer $Writer;
   /**
    * The call slot of each Fiber that used the disk: `[depth, failure]`.
    *
    * @var WeakMap<object,array{0:int,1:null|Failure}> Keys are the Fibers.
    */
   private WeakMap $Calls;
   /**
    * The call slot of the main context (no Fiber): `[depth, failure]`.
    *
    * @var array{0:int,1:null|Failure}
    */
   private array $call = [0, null];


   /**
    * Build the S3 driver from a disk's `root` (key prefix) and `options`
    * (bucket, region, key, secret, optional endpoint/path_style/token/service).
    *
    * @param array<string,mixed> $options
    *
    * @throws InvalidArgumentException For an insecure transport that was not opted into, or a
    *    key, region, service or token holding a space or a control character.
    */
   public function __construct (string $root, #[SensitiveParameter] array $options = [])
   {
      parent::__construct($root, $options);

      // * Metadata
      $this->Writer = new Writer($root, $options);
      $this->bucket = $this->Writer->bucket;
      $this->region = $this->Writer->region;
      $this->accessKey = $this->pick('key');
      $this->secretKey = $this->pick('secret');
      $this->token = $this->pick('token');
      $this->service = $this->Writer->service;
      $this->scheme = $this->Writer->scheme;
      $this->host = $this->Writer->host;
      $this->port = $this->Writer->port;
      $this->pathStyle = $this->Writer->pathStyle;
      $this->verify = $this->Writer->verify;
      $this->prefix = $this->Writer->prefix;
      $this->authority = $this->Writer->authority;
      $this->Calls = new WeakMap;

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
    * What a dump of the disk shows: where it points, never its credentials (nor the writer
    * and the handle, which hold them).
    *
    * Written by hand on purpose, instead of `Redactable`: it lists only the safe fields, so a
    * credential a later change adds stays out of the dump without a `#[Secret]` mark.
    *
    * @return array<string,string>
    */
   public function __debugInfo (): array
   {
      // :
      return [
         'bucket' => $this->bucket,
         'region' => $this->region,
         'endpoint' => "{$this->scheme}://{$this->authority}",
         'prefix' => $this->prefix,
         'error' => $this->error,
      ];
   }

   /**
    * Upload to a key from a readable stream (single PUT, or Multipart for large objects).
    *
    * @param resource $source
    * @param array<string,mixed> $options `type` (Content-Type) and `meta` (a `x-amz-meta-*` map).
    */
   public function write (string $path, $source, array $options = []): bool
   {
      $this->enter();
      try {
         $this->error = '';
         $key = $this->Writer->locate($path);

         // ! Content-Type + user metadata (x-amz-meta-* are signed by call())
         $extra = $this->Writer->describe($options);
         // ? Reject CRLF in user-supplied options — never let them inject HTTP headers
         if (is_string($extra) === true) {
            return $this->fail("S3 write {$key}: {$extra} in write options");
         }

         // @ Probe the stream: read one part, then a second to detect "more than one part"
         $part1 = $this->gather($source, self::PART);
         $part2 = $this->gather($source, self::PART);
         // ? A source read error must not upload a truncated object
         if ($part1 === false || $part2 === false) {
            return $this->fail("S3 write {$key}: source stream read error");
         }

         // ?: Small object — a single PUT (body bounded by one part)
         if ($part2 === '') {
            [$code, $headers, $body] = $this->call('PUT', $key, [], $part1, $extra);
            $Failure = Reader::judge(Operations::PutObject, $code, $headers, $body, "S3 PUT {$key}");

            // :
            return $Failure === null ? true : $this->record($Failure);
         }

         // : Large object — Multipart Upload (peak memory ≈ one part)
         return $this->upload($key, $source, $part1, $part2, $extra);
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Download a key into a writable stream (streamed GET); false when missing.
    *
    * With `$offset` and/or `$length` it downloads only that window of the object (a `Range`
    * request): `$length` bytes starting at `$offset`, or everything from `$offset` on when
    * `$length` is null. A window that ends past the object is cut at its end; one that
    * starts past it is false. The sink never receives a byte outside the window, and
    * nothing at all from an answer that is not the object or that window (another range,
    * an error body — an error is read for its code, never written). A body cut short in
    * transit fails after its first bytes were written: on false, discard what the sink
    * received.
    *
    * @param resource $sink
    * @param int $offset First byte of the window (0-based).
    * @param null|int $length Size of the window in bytes; null reads to the end of the object.
    */
   public function read (string $path, $sink, int $offset = 0, null|int $length = null): bool
   {
      $this->enter();
      try {
         $key = $this->Writer->locate($path);
         $range = $this->Writer->range($offset, $length);
         // ? A window that cannot be expressed as a byte range
         if ($range === null) {
            return $this->fail("S3 GET {$key}: invalid range (offset >= 0, length >= 1)");
         }

         // ! The whole object by default (no Range header: the request is what it always was)
         $window = $offset > 0 || $length !== null ? [$offset, $length] : null;
         $extra = $window !== null ? ['Range' => $range] : [];

         // ! Generic reason up front; drain() refines it (truncation), success clears it
         $generic = "S3 GET {$key}: read failed";
         $this->error = $generic;
         [$code, $headers, $body] = $this->call('GET', $key, [], '', $extra, $sink, $window);
         // ? No answer, or one cut short: a precise reason recorded on the way wins
         if ($code === 0) {
            return $this->error !== $generic
               ? false
               : $this->record(new Failure(0, '', Kinds::Transport, $generic));
         }

         $Failure = Reader::judge(Operations::GetObject, $code, $headers, $body, "S3 GET {$key}", $window);
         // ?: Success — 200 for the whole object, 206 for a window
         if ($Failure === null) {
            $this->error = '';

            return true;
         }

         // :
         return $this->record($Failure);
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Presign one request for an object and return its absolute URL; false when the request
    * is refused (the reason is on `$error`).
    *
    * Whoever holds the URL can send that request — this method on this object — until it
    * expires, with no credentials: treat it as a secret and never log it. The payload is
    * never signed and, with no `$headers`, neither is anything but the `Host`: on a `PUT`
    * the holder chooses the body, its size and its Content-Type. `$headers` binds them —
    * the upload must then carry each header with exactly that value, or the store refuses
    * it: `Content-Length` fixes the size, `Content-MD5` the bytes, `Content-Type` the type
    * and `x-amz-meta-*` the metadata. For a large or resumable upload, use the `$Uploads`
    * handle.
    *
    * The path goes through the same root prefix as every other operation, and the root
    * itself is never presigned. Deleting, listing and the multipart steps are not offered
    * here.
    *
    * @param string $method `GET`, `HEAD` or `PUT`.
    * @param int $expires Lifetime of the URL in seconds, 1..604800 (7 days).
    * @param array<array-key,mixed> $query Response overrides of a `GET`/`HEAD`, each a string:
    *    `response-content-disposition`, `response-content-type`, `response-cache-control`,
    *    `response-content-encoding`, `response-content-language`, `response-expires`.
    *    Anything else — another name, a value that is not a string — refuses the request.
    * @param array<array-key,mixed> $headers What a `PUT` must send, each once, as a string (or
    *    an integer): `Content-Type`, `Content-Length` (bytes), `Content-MD5` (the base64 of
    *    the body's MD5) and `x-amz-meta-*`. Anything else — another header, a `GET` or a
    *    `HEAD`, an empty value — refuses the request.
    */
   public function presign (string $method, string $path, int $expires, array $query = [], array $headers = []): string|false
   {
      $this->enter();
      try {
         $this->error = '';
         // ? A presigned URL authorizes reading or writing one object, nothing else
         if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'PUT') {
            return $this->fail('S3 presign: the method is GET, HEAD or PUT');
         }
         // ? Any other parameter could select another operation on the object (its ACL, its
         //   tags, a multipart step) or carry an `x-amz-*` header in the query
         $overrides = [];
         foreach ($query as $name => $value) {
            if (
               $method === 'PUT'
               || in_array($name, self::OVERRIDES, true) === false
               || is_string($value) === false
               || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            ) {
               return $this->fail('S3 presign: only the response-* overrides of a GET or HEAD are accepted as query');
            }

            $overrides[(string) $name] = $value;
         }
         // ? Only an upload is bound; any other header could turn it into another operation
         //   (`x-amz-acl`, `x-amz-copy-source`)
         $bound = $method === 'PUT' || $headers === [] ? $this->Writer->bind($headers, false) : null;
         if ($bound === null) {
            return $this->fail('S3 presign: a PUT binds Content-Type, Content-Length, Content-MD5 and x-amz-meta-* only, each once, as a string');
         }

         $URL = $this->Writer->presign($method, $path, $expires, $overrides, $bound);

         // :
         return $URL instanceof Failure ? $this->record($URL) : $URL;
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Delete a key (DELETE object); idempotent (a missing key is success).
    */
   public function delete (string $path): bool
   {
      $this->enter();
      try {
         $this->error = '';
         [$code, $headers, $body] = $this->call('DELETE', $this->Writer->locate($path), [], '', []);
         $Failure = Reader::judge(Operations::DeleteObject, $code, $headers, $body, "S3 DELETE {$path}");

         // :
         return $Failure === null ? true : $this->record($Failure);
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Whether a key exists (HEAD object).
    *
    * False both when it does not exist and when the store could not tell: a missing key
    * (`404`) leaves no failure, any other answer leaves one (a `403`, a dropped connection).
    */
   public function check (string $path): bool
   {
      $this->enter();
      try {
         $this->error = '';
         $key = $this->Writer->locate($path);
         [$code, $headers, $body] = $this->call('HEAD', $key, [], '', []);
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

   /**
    * List keys under a prefix (ListObjectsV2, all pages), returned disk-relative.
    *
    * A page that fails, is not a listing or does not advance ends the listing with the
    * reason on `$error`: the keys returned are then the ones read so far, never the whole
    * prefix — check `$error` before treating the result as complete.
    *
    * @return array<int,string>
    */
   public function list (string $path = '', bool $recursive = false): array
   {
      $this->enter();
      try {
         $this->error = '';
         $prefix = $this->Writer->resolve($path);
         if ($prefix !== '') {
            $prefix = rtrim($prefix, '/') . '/';
         }

         $keys = [];
         $token = '';
         $followed = [];

         // @@ Page through ListObjectsV2 until the bucket stops truncating
         while (true) {
            $query = ['list-type' => '2', 'prefix' => $prefix];
            if ($recursive === false) {
               $query['delimiter'] = '/';
            }
            if ($token !== '') {
               $query['continuation-token'] = $token;
            }

            [$code, $headers, $body] = $this->call('GET', $this->Writer->base, $query, '', []);
            // ? A failed page must be observable (callers/clear() fail closed on $error)
            $Failure = Reader::judge(Operations::ListObjectsV2, $code, $headers, $body, "S3 list {$prefix}");
            if ($Failure !== null) {
               $this->record($Failure);

               break;
            }
            $Page = Reader::index($body, $followed, "S3 list {$prefix}");
            if ($Page instanceof Failure) {
               $this->record($Page);

               break;
            }

            [$found, $next] = $Page;
            foreach ($found as $stored) {
               $keys[] = $this->Writer->relativize($stored);
            }

            // ?: The last page — or a token that does not advance (recorded)
            if ($next instanceof Failure) {
               $this->record($next);

               break;
            }
            if ($next === '') {
               break;
            }
            $token = $next;
            $followed[$token] = true;
         }

         // :
         return $keys;
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Copy a key to another (PUT + x-amz-copy-source).
    *
    * False — with the reason on `$error` — when the store refuses the copy, including a copy
    * it reports failed inside a `200` answer.
    */
   public function copy (string $from, string $to): bool
   {
      $this->enter();
      try {
         $this->error = '';
         $target = $this->Writer->locate($to);
         [$code, $headers, $body] = $this->call('PUT', $target, [], '', [
            'x-amz-copy-source' => $this->Writer->source($from),
         ]);
         $Failure = Reader::judge(Operations::CopyObject, $code, $headers, $body, "S3 COPY {$target}");

         // :
         return $Failure === null ? true : $this->record($Failure);
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Move a key (copy then delete the source); a failed copy keeps the source.
    */
   public function move (string $from, string $to): bool
   {
      $this->enter();
      try {
         // ?
         if ($this->copy($from, $to) === false) {
            return false;
         }

         // :
         return $this->delete($from);
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Object size in bytes (HEAD `Content-Length`); false when missing.
    */
   public function measure (string $path): int|false
   {
      $this->enter();
      try {
         $this->error = '';
         $key = $this->Writer->locate($path);
         [$code, $headers, $body] = $this->call('HEAD', $key, [], '', []);
         $Failure = Reader::judge(Operations::HeadObject, $code, $headers, $body, "S3 HEAD {$key}");
         // ?
         if ($Failure !== null) {
            return $this->record($Failure);
         }

         $length = $this->extract($headers, 'content-length');

         // :
         return $length !== null
            ? (int) $length
            : $this->record(new Failure($code, '', Kinds::Protocol, "S3 HEAD {$key}: no Content-Length"));
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Object metadata (HEAD): `['size' => bytes, 'modified' => Unix mtime]`; false when missing.
    *
    * @return array{size:int,modified:int}|false
    */
   public function inspect (string $path): array|false
   {
      $this->enter();
      try {
         $this->error = '';
         $key = $this->Writer->locate($path);
         [$code, $headers, $body] = $this->call('HEAD', $key, [], '', []);
         $Failure = Reader::judge(Operations::HeadObject, $code, $headers, $body, "S3 HEAD {$key}");
         // ?
         if ($Failure !== null) {
            return $this->record($Failure);
         }

         $length = $this->extract($headers, 'content-length');
         $modified = $this->extract($headers, 'last-modified');

         // :
         return [
            'size' => $length !== null ? (int) $length : 0,
            'modified' => $modified !== null ? (int) strtotime($modified) : 0,
         ];
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Create a directory — a no-op, since S3 prefixes are implicit.
    */
   public function make (string $path): bool
   {
      $this->enter();
      try {
         // :
         return true;
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Remove every key under a prefix (list + per-key delete).
    *
    * `$Failure` names the first request that failed (a page of the listing, or the first
    * delete refused); `$error` says the clear failed.
    */
   public function clear (string $path = ''): bool
   {
      $this->enter();
      try {
         $keys = $this->list($path, true);
         // ? A failed/partial listing must not be reported as a successful clear
         if ($this->error !== '') {
            return false;
         }

         $cleared = true;
         foreach ($keys as $key) {
            if ($this->delete($key) === false) {
               $cleared = false;
            }
         }
         if ($cleared === false) {
            $this->error = "S3 clear {$path}: one or more deletes failed";
         }

         // :
         return $cleared;
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Run `$Work` and give the disk back the outcome of the call made before it: `$error` and
    * `$Failure` read after `$Work` what they read before, whatever the calls inside it left (the
    * Storage facade runs its event listeners here). `$Failure` is restored in the current Fiber
    * only; `$error` is shared by every Fiber (see `Driver::preserve()`). When `$Work` itself runs
    * inside a call of this disk (from a stream that call reads or writes), the failures of the
    * calls inside it belong to that call, as any call made from there.
    *
    * @param Closure(): mixed $Work
    */
   public function preserve (Closure $Work): void
   {
      $Fiber = Fiber::getCurrent();
      $Failure = $this->Failure;

      // @
      try {
         parent::preserve($Work);
      }
      finally {
         if ($Fiber === null) {
            $this->call[1] = $Failure;
         }
         else {
            $Slot = $this->Calls[$Fiber] ?? [0, null];
            $this->Calls[$Fiber] = [$Slot[0], $Failure];
         }
      }
   }

   // ---

   /**
    * Record a refusal of the driver itself (an input it does not send) and return false.
    */
   protected function fail (string $message): false
   {
      // :
      return $this->record(new Failure(0, '', Kinds::Refusal, $message));
   }

   /**
    * Enter a public call: the outermost one of the current Fiber clears its failure.
    */
   private function enter (): void
   {
      $Fiber = Fiber::getCurrent();

      // ?: The main context
      if ($Fiber === null) {
         if ($this->call[0] === 0) {
            $this->call[1] = null;
         }
         $this->call[0]++;

         return;
      }

      // @
      $Slot = $this->Calls[$Fiber] ?? [0, null];
      $this->Calls[$Fiber] = $Slot[0] === 0 ? [1, null] : [$Slot[0] + 1, $Slot[1]];
   }

   /**
    * Leave a public call (always — a Throwable included — from a `finally`).
    */
   private function leave (): void
   {
      $Fiber = Fiber::getCurrent();

      // @
      if ($Fiber === null) {
         $this->call[0]--;

         return;
      }
      $Slot = $this->Calls[$Fiber] ?? null;
      if ($Slot !== null) {
         $this->Calls[$Fiber] = [$Slot[0] - 1, $Slot[1]];
      }
   }

   /**
    * Record a failed request: its message becomes `$error`, and it becomes the call's
    * `$Failure` unless an earlier request of the same outermost call already failed.
    */
   private function record (Failure $Failure): false
   {
      $this->error = $Failure->message;

      $Fiber = Fiber::getCurrent();
      // @
      if ($Fiber === null) {
         $this->call[1] ??= $Failure;
      }
      else {
         $Slot = $this->Calls[$Fiber] ?? [0, null];
         $Slot[1] ??= $Failure;
         $this->Calls[$Fiber] = $Slot;
      }

      // :
      return false;
   }

   /**
    * Read one string option, falling back to a default.
    */
   private function pick (string $name, string $default = ''): string
   {
      $value = $this->options[$name] ?? null;

      // :
      return is_string($value) ? $value : $default;
   }

   /**
    * Read one response header (case-insensitive; first value when repeated).
    *
    * @param array<string,string|array<int,string>> $headers
    */
   private function extract (array $headers, string $name): null|string
   {
      $value = $headers[$name] ?? null;
      // ?
      if ($value === null) {
         return null;
      }

      // :
      return is_array($value) ? ($value[0] ?? null) : $value;
   }

   /**
    * Sign (SigV4) and send one S3 request; returns `[code, headers, body]`.
    *
    * @param array<string,string> $query
    * @param array<string,string> $extra Extra (unsigned) headers to send.
    * @param resource|null $sink Stream the response body into this sink instead of buffering.
    * @param null|array{0:int,1:null|int} $window The `[offset, length]` a ranged read asked for.
    *
    * @return array{0:int,1:array<string,string|array<int,string>>,2:string}
    */
   private function call (string $method, string $path, array $query, string $body, array $extra, $sink = null, null|array $window = null): array
   {
      try {
         [$URI, $headers] = $this->sign($method, $path, $query, $body, $extra);
      }
      catch (InvalidArgumentException) {
         // ? A request that cannot be put in canonical form (a bucket or a header name with a
         //   blank, a control character) is never sent — a refusal, not a network failure
         $this->record(new Failure(0, '', Kinds::Refusal, "S3 {$method} {$path}: the request cannot be signed (check the bucket and the endpoint)"));

         return [0, [], ''];
      }

      // :
      return $this->send($method, $URI, $headers, $body, $sink, $window);
   }

   /**
    * Compute the SigV4-signed request line and headers; returns `[uri, headers]` (the `Host`
    * is written by the transport).
    *
    * @param array<string,string> $query
    * @param array<string,string> $extra Extra headers — signed when `x-amz-*` or `Range`.
    * @param null|int $time Unix timestamp for the signature date (defaults to now; set for tests).
    *
    * @throws InvalidArgumentException When the request cannot be put in canonical form.
    *
    * @return array{0:string,1:array<string,string>}
    */
   private function sign (string $method, string $path, array $query, string $body, array $extra, null|int $time = null): array
   {
      [$URI, $headers] = $this->Writer->compose($method, $path, $query, $body, $extra, $time);
      unset($headers['Host']);

      // :
      return [$URI, $headers];
   }

   /**
    * Connect to the disk's endpoint (TLS for `https`); false when the connection fails.
    *
    * @return resource|false
    */
   private function connect ()
   {
      $secure = $this->Writer->secure;
      $context = stream_context_create($secure !== null ? ['ssl' => $secure] : []);

      $socket = @stream_socket_client(
         ($secure !== null ? 'tls' : 'tcp') . "://{$this->Writer->host}:{$this->Writer->port}",
         $errno,
         $error,
         self::TIMEOUT,
         STREAM_CLIENT_CONNECT,
         $context
      );
      // ?
      if ($socket === false) {
         return false;
      }
      stream_set_timeout($socket, self::TIMEOUT);

      // :
      return $socket;
   }

   /**
    * Write one request (Connection: close): the head, then the body in slices — the body is
    * never copied into the request, so a part costs its size once, not twice.
    *
    * @param resource $socket
    * @param array<string,string> $headers Sensitive: the signed `Authorization` and the session token.
    */
   private function transmit ($socket, string $method, string $URI, #[SensitiveParameter] array $headers, string $body): bool
   {
      $head = "{$method} {$URI} HTTP/1.1\r\n";
      $head .= "Host: {$this->Writer->authority}\r\n";
      foreach ($headers as $name => $value) {
         $head .= "{$name}: {$value}\r\n";
      }
      $head .= 'Content-Length: ' . strlen($body) . "\r\n";
      $head .= "Connection: close\r\n\r\n";

      // @@ The head, then the body (guard against partial writes)
      foreach ([$head, $body] as $data) {
         $total = strlen($data);
         $sent = 0;
         while ($sent < $total) {
            $written = @fwrite($socket, substr($data, $sent, self::CHUNK));
            if ($written === false || $written === 0) {
               return false;
            }
            $sent += $written;
         }
      }

      // :
      return true;
   }

   /**
    * Send one HTTP/1.1 request over a blocking TLS/TCP socket; `[code, headers, body]`.
    *
    * @param array<string,string> $headers Sensitive: the signed `Authorization` and the session token.
    * @param resource|null $sink Stream the response body into this sink instead of buffering.
    * @param null|array{0:int,1:null|int} $window The `[offset, length]` a ranged read asked for.
    *
    * @return array{0:int,1:array<string,string|array<int,string>>,2:string}
    */
   private function send (string $method, string $URI, #[SensitiveParameter] array $headers, string $body, $sink = null, null|array $window = null): array
   {
      $socket = $this->connect();
      // ?
      if ($socket === false) {
         return [0, [], ''];
      }

      // @ Write the full request
      if ($this->transmit($socket, $method, $URI, $headers, $body) === false) {
         @fclose($socket);

         return [0, [], ''];
      }

      // ?: Stream the response body into the sink (constant memory)
      if ($sink !== null) {
         return $this->drain($socket, $sink, $window);
      }

      // @ Buffer the whole response, then parse
      $response = '';
      while (feof($socket) === false) {
         $chunk = @fread($socket, 8192);
         if ($chunk === false) {
            break;
         }
         $response .= $chunk;
         // ? An answer this large is no S3 XML document: stop buffering it
         if (strlen($response) > self::ANSWER) {
            @fclose($socket);
            [$code] = $this->scan(explode("\r\n\r\n", $response, 2)[0]);
            $path = explode('?', $URI, 2)[0];
            $this->record(new Failure($code, '', Kinds::Protocol, "S3 {$method} {$path}: the answer is too large to be an S3 answer"));

            return [0, [], ''];
         }
      }
      @fclose($socket);

      // :
      return $this->parse($response);
   }

   /**
    * Parse a raw HTTP response into `[code, headers, body]` (de-chunking if needed).
    *
    * @return array{0:int,1:array<string,string|array<int,string>>,2:string}
    */
   private function parse (string $response): array
   {
      $split = explode("\r\n\r\n", $response, 2);
      [$code, $headers] = $this->scan($split[0]);
      $body = $split[1] ?? '';

      // ? De-chunk a chunked transfer-encoded body
      $encoding = $headers['transfer-encoding'] ?? '';
      $encoding = is_array($encoding) ? implode(',', $encoding) : $encoding;
      if (stripos($encoding, 'chunked') !== false) {
         $body = $this->dechunk($body);
      }

      // :
      return [$code, $headers, $body];
   }

   /**
    * Scan an HTTP response head (status line + headers) into `[code, headers]`.
    *
    * @return array{0:int,1:array<string,string|array<int,string>>}
    */
   private function scan (string $head): array
   {
      $lines = explode("\r\n", $head);
      $statusLine = (string) array_shift($lines);
      $code = preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $matches) === 1 ? (int) $matches[1] : 0;

      /** @var array<string,string|array<int,string>> $headers */
      $headers = [];
      foreach ($lines as $line) {
         $pos = strpos($line, ':');
         if ($pos === false) {
            continue;
         }
         $name = strtolower(trim(substr($line, 0, $pos)));
         $value = trim(substr($line, $pos + 1));

         if (isset($headers[$name]) === true) {
            $existing = $headers[$name];
            $headers[$name] = is_array($existing) ? [...$existing, $value] : [$existing, $value];
         }
         else {
            $headers[$name] = $value;
         }
      }

      // :
      return [$code, $headers];
   }

   /**
    * Decode an HTTP chunked transfer-encoded body.
    */
   private function dechunk (string $body): string
   {
      $output = '';
      $offset = 0;
      $length = strlen($body);

      while ($offset < $length) {
         $EOL = strpos($body, "\r\n", $offset);
         if ($EOL === false) {
            break;
         }

         // @ Chunk size (hex), ignoring any chunk extensions after ';'
         $sizeLine = substr($body, $offset, $EOL - $offset);
         $sizeHex = trim(explode(';', $sizeLine)[0]);
         $size = (int) hexdec($sizeHex);
         if ($size <= 0) {
            break;
         }

         $offset = $EOL + 2;
         $output .= substr($body, $offset, $size);
         $offset += $size + 2;
      }

      // :
      return $output;
   }

   /**
    * Read up to `$size` bytes from a stream (handling short reads); false on a read error.
    *
    * @param resource $source
    */
   private function gather ($source, int $size): string|false
   {
      $buffer = '';
      while (($need = $size - strlen($buffer)) > 0) {
         $chunk = fread($source, $need);
         // ? Distinguish a source read error from a clean EOF (never upload a truncated part)
         if ($chunk === false) {
            return false;
         }
         if ($chunk === '') {
            if (feof($source) === true) {
               break;
            }

            return false;
         }
         $buffer .= $chunk;
      }

      // :
      return $buffer;
   }

   /**
    * Upload a large object via S3 Multipart Upload — the first two parts are
    * already buffered; the rest is streamed from `$source`. Aborts on failure.
    *
    * @param resource $source
    * @param array<string,string> $extra Create-request headers (Content-Type + x-amz-meta-*).
    */
   private function upload (string $key, $source, string $part1, string $part2, array $extra): bool
   {
      // ! Create the multipart upload (carries Content-Type / metadata onto the final object)
      $uploadId = $this->initiate($key, $extra);
      if ($uploadId === false) {
         return false;
      }

      // @ Upload the parts concurrently (pipelined sockets); ETags keyed by part number
      $etags = $this->pump($key, $source, $uploadId, $part1, $part2);
      if ($etags === false) {
         // ? pump() already recorded the reason
         $this->abort($key, $uploadId);

         return false;
      }

      // ! Complete the multipart upload
      $completion = $this->Writer->join(array_map(static fn (string $etag): array => ['etag' => $etag], $etags));
      if ($completion instanceof Failure) {
         $this->record($completion);
         $this->abort($key, $uploadId);

         return false;
      }
      if ($this->conclude($key, $uploadId, $completion) === false) {
         // ? conclude() already recorded the reason
         $this->abort($key, $uploadId);

         return false;
      }

      // :
      return true;
   }

   /**
    * Create a multipart upload and return its upload id; false with the reason on `$error`.
    *
    * @param array<string,string> $extra Create-request headers (Content-Type + x-amz-meta-*).
    */
   private function initiate (string $key, array $extra): string|false
   {
      [$code, $headers, $body] = $this->call('POST', $key, ['uploads' => ''], '', $extra);
      $Failure = Reader::judge(Operations::CreateMultipartUpload, $code, $headers, $body, "S3 create multipart {$key}");
      if ($Failure !== null) {
         return $this->record($Failure);
      }
      $uploadId = Reader::identify($body, "S3 create multipart {$key}");

      // :
      return $uploadId instanceof Failure ? $this->record($uploadId) : $uploadId;
   }

   /**
    * Complete a multipart upload from its CompleteMultipartUpload body (`Writer::join()`);
    * false with the reason on `$error`. The upload stays open on failure: the caller decides.
    */
   private function conclude (string $key, string $uploadId, string $completion): bool
   {
      [$code, $headers, $body] = $this->call('POST', $key, ['uploadId' => $uploadId], $completion, [
         'Content-Type' => 'application/xml',
      ]);
      // ? S3 can return 200 with an <Error> body on Complete
      $Failure = Reader::judge(Operations::CompleteMultipartUpload, $code, $headers, $body, "S3 complete multipart {$key}");

      // :
      return $Failure === null ? true : $this->record($Failure);
   }

   /**
    * Abort a multipart upload, releasing its uploaded parts; returns the answer
    * `[code, headers, body]` (code 0 when the store gave no answer) and records nothing.
    *
    * @return array{0:int,1:array<string,string|array<int,string>>,2:string}
    */
   private function abort (string $key, string $uploadId): array
   {
      // :
      return $this->call('DELETE', $key, ['uploadId' => $uploadId], '', []);
   }

   // # The steps behind the public `$Uploads` handle (disk-relative path, validated input)

   /**
    * `Uploads::create()` — start a Multipart Upload for an object.
    *
    * @param array<string,mixed> $options `type` and `meta`, as in `write()`.
    */
   private function open (string $path, array $options): string|false
   {
      $this->enter();
      try {
         $this->error = '';
         $key = $this->Writer->target($path);
         // ?
         if ($key === null) {
            return $this->fail('S3 create multipart: an object path is required');
         }
         $extra = $this->Writer->describe($options);
         if (is_string($extra) === true) {
            return $this->fail("S3 create multipart {$key}: {$extra} in the options");
         }

         // :
         return $this->initiate($key, $extra);
      }
      finally {
         $this->leave();
      }
   }

   /**
    * `Uploads::sign()` — presign the `PUT` of one part.
    *
    * @param array<array-key,mixed> $headers `Content-Length` and `Content-MD5`, to bind the part.
    */
   private function stamp (string $path, string $id, int $part, int $expires, array $headers = []): string|false
   {
      $this->enter();
      try {
         $this->error = '';
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
    * `Uploads::put()` — upload one part (UploadPart) and return its ETag.
    */
   private function put (string $path, string $id, int $part, string $body): string|false
   {
      $this->enter();
      try {
         $this->error = '';
         // ?
         $Refusal = $this->Writer->validate('upload part', $id, $part);
         if ($Refusal !== null) {
            return $this->record($Refusal);
         }
         $key = $this->Writer->target($path);
         if ($key === null) {
            return $this->fail('S3 upload part: an object path is required');
         }
         if (strlen($body) > self::MAXIMUM) {
            return $this->fail('S3 upload part: a part is at most 5 GiB');
         }

         [$code, $headers, $answer] = $this->call('PUT', $key, [
            'partNumber' => (string) $part,
            'uploadId' => $id,
         ], $body, []);
         $Failure = Reader::judge(Operations::UploadPart, $code, $headers, $answer, "S3 upload part {$part}");
         if ($Failure !== null) {
            return $this->record($Failure);
         }
         $ETag = Reader::tag($headers, "S3 upload part {$part}");

         // :
         return $ETag instanceof Failure ? $this->record($ETag) : $ETag;
      }
      finally {
         $this->leave();
      }
   }

   /**
    * `Uploads::list()` — every part the store holds for an upload (ListParts, all pages),
    * keyed by part number. Any page that fails, is malformed or does not advance fails the
    * whole listing: a partial list would complete an object with a hole in it.
    *
    * @return array<int,array{etag:string,size:int}>|false
    */
   private function enumerate (string $path, string $id): array|false
   {
      $this->enter();
      try {
         $this->error = '';
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
         // @@ One page per request (up to 1,000 parts each)
         while (true) {
            $query = ['uploadId' => $id];
            if ($marker > 0) {
               $query['part-number-marker'] = (string) $marker;
            }

            [$code, $headers, $body] = $this->call('GET', $key, $query, '', []);
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
    * `Uploads::complete()` — join the parts into the object.
    *
    * @param array<int|string,mixed> $parts `[partNumber => ['etag' => ...]]`.
    */
   private function close (string $path, string $id, array $parts): bool
   {
      $this->enter();
      try {
         $this->error = '';
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

         // :
         return $this->conclude($key, $id, $completion);
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
      $this->enter();
      try {
         $this->error = '';
         // ?
         $Refusal = $this->Writer->validate('abort multipart', $id);
         if ($Refusal !== null) {
            return $this->record($Refusal);
         }
         $key = $this->Writer->target($path);
         if ($key === null) {
            return $this->fail('S3 abort multipart: an object path is required');
         }

         [$code, $headers, $body] = $this->abort($key, $id);
         $Failure = Reader::judge(Operations::AbortMultipartUpload, $code, $headers, $body, "S3 abort multipart {$key}");

         // :
         return $Failure === null ? true : $this->record($Failure);
      }
      finally {
         $this->leave();
      }
   }

   /**
    * Pump multipart parts concurrently over pipelined sockets, preserving
    * order. Returns the `[partNumber => ETag]` map, or false on any failure.
    *
    * @param resource $source
    *
    * @return array<int,string>|false
    */
   private function pump (string $key, $source, string $uploadId, string $part1, string $part2): array|false
   {
      $etags = [];
      $queue = [$part1, $part2];   // the two probed parts, then stream the rest
      $number = 0;
      $eof = false;
      $slotId = 0;
      /** @var array<int,resource> $socks */
      $socks = [];
      /** @var array<int,int> $nums */
      $nums = [];
      /** @var array<int,string> $bufs */
      $bufs = [];

      while (true) {
         // ! Fill the pipeline up to CONCURRENCY
         while (count($socks) < self::CONCURRENCY) {
            if ($queue !== []) {
               $chunk = (string) array_shift($queue);
            }
            elseif ($eof === false) {
               $chunk = $this->gather($source, self::PART);
               // ? Source read error mid-stream — never complete a truncated upload
               if ($chunk === false) {
                  $this->reap($socks);

                  return $this->fail('S3 multipart: source stream read error');
               }
               if ($chunk === '') {
                  $eof = true;
                  break;
               }
            }
            else {
               break;
            }

            $number++;
            $socket = $this->dispatch('PUT', $key, [
               'partNumber' => (string) $number,
               'uploadId' => $uploadId,
            ], $chunk);
            if ($socket === false) {
               $this->reap($socks);

               return $this->record(new Failure(0, '', Kinds::Transport, "S3 upload part {$number}: connect failed"));
            }
            $socks[$slotId] = $socket;
            $nums[$slotId] = $number;
            $bufs[$slotId] = '';
            $slotId++;
         }

         // ?: Pipeline drained and stream exhausted
         if ($socks === []) {
            break;
         }

         // @ Wait for any in-flight socket to become readable (stream_select keeps keys)
         $read = $socks;
         $write = null;
         $except = null;
         // ? false = select error; 0 = timeout, no socket readable (stuck/half-open peer → don't spin)
         $ready = @stream_select($read, $write, $except, self::TIMEOUT);
         if ($ready === false || $ready === 0) {
            $this->reap($socks);

            return $this->record(new Failure(0, '', Kinds::Transport, 'S3 multipart: socket timeout'));
         }

         // @@ Drain ready sockets; an EOF (Connection: close) means the response is complete
         foreach ($read as $id => $socket) {
            $chunk = @fread($socket, 65536);
            if ($chunk !== false && $chunk !== '') {
               $bufs[$id] .= $chunk;
               // ? An answer this large is no S3 answer to a part
               if (strlen($bufs[$id]) > self::ANSWER) {
                  $this->reap($socks);

                  return $this->record(new Failure(0, '', Kinds::Protocol, "S3 upload part {$nums[$id]}: the answer is too large to be an S3 answer"));
               }

               continue;
            }

            @fclose($socket);
            [$code, $headers, $body] = $this->parse($bufs[$id]);
            $n = $nums[$id];
            unset($socks[$id], $nums[$id], $bufs[$id]);
            $Failure = Reader::judge(Operations::UploadPart, $code, $headers, $body, "S3 upload part {$n}");
            $ETag = $Failure === null ? Reader::tag($headers, "S3 upload part {$n}") : $Failure;
            if ($ETag instanceof Failure) {
               $this->reap($socks);

               return $this->record($ETag);
            }
            $etags[$n] = $ETag;
         }
      }

      ksort($etags);

      // :
      return $etags;
   }

   /**
    * Sign + connect + write one request (Connection: close), leaving the
    * response unread for pipelined draining. Returns the socket, or false.
    *
    * @param array<string,string> $query
    *
    * @return resource|false
    */
   private function dispatch (string $method, string $path, array $query, string $body)
   {
      try {
         [$uri, $headers] = $this->sign($method, $path, $query, $body, []);
      }
      catch (InvalidArgumentException) {
         // ? See call(): a request that cannot be signed is never sent
         $this->record(new Failure(0, '', Kinds::Refusal, "S3 {$method} {$path}: the request cannot be signed (check the bucket and the endpoint)"));

         return false;
      }

      $socket = $this->connect();
      // ?
      if ($socket === false) {
         return false;
      }
      if ($this->transmit($socket, $method, $uri, $headers, $body) === false) {
         @fclose($socket);

         return false;
      }

      // :
      return $socket;
   }

   /**
    * Close every still-in-flight socket (failure cleanup).
    *
    * @param array<int,resource> $socks
    */
   private function reap (array $socks): void
   {
      foreach ($socks as $socket) {
         @fclose($socket);
      }
   }

   /**
    * Read a streamed HTTP response: parse the head, then pump the body into the sink.
    *
    * Only the success of the read reaches the sink: a `200` for the whole object, or — with a
    * `$window` (a ranged read) — a `206` whose `Content-Range` is that window, never more bytes
    * than it announces. An error answer (`>= 300`) is buffered for its `<Code>` (up to 64 KiB,
    * past that only its status is kept); any other answer is left unread. A body that ends
    * early fails with its first bytes already in the sink.
    *
    * @param resource $socket
    * @param resource $sink
    * @param null|array{0:int,1:null|int} $window The `[offset, length]` a ranged read asked for.
    *
    * @return array{0:int,1:array<string,string|array<int,string>>,2:string}
    */
   private function drain ($socket, $sink, null|array $window = null): array
   {
      // @ Read until the end of the headers (searched in the new bytes only, bounded)
      $buffer = '';
      $found = false;
      while (feof($socket) === false && strlen($buffer) <= self::HEAD) {
         $chunk = @fread($socket, 8192);
         if ($chunk === false || $chunk === '') {
            break;
         }
         $from = max(0, strlen($buffer) - 3);
         $buffer .= $chunk;
         if (strpos($buffer, "\r\n\r\n", $from) !== false) {
            $found = true;
            break;
         }
      }
      // ?
      if ($found === false) {
         @fclose($socket);
         // ? A head that never ends is no S3 answer
         if (strlen($buffer) > self::HEAD) {
            $this->record(new Failure(0, '', Kinds::Protocol, 'S3 GET: the answer head is too large to be an S3 answer'));
         }

         return [0, [], ''];
      }

      $pos = (int) strpos($buffer, "\r\n\r\n");
      [$code, $headers] = $this->scan(substr($buffer, 0, $pos));
      $leftover = substr($buffer, $pos + 4);

      $encoding = $headers['transfer-encoding'] ?? '';
      $encoding = is_array($encoding) ? implode(',', $encoding) : $encoding;
      $chunked = stripos($encoding, 'chunked') !== false;

      // ?: An error answer: buffered for its code, never written to the sink
      if ($code >= 300) {
         $body = $leftover;
         while (strlen($body) <= self::ERROR && feof($socket) === false) {
            $chunk = @fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $body .= $chunk;
         }
         @fclose($socket);
         // ! Past the cap the status stays, the body goes
         $body = strlen($body) > self::ERROR ? '' : ($chunked === true ? $this->dechunk($body) : $body);

         return [$code, $headers, $body];
      }
      // ?: Not the success of this read (the whole object to a ranged read, another 2xx): the
      //    caller reports the status and the sink stays untouched
      if ($code !== ($window === null ? 200 : 206)) {
         @fclose($socket);

         return [$code, $headers, ''];
      }

      // # A ranged read: how many bytes the body must hold (-1: not a ranged read)
      $span = -1;
      if ($window !== null) {
         $span = Reader::frame($headers, $window);
         // ? Another window than the one asked for
         if ($span === 0) {
            @fclose($socket);
            $this->record(new Failure($code, '', Kinds::Protocol, 'S3 GET: the answer is not the requested range'));

            return [0, $headers, ''];
         }
      }

      // # Body: de-chunk on the fly, else copy by Content-Length (or until EOF)
      if ($chunked === true) {
         $complete = $this->siphon($socket, $leftover, $sink, $span);
      }
      else {
         $length = $headers['content-length'] ?? null;
         $length = is_array($length) ? ($length[0] ?? null) : $length;
         $remaining = $length !== null ? (int) $length : -1;
         $complete = true;
         if ($span !== -1) {
            // ? A length that disagrees with the announced window
            if ($remaining !== -1 && $remaining !== $span) {
               @fclose($socket);
               $this->record(new Failure($code, '', Kinds::Protocol, 'S3 GET: the answer is not the requested range'));

               return [0, $headers, ''];
            }
            // ! Exactly the window: a longer body is cut, a shorter one is incomplete
            $remaining = $span;
         }

         if ($leftover !== '') {
            if ($remaining > 0 && strlen($leftover) > $remaining) {
               $leftover = substr($leftover, 0, $remaining);
            }
            if (fwrite($sink, $leftover) === false) {
               $complete = false;
            }
            if ($remaining > 0) {
               $remaining -= strlen($leftover);
            }
         }
         while ($complete === true && ($remaining === -1 || $remaining > 0) && feof($socket) === false) {
            $chunk = @fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            if ($remaining > 0 && strlen($chunk) > $remaining) {
               $chunk = substr($chunk, 0, $remaining);
            }
            if (fwrite($sink, $chunk) === false) {
               $complete = false;
               break;
            }
            if ($remaining > 0) {
               $remaining -= strlen($chunk);
            }
         }
         // ? Connection closed before the declared Content-Length arrived
         if ($remaining > 0) {
            $complete = false;
         }
      }
      @fclose($socket);

      // ? A truncated body or a failed sink write must not look like success
      if ($complete === false) {
         $this->record(new Failure($code, '', Kinds::Transport, 'S3 GET: incomplete response body'));

         return [0, $headers, ''];
      }

      // :
      return [$code, $headers, ''];
   }

   /**
    * Stream-decode a chunked transfer-encoded body from the socket into the sink.
    * Returns true only when the terminal zero-size chunk is reached (a complete body) —
    * and, with a `$span`, only when the body held exactly that many bytes.
    *
    * @param resource $socket
    * @param resource $sink
    * @param int $span The exact body size a ranged read expects; -1 for any size.
    */
   private function siphon ($socket, string $buffer, $sink, int $span = -1): bool
   {
      $written = 0;

      while (true) {
         // ! Ensure a full chunk-size line is buffered
         while (strpos($buffer, "\r\n") === false && feof($socket) === false) {
            $chunk = @fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $buffer .= $chunk;
         }
         $EOL = strpos($buffer, "\r\n");
         // ? No chunk-size line left → connection ended mid-body (truncated)
         if ($EOL === false) {
            return false;
         }

         // @ Chunk size (hex), ignoring any extensions after ';'
         $size = (int) hexdec(trim(explode(';', substr($buffer, 0, $EOL))[0]));
         $buffer = substr($buffer, $EOL + 2);
         // ?: Terminal zero-size chunk — the body is complete
         if ($size <= 0) {
            return $span === -1 || $written === $span;
         }
         // ? More than the window a ranged read announced: nothing past it reaches the sink
         if ($span !== -1 && $written + $size > $span) {
            return false;
         }
         $written += $size;

         // ! Ensure the chunk data (+ trailing CRLF) is buffered
         while (strlen($buffer) < $size + 2 && feof($socket) === false) {
            $chunk = @fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $buffer .= $chunk;
         }
         // ? Socket closed before the full chunk arrived → truncated
         if (strlen($buffer) < $size) {
            fwrite($sink, $buffer);

            return false;
         }
         if (fwrite($sink, substr($buffer, 0, $size)) === false) {
            return false;
         }
         $buffer = substr($buffer, $size + 2);
      }
   }
}
