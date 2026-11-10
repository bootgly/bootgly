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


/**
 * The Multipart Upload handle of an S3 disk (`$S3->Uploads`): the steps of an upload whose
 * parts are sent by someone else — a browser, another service — straight to the bucket.
 *
 * The handle is stateless: an upload spans several requests, so every step takes the object
 * path and the upload id `create()` returned, and the id travels through the client. The
 * flow is `create()` once, `sign()` per part (the client `PUT`s each part to the URL it
 * gets), `list()` to see what the bucket holds, then `complete()` — or `abort()`.
 *
 * Every step runs inside the disk's root prefix, like any other driver operation, and
 * fails like one: it returns `false` and leaves the reason on the driver's `$error`.
 */
final class Uploads
{
   /** The most parts one upload holds, and the highest part number. */
   public const int PARTS = 10000;

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


   /**
    * Bind the handle to the driver's multipart steps (the driver builds it — an application
    * reads it from `$S3->Uploads`).
    *
    * @param Closure(string, array<string,mixed>): (string|false) $Create
    * @param Closure(string, string, int, int, array<array-key,mixed>): (string|false) $Sign
    * @param Closure(string, string): (array<int,array{etag:string,size:int}>|false) $List
    * @param Closure(string, string, array<int,array{etag:string,size?:int}>): bool $Complete
    * @param Closure(string, string): bool $Abort
    */
   public function __construct (Closure $Create, Closure $Sign, Closure $List, Closure $Complete, Closure $Abort)
   {
      // * Data
      $this->Create = $Create;
      $this->Sign = $Sign;
      $this->List = $List;
      $this->Complete = $Complete;
      $this->Abort = $Abort;
   }

   /**
    * What a dump of the handle shows: nothing — its steps are bound to the disk, and the
    * disk holds the credentials.
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
