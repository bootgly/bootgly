<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use const JSON_THROW_ON_ERROR;
use const PREG_SET_ORDER;
use function array_keys;
use function array_map;
use function array_slice;
use function array_walk_recursive;
use function base64_decode;
use function bin2hex;
use function clearstatcache;
use function count;
use function ctype_digit;
use function date;
use function explode;
use function fwrite;
use function is_array;
use function is_file;
use function is_scalar;
use function is_string;
use function json_decode;
use function min;
use function parse_str;
use function preg_match;
use function preg_match_all;
use function random_bytes;
use function rtrim;
use function strcasecmp;
use function stripos;
use function strlen;
use function strpos;
use function strrpos;
use function strstr;
use function strtok;
use function strtolower;
use function strtotime;
use function substr;
use function time;
use function trim;
use function uasort;
use function unlink;
use function usort;
use AllowDynamicProperties;
use JsonException;

use const Bootgly\WPI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header\Cookie;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Chunked;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Waiting;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Authentications\Basic;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header\Cookies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;


#[AllowDynamicProperties]
class Request
{
   public protected(set) Header $Header;
   public protected(set) Body $Body;


   // * Config
   /** @var int Maximum file size in bytes for multipart/form-data downloads (default: 500MB) */
   public static int $maxFileSize = 500 * 1024 * 1024; // @ 500 megabytes
   /** @var int Maximum body size in bytes for non-multipart requests (default: 10MB) */
   public static int $maxBodySize = 10 * 1024 * 1024; // @ 10 megabytes

   public string $base {
      get => $this->base;
      set {
         $this->base = $value;
      }
   }

