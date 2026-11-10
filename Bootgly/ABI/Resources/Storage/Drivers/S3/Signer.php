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


use const SORT_STRING;
use function array_keys;
use function gmdate;
use function hash;
use function hash_hmac;
use function implode;
use function in_array;
use function ksort;
use function preg_match;
use function preg_replace;
use function rawurlencode;
use function strcmp;
use function strtolower;
use function substr;
use function time;
use function trim;
use function usort;
use InvalidArgumentException;
use SensitiveParameter;


/**
 * AWS Signature Version 4 for S3 and S3-compatible storage (MinIO, Cloudflare R2, Wasabi).
 *
 * Two forms of the same signature:
 * - **header auth** (`sign()`), for a request the server sends itself — the signature
 *   travels in the `Authorization` header and covers the payload hash;
 * - **query auth** (`presign()`), for a request someone else sends with no credentials — a
 *   browser uploading a part, a player reading an object. The signature travels in the query
 *   string, expires, and covers only the method, the path, the query and the `Host`.
 *
 * Inputs are canonical already: `$path` is the percent-encoded request path (each segment
 * `rawurlencode`d, `/` kept — S3 neither re-encodes nor normalizes it), `$host` is the wire
 * `Host` (with `:port` when it is not the scheme's default) and `$payload` is the lowercase
 * hex SHA-256 of the body, or `Signer::UNSIGNED`. Query parameters are raw values: they are
 * encoded and sorted here.
 *
 * Credentials are opaque: a wrong or empty key or secret is not refused, it yields a
 * signature the service rejects. Nothing this class throws or returns carries the secret.
 */
final class Signer
{
   /** The signing algorithm, as it is named on the wire. */
   public const string ALGORITHM = 'AWS4-HMAC-SHA256';
   /** The payload hash of a request whose body is not covered by the signature. */
   public const string UNSIGNED = 'UNSIGNED-PAYLOAD';
   /** The longest presigned URL lifetime Signature Version 4 accepts, in seconds (7 days). */
   public const int LIFETIME = 604800;

   /** The query parameters `presign()` owns — a caller can never supply them. */
   private const array RESERVED = [
      'x-amz-algorithm',
      'x-amz-credential',
      'x-amz-date',
      'x-amz-expires',
      'x-amz-security-token',
      'x-amz-signature',
      'x-amz-signedheaders',
   ];

   // * Config
   /** The access key id. */
   public private(set) string $key;
   /** The signing region (`us-east-1`, `auto`, ...). */
   public private(set) string $region;
   /** The signing service (`s3`). */
   public private(set) string $service;

   // * Data
   private string $secret;
   private string $token;

   // * Metadata
   /** The date stamp (`Ymd`) the cached signing key belongs to. */
   private string $day = '';
   /** The signing key of `$day` (raw bytes) — derived once per day, not once per request. */
   private string $derived = '';


