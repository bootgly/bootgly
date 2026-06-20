<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage\Drivers;


use const STREAM_CLIENT_CONNECT;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_shift;
use function count;
use function explode;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function gmdate;
use function hash;
use function hash_hmac;
use function hexdec;
use function implode;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function ksort;
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
use function time;
use function trim;
use InvalidArgumentException;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Resources\Storage\Driver;


/**
 * Amazon S3 (and S3-compatible) storage driver.
 *
 * Speaks the S3 REST API over a blocking TLS/TCP socket — no SDK, no event
 * loop — with AWS Signature V4 computed from core `hash`/`hash_hmac`. The
 * blocking transport mirrors the Cache Redis driver, so it works the same in
 * scripts, the test runner and async workers. Supports AWS plus S3-compatible
 * services (MinIO, Cloudflare R2, Wasabi) via a custom `endpoint` and
 * `path_style` addressing.
 */
class S3 extends Driver
{
   private const int TIMEOUT = 30;
   private const int PART = 16 * 1024 * 1024;   // 16 MiB (S3 min part = 5 MiB; max 10000 parts)
   private const int CONCURRENCY = 4;           // multipart parts uploaded in parallel

   // * Metadata
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
    */
   public function __construct (string $root, array $options = [])
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
            return $this->fail("S3 write {$key}: illegal newline in write options");
         }
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
    * @param resource $sink
    */
   public function read (string $path, $sink): bool
   {
      $key = $this->locate($this->resolve($path));
      // ! Generic reason up front; drain() refines it (truncation), success clears it
      $this->error = "S3 GET {$key}: read failed";
      [$code] = $this->call('GET', $key, [], '', [], $sink);
      // ?: Success
      if ($code === 200) {
         $this->error = '';

         return true;
      }

      // ? A real HTTP status wins; code 0 keeps drain()'s precise reason (e.g. truncation)
      if ($code !== 0) {
         $this->error = "S3 GET {$key}: HTTP {$code}";
      }

      // :
      return false;
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
    *
    * @return array{0:int,1:array<string,string|array<int,string>>,2:string}
    */
   private function call (string $method, string $path, array $query, string $body, array $extra, $sink = null): array
   {
      [$uri, $headers] = $this->sign($method, $path, $query, $body, $extra);

      // :
      return $this->send($method, $uri, $headers, $body, $sink);
   }

   /**
    * Compute the SigV4-signed request line and headers; returns `[uri, headers]`.
    *
    * @param array<string,string> $query
    * @param array<string,string> $extra Extra (signed when `x-amz-*`) headers.
    * @param null|int $time Unix timestamp for the signature date (defaults to now; set for tests).
    *
    * @return array{0:string,1:array<string,string>}
    */
   private function sign (string $method, string $path, array $query, string $body, array $extra, null|int $time = null): array
   {
      $now = $time ?? time();
      $amzDate = gmdate('Ymd\THis\Z', $now);
      $dateStamp = gmdate('Ymd', $now);
      $payloadHash = hash('sha256', $body);

      // @ Headers covered by the signature (sorted; host matches the wire Host)
      $signed = [
         'host' => $this->authority,
         'x-amz-content-sha256' => $payloadHash,
         'x-amz-date' => $amzDate,
      ];
      if ($this->token !== '') {
         $signed['x-amz-security-token'] = $this->token;
      }
      // @ AWS SigV4 requires every x-amz-* header to be signed (e.g. x-amz-copy-source)
      foreach ($extra as $name => $value) {
         $lower = strtolower($name);
         if (str_starts_with($lower, 'x-amz-') === true) {
            $signed[$lower] = $value;
         }
      }
      ksort($signed);

      // @ Canonical (sorted, percent-encoded) query string
      $canonicalQuery = '';
      if ($query !== []) {
         ksort($query);
         $pairs = [];
         foreach ($query as $name => $value) {
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
         }
         $canonicalQuery = implode('&', $pairs);
      }
      $canonicalHeaders = '';
      foreach ($signed as $name => $value) {
         $canonicalHeaders .= "{$name}:{$value}\n";
      }
      $signedHeaders = implode(';', array_keys($signed));

      $canonicalRequest = "{$method}\n{$path}\n{$canonicalQuery}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

      $scope = "{$dateStamp}/{$this->region}/{$this->service}/aws4_request";
      $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);

      // @ Derive the signing key and sign
      $kDate = hash_hmac('sha256', $dateStamp, "AWS4{$this->secretKey}", true);
      $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
      $kService = hash_hmac('sha256', $this->service, $kRegion, true);
      $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
      $signature = hash_hmac('sha256', $stringToSign, $kSigning);

      $authorization = "AWS4-HMAC-SHA256 "
         . "Credential={$this->accessKey}/{$scope}, "
         . "SignedHeaders={$signedHeaders}, "
         . "Signature={$signature}";

      // @ Headers to send: signed amz headers + Authorization + extras
      $headers = [
         'x-amz-content-sha256' => $payloadHash,
         'x-amz-date' => $amzDate,
         'Authorization' => $authorization,
      ];
      if ($this->token !== '') {
         $headers['x-amz-security-token'] = $this->token;
      }
      foreach ($extra as $name => $value) {
         $headers[$name] = $value;
      }

      $uri = $path . ($canonicalQuery !== '' ? "?{$canonicalQuery}" : '');

      // :
      return [$uri, $headers];
   }

   /**
    * Send one HTTP/1.1 request over a blocking TLS/TCP socket; `[code, headers, body]`.
    *
    * @param array<string,string> $headers
    * @param resource|null $sink Stream the response body into this sink instead of buffering.
    *
    * @return array{0:int,1:array<string,string|array<int,string>>,2:string}
    */
   private function send (string $method, string $uri, array $headers, string $body, $sink = null): array
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
      $raw = "{$method} {$uri} HTTP/1.1\r\n";
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
         return $this->drain($socket, $sink);
      }

      // @ Buffer the whole response, then parse
      $response = '';
      while (feof($socket) === false) {
         $chunk = @fread($socket, 8192);
         if ($chunk === false) {
            break;
         }
         $response .= $chunk;
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
         $eol = strpos($body, "\r\n", $offset);
         if ($eol === false) {
            break;
         }

         // @ Chunk size (hex), ignoring any chunk extensions after ';'
         $sizeLine = substr($body, $offset, $eol - $offset);
         $sizeHex = trim(explode(';', $sizeLine)[0]);
         $size = (int) hexdec($sizeHex);
         if ($size <= 0) {
            break;
         }

         $offset = $eol + 2;
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
      [$code, , $body] = $this->call('POST', $key, ['uploads' => ''], '', $extra);
      if ($code < 200 || $code >= 300) {
         return $this->fail("S3 create multipart {$key}: HTTP {$code}");
      }
      $clean = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $body) ?? $body;
      $XML = simplexml_load_string($clean);
      $uploadId = $XML !== false ? (string) $XML->UploadId : '';
      if ($uploadId === '') {
         return $this->fail("S3 create multipart {$key}: missing UploadId");
      }

      // @ Upload the parts concurrently (pipelined sockets); ETags keyed by part number
      $etags = $this->pump($key, $source, $uploadId, $part1, $part2);
      if ($etags === false) {
         // ? pump() already recorded the reason
         $this->abort($key, $uploadId);

         return false;
      }

      // ! Complete the multipart upload
      $xml = '<CompleteMultipartUpload>';
      foreach ($etags as $number => $etag) {
         $xml .= "<Part><PartNumber>{$number}</PartNumber><ETag>{$etag}</ETag></Part>";
      }
      $xml .= '</CompleteMultipartUpload>';

      [$code, , $body] = $this->call('POST', $key, ['uploadId' => $uploadId], $xml, [
         'Content-Type' => 'application/xml',
      ]);
      // ? S3 can return 200 with an <Error> body on Complete
      if ($code < 200 || $code >= 300 || strpos($body, '<Error') !== false) {
         $this->abort($key, $uploadId);

         return $this->fail("S3 complete multipart {$key}: HTTP {$code}");
      }

      // :
      return true;
   }

   /**
    * Abort a multipart upload, releasing its uploaded parts.
    */
   private function abort (string $key, string $uploadId): void
   {
      $this->call('DELETE', $key, ['uploadId' => $uploadId], '', []);
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
      [$uri, $headers] = $this->sign($method, $path, $query, $body, []);

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
    * Read a streamed HTTP response: parse the head, then pump the body into the sink.
    *
    * @param resource $socket
    * @param resource $sink
    *
    * @return array{0:int,1:array<string,string|array<int,string>>,2:string}
    */
   private function drain ($socket, $sink): array
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

      // # Body: de-chunk on the fly, else copy by Content-Length (or until EOF)
      $encoding = $headers['transfer-encoding'] ?? '';
      $encoding = is_array($encoding) ? implode(',', $encoding) : $encoding;
      if (stripos($encoding, 'chunked') !== false) {
         $complete = $this->siphon($socket, $leftover, $sink);
      }
      else {
         $length = $headers['content-length'] ?? null;
         $length = is_array($length) ? ($length[0] ?? null) : $length;
         $remaining = $length !== null ? (int) $length : -1;
         $complete = true;

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
    * Returns true only when the terminal zero-size chunk is reached (a complete body).
    *
    * @param resource $socket
    * @param resource $sink
    */
   private function siphon ($socket, string $buffer, $sink): bool
   {
      while (true) {
         // ! Ensure a full chunk-size line is buffered
         while (strpos($buffer, "\r\n") === false && feof($socket) === false) {
            $chunk = @fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $buffer .= $chunk;
         }
         $eol = strpos($buffer, "\r\n");
         // ? No chunk-size line left → connection ended mid-body (truncated)
         if ($eol === false) {
            return false;
         }

         // @ Chunk size (hex), ignoring any extensions after ';'
         $size = (int) hexdec(trim(explode(';', substr($buffer, 0, $eol))[0]));
         $buffer = substr($buffer, $eol + 2);
         // ?: Terminal zero-size chunk — the body is complete
         if ($size <= 0) {
            return true;
         }

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