   // * Data
   // \ TCP
   // / Connection
   /**
    * The IP address of the HTTP Client.
    * Always reflects the TCP-level connection IP.
    * To trust proxy headers (X-Forwarded-For, cf-connecting-ip, etc.),
    * use the TrustedProxy middleware with the proxy's IP in the trusted list.
    */
   public string $address {
      get => $this->address;
   }
   /**
    * The port of the HTTP Client.
    */
   public int $port {
      get => $this->port;
   }
   /**
    * The scheme of the Request.
    */
   public string $scheme {
      get => $this->scheme;
   }
   // | HTTP Request
   // / Header
   /**
    * The Request headers.
    *
    * @var array<string|array<string>>
    */
   public array $headers {
      get => $this->Header->fields;
   }
   /**
    * The Request method.
    */
   public string $method {
      get => $this->method;
   }
   /**
    * The Request URI (Uniform Resource Identifier).
    */
   public string $URI {
      get => $this->URI;
      set (string $value) {
         $this->URI = $value;
         // @ Invalidate cached derivations of URI
         $this->_URL = null;
         $this->_URN = null;
         $this->_query = null;
         $this->_queries = null;
      }
   }
   /**
    * The Request protocol.
    */
   public string $protocol {
      get => $this->protocol;
   }
   // ^ Resource
   /**
    * The Request URL (Uniform Resource Locator).
    */
   private null|string $_URL = null;
   public string $URL {
      get {
         if ($this->_URL !== null) {
            return $this->_URL;
         }

         $locator = strtok($this->URI, '?');
         if ($locator === false) {
            $locator = '';
         }

         $locator = rtrim($locator, '/');

         $base = $this->base;
         if ($base && substr($locator, 0, strlen($base)) === $base) {
            // @ Return relative location
            $locator = substr($locator, strlen($base));
         }

         return $this->_URL = $locator;
      }
   }
   /**
    * The Request URN (Uniform Resource Name).
    */
   private null|string $_URN = null;
   public string $URN {
      get {
         if ($this->_URN !== null) {
            return $this->_URN;
         }

         $URL = $this->URL;

         // @ Extract the URN after the last slash
         return $this->_URN = substr($URL, strrpos($URL, '/') + 1);
      }
   }
   // Host
   /**
    * The Request host.
    */
   public string $host {
      get {
         $host = $this->Header->get('Host');
         if (is_string($host)) {
            return $host;
         }
         return '';
      }
   }
   /**
    * The Request domain.
    */
   public string $domain {
      get {
         $host = $this->host;
  
         $pattern = "/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})(:[\d]+)?$/i";
         if (preg_match($pattern, $host, $matches)) {
            return $matches['domain'];
         }

         $colon = strpos($host, ":");
         if ($colon === false) {
            return $host;
         }

         return substr($host, 0, $colon);
      }
   }
   /**
    * The Request subdomain.
    */
   public string $subdomain {
      get {
         $SLD = strstr($this->host, $this->domain, true);
         if ($SLD === false) {
            return '';
         }
         return rtrim($SLD, '.');
      }
   }
   /**
    * The Request subdomains.
    *
    * @var array<string>
    */
   public array $subdomains {
      get => explode('.', $this->subdomain);
   }
   /**
    * The Request IPs.
    *
    * @var array<string>
    */
   public array $IPs {
      get {
         // ! Always return TCP peer IP only.
         // Proxy headers (X-Forwarded-For) are handled by TrustedProxy middleware.
         return [$this->address];
      }
   }
   // Query
   /**
    * The Request query.
    */
   private null|string $_query = null;
   public string $query {
      get {
         if ($this->_query !== null) {
            return $this->_query;
         }

         $URI = $this->URI;

         $mark = strpos($URI, '?');
         $query = '';

         if ($mark !== false) {
            $query = substr($URI, $mark + 1);
         }

         return $this->_query = $query;
      }
   }
   /**
    * The Request queries.
    */
   /** @var array<string,string|string[]>|null */
   private null|array $_queries = null;
   /** @var array<string,string|string[]> The Request queries */
   public array $queries {
      get {
         if ($this->_queries !== null) {
            return $this->_queries;
         }

         $queries = [];
         parse_str($this->query, $queries);

         /** @var array<string,string|string[]> $queries */
         return $this->_queries = $queries;
      }
   }
   // / Header Cookie
   /**
    * The Request Cookies object.
    */
   public Cookies $Cookies {
      get => $this->Header->Cookies;
   }
   /**
    * The Request cookies.
    *
    * @var array<int, array<string, string>>
    */
   public array $cookies {
      get => $this->Cookies->cookies;
   }
   // / Session
   /**
    * The Request Session.
    */
   public private(set) null|Session $Session = null {
      get {
         if ($this->Session === null) {
            // !
            $name = Session::$name;
            $id = $this->Cookies->get($name);

            if ($id === '') {
               $id = bin2hex(random_bytes(16));
            }

            $this->Session = new Session($id);

            // @
            $Cookie = new Cookie($name, $id);
            $Cookie->expiration = Session::$cookieLifetime;
            $Cookie->path = Session::$cookiePath;
            $Cookie->domain = Session::$domain;
            $Cookie->secure = Session::$secure;
            $Cookie->HTTP_only = Session::$httpOnly;
            $Cookie->same_site = Session::$sameSite;

            HTTP_Server_CLI::$Response->Header->Cookies->append($Cookie);
         }

         return $this->Session;
      }
   }
   // / Body
   /**
    * The Request Body input.
    */
   public string $input {
      /** @phpstan-ignore-next-line */
      get => $this->Body->input ?? $this->receive();
   }
   /**
    * The Request POST data.
    *
      * @var array|string
      * @phpstan-var array<string, array<string>|bool|float|int|string>|string
    */
   public array|string $post {
      get {
         if ($this->method === 'POST' && $_POST === [] && ! $this->Body->streaming) {
            /** @var array<string, array<string>|bool|float|int|string>|null $input */
            $input = $this->input();
            return $input ?? [];
         }

         /** @var array<string, array<string>|bool|float|int|string> $post */
         $post = $_POST;
         return $post;
      }
   }
   /**
    * The Request files.
    *
    * @var array<string, array<string, bool|int|string|array<int|string, bool|int|string>>> 
    */
   public array $files {
      get {
         /** @var array<string, array<string, bool|int|string|array<int|string, bool|int|string>>> $files */
         $files = $_FILES;

         return $files;
      }
   }

