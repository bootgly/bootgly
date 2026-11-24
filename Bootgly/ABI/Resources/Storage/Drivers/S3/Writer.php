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


use const ENT_NOQUOTES;
use const ENT_XML1;
use const PHP_INT_MAX;
use function array_key_exists;
use function array_map;
use function count;
use function explode;
use function hash;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_scalar;
use function is_string;
use function ksort;
use function ltrim;
use function parse_url;
use function preg_match;
use function rawurlencode;
use function rtrim;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use InvalidArgumentException;
use SensitiveParameter;

use Bootgly\ABI\Code\__String\Path;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;


/**
 * Composes the requests of one S3 disk — transport-free: it says what to send (the method, the
 * request path and query, the signed headers, where to connect), never sends it.
 *
 * It is built from a disk's `root` and `options` exactly as the S3 driver reads them, so any
 * client speaks to the same bucket, under the same prefix, with the same signatures: the
 * blocking driver sends what it composes over its own socket; another transport (an HTTP client
 * on an event loop) can send the same requests. A request must carry the `Host` header
 * `compose()` returns — the `authority` it was signed for, which keeps a non-default port even
 * on 80/443 of the other scheme.
 *
 * The credentials stay inside the private `Signer`: nothing here exposes the secret, the session
 * token or the raw endpoint.
 */
final class Writer
{
   /** The headers a request sets itself, never taken from the extras (lowercase names). */
   private const array RESERVED = [
      'host' => true,
      'authorization' => true,
      'x-amz-date' => true,
      'x-amz-content-sha256' => true,
      'x-amz-security-token' => true,
      'content-length' => true,
      'transfer-encoding' => true,
      'connection' => true,
   ];

   // * Metadata
   /** The bucket. */
   public private(set) string $bucket;
   /** The signing region. */
   public private(set) string $region;
   /** The signing service (`s3`). */
   public private(set) string $service;
   /** The key prefix every disk path lives under (the disk `root`, normalized, no slashes around). */
   public private(set) string $prefix;
   /** `https` or `http`. */
   public private(set) string $scheme;
   /** The host to connect to (lowercase). */
   public private(set) string $host;
   /** The port to connect to. */
   public private(set) int $port;
   /** The `Host` value — the host, plus the port unless it is the scheme's own default. */
   public private(set) string $authority;
   /** Whether the bucket is the first path segment (path-style) instead of a host label. */
   public private(set) bool $pathStyle;
   /** Whether a TLS peer certificate and name are verified. */
   public private(set) bool $verify;
   /** The request path of the bucket itself (what a listing asks for). */
   public string $base {
      get => $this->pathStyle ? "/{$this->bucket}" : '/';
   }
   /**
    * The TLS options of a connection (`verify_peer`, `verify_peer_name`, `peer_name`) — the
    * `ssl` context of a stream, the secure configuration of a client; null for `http`.
    *
    * @var null|array{verify_peer:bool,verify_peer_name:bool,peer_name:string}
    */
   public null|array $secure {
      get => $this->scheme === 'https'
         ? ['verify_peer' => $this->verify, 'verify_peer_name' => $this->verify, 'peer_name' => $this->host]
         : null;
   }
   private Signer $Signer;
   /** Whether the disk has a key and a secret (a presigned URL without them authorizes nothing). */
   private bool $credentialed;


