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


use const ENT_NOQUOTES;
use const ENT_XML1;
use const LIBXML_NONET;
use const PHP_INT_MAX;
use const STREAM_CLIENT_CONNECT;
use function array_key_exists;
use function array_map;
use function array_shift;
use function count;
use function explode;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function hash;
use function hexdec;
use function htmlspecialchars;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_scalar;
use function is_string;
use function ksort;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function ltrim;
use function parse_url;
use function preg_match;
use function preg_replace;
use function rawurlencode;
use function rtrim;
use function simplexml_load_string;
use function str_starts_with;
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
use InvalidArgumentException;
use SensitiveParameter;
use SimpleXMLElement;

use Bootgly\ABI\Code\__String\Path;
use Bootgly\ABI\Resources\Storage\Driver;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Signer;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Uploads;


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
 * Upload whose parts go straight to the bucket) and a ranged `read()`.
 */
class S3 extends Driver
{
   private const int TIMEOUT = 30;
   private const int PART = 16 * 1024 * 1024;   // 16 MiB (S3 min part = 5 MiB; max 10000 parts)
   private const int CONCURRENCY = 4;           // multipart parts uploaded in parallel
   private const int ANSWER = 32 * 1024 * 1024; // a buffered (XML) answer never legitimately gets near this
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
   /** The Multipart Upload handle: `create`, `sign`, `list`, `complete`, `abort`. */
   public private(set) Uploads $Uploads;
   private Signer $Signer;
   protected string $bucket;
   protected string $region;
   protected string $accessKey;
   protected string $secretKey;
   protected string $token;
   protected string $service;
   protected string $scheme;
   protected string $host;
   protected int $port;
   protected bool $pathStyle;
   protected bool $verify;
   protected string $prefix;
   protected string $authority;


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
      $this->bucket    = $this->pick('bucket');
      $this->region    = $this->pick('region', 'us-east-1');
      $this->accessKey = $this->pick('key');
      $this->secretKey = $this->pick('secret');
      $this->token     = $this->pick('token');
      $this->service   = $this->pick('service', 's3');
      $this->verify    = is_bool($options['verify'] ?? null) ? $options['verify'] : true;
      $this->prefix    = $root !== '' ? trim(Path::normalize($root), '/') : '';

      $pathStyle = is_bool($options['path_style'] ?? null) ? $options['path_style'] : false;
      $endpoint  = $this->pick('endpoint');

      // # Endpoint (S3-compatible) overrides the AWS host derivation
      if ($endpoint !== '') {
         $parts = parse_url($endpoint);
         $this->scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https';
         $this->host = strtolower(is_string($parts['host'] ?? null) ? $parts['host'] : '');
         $this->port = (int) ($parts['port'] ?? ($this->scheme === 'https' ? 443 : 80));
         // ? Custom endpoints default to path-style unless explicitly told otherwise
         $this->pathStyle = array_key_exists('path_style', $options) ? $pathStyle : true;
      }
      else {
         $this->scheme = 'https';
         $this->port = 443;
         $this->pathStyle = $pathStyle;
         $this->host = $pathStyle
            ? "s3.{$this->region}.amazonaws.com"
            : "{$this->bucket}.s3.{$this->region}.amazonaws.com";
      }

      // # The `Host` value (includes a non-default port)
      $this->authority = $this->host
         . ($this->port !== 443 && $this->port !== 80 ? ":{$this->port}" : '');

      // ? Fail closed on insecure transport unless explicitly opted in (prevents prod copying
      //   local MinIO settings and leaking keys over http / accepting a MITM cert)
      $insecure = is_bool($options['insecure'] ?? null) ? $options['insecure'] : false;
      if (($this->scheme !== 'https' || $this->verify === false) && $insecure === false) {
         throw new InvalidArgumentException(
            "S3 insecure transport (http or verify => false) requires the 'insecure' => true option."
         );
      }