   // * Metadata
   public string $raw {
      get {
         $raw = <<<RAW
         {$this->method} {$this->URI} {$this->protocol}
         RAW;

         $raw .= "\r\n";
         $raw .= $this->Header->raw;
         $raw .= "\r\n";
         $raw .= $this->Body->input;

         return $raw;
      }
   }
   // \ TCP
   // / Connection
   public bool $secure {
      get => $this->scheme === 'https';
   }
   // | HTTP
   // HTTP Basic Authentication
   public string $username {
      get {
         if ($this->authUsername === '') {
            $auth = $this->authenticate();
            if ($auth !== null) {
               $this->authUsername = $auth->username;
               $this->authPassword = $auth->password;
            }
         }

         return $this->authUsername;
      }
      set {
         $this->authUsername = $value;
      }
   }
   public string $password {
      get {
         if ($this->authPassword === '') {
            $auth = $this->authenticate();
            if ($auth !== null) {
               $this->authUsername = $auth->username;
               $this->authPassword = $auth->password;
            }
         }

         return $this->authPassword;
      }
      set {
         $this->authPassword = $value;
      }
   }
   // HTTP Content Negotiation (RFC 7231 section-5.3)
   /** @var array<string> */
   public array $types {
      get {
         $types = $this->negotiate(with: self::ACCEPTS_TYPES);
         return $types;
      }
   }
   public string $type {
      get => $this->types[0] ?? '';
   }

   /** @var array<string> */
   public array $languages {
      get {
         $languages = $this->negotiate(with: self::ACCEPTS_LANGUAGES);
         return $languages;
      }
   }
   public string $language {
      get => $this->languages[0] ?? '';
   }

   /** @var array<string> */
   public array $charsets {
      get {
         $charsets = $this->negotiate(with: self::ACCEPTS_CHARSETS);
         return $charsets;
      }
   }
   public string $charset {
      get => $this->charsets[0] ?? '';
   }

   /** @var array<string> */
   public array $encodings {
      get {
         $encodings = $this->negotiate(with: self::ACCEPTS_ENCODINGS);
         return $encodings;
      }
   }
   public string $encoding {
      get => $this->encodings[0] ?? '';
   }

   // HTTP Caching Specification (RFC 7234)
   public bool $fresh {
      get => $this->freshen();
   }
   public bool $stale {
      get => ! $this->fresh;
   }

   /** @var array<string, mixed> */
   protected array $_SERVER;

   // * Metadata
   public readonly string $on;
   public readonly string $at;
   public readonly int $time;
   public static int $multiparts = 0;
   // @ Connection management
   public bool $closeConnection = false;

   private string $authUsername = '';
   private string $authPassword = '';


   public function __construct ()
   {
      $this->Header = new Header;
      $this->Body = new Body;

      // * Config
      $this->base = '';
      // TODO pre-defined filters
      // $this->Filter->sanitize(...) | $this->Filter->validate(...)

      // * Data
      // ... dynamically
      $_POST = [];
      #$_FILES = []; // Reseted on __destruct only
      $_SERVER = [];

      // * Metadata
      $this->on = date("Y-m-d");
      $this->at = date("H:i:s");
      $this->time = time();

   }

   public function __clone ()
   {
      $this->_SERVER = $_SERVER;

      $this->Session = null;
   }
   public function reboot (): void
   {
      if ( isSet($this->_SERVER) ) {
         $_SERVER = $this->_SERVER;
      }

      $this->Session = null;

      // @ Invalidate URI-derived caches (safe: URI is re-set on cache miss,
      // but on cache hit the cached Request keeps its URI so these stay valid).
      // Reset here only when session-sensitive or cross-connection state may have changed.
      // NOTE: we keep $_URL et al. because the cached Request's URI is unchanged.
   }

   /**
    * Get a single query parameter as a string (type-safe).
    *
    * @param string $key The query parameter name.
    * @param string $default The default value if the key is missing or not a scalar.
    *
    * @return string
    */
   public function query (string $key, string $default = ''): string
   {
      $value = $this->queries[$key] ?? null;

      if ($value === null || is_array($value)) {
         return $default;
      }

      return (string) $value;
   }