   /**
    * Bind a signer to one set of credentials and one signing scope.
    *
    * @param string $key The access key id.
    * @param string $secret The secret access key.
    * @param string $region The signing region.
    * @param string $service The signing service.
    * @param string $token A session token (temporary credentials), or empty.
    *
    * @throws InvalidArgumentException When the key, region, service or token holds a space or a control character.
    */
   public function __construct (
      string $key,
      #[SensitiveParameter] string $secret,
      string $region = 'us-east-1',
      string $service = 's3',
      #[SensitiveParameter] string $token = ''
   )
   {
      // ? These reach the wire (the `Authorization` header, the query string): a blank or a
      //   control character there could never authenticate and would break the request framing
      foreach ([$key, $region, $service, $token] as $value) {
         if (preg_match('/^[\x21-\x7e]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('S3 Signer: the key, region, service and token must be printable ASCII without spaces.');
         }
      }

      // * Config
      $this->key = $key;
      $this->region = $region;
      $this->service = $service;

      // * Data
      $this->secret = $secret;
      $this->token = $token;
   }

   /**
    * Header auth: the headers to add to a request — `Authorization`, `x-amz-date`,
    * `x-amz-content-sha256` (and `x-amz-security-token` with a session token).
    *
    * Every header in `$headers` is signed, so pass exactly the ones the request sends and
    * must be bound to the signature (`Range`, `Content-Type`, `x-amz-*`); `Host` comes from
    * `$host`, and the four headers above are set here.
    *
    * @param string $method The HTTP method, uppercase.
    * @param string $host The wire `Host`.
    * @param string $path The percent-encoded request path, starting with `/`.
    * @param array<int|string,int|string> $query Raw query parameters (`['uploads' => '']`).
    * @param array<string,string> $headers The request headers to sign, besides `Host`.
    * @param string $payload The hex SHA-256 of the body, or `Signer::UNSIGNED`.
    * @param null|int $time Unix time of the signature (now when null).
    *
    * @throws InvalidArgumentException When an input is not canonical (see the class description).
    *
    * @return array<string,string> The headers to add to the request.
    */
   public function sign (
      string $method,
      string $host,
      string $path,
      array $query,
      array $headers,
      string $payload,
      null|int $time = null
   ): array
   {
      $this->check($method, $host, $path);
      // ?
      if (preg_match('/^[\x21-\x7e]+$/D', $payload) !== 1) {
         throw new InvalidArgumentException('S3 Signer: the payload is a hex SHA-256 or UNSIGNED-PAYLOAD.');
      }

      $time ??= time();
      $date = gmdate('Ymd\THis\Z', $time);
      $day = substr($date, 0, 8);

      // ! Canonical headers: lowercase names, values trimmed with inner runs of white space
      //   folded, sorted by name
      $signed = [];
      foreach ($headers as $name => $value) {
         $name = strtolower(trim((string) $name));
         // ? A name or value that could break out of its header line is never signed
         if (
            preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) !== 1
            || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1
         ) {
            throw new InvalidArgumentException('S3 Signer: a signed header has an illegal name or a control character in its value.');
         }
         if ($name === 'host' || $name === 'authorization') {
            continue;
         }
         $signed[$name] = (string) preg_replace('/\s+/', ' ', trim($value));
      }
      $signed['host'] = $host;
      $signed['x-amz-content-sha256'] = $payload;
      $signed['x-amz-date'] = $date;
      if ($this->token !== '') {
         $signed['x-amz-security-token'] = $this->token;
      }
      ksort($signed, SORT_STRING);

      $canonical = '';
      foreach ($signed as $name => $value) {
         $canonical .= "{$name}:{$value}\n";
      }
      $names = implode(';', array_keys($signed));

      // @ Canonical request → string to sign → signature
      $encoded = self::encode($query);
      $request = "{$method}\n{$path}\n{$encoded}\n{$canonical}\n{$names}\n{$payload}";
      $scope = "{$day}/{$this->region}/{$this->service}/aws4_request";
      $signature = $this->calculate($day, "{$date}\n{$scope}\n" . hash('sha256', $request));

      $added = [
         'Authorization' => self::ALGORITHM . " Credential={$this->key}/{$scope}, SignedHeaders={$names}, Signature={$signature}",
         'x-amz-date' => $date,
         'x-amz-content-sha256' => $payload,
      ];
      if ($this->token !== '') {
         $added['x-amz-security-token'] = $this->token;
      }

      // :
      return $added;
   }

