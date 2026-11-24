<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage\Drivers\S3;


use Closure;
use LogicException;


/**
 * The Multipart Upload handle of an S3 disk (`$S3->Uploads`): the steps of an upload whose
 * parts are sent by someone else — a browser, another service — straight to the bucket.
 *
 * The handle is stateless: an upload spans several requests, so every step takes the object
 * path and the upload id `create()` returned, and the id travels through the client. The
 * flow is `create()` once, `sign()` per part (the client `PUT`s each part to the URL it
 * gets) — or `put()` per part the server sends itself — `list()` to see what the bucket holds,
 * then `complete()` — or `abort()`.
 *
 * Every step runs inside the disk's root prefix, like any other driver operation, and
 * fails like one: it returns `false` and leaves the reason on its owner's `$error`, and the
 * failure (status, the store's code, kind) on its owner's `$Failure` — the owner is the S3 driver
 * (`$S3->Uploads`) or the HTTP server's S3 response resource (`$Response->S3->Uploads`), each
 * building the handle from its own steps.
 */
final class Uploads
{
   /** The most parts one upload holds, and the highest part number. */
   public const int PARTS = 10000;
   /** The smallest size of a part but the last (5 MiB): the store refuses a smaller one at completion. */
   public const int MINIMUM = 5 * 1024 * 1024;
   /** The largest size of a part (5 GiB). */
   public const int MAXIMUM = 5 * 1024 * 1024 * 1024;

   // * Data
   /** @var Closure(string, array<string,mixed>): (string|false) */
   private Closure $Create;
   /** @var Closure(string, string, int, int, array<array-key,mixed>): (string|false) */
   private Closure $Sign;
   /** @var Closure(string, string): (array<int,array{etag:string,size:int}>|false) */
   private Closure $List;
   /** @var Closure(string, string, array<int,array{etag:string,size?:int}>): bool */
   private Closure $Complete;
   /** @var Closure(string, string): bool */
   private Closure $Abort;
   /** @var null|Closure(string, string, int, string): (string|false) */
   private null|Closure $Put;


   /**
    * Bind the handle to its owner's multipart steps (the owner builds it — an application reads
    * it from `$S3->Uploads` or `$Response->S3->Uploads`).
    *
    * @param Closure(string, array<string,mixed>): (string|false) $Create
    * @param Closure(string, string, int, int, array<array-key,mixed>): (string|false) $Sign
    * @param Closure(string, string): (array<int,array{etag:string,size:int}>|false) $List
    * @param Closure(string, string, array<int,array{etag:string,size?:int}>): bool $Complete
    * @param Closure(string, string): bool $Abort
    * @param null|Closure(string, string, int, string): (string|false) $Put The `put()` step; a handle
    *    built without it refuses `put()`.
    */
   public function __construct (
      Closure $Create,
      Closure $Sign,
      Closure $List,
      Closure $Complete,
      Closure $Abort,
      null|Closure $Put = null
   )
   {
      // * Data
      $this->Create = $Create;
      $this->Sign = $Sign;
      $this->List = $List;
      $this->Complete = $Complete;
      $this->Abort = $Abort;
      $this->Put = $Put;
   }

   /**
    * What a dump of the handle shows: nothing — its steps are bound to the disk, and the
    * disk holds the credentials.
    *
    * Written by hand on purpose, instead of `Redactable`: nothing here is safe to list.
    *
    * @return array<string,mixed>
    */
   public function __debugInfo (): array
   {
      // :
      return [];
   }

   /**
    * Start a Multipart Upload for an object and return its upload id; false on failure.
    *
    * The object's Content-Type and metadata are set here, by the server — never by whoever
    * uploads the parts.
    *
    * @param array<string,mixed> $options `type` (Content-Type) and `meta` (a `x-amz-meta-*` map), as in `write()`.
    */
   public function create (string $path, array $options = []): string|false
   {
      // :
      return ($this->Create)($path, $options);
   }

   /**
    * Presign the `PUT` of one part and return its URL; false when the upload id, the part
    * number or the lifetime is refused.
    *
    * Whoever holds the URL can upload that one part of that one upload until it expires,
    * with no credentials — and, with no `$headers`, chooses its bytes and its size.
    * `$headers` binds them: the upload must then carry each header with exactly that value,
    * or the store refuses it. Never log the URL.
    *
    * @param string $id The upload id `create()` returned.
    * @param int $part The part number, 1..`Uploads::PARTS`.
    * @param int $expires Lifetime of the URL in seconds, 1..604800.
    * @param array<array-key,mixed> $headers What the `PUT` must send, each once, as a string
    *    (or an integer): `Content-Length` (the size of the part, in bytes) and `Content-MD5`
    *    (the base64 of its MD5). Anything else refuses the request.
    */
   public function sign (string $path, string $id, int $part, int $expires, array $headers = []): string|false
   {
      // :
      return ($this->Sign)($path, $id, $part, $expires, $headers);
   }

   /**
    * Upload one part of that upload from the server (UploadPart) and return its ETag, as the
    * store sent it (quoted) — the value `complete()` takes; false on failure.
    *
    * The body is held in memory and hashed whole (the signature binds it): a part costs about
    * its size once more while it is sent. Every part but the last must be at least 5 MiB — the
    * store refuses a smaller one only at `complete()` (`EntityTooSmall` on the owner's `$Failure->code`) —
    * and Cloudflare R2 also wants every part but the last to have the same size. A part sent
    * again replaces the earlier one, so a failed part is safe to retry; `complete()` is not.
    *
    * @param string $id The upload id `create()` returned.
    * @param int $part The part number, 1..`Uploads::PARTS`.
    * @param string $body The bytes of the part, at most 5 GiB.
    *
    * @throws LogicException When the handle was built without a `put()` step.
    */
   public function put (string $path, string $id, int $part, string $body): string|false
   {
      // ?
      if ($this->Put === null) {
         throw new LogicException('S3 Uploads: this handle was built without a put() step.');
      }

      // :
      return ($this->Put)($path, $id, $part, $body);
   }

   /**
    * List the parts the bucket holds for an upload, keyed by part number in ascending
    * order; false on failure — never a partial list.
    *
    * Check the sizes here before completing: they are what the uploader actually sent.
    *
    * @param string $id The upload id `create()` returned.
    *
    * @return array<int,array{etag:string,size:int}>|false
    */
   public function list (string $path, string $id): array|false
   {
      // :
      return ($this->List)($path, $id);
   }

   /**
    * Join the parts into the object. Takes the parts as `list()` returns them (or
    * `[number => ['etag' => ...]]` built from what the uploader reported).
    *
    * A failed completion leaves the upload open: fix the parts and complete again, or
    * `abort()` it.
    *
    * @param string $id The upload id `create()` returned.
    * @param array<int,array{etag:string,size?:int}> $parts Keyed by part number.
    */
   public function complete (string $path, string $id, array $parts): bool
   {
      // :
      return ($this->Complete)($path, $id, $parts);
   }

   /**
    * Abort an upload and release its parts; true when the bucket no longer holds it (an
    * upload it does not know counts as aborted).
    *
    * @param string $id The upload id `create()` returned.
    */
   public function abort (string $path, string $id): bool
   {
      // :
      return ($this->Abort)($path, $id);
   }
}