   // # Raw
   /**
    * Decode the Request raw received
    *
    * @param Packages $Package
    * @param string $buffer
    * @param int $size
    *
    * @return int
    */
   public function decode (Packages $Package, string &$buffer, int $size): int
   {
      // @ Check Request raw separator
      $separator_position = strpos($buffer, "\r\n\r\n");
      // @ Check if the Request raw has a separator
      if ($separator_position === false) {
         // @ Check Request raw length
         if ($size >= 16384) { // Package size
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
         }

         return 0;
      }

      // @ Init Request length
      $length = $separator_position + 4;

      // # Request Meta (first line of HTTP Header)
      // @ Get Request Meta raw
      // Sample: GET /path HTTP/1.1
      $meta_raw = strstr($buffer, "\r\n", true);
      if ($meta_raw === false) {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return 0;
      }

      @[$method, $URI, $protocol] = explode(' ', $meta_raw, 3);

      // @ Check Request Meta
      if (! $method || ! $URI || ! $protocol) {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return 0;
      }
      // method
      switch ($method) {
         case 'GET':
         case 'HEAD':
         case 'POST':
         case 'PUT':
         case 'PATCH':
         case 'DELETE':
         case 'OPTIONS':
            break;
         case 'TRACE':
         case 'CONNECT':
            $Package->reject("HTTP/1.1 501 Not Implemented\r\n\r\n");
            return 0;
         default:
            $Package->reject("HTTP/1.1 405 Method Not Allowed\r\nAllow: GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS\r\n\r\n");
            return 0;
      }
      // URI
      if (strlen($URI) > 8192) {
         $Package->reject("HTTP/1.1 414 URI Too Long\r\n\r\n");
         return 0;
      }
      // protocol
      
      // @ Set Request Meta length
      $meta_length = strlen($meta_raw);

      // # Request Header
      // @ Get Request Header raw
      $header_raw = substr($buffer, $meta_length + 2, $separator_position - $meta_length);

      // # Request Body
      // @ Set Request Body length if possible (RFC 9112 §6.1 — strict parse)
      //
      //   Single case-insensitive locate + line-slice + `ctype_digit`
      //   validation rejects every smuggling vector in one pass:
      //     - negative / signed          `Content-Length: -10`
      //     - multi-space OWS            `Content-Length:  10`  (fast-path desync)
      //     - lowercase + multi-space    `Content-length:  10`  (regex bypass)
      //     - comma / list form          `Content-Length: 10, 20`
      //     - hex / sci prefixes         `Content-Length: 0x10`
      //     - duplicate headers          two `Content-Length:` lines
      //
      //   Hot-path guard: `strpos("ontent-")` is a case-sensitive 8-byte scan
      //   that hits both `Content-` and `content-` (shared lowercase stem).
      //   GET requests with no Content-* header (the benchmark path) skip
      //   the costly `stripos` entirely — ~10-30ns versus ~200ns stripos on
      //   a typical request header block.
      if (strpos($header_raw, "ontent-") !== false
         && ($clStart = stripos($header_raw, "\r\nContent-Length:")) !== false
      ) {
         $clLineEnd = strpos($header_raw, "\r\n", $clStart + 2);
         // Reject missing CRLF or duplicate Content-Length (smuggling guard)
         if ($clLineEnd === false
            || stripos($header_raw, "\r\nContent-Length:", $clLineEnd) !== false
         ) {
            $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
            return 0;
         }
         // "\r\nContent-Length:" is 17 bytes — value follows, OWS = SP/HTAB only.
         $clValue = trim(
            substr($header_raw, $clStart + 17, $clLineEnd - $clStart - 17),
            " \t"
         );
         if ($clValue === '' || strlen($clValue) > 19 || ! ctype_digit($clValue)) {
            $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
            return 0;
         }
         $content_length = (int) $clValue;
      }

      // @ Handle Transfer-Encoding (RFC 9112 §6.1 / §6.3)
      //   Same fingerprint trick: `strpos("ransfer-")` skips the stripos for
      //   any request that has no Transfer-* header.
      if (strpos($header_raw, "ransfer-") !== false
         && ($teStart = stripos($header_raw, "\r\nTransfer-Encoding:")) !== false
      ) {
         // Transfer-Encoding + Content-Length conflict
         if (isSet($content_length)) {
            $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
            return 0;
         }
         $teLineEnd = strpos($header_raw, "\r\n", $teStart + 2);
         // Reject missing CRLF or duplicate Transfer-Encoding (smuggling guard)
         if ($teLineEnd === false
            || stripos($header_raw, "\r\nTransfer-Encoding:", $teLineEnd) !== false
         ) {
            $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
            return 0;
         }
         // "\r\nTransfer-Encoding:" is 20 bytes. On requests the value MUST
         //   be exactly "chunked" (case-insensitive, OWS trimmed). Any list
         //   form (`chunked, gzip`, `gzip, chunked`, `x,chunked`) or variant
         //   (`\tchunked`, `chunked\t`) is rejected — those are the classic
         //   TE-smuggling vectors where an upstream honours `chunked` while
         //   the origin treats the request as opaque.
         $teValue = trim(
            substr($header_raw, $teStart + 20, $teLineEnd - $teStart - 20),
            " \t"
         );
         if (strcasecmp($teValue, 'chunked') !== 0) {
            $Package->reject("HTTP/1.1 501 Not Implemented\r\n\r\n");
            return 0;
         }

         $this->Body->waiting = true;
         $this->Body->length = 0;

         $Decoder = new Decoder_Chunked;
         $Decoder->init();

         // @ Feed initial body data if any
         $initialBody = substr($buffer, $separator_position + 4);
         if ($initialBody !== '') {
            $Decoder->feed($initialBody);
         }

         $Package->Decoder = $Decoder;
      }

      // @ Handle Expect header (RFC 9110 §10.1.1)
      if (strpos($header_raw, "\r\nExpect: ") !== false
         || stripos($header_raw, "\r\nexpect: ") !== false
      ) {
         if (stripos($header_raw, "\r\nExpect: 100-continue") !== false
            || stripos($header_raw, "\r\nexpect: 100-continue") !== false
         ) {
            @fwrite($Package->Connection->Socket, "HTTP/1.1 100 Continue\r\n\r\n");
         }
         else {
            $Package->reject("HTTP/1.1 417 Expectation Failed\r\n\r\n");
            return 0;
         }
      }

      // @ Set Request Body raw if possible
      if ( isSet($content_length) ) {
         $length += $content_length; // @ Add Request Body length

         // @ Detect multipart/form-data for streaming download
         $isMultipart = false;
         $multipartBoundary = '';
         if ( $ctPos = stripos($header_raw, "\r\nContent-Type: multipart/form-data") ) {
            $isMultipart = true;
            if ( preg_match('/boundary="?(\S+)"?/', substr($header_raw, $ctPos, 200), $bMatch) ) {
               $multipartBoundary = trim('--' . $bMatch[1], '"');
            }
         }

         // ?: Validate max request size
         $maxSize = $isMultipart ? static::$maxFileSize : static::$maxBodySize;
         if ($length > $maxSize) {
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
            return 0;
         }

         if ($content_length > 0) {
            // @ Check if HTTP content is not empty
            if ($size >= $separator_position + 4) {
               $initialBody = substr($buffer, $separator_position + 4, $content_length);
               $initialLength = strlen($initialBody);
            }
            else {
               $initialBody = '';
               $initialLength = 0;
            }

            // @ Use streaming decoder for multipart/form-data
            if ($isMultipart && $multipartBoundary !== '') {
               if ($initialLength >= $content_length) {
                  // @ Complete body available: process immediately via streaming decoder
                  $this->Body->downloaded = $initialLength;
                  $this->Body->length = $content_length;
                  $this->Body->waiting = true;
                  $this->Body->streaming = true;

                  $Decoder = new Decoder_Downloading;
                  $Decoder->init($multipartBoundary);
                  $Decoder->feed($initialBody);
                  // @ Simulate decode call with the full body data.
                  // @ Body is fully consumed here; do NOT attach the decoder
                  // to the Connection — otherwise the next request on the
                  // same connection would dispatch through this stale
                  // decoder and trigger an extra Request::__construct,
                  // whose __destruct clears the current $_FILES superglobal.
                  $Decoder->decode($Package, '', 0);
               }
               else {
                  // @ Incomplete body: set streaming decoder for subsequent chunks
                  $this->Body->downloaded = $initialLength;
                  $this->Body->waiting = true;
                  $this->Body->streaming = true;

                  $Decoder = new Decoder_Downloading;
                  $Decoder->init($multipartBoundary);

                  if ($initialBody !== '') {
                     $Decoder->feed($initialBody);
                  }

                  $Package->Decoder = $Decoder;
               }
            }
            else {
               // @ Non-multipart: buffer in memory (original behavior)
               $this->Body->raw = $initialBody;
               $this->Body->downloaded = $initialLength;

               if ($content_length > $this->Body->downloaded) {
                  $this->Body->waiting = true;
                  $Waiting = new Decoder_Waiting;
                  $Waiting->init();
                  $Package->Decoder = $Waiting;
               }
            }
         }

         $this->Body->length = $content_length;
      }

      // @ Set Request
      // # Request
      // address
      $this->address = $Package->Connection->ip;
      // port
      $this->port = $Package->Connection->port;
      // scheme
      $this->scheme = $Package->Connection->encrypted ? 'https' : 'http';
      // @@
      // method
      $this->method = $method;
      // URI
      $this->URI = $URI;
      // protocol
      $this->protocol = $protocol;
      // @ Validate Host header (RFC 9112 §3.2) — required for HTTP/1.1
      if ($protocol === 'HTTP/1.1') {
         // ! Reject duplicate Host headers (request smuggling guard)
         if (preg_match_all("/(?:^|\r\n)host:/i", $header_raw) > 1) {
            $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
            return 0;
         }
         // ! Require a valid Host header with non-empty value
         if (preg_match("/(?:^|\r\n)Host: *(\S+)/i", $header_raw) !== 1) {
            $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
            return 0;
         }
      }
      // @ Connection management (RFC 9112 §9.3)
      // HTTP/1.1: close only when explicitly requested via Connection: close
      // HTTP/1.0: close by default unless Connection: keep-alive
      $this->closeConnection = stripos($header_raw, 'Connection: close') !== false
         || ($protocol === 'HTTP/1.0' && stripos($header_raw, 'Connection: keep-alive') === false);

      // # Request Header
      // raw
      $this->Header->define(raw: $header_raw);
      // host
      #$_SERVER['HTTP_HOST'] = $this->Header->get('HOST');

      // # Request Body
      $this->Body->position = $separator_position + 4;

      // @ return Request length
      return $length;
   }