      $this->Signer = new Signer($this->accessKey, $this->secretKey, $this->region, $this->service, $this->token);
      $this->Uploads = new Uploads(
         Create: $this->open(...),
         Sign: $this->stamp(...),
         List: $this->enumerate(...),
         Complete: $this->close(...),
         Abort: $this->cancel(...)
      );
   }

   /**
    * What a dump of the disk shows: where it points, never its credentials (nor the signer
    * and the handle, which hold them).
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
      $this->error = '';
      $key = $this->locate($this->resolve($path));

      // ! Content-Type + user metadata (x-amz-meta-* are signed by call())
      $extra = $this->describe($options);
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
         [$code] = $this->call('PUT', $key, [], $part1, $extra);

         // :
         return $code >= 200 && $code < 300
            ? true
            : $this->fail("S3 PUT {$key}: HTTP {$code}");
      }

      // : Large object — Multipart Upload (peak memory ≈ one part)
      return $this->upload($key, $source, $part1, $part2, $extra);
   }

   /**
    * Download a key into a writable stream (streamed GET); false when missing.
    *
    * With `$offset` and/or `$length` it downloads only that window of the object (a `Range`
    * request): `$length` bytes starting at `$offset`, or everything from `$offset` on when
    * `$length` is null. A window that ends past the object is cut at its end; one that
    * starts past it is false. The sink never receives a byte outside the window, and
    * nothing at all from an answer that is not that window (the whole object, another
    * range, an error body). A window cut short in transit fails after its first bytes were
    * written: on false, discard what the sink received.
    *
    * @param resource $sink
    * @param int $offset First byte of the window (0-based).
    * @param null|int $length Size of the window in bytes; null reads to the end of the object.
    */
   public function read (string $path, $sink, int $offset = 0, null|int $length = null): bool
   {
      $key = $this->locate($this->resolve($path));
      // ? A window that cannot be expressed as a byte range
      if ($offset < 0 || ($length !== null && ($length < 1 || $length > PHP_INT_MAX - $offset))) {
         return $this->fail("S3 GET {$key}: invalid range (offset >= 0, length >= 1)");
      }

      // ! The whole object by default (no Range header: the request is what it always was)
      $window = $offset > 0 || $length !== null ? [$offset, $length] : null;
      $extra = [];
      if ($window !== null) {
         $last = $length !== null ? (string) ($offset + $length - 1) : '';
         $extra['Range'] = "bytes={$offset}-{$last}";
      }

      // ! Generic reason up front; drain() refines it (truncation), success clears it
      $this->error = "S3 GET {$key}: read failed";
      [$code] = $this->call('GET', $key, [], '', $extra, $sink, $window);
      // ?: Success — 200 for the whole object, 206 for a window
      if ($code === ($window === null ? 200 : 206)) {
         $this->error = '';

         return true;
      }

      // ? A real HTTP status wins; code 0 keeps drain()'s precise reason (e.g. truncation)
      if ($code !== 0) {
         $this->error = match (true) {
            $window !== null && $code === 416 => "S3 GET {$key}: range not satisfiable (HTTP 416)",
            $window !== null && $code === 200 => "S3 GET {$key}: range ignored by the endpoint (HTTP 200)",
            default => "S3 GET {$key}: HTTP {$code}"
         };
      }

      // :
      return false;
   }

   /**
    * Presign one request for an object and return its absolute URL; false when the request
    * is refused (the reason is on `$error`).
    *
    * Whoever holds the URL can send that request — this method on this object — until it
    * expires, with no credentials: treat it as a secret and never log it. Only the `Host`
    * is signed and the payload is not, so on a `PUT` the holder chooses the body, its size
    * and its Content-Type; to let someone upload, prefer the `$Uploads` handle, which sets
    * the type on the server and lets you check the sizes before completing.
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
    */
   public function presign (string $method, string $path, int $expires, array $query = []): string|false
   {
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

      // :
      return $this->endorse($method, $path, $expires, $overrides);
   }

   /**
    * Delete a key (DELETE object); idempotent (a missing key is success).
    */
   public function delete (string $path): bool
   {
      $this->error = '';
      [$code] = $this->call('DELETE', $this->locate($this->resolve($path)), [], '', []);

      // :
      return $code === 200 || $code === 204 || $code === 404
         ? true
         : $this->fail("S3 DELETE {$path}: HTTP {$code}");
   }

   /**
    * Whether a key exists (HEAD object).
    */
   public function check (string $path): bool
   {
      [$code] = $this->call('HEAD', $this->locate($this->resolve($path)), [], '', []);

      // :
      return $code === 200;
   }

   /**
    * List keys under a prefix (ListObjectsV2), returned disk-relative.
    *
    * @return array<int,string>
    */
   public function list (string $path = '', bool $recursive = false): array
   {
      $this->error = '';
      $prefix = $this->resolve($path);
      if ($prefix !== '') {
         $prefix = rtrim($prefix, '/') . '/';
      }

      $base = $this->pathStyle ? "/{$this->bucket}" : '/';
      $keys = [];
      $token = '';

      // @ Page through ListObjectsV2 until the bucket stops truncating
      do {
         $query = ['list-type' => '2', 'prefix' => $prefix];
         if ($recursive === false) {
            $query['delimiter'] = '/';
         }
         if ($token !== '') {
            $query['continuation-token'] = $token;
         }

         [$code, , $body] = $this->call('GET', $base, $query, '', []);
         // ? A failed page must be observable (callers/clear() fail closed on $error)
         if ($code !== 200) {
            $this->error = "S3 list {$prefix}: HTTP {$code}";

            return $keys;
         }

         // ? Drop the default (and any prefixed) xmlns so element access works
         //   uniformly across S3 and S3-compatible providers
         $clean = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $body) ?? $body;
         $XML = simplexml_load_string($clean);
         if ($XML === false) {
            $this->error = "S3 list {$prefix}: malformed XML response";

            break;
         }

         foreach ($XML->Contents as $Content) {
            $keys[] = $this->relativize((string) $Content->Key);
         }

         $token = ((string) $XML->IsTruncated) === 'true'
            ? (string) $XML->NextContinuationToken
            : '';
      }
      while ($token !== '');

      // :
      return $keys;
   }

   /**
    * Copy a key to another (PUT + x-amz-copy-source).
    */
   public function copy (string $from, string $to): bool
   {
      $source = "/{$this->bucket}/" . $this->encode($this->resolve($from));

      [$code] = $this->call('PUT', $this->locate($this->resolve($to)), [], '', [
         'x-amz-copy-source' => $source,
      ]);

      // :
      return $code >= 200 && $code < 300;
   }

   /**
    * Move a key (copy then delete the source).
    */
   public function move (string $from, string $to): bool
   {
      // ?
      if ($this->copy($from, $to) === false) {
         return false;
      }

      // :
      return $this->delete($from);
   }

   /**
    * Object size in bytes (HEAD `Content-Length`); false when missing.
    */
   public function measure (string $path): int|false
   {
      [$code, $headers] = $this->call('HEAD', $this->locate($this->resolve($path)), [], '', []);
      // ?
      if ($code !== 200) {
         return false;
      }

      $length = $this->extract($headers, 'content-length');

      // :
      return $length !== null ? (int) $length : false;
   }

   /**
    * Object metadata (HEAD): `['size' => bytes, 'modified' => Unix mtime]`; false when missing.
    *
    * @return array{size:int,modified:int}|false
    */
   public function inspect (string $path): array|false
   {
      [$code, $headers] = $this->call('HEAD', $this->locate($this->resolve($path)), [], '', []);
      // ?
      if ($code !== 200) {
         return false;
      }

      $length = $this->extract($headers, 'content-length');
      $modified = $this->extract($headers, 'last-modified');

      // :
      return [
         'size' => $length !== null ? (int) $length : 0,
         'modified' => $modified !== null ? (int) strtotime($modified) : 0,
      ];
   }

   /**
    * Create a directory — a no-op, since S3 prefixes are implicit.
    */
   public function make (string $path): bool
   {
      // :
      return true;
   }

   /**
    * Remove every key under a prefix (list + per-key delete).
    */
   public function clear (string $path = ''): bool
   {
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

   // ---

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
    * Build the object headers of a write from its options: the Content-Type and one
    * `x-amz-meta-*` per scalar `meta` entry. A string — the reason — when an option cannot
    * be sent as a header: `illegal newline`, or `illegal character` for a metadata name
    * that is not a header token or a value holding a control character.
    *
    * @param array<string,mixed> $options
    *
    * @return string|array<string,string>
    */
   private function describe (array $options): string|array
   {
      $extra = [
         'Content-Type' => is_string($options['type'] ?? null) ? $options['type'] : 'application/octet-stream',
      ];
      $meta = $options['meta'] ?? null;
      if (is_array($meta) === true) {
         foreach ($meta as $name => $value) {
            if (is_scalar($value) === true) {
               $extra['x-amz-meta-' . strtolower((string) $name)] = (string) $value;
            }
         }
      }
      // ? Reject CRLF in user-supplied options — never let them inject HTTP headers
      foreach ($extra as $name => $value) {
         if (preg_match('/[\r\n]/', "{$name}{$value}") === 1) {
            return 'illegal newline';
         }
         // ? What the signer would refuse: say so here, not as a request that never left
         if (
            preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) !== 1
            || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $value) === 1
         ) {
            return 'illegal character';
         }
      }

      // :
      return $extra;
   }

   /**
    * Resolve a disk-relative path into the request path of one object; false (with the
    * reason on `$error`) when the path names the disk root — a request on the root is a
    * request on the prefix or on the bucket itself, never on an object.
    */
   private function target (string $path, string $step): string|false
   {
      // ?
      if ($path === '' || ltrim(Path::normalize($path), '/') === '') {
         return $this->fail("S3 {$step}: an object path is required");
      }

      // :
      return $this->locate($this->resolve($path));
   }

   /**
    * Whether an upload id and a part number may reach a URL: the id is the opaque token the
    * store issued (printable ASCII), the part number is 1..10000. False with the reason on
    * `$error`.
    */
   private function validate (string $step, string $id, int $part = 1): bool
   {
      // ?
      if (preg_match('/^[\x21-\x7e]{1,1024}$/D', $id) !== 1) {
         return $this->fail("S3 {$step}: invalid upload id");
      }
      if ($part < 1 || $part > Uploads::PARTS) {
         return $this->fail("S3 {$step}: the part number is 1.." . Uploads::PARTS);
      }

      // :
      return true;
   }

   /**
    * Presign one request on an object (query auth) and return its absolute URL; false with
    * the reason on `$error`. `$query` is trusted here: `presign()` and `stamp()` decide
    * what may go in it.
    *
    * @param array<string,string> $query
    */
   private function endorse (string $method, string $path, int $expires, array $query): string|false
   {
      // ?
      if ($expires < 1 || $expires > Signer::LIFETIME) {
         return $this->fail('S3 presign: the lifetime is 1..' . Signer::LIFETIME . ' seconds');
      }
      // ? A URL signed with no credentials authorizes nothing: say so here, not in a browser
      if ($this->accessKey === '' || $this->secretKey === '') {
         return $this->fail('S3 presign: the disk has no credentials (key, secret)');
      }
      if ($this->scheme !== 'https' && $this->scheme !== 'http') {
         return $this->fail('S3 presign: the endpoint is not http(s)');
      }
      $located = $this->target($path, 'presign');
      if ($located === false) {
         return false;
      }

      try {
         $signed = $this->Signer->presign($method, $this->authority, $located, $query, $expires);
      }
      catch (InvalidArgumentException) {
         // ? The disk's own bucket or endpoint cannot be put in a canonical request
         return $this->fail('S3 presign: the request cannot be signed (check the bucket and the endpoint)');
      }

      // :
      return "{$this->scheme}://{$this->authority}{$located}?{$signed}";
   }

   /**
    * Resolve a disk-relative path into a full object key (prefix + normalized path).
    */
   private function resolve (string $path): string
   {
      // ?
      if ($path === '') {
         return $this->prefix;
      }

      $relative = ltrim(Path::normalize($path), '/');

      // :
      return $this->prefix !== ''
         ? rtrim($this->prefix, '/') . ($relative !== '' ? "/{$relative}" : '')
         : $relative;
   }

   /**
    * Strip the configured prefix from a key to make it disk-relative.
    */
   private function relativize (string $key): string
   {
      $root = $this->prefix !== '' ? rtrim($this->prefix, '/') . '/' : '';

      // :
      return $root !== '' && str_starts_with($key, $root) ? substr($key, strlen($root)) : $key;
   }

   /**
    * URI-encode an object key (each segment encoded, `/` preserved).
    */
   private function encode (string $key): string
   {
      // :
      return implode('/', array_map(fn (string $segment): string => rawurlencode($segment), explode('/', $key)));
   }

   /**
    * Build the request path for a key (path-style prepends the bucket).
    */
   private function locate (string $key): string
   {
      $encoded = $this->encode($key);

      // :
      return $this->pathStyle ? "/{$this->bucket}/{$encoded}" : "/{$encoded}";
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
         //   blank, a control character) is never sent: it fails like a refused connection
         return [0, [], ''];
      }

      // :
      return $this->send($method, $URI, $headers, $body, $sink, $window);
   }

   /**
    * Compute the SigV4-signed request line and headers; returns `[uri, headers]`.
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
      // @ AWS SigV4 requires every x-amz-* header to be signed (e.g. x-amz-copy-source);
      //   a Range is signed too, so the window asked for is the window authorized
      $signed = [];
      foreach ($extra as $name => $value) {
         $lower = strtolower($name);
         if ($lower === 'range' || str_starts_with($lower, 'x-amz-') === true) {
            $signed[$lower] = $value;
         }
      }
      $added = $this->Signer->sign($method, $this->authority, $path, $query, $signed, hash('sha256', $body), $time);

      // @ Headers to send: signed amz headers + Authorization + extras
      $headers = [
         'x-amz-content-sha256' => $added['x-amz-content-sha256'],
         'x-amz-date' => $added['x-amz-date'],
         'Authorization' => $added['Authorization'],
      ];
      if (isset($added['x-amz-security-token']) === true) {
         $headers['x-amz-security-token'] = $added['x-amz-security-token'];
      }
      foreach ($extra as $name => $value) {
         $headers[$name] = $value;
      }

      $URI = $path . ($query !== [] ? '?' . Signer::encode($query) : '');

      // :
      return [$URI, $headers];
   }

   /**
    * Send one HTTP/1.1 request over a blocking TLS/TCP socket; `[code, headers, body]`.
    *
    * @param array<string,string> $headers
    * @param resource|null $sink Stream the response body into this sink instead of buffering.
    * @param null|array{0:int,1:null|int} $window The `[offset, length]` a ranged read asked for.
    *
    * @return array{0:int,1:array<string,string|array<int,string>>,2:string}
    */
   private function send (string $method, string $URI, array $headers, string $body, $sink = null, null|array $window = null): array
   {
      $transport = $this->scheme === 'https' ? 'tls' : 'tcp';
      $context = stream_context_create($this->scheme === 'https'
         ? ['ssl' => ['verify_peer' => $this->verify, 'verify_peer_name' => $this->verify, 'peer_name' => $this->host]]
         : []);

      $socket = @stream_socket_client(
         "{$transport}://{$this->host}:{$this->port}",
         $errno,
         $error,
         self::TIMEOUT,
         STREAM_CLIENT_CONNECT,
         $context
      );
      // ?
      if ($socket === false) {
         return [0, [], ''];
      }
      stream_set_timeout($socket, self::TIMEOUT);

      // @ Build the raw request (Connection: close → read until EOF)
      $raw = "{$method} {$URI} HTTP/1.1\r\n";
      $raw .= "Host: {$this->authority}\r\n";
      foreach ($headers as $name => $value) {
         $raw .= "{$name}: {$value}\r\n";
      }
      $raw .= 'Content-Length: ' . strlen($body) . "\r\n";
      $raw .= "Connection: close\r\n\r\n";
      $raw .= $body;

      // @ Write the full request (guard against partial writes)
      $total = strlen($raw);
      $sent = 0;
      while ($sent < $total) {
         $written = @fwrite($socket, substr($raw, $sent));
         if ($written === false || $written === 0) {
            @fclose($socket);

            return [0, [], ''];
         }
         $sent += $written;
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
      if ($this->conclude($key, $uploadId, $etags) === false) {
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
      [$code, , $body] = $this->call('POST', $key, ['uploads' => ''], '', $extra);
      if ($code < 200 || $code >= 300) {
         return $this->fail("S3 create multipart {$key}: HTTP {$code}");
      }
      $XML = $this->decode($body);
      $uploadId = $XML !== null ? (string) $XML->UploadId : '';
      if ($uploadId === '') {
         return $this->fail("S3 create multipart {$key}: missing UploadId");
      }

      // :
      return $uploadId;
   }

   /**
    * Complete a multipart upload from its `[partNumber => ETag]` map (ascending); false
    * with the reason on `$error`. The upload stays open on failure: the caller decides.
    *
    * @param array<int,string> $etags
    */
   private function conclude (string $key, string $uploadId, array $etags): bool
   {
      $completion = '<CompleteMultipartUpload>';
      foreach ($etags as $number => $etag) {
         // ! An ETag is what the store or the uploader said: text, never markup
         $escaped = htmlspecialchars($etag, ENT_XML1 | ENT_NOQUOTES);
         $completion .= "<Part><PartNumber>{$number}</PartNumber><ETag>{$escaped}</ETag></Part>";
      }
      $completion .= '</CompleteMultipartUpload>';

      [$code, , $body] = $this->call('POST', $key, ['uploadId' => $uploadId], $completion, [
         'Content-Type' => 'application/xml',
      ]);
      // ? S3 can return 200 with an <Error> body on Complete
      if ($code < 200 || $code >= 300 || strpos($body, '<Error') !== false) {
         return $this->fail("S3 complete multipart {$key}: HTTP {$code}");
      }

      // :
      return true;
   }

   /**
    * Abort a multipart upload, releasing its uploaded parts; returns the HTTP status (0 when
    * the store gave no answer) and never touches `$error`.
    */
   private function abort (string $key, string $uploadId): int
   {
      [$code] = $this->call('DELETE', $key, ['uploadId' => $uploadId], '', []);

      // :
      return $code;
   }

   // # The steps behind the public `$Uploads` handle (disk-relative path, validated input)

   /**
    * `Uploads::create()` — start a Multipart Upload for an object.
    *
    * @param array<string,mixed> $options `type` and `meta`, as in `write()`.
    */
   private function open (string $path, array $options): string|false
   {
      $this->error = '';
      $key = $this->target($path, 'create multipart');
      // ?
      if ($key === false) {
         return false;
      }
      $extra = $this->describe($options);
      if (is_string($extra) === true) {
         return $this->fail("S3 create multipart {$key}: {$extra} in the options");
      }

      // :
      return $this->initiate($key, $extra);
   }

   /**
    * `Uploads::sign()` — presign the `PUT` of one part.
    */
   private function stamp (string $path, string $id, int $part, int $expires): string|false
   {
      $this->error = '';
      // ?
      if ($this->validate('sign part', $id, $part) === false) {
         return false;
      }

      // :
      return $this->endorse('PUT', $path, $expires, ['partNumber' => (string) $part, 'uploadId' => $id]);
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
      $this->error = '';
      // ?
      if ($this->validate('list parts', $id) === false) {
         return false;
      }
      $key = $this->target($path, 'list parts');
      if ($key === false) {
         return false;
      }

      $parts = [];
      $marker = 0;
      // @@ One page per request (up to 1,000 parts each)
      while (true) {
         $query = ['uploadId' => $id];
         if ($marker > 0) {
            $query['part-number-marker'] = (string) $marker;
         }

         [$code, , $body] = $this->call('GET', $key, $query, '', []);
         if ($code !== 200) {
            return $this->fail("S3 list parts {$key}: HTTP {$code}");
         }
         $XML = $this->decode($body);
         if ($XML === null || $XML->getName() !== 'ListPartsResult') {
            return $this->fail("S3 list parts {$key}: malformed XML response");
         }

         $last = $marker;
         foreach ($XML->Part as $Part) {
            $number = (string) $Part->PartNumber;
            $size = (string) $Part->Size;
            $etag = trim((string) $Part->ETag);
            // ? Strictly ascending part numbers 1..10000, each past the marker, a size and
            //   an ETag — or the page is not a listing
            if (
               preg_match('/^[1-9]\d{0,4}$/D', $number) !== 1
               || (int) $number > Uploads::PARTS
               || (int) $number <= $last
               || preg_match('/^\d{1,18}$/D', $size) !== 1
               || $etag === ''
            ) {
               return $this->fail("S3 list parts {$key}: malformed XML response");
            }
            $last = (int) $number;
            $parts[$last] = ['etag' => $etag, 'size' => (int) $size];
         }

         // ?: The last page
         if (strtolower(trim((string) $XML->IsTruncated)) !== 'true') {
            break;
         }
         $next = (string) $XML->NextPartNumberMarker;
         // ? The marker of a truncated page is its last part: a page with no part would loop
         //   forever, a marker short of the last part would list parts twice, and one past
         //   it would skip parts — a hole in the completed object
         if (preg_match('/^\d{1,5}$/D', $next) !== 1 || $last <= $marker || (int) $next !== $last) {
            return $this->fail("S3 list parts {$key}: the listing does not advance");
         }
         $marker = (int) $next;
      }

      // :
      return $parts;
   }

   /**
    * `Uploads::complete()` — join the parts into the object.
    *
    * @param array<int|string,mixed> $parts `[partNumber => ['etag' => ...]]`.
    */
   private function close (string $path, string $id, array $parts): bool
   {
      $this->error = '';
      // ?
      if ($this->validate('complete multipart', $id) === false) {
         return false;
      }
      if ($parts === [] || count($parts) > Uploads::PARTS) {
         return $this->fail('S3 complete multipart: 1..' . Uploads::PARTS . ' parts are required');
      }
      $etags = [];
      foreach ($parts as $number => $part) {
         $etag = is_array($part) === true ? ($part['etag'] ?? null) : null;
         // ? A part number 1..10000 and an ETag that fits on one line
         if (
            is_int($number) === false || $number < 1 || $number > Uploads::PARTS
            || is_string($etag) === false
            || preg_match('/^[\x20-\x7e]{1,1024}$/D', $etag) !== 1
            || trim($etag, '" ') === ''
         ) {
            return $this->fail('S3 complete multipart: each part is [number => [\'etag\' => ...]], numbered 1..' . Uploads::PARTS);
         }
         $etags[$number] = $etag;
      }
      // ! S3 wants the parts in ascending order
      ksort($etags);
      $key = $this->target($path, 'complete multipart');
      if ($key === false) {
         return false;
      }

      // :
      return $this->conclude($key, $id, $etags);
   }

   /**
    * `Uploads::abort()` — abort an upload; one the store no longer knows counts as aborted.
    */
   private function cancel (string $path, string $id): bool
   {
      $this->error = '';
      // ?
      if ($this->validate('abort multipart', $id) === false) {
         return false;
      }
      $key = $this->target($path, 'abort multipart');
      if ($key === false) {
         return false;
      }

      $code = $this->abort($key, $id);

      // :
      return $code === 204 || $code === 200 || $code === 404
         ? true
         : $this->fail("S3 abort multipart {$key}: HTTP {$code}");
   }

   /**
    * Parse an S3 XML answer; null when it is not one. No network access, and no document
    * type: an S3 answer never declares one, so refusing it rules out entity expansion.
    */
   private function decode (string $body): null|SimpleXMLElement
   {
      // ? An S3 answer is UTF-8. Another encoding — NUL bytes (UTF-16/32), a declared
      //   charset, a first byte that is not `<` — could hide a DOCTYPE from the check below
      $head = ltrim(str_starts_with($body, "\xEF\xBB\xBF") === true ? substr($body, 3) : $body);
      if (
         $head === ''
         || $head[0] !== '<'
         || strpos($body, "\x00") !== false
         || preg_match('/^<\?xml[^>]*\bencoding\s*=\s*["\'](?!utf-8["\'])/i', $head) === 1
      ) {
         return null;
      }
      // ?
      if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
         return null;
      }

      // ? Drop the default (and any prefixed) xmlns so element access works
      //   uniformly across S3 and S3-compatible providers
      $clean = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $body) ?? $body;
      $previous = libxml_use_internal_errors(true);
      $XML = simplexml_load_string($clean, SimpleXMLElement::class, LIBXML_NONET);
      libxml_clear_errors();
      libxml_use_internal_errors($previous);

      // :
      return $XML === false ? null : $XML;
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
                  $this->error = 'S3 multipart: source stream read error';
                  $this->reap($socks);

                  return false;
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
               $this->error = "S3 upload part {$number}: connect failed";
               $this->reap($socks);

               return false;
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
            $this->error = 'S3 multipart: socket timeout';
            $this->reap($socks);

            return false;
         }

         // @@ Drain ready sockets; an EOF (Connection: close) means the response is complete
         foreach ($read as $id => $socket) {
            $chunk = @fread($socket, 65536);
            if ($chunk !== false && $chunk !== '') {
               $bufs[$id] .= $chunk;

               continue;
            }

            @fclose($socket);
            [$code, $headers] = $this->parse($bufs[$id]);
            $etag = $this->extract($headers, 'etag');
            $n = $nums[$id];
            unset($socks[$id], $nums[$id], $bufs[$id]);
            if ($code < 200 || $code >= 300 || $etag === null) {
               $this->error = "S3 upload part {$n}: HTTP {$code}";
               $this->reap($socks);

               return false;
            }
            $etags[$n] = $etag;
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
         return false;
      }

      $transport = $this->scheme === 'https' ? 'tls' : 'tcp';
      $context = stream_context_create($this->scheme === 'https'
         ? ['ssl' => ['verify_peer' => $this->verify, 'verify_peer_name' => $this->verify, 'peer_name' => $this->host]]
         : []);

      $socket = @stream_socket_client(
         "{$transport}://{$this->host}:{$this->port}",
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

      $raw = "{$method} {$uri} HTTP/1.1\r\n";
      $raw .= "Host: {$this->authority}\r\n";
      foreach ($headers as $name => $value) {
         $raw .= "{$name}: {$value}\r\n";
      }
      $raw .= 'Content-Length: ' . strlen($body) . "\r\n";
      $raw .= "Connection: close\r\n\r\n";
      $raw .= $body;

      $total = strlen($raw);
      $sent = 0;
      while ($sent < $total) {
         $written = @fwrite($socket, substr($raw, $sent));
         if ($written === false || $written === 0) {
            @fclose($socket);

            return false;
         }
         $sent += $written;
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
    * The number of bytes a 206 answer must carry for a ranged read, from its
    * `Content-Range`; 0 when that is not the window asked for — it must start at the
    * offset and end where the request ended, or at the end of the object when that comes
    * first.
    *
    * @param array<string,string|array<int,string>> $headers
    * @param array{0:int,1:null|int} $window The `[offset, length]` asked for.
    */
   private function frame (array $headers, array $window): int
   {
      [$offset, $length] = $window;
      $range = $this->extract($headers, 'content-range');
      // ?
      if ($range === null || preg_match('#^bytes (\d{1,18})-(\d{1,18})/(\d{1,18}|\*)$#D', trim($range), $matches) !== 1) {
         return 0;
      }
      $first = (int) $matches[1];
      $last = (int) $matches[2];
      if ($first !== $offset || $last < $first) {
         return 0;
      }

      $wanted = $length !== null ? $offset + $length - 1 : null;
      // ?: The object's size is unknown to the store: the window may only end early
      if ($matches[3] === '*') {
         return $wanted !== null && $last > $wanted ? 0 : $last - $first + 1;
      }

      $end = (int) $matches[3] - 1;
      $expected = $wanted === null || $wanted > $end ? $end : $wanted;

      // :
      return $last === $expected ? $last - $first + 1 : 0;
   }

   /**
    * Read a streamed HTTP response: parse the head, then pump the body into the sink.
    *
    * With a `$window` (a ranged read) only a 206 whose `Content-Range` is that window is
    * streamed, and never more bytes than it announces; a body that ends early fails with
    * its first bytes already in the sink.
    *
    * @param resource $socket
    * @param resource $sink
    * @param null|array{0:int,1:null|int} $window The `[offset, length]` a ranged read asked for.
    *
    * @return array{0:int,1:array<string,string|array<int,string>>,2:string}
    */
   private function drain ($socket, $sink, null|array $window = null): array
   {
      // @ Read until the end of the headers
      $buffer = '';
      $found = false;
      while (feof($socket) === false) {
         $chunk = @fread($socket, 8192);
         if ($chunk === false || $chunk === '') {
            break;
         }
         $buffer .= $chunk;
         if (strpos($buffer, "\r\n\r\n") !== false) {
            $found = true;
            break;
         }
      }
      // ?
      if ($found === false) {
         @fclose($socket);

         return [0, [], ''];
      }

      $pos = (int) strpos($buffer, "\r\n\r\n");
      [$code, $headers] = $this->scan(substr($buffer, 0, $pos));
      $leftover = substr($buffer, $pos + 4);

      // # A ranged read: how many bytes the body must hold (-1: not a ranged read)
      $span = -1;
      if ($window !== null) {
         // ?: Not a partial answer (the whole object, an error): the caller reports the
         //    status and the sink stays untouched
         if ($code !== 206) {
            @fclose($socket);

            return [$code, $headers, ''];
         }
         $span = $this->frame($headers, $window);
         // ? Another window than the one asked for
         if ($span === 0) {
            @fclose($socket);
            $this->error = 'S3 GET: the answer is not the requested range';

            return [0, $headers, ''];
         }
      }

      // # Body: de-chunk on the fly, else copy by Content-Length (or until EOF)
      $encoding = $headers['transfer-encoding'] ?? '';
      $encoding = is_array($encoding) ? implode(',', $encoding) : $encoding;
      if (stripos($encoding, 'chunked') !== false) {
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
               $this->error = 'S3 GET: the answer is not the requested range';

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
         $this->error = 'S3 GET: incomplete response body';

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