   /**
    * Read a disk's `root` (key prefix) and `options` (bucket, region, key, secret, optional
    * endpoint/path_style/token/service/verify/insecure), as the S3 driver does.
    *
    * @param array<string,mixed> $options Sensitive: the disk's credentials.
    *
    * @throws InvalidArgumentException For an insecure transport that was not opted into, or a
    *    key, region, service or token holding a space or a control character.
    */
   public function __construct (string $root, #[SensitiveParameter] array $options = [])
   {
      $pick = static fn (string $name, string $default = ''): string => is_string($options[$name] ?? null)
         ? $options[$name]
         : $default;

      // * Metadata
      $this->bucket = $pick('bucket');
      $this->region = $pick('region', 'us-east-1');
      $this->service = $pick('service', 's3');
      $this->verify = is_bool($options['verify'] ?? null) ? $options['verify'] : true;
      $this->prefix = $root !== '' ? trim(Path::normalize($root), '/') : '';

      $pathStyle = is_bool($options['path_style'] ?? null) ? $options['path_style'] : false;
      $endpoint = $pick('endpoint');

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

      // # The `Host` value: the port is implicit only when it is the scheme's own default
      //   (`http` on 443 keeps it — a presigned URL must point where the disk connects)
      $this->authority = $this->port === ($this->scheme === 'https' ? 443 : 80)
         ? $this->host
         : "{$this->host}:{$this->port}";

      // ? Fail closed on insecure transport unless explicitly opted in (prevents prod copying
      //   local MinIO settings and leaking keys over http / accepting a MITM cert)
      $insecure = is_bool($options['insecure'] ?? null) ? $options['insecure'] : false;
      if (($this->scheme !== 'https' || $this->verify === false) && $insecure === false) {
         throw new InvalidArgumentException(
            "S3 insecure transport (http or verify => false) requires the 'insecure' => true option."
         );
      }

      $key = $pick('key');
      $secret = $pick('secret');
      $this->Signer = new Signer($key, $secret, $this->region, $this->service, $pick('token'));
      $this->credentialed = $key !== '' && $secret !== '';
   }

   /**
    * What a dump of the writer shows: where it points, never the signer (which holds the
    * credentials).
    *
    * Written by hand on purpose, instead of `Redactable`: it lists only the safe fields.
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
      ];
   }

   /**
    * The object key of a disk-relative path: the prefix, then the normalized path (the disk root
    * itself for `''`).
    */
   public function resolve (string $path): string
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
    * The disk-relative path of a stored key (the prefix stripped).
    */
   public function relativize (string $key): string
   {
      $root = $this->prefix !== '' ? rtrim($this->prefix, '/') . '/' : '';

      // :
      return $root !== '' && str_starts_with($key, $root) ? substr($key, strlen($root)) : $key;
   }

   /**
    * The request path of a disk-relative path (the root allowed): each key segment
    * percent-encoded, the bucket first when path-style.
    */
   public function locate (string $path): string
   {
      $encoded = $this->encode($this->resolve($path));

      // :
      return $this->pathStyle ? "/{$this->bucket}/{$encoded}" : "/{$encoded}";
   }

   /**
    * The request path of one object; null when the path names the disk root — a request on the
    * root is a request on the prefix or on the bucket itself, never on an object.
    */
   public function target (string $path): null|string
   {
      // ?
      if ($path === '' || ltrim(Path::normalize($path), '/') === '') {
         return null;
      }

      // :
      return $this->locate($path);
   }

   /**
    * The `x-amz-copy-source` of a disk-relative path: `/{bucket}/{encoded key}`.
    */
   public function source (string $path): string
   {
      // :
      return "/{$this->bucket}/" . $this->encode($this->resolve($path));
   }

   /**
    * The `Range` value of a window (`bytes=offset-last`, or `bytes=offset-` to the end); null
    * when the window cannot be a byte range (a negative offset, a length below 1 or past the
    * largest integer).
    */
   public function range (int $offset, null|int $length = null): null|string
   {
      // ?
      if ($offset < 0 || ($length !== null && ($length < 1 || $length > PHP_INT_MAX - $offset))) {
         return null;
      }

      $last = $length !== null ? (string) ($offset + $length - 1) : '';

      // :
      return "bytes={$offset}-{$last}";
   }

   /**
    * The object headers of a write from its options: the Content-Type and one `x-amz-meta-*` per
    * scalar `meta` entry. A string — the reason — when an option cannot be sent as a header:
    * `illegal newline`, or `illegal character` for a metadata name that is not a header token or
    * a value holding a control character.
    *
    * @param array<string,mixed> $options `type` (Content-Type) and `meta` (a `x-amz-meta-*` map).
    *
    * @return string|array<string,string>
    */
   public function describe (array $options): string|array
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
    * Whether an upload id and a part number may reach a URL: null when they may, else a
    * `Refusal`. The id is the opaque token the store issued (printable ASCII, 1..1024 bytes), the
    * part number 1..`Uploads::PARTS`.
    *
    * @param string $step What is asked, as the message names it (`upload part`).
    */
   public function validate (string $step, string $id, int $part = 1): null|Failure
   {
      // ?
      if (preg_match('/^[\x21-\x7e]{1,1024}$/D', $id) !== 1) {
         return new Failure(0, '', Kinds::Refusal, "S3 {$step}: invalid upload id");
      }
      if ($part < 1 || $part > Uploads::PARTS) {
         return new Failure(0, '', Kinds::Refusal, "S3 {$step}: the part number is 1.." . Uploads::PARTS);
      }

      // :
      return null;
   }

   /**
    * The headers a presigned `PUT` binds, by lowercase name; null when one is refused — a header
    * that is not offered, twice the same one, a value that is not of its form.
    *
    * @param array<array-key,mixed> $headers
    * @param bool $part Whether the `PUT` uploads a part: only its size and its bytes are bound.
    *
    * @return null|array<string,string>
    */
   public function bind (array $headers, bool $part): null|array
   {
      $forms = [
         'content-length' => '/^(0|[1-9]\d{0,17})$/D',
         'content-md5' => '/^[A-Za-z0-9+\/]{22}==$/D',
         'content-type' => '/^[\x20-\x7e]{1,255}$/D',
      ];

      $bound = [];
      foreach ($headers as $name => $value) {
         $name = strtolower((string) $name);
         // ? Each header once, as a string or an integer
         if (isset($bound[$name]) === true || (is_string($value) === false && is_int($value) === false)) {
            return null;
         }
         $value = (string) $value;

         $form = $part === true && $name === 'content-type' ? null : ($forms[$name] ?? null);
         // ? Metadata belongs to the object, never to one of its parts
         if ($form === null && $part === false && preg_match('/^x-amz-meta-[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) === 1) {
            $form = '/^[^\x00-\x1f\x7f]+$/D';
         }
         if ($form === null || preg_match($form, $value) !== 1 || trim($value) === '') {
            return null;
         }
         $bound[$name] = $value;
      }

      // :
      return $bound;
   }

   /**
    * The body of a `CompleteMultipartUpload` from `[partNumber => ['etag' => ...]]`, in ascending
    * part order; a `Refusal` when there is no part, more than `Uploads::PARTS`, or a part whose
    * number is not 1..`Uploads::PARTS` or whose ETag does not fit on one line.
    *
    * @param array<int|string,mixed> $parts
    */
   public function join (array $parts): string|Failure
   {
      // ?
      if ($parts === [] || count($parts) > Uploads::PARTS) {
         return new Failure(0, '', Kinds::Refusal, 'S3 complete multipart: 1..' . Uploads::PARTS . ' parts are required');
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
            return new Failure(
               0,
               '',
               Kinds::Refusal,
               'S3 complete multipart: each part is [number => [\'etag\' => ...]], numbered 1..' . Uploads::PARTS
            );
         }
         $etags[$number] = $etag;
      }
      // ! S3 wants the parts in ascending order
      ksort($etags);

      $completion = '<CompleteMultipartUpload>';
      foreach ($etags as $number => $etag) {
         // ! An ETag is what the store or the uploader said: text, never markup
         $escaped = htmlspecialchars($etag, ENT_XML1 | ENT_NOQUOTES);
         $completion .= "<Part><PartNumber>{$number}</PartNumber><ETag>{$escaped}</ETag></Part>";
      }

      // :
      return "{$completion}</CompleteMultipartUpload>";
   }

   /**
    * Compose one request for header auth: `[URI, headers]` — the request path plus its canonical
    * query, and every header to send: `Host` (the `authority`), the signature headers
    * (`Authorization`, `x-amz-date`, `x-amz-content-sha256`, `x-amz-security-token` with a session
    * token), then `$extra`. `Range` and every `x-amz-*` of `$extra` are signed. The body is
    * hashed whole (SigV4 binds it), so it is held in memory once here.
    *
    * @param string $path The request path (`locate()`, `target()`, `base`).
    * @param array<string,string> $query Raw query parameters (`['uploads' => '']`).
    * @param array<string,string> $extra Headers to send — signed when `x-amz-*` or `Range`.
    * @param null|int $time Unix time of the signature (now when null).
    *
    * @throws InvalidArgumentException When the request cannot be put in canonical form (a bucket
    *    or a header name with a blank or a control character), or `$extra` holds a header that
    *    cannot be sent as given: a name that is not a header token, a value with a line break or a
    *    control character, a name given twice (in any letter case), or a header the request sets
    *    itself — `Host`, `Authorization`, `x-amz-date`, `x-amz-content-sha256`,
    *    `x-amz-security-token`, `Content-Length`, `Transfer-Encoding`, `Connection`.
    *
    * @return array{0:string,1:array<string,string>}
    */
   public function compose (string $method, string $path, array $query, string $body, array $extra, null|int $time = null): array
   {
      // @ AWS SigV4 requires every x-amz-* header to be signed (e.g. x-amz-copy-source); a Range
      //   is signed too, so the window asked for is the window authorized
      $signed = [];
      $seen = [];
      foreach ($extra as $name => $value) {
         $lower = strtolower($name);
         // ? Every header goes on the wire as given and once: a header token for a name, no line
         //   break or control character in a value, no name twice (in any letter case), and none
         //   of the headers this composer or the transport set themselves
         if (
            preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) !== 1
            || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1
            || isset(self::RESERVED[$lower]) === true
            || isset($seen[$lower]) === true
         ) {
            throw new InvalidArgumentException('S3 Writer: a header cannot be sent as given (an illegal name, a control character, a name given twice, or a header the request sets itself).');
         }
         $seen[$lower] = true;
         if ($lower === 'range' || str_starts_with($lower, 'x-amz-') === true) {
            $signed[$lower] = $value;
         }
      }
      $added = $this->Signer->sign($method, $this->authority, $path, $query, $signed, hash('sha256', $body), $time);

      // @ Headers to send: Host, signed amz headers + Authorization, then the extras
      $headers = [
         'Host' => $this->authority,
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
    * Presign one request on an object (query auth) and return its absolute URL; a `Refusal` when
    * the lifetime is out of 1..`Signer::LIFETIME`, the disk has no credentials, the endpoint is not
    * http(s), the path is the disk root, or the request cannot be signed. `$query` and `$headers`
    * are trusted here: the caller decides what may go in them.
    *
    * @param array<string,string> $query
    * @param array<string,string> $headers The headers the request is bound to send.
    */
   public function presign (string $method, string $path, int $expires, array $query, array $headers = []): string|Failure
   {
      // ?
      if ($expires < 1 || $expires > Signer::LIFETIME) {
         return new Failure(0, '', Kinds::Refusal, 'S3 presign: the lifetime is 1..' . Signer::LIFETIME . ' seconds');
      }
      // ? A URL signed with no credentials authorizes nothing: say so here, not in a browser
      if ($this->credentialed === false) {
         return new Failure(0, '', Kinds::Refusal, 'S3 presign: the disk has no credentials (key, secret)');
      }
      if ($this->scheme !== 'https' && $this->scheme !== 'http') {
         return new Failure(0, '', Kinds::Refusal, 'S3 presign: the endpoint is not http(s)');
      }
      $located = $this->target($path);
      if ($located === null) {
         return new Failure(0, '', Kinds::Refusal, 'S3 presign: an object path is required');
      }

      try {
         $signed = $this->Signer->presign($method, $this->authority, $located, $query, $expires, $headers);
      }
      catch (InvalidArgumentException) {
         // ? The disk's own bucket or endpoint cannot be put in a canonical request
         return new Failure(0, '', Kinds::Refusal, 'S3 presign: the request cannot be signed (check the bucket and the endpoint)');
      }

      // :
      return "{$this->scheme}://{$this->authority}{$located}?{$signed}";
   }

   // ---

   /**
    * URI-encode an object key (each segment encoded, `/` preserved).
    */
   private function encode (string $key): string
   {
      // :
      return implode('/', array_map(fn (string $segment): string => rawurlencode($segment), explode('/', $key)));
   }
}