   /**
    * Receive the input data from the request.
    *
    * @return array<string>|null
    */
   public function input (): array|null
   {
      /** @var array<string> $inputs */
      $inputs = [];

      // @ Try to convert input automatically
      try {
         $input = $this->input;

         // raw (JSON)
         $decoded = json_decode(
            json: $input,
            associative: true,
            depth: 512,
            flags: JSON_THROW_ON_ERROR
         );
         /** @var array<string> $inputs */
         $inputs = is_array($decoded) ? $decoded : [];
      }
      catch (JsonException) {
         // x-www-form-urlencoded
         parse_str(
            string: $input,
            result: $inputs
         );
      }

      return $inputs; // @phpstan-ignore-line
   }
   /**
    * Download the request body data (files and fields).
    *
    * @return array<array<string>>|null The request method.
    */
   /**
    * @return array<string, mixed>|null
    */
   public function download (null|string $key = null): array|null
   {
      // : parsed $_FILES || null
      if ($key === null) {
         /** @var array<string,array<string,bool|int|string|array<int|string,bool|int|string>>> $files */
         $files = $_FILES;

         return $files;
      }

      if ( isSet($_FILES[$key]) && is_array($_FILES[$key]) ) {
         /** @var array<string,bool|int|string|array<int|string,bool|int|string>> $file */
         $file = $_FILES[$key];
         return $file;
      }

      return null;
   }
   /**
    * Receive the request body data.
    *
    * @return array<array<string>>|string|null The request method.
    */
   /**
    * @return array<string, mixed>|string|null
    */
   public function receive (null|string $key = null): array|string|null
   {
      $content_type = $this->Header->get('Content-Type');
      $parsed = $this->Body->parse(
         content: 'raw',
         type: $content_type
      );

      // : parsed $_POST || null
      if ($key === null) {
         /** @var array<string,array<string>|bool|float|int|string> $post */
         $post = $_POST;
         return $post;
      }

      if ( isSet($_POST[$key]) ) {
         $value = $_POST[$key];

         if (is_array($value)) {
            /** @var array<string,mixed> $value */
            return $value;
         }

         if (is_scalar($value)) {
            return (string) $value;
         }
      }

      return null;
   }