   /**
    * Query auth (a presigned URL): the full query string — the caller's parameters plus
    * `X-Amz-Algorithm`, `X-Amz-Credential`, `X-Amz-Date`, `X-Amz-Expires`,
    * `X-Amz-SignedHeaders=host` (and `X-Amz-Security-Token` with a session token),
    * canonically encoded and sorted, ending in `X-Amz-Signature`.
    *
    * Whoever holds the resulting URL can send that one request — this method, path and
    * query, on this host — until it expires, with no credentials. Only `Host` is signed and
    * the payload is `UNSIGNED-PAYLOAD`: the holder chooses the body and every other header.
    * Treat the result as a secret until it expires: never log it.
    *
    * @param string $method The HTTP method, uppercase.
    * @param string $host The wire `Host`.
    * @param string $path The percent-encoded request path, starting with `/`.
    * @param array<int|string,int|string> $query Raw query parameters (`['partNumber' => 3, 'uploadId' => '…']`).
    * @param int $expires Lifetime in seconds, 1..`Signer::LIFETIME`.
    * @param null|int $time Unix time of the signature (now when null).
    *
    * @throws InvalidArgumentException When an input is not canonical, the lifetime is out of bounds or a query parameter is one of the `X-Amz-*` authentication parameters.
    *
    * @return string The query string, without the leading `?`.
    */
   public function presign (
      string $method,
      string $host,
      string $path,
      array $query,
      int $expires,
      null|int $time = null
   ): string
   {
      $this->check($method, $host, $path);
      // ?
      if ($expires < 1 || $expires > self::LIFETIME) {
         throw new InvalidArgumentException('S3 Signer: a presigned URL expires in 1..' . self::LIFETIME . ' seconds.');
      }
      // ? The authentication parameters are set here, in any letter case
      foreach ($query as $name => $value) {
         if (in_array(strtolower((string) $name), self::RESERVED, true) === true) {
            throw new InvalidArgumentException('S3 Signer: the X-Amz-* authentication parameters cannot be supplied.');
         }
      }

      $time ??= time();
      $date = gmdate('Ymd\THis\Z', $time);
      $day = substr($date, 0, 8);
      $scope = "{$day}/{$this->region}/{$this->service}/aws4_request";

      // ! The authentication parameters are part of the signed query
      $query['X-Amz-Algorithm'] = self::ALGORITHM;
      $query['X-Amz-Credential'] = "{$this->key}/{$scope}";
      $query['X-Amz-Date'] = $date;
      $query['X-Amz-Expires'] = $expires;
      $query['X-Amz-SignedHeaders'] = 'host';
      if ($this->token !== '') {
         $query['X-Amz-Security-Token'] = $this->token;
      }
      $canonical = self::encode($query);

      // @ Canonical request → string to sign → signature
      $request = "{$method}\n{$path}\n{$canonical}\nhost:{$host}\n\nhost\n" . self::UNSIGNED;
      $signature = $this->calculate($day, "{$date}\n{$scope}\n" . hash('sha256', $request));

      // :
      return "{$canonical}&X-Amz-Signature={$signature}";
   }

   /**
    * What a dump of the signer shows: its scope, never the secret, the session token or
    * the derived signing key.
    *
    * @return array<string,string>
    */
   public function __debugInfo (): array
   {
      // :
      return [
         'key' => $this->key,
         'region' => $this->region,
         'service' => $this->service,
      ];
   }

   /**
    * The canonical query string: names and values percent-encoded per RFC 3986
    * (`rawurlencode`), sorted by encoded name, then by encoded value; a parameter without a
    * value keeps its `=` (`uploads=`).
    *
    * @param array<int|string,int|string> $query Raw query parameters.
    */
   public static function encode (array $query): string
   {
      $pairs = [];
      foreach ($query as $name => $value) {
         $pairs[] = [rawurlencode((string) $name), rawurlencode((string) $value)];
      }
      usort($pairs, static fn (array $A, array $B): int => strcmp($A[0], $B[0]) ?: strcmp($A[1], $B[1]));

      $encoded = [];
      foreach ($pairs as [$name, $value]) {
         $encoded[] = "{$name}={$value}";
      }

      // :
      return implode('&', $encoded);
   }

   /**
    * Refuse a request line that is not canonical: each of these is one line of the canonical
    * request, so a line break — or a `?` in the path — would let one request be signed as
    * another.
    */
   private function check (string $method, string $host, string $path): void
   {
      // ?
      if (preg_match('/^[A-Z]+$/D', $method) !== 1) {
         throw new InvalidArgumentException('S3 Signer: the method is an uppercase HTTP method.');
      }
      if (preg_match('/^[\x21-\x7e]+$/D', $host) !== 1) {
         throw new InvalidArgumentException('S3 Signer: the host is the wire Host, without spaces or control characters.');
      }
      if (preg_match('/^\/[\x21-\x22\x24-\x3e\x40-\x7e]*$/D', $path) !== 1) {
         throw new InvalidArgumentException('S3 Signer: the path is percent-encoded and starts with "/".');
      }
   }

   /**
    * HMAC chain: the day's signing key (derived once per day), then the signature.
    */
   private function calculate (string $day, string $scoped): string
   {
      // ! One cached key: a long-running worker never accumulates a key per day
      if ($this->day !== $day) {
         $key = hash_hmac('sha256', $day, "AWS4{$this->secret}", true);
         $key = hash_hmac('sha256', $this->region, $key, true);
         $key = hash_hmac('sha256', $this->service, $key, true);
         $this->derived = hash_hmac('sha256', 'aws4_request', $key, true);
         $this->day = $day;
      }

      // :
      return hash_hmac('sha256', self::ALGORITHM . "\n{$scoped}", $this->derived);
   }
}