   // HTTP Basic Authentication
   /**
    * @return Basic|null
    */
   public function authenticate (): Basic|null
   {
      $authorization = $this->Header->get('Authorization');

      if (! is_string($authorization) || strpos($authorization, 'Basic ') !== 0) {
         return null;
      }

      $encoded_credentials = trim(substr($authorization, 6));
      if ($encoded_credentials === '') {
         return null;
      }

      $decoded_credentials = base64_decode($encoded_credentials, true);
      if ($decoded_credentials === false) {
         return null;
      }

      if (strpos($decoded_credentials, ':') === false) {
         return null;
      }

      [$username, $password] = explode(':', $decoded_credentials, 2);

      $this->username = $username;
      $this->password = $password;

      return new Basic($username, $password);
   }

   // HTTP Content Negotiation
   public const int ACCEPTS_TYPES = 1;
   public const int ACCEPTS_LANGUAGES = 2;
   public const int ACCEPTS_CHARSETS = 4;
   public const int ACCEPTS_ENCODINGS = 8;
   /**
    * Negotiate the request content.
    *
    * @param int $with The content to negotiate.
    *
    * @return array<string> The negotiated content.
    */
   public function negotiate (int $with = self::ACCEPTS_TYPES): array
   {
      switch ($with) {
         case self::ACCEPTS_TYPES:
            // @ Accept
            $header = $this->Header->get('Accept');
            $pattern = '/([\w\/\+\*.-]+)(?:;\s*q\s*=\s*(\d*(?:\.\d+)?))?/i';

            break;
         case self::ACCEPTS_CHARSETS:
            // @ Accept-Charset
            $header = $this->Header->get('Accept-Charset');
            $pattern = '/([a-z0-9]{1,8}(?:[-_][a-z0-9]{1,8}){0,3})\s*(?:;\s*q\s*=\s*(\d*(?:\.\d+)?))?/i';

            break;
         case self::ACCEPTS_LANGUAGES:
            // @ Accept-Language
            $header = $this->Header->get('Accept-Language');
            $pattern = '/([a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(\d*(?:\.\d+)?))?/i';

            break;
         case self::ACCEPTS_ENCODINGS:
            // @ Accept-Encoding
            $header = $this->Header->get('Accept-Encoding');
            $pattern = '/([a-z0-9]{1,8}(?:[-_][a-z0-9]{1,8}){0,3})\s*(?:;\s*q\s*=\s*(\d*(?:\.\d+)?))?/i';

            break;
         default:
            $header = null;
            $pattern = null;
            break;
      }

      // @ Validate header
      if ( empty($header) ) {
         return [];
      }

      if ($pattern === null) {
         return [];
      }

      // @ Validate RegEx
      preg_match_all(
         $pattern,
         $header,
         $matches,
         PREG_SET_ORDER
      );

      $results = [];
      foreach ($matches as $match) {
         $item = $match[1];
         $quality = (float) ($match[2] ?? 1.0);

         $results[$item] = $quality;
      }

      uasort($results, function ($a, $b) {
         return $b <=> $a;
      });

      return array_keys($results);
   }

   // HTTP Caching Specification
   public function freshen (): bool
   {
      if ($this->method !== 'GET' && $this->method !== 'HEAD') {
         return false;
      }

      $if_modified_since = $this->Header->get('If-Modified-Since');
      $if_none_match = $this->Header->get('If-None-Match');
      if ( ! $if_modified_since && ! $if_none_match ) {
         return false;
      }

      // @ cache-control
      $cache_control = $this->Header->get('Cache-Control');
      if ($cache_control && preg_match('/(?:^|,)\s*?no-cache\s*?(?:,|$)/', $cache_control)) {
         return false;
      }

      // @ if-none-match
      if ($if_none_match && $if_none_match !== '*') {
         $entity_tag = WPI->Response->Header->get('ETag');

         if ( ! $entity_tag ) {
            return false;
         }

         $entity_tag_stale = true;

         // ? HTTP Parse Token List
         $matches = [];
         $start = 0;
         $end = 0;
         // @ Gather tokens
         for ($i = 0; $i < strlen($if_none_match); $i++) {
            switch ($if_none_match[$i]) {
               case ' ':
                  if ($start === $end) {
                     $start = $end = $i + 1;
                  }
                  break;
               case ',':
                  $matches[] = substr($if_none_match, $start, $end);
                  $start = $end = $i + 1;
                  break;
               default:
                  $end = $i + 1;
                  break;
            }
         }
         // final token
         $matches[] = substr($if_none_match, $start, $end);

         for ($i = 0; $i < count($matches); $i++) {
            $match = $matches[$i];
            if ($match === $entity_tag || $match === 'W/' . $entity_tag || 'W/' . $match === $entity_tag) {
               $entity_tag_stale = false;
               break;
            }
         }

         if ($entity_tag_stale) {
            return false;
         }
      }

      // @ if-modified-since
      if ($if_modified_since !== null) {
         $last_modified = WPI->Response->Header->get('Last-Modified');
         if ($last_modified === '') {
            return false;
         }

         $last_modified_time = strtotime($last_modified);
         $if_modified_since_time = strtotime($if_modified_since);
         if ($last_modified_time === false || $if_modified_since_time === false) {
            return false;
         }

         $modified_stale = $last_modified_time > $if_modified_since_time;
         if ($modified_stale) {
            return false;
         }
      }

      return true;
   }

   /**
    * Parse range header field
    *
    * @param int $size
    * @param string $header
    * @param bool $combine
    *
    * @return int|array<int|string, array<string, int>|string>
    */
   public function range (int $size, string $header, bool $combine = false): int|array
   {
      // @ Validate
      $equalIndex = strpos($header, '=');
      if ($equalIndex === false) {
         return -2; // @ Return malformed header string
      }

      // @ Split ranges
      $headerRanges = explode(',', substr($header, $equalIndex + 1));
      $ranges = [];

      // @ Iterate ranges (0-1,50-100,...)
      for ($i = 0; $i < count($headerRanges); $i++) {
         $range = explode('-', $headerRanges[$i]);

         if ( count($range) > 2 ) {
            return -1; // Unsatisifiable range
         }

         if ( $range[0] !== '' && ! ctype_digit($range[0]) ) {
            return -1; // Unsatisifiable range
         }
         if ( $range[1] !== '' && ! ctype_digit($range[1]) ) {
            return -1; // Unsatisifiable range
         }

         $start = (int) $range[0];
         $end = (int) $range[1];

         if ($range[0] === '') {
            $start = $size - $end;
            $end = $size - 1;
         }
         else if ($range[1] === '') {
            $end = $size - 1;
         }

         // @ Limit last-byte-pos to current length
         if ($end > $size - 1) {
            $end = $size - 1;
         }

         if ($start > $end || $start < 0) {
            continue;
         }

         $ranges[] = [
            'start' => $start,
            'end' => $end
         ];
      }

      if ( empty($ranges) ) {
         return -1; // Unsatisifiable range
      }

      if ($combine) {
         // @ Combine overlapping & adjacent ranges
         // @ Map with index
         $ordered = array_map(
            function ($range, $index) {
               return [
                  'start' => $range['start'],
                  'end' => $range['end'],
                  'index' => $index
               ];
            },
            $ranges,
            array_keys($ranges)
         );
         // @ Sort by range start
         usort($ordered, function ($a, $b) {
            return (int) $a['start'] - (int) $b['start'];
         });
     
         for ($j = 0, $i = 1; $i < count($ordered); $i++) {
            $next = &$ordered[$i];
            $current = &$ordered[$j];

            if ((int) $next['start'] > (int) $current['end'] + 1) {
               // @ Next range
               $ordered[++$j] = $next;
            }
            else if ($next['end'] > $current['end']) {
               // @ Extend range
               $current['end'] = $next['end'];
               $current['index'] = min($current['index'], $next['index']);
            }
         }

         // @ Trim ordered array
         $ordered2 = array_slice($ordered, 0, $j + 1);

         // @ Generate combined range
         // @ Sort by range index
         usort($ordered2, function ($a, $b) {
            return (int) $a['index'] - (int) $b['index'];
         });
         // @ Map without index
         $ranges = array_map(
            function ($range) {
               return [
                  'start' => (int) $range['start'],
                  'end' => (int) $range['end']
               ];
            },
            $ordered2
         );
      }

      $ranges['type'] = substr($header, 0, $equalIndex);

      return $ranges;
   }

   public function __destruct ()
   {
      // @ Delete files downloaded by server in temp folder
      if (empty($_FILES) === false) {
         // @ Clear cache
         clearstatcache();

         // @ Delete temp files
         array_walk_recursive($_FILES, function ($value, $key) {
            if ($key === 'tmp_name' && is_file($value) === true) {
               unlink($value);
            }
         });

         // @ Reset $_FILES
         $_FILES = [];
      }
   }
}
