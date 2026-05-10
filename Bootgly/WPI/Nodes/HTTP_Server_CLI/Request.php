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
use function str_ends_with;
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
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Chunked;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Waiting;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Authentications\Basic;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Frame;
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
   /** @var int Maximum multipart text field size in bytes (default: 1MB) */
   public static int $maxMultipartFieldSize = 1 * 1024 * 1024; // @ 1 megabyte
   /** @var int Maximum multipart part header block size in bytes (default: 8KB) */
   public static int $maxMultipartHeaderSize = 8 * 1024; // @ 8 kilobytes
   /** @var int Maximum number of multipart text fields */
   public static int $maxMultipartFields = 1024;
   /** @var int Maximum number of multipart file parts */
   public static int $maxMultipartFiles = 1024;
   /**
    * Allowed `Host` header values (RFC 9112 §3.2 / §7.2). When non-empty,
    *   any request whose `Host` header (case-insensitive, port-agnostic)
    *   does not match is rejected with `400 Bad Request` at decode time —
    *   blocks Host-header spoofing (cache poisoning, password-reset
    *   poisoning in multi-tenant apps).
    *
    *   Each entry is a lowercase hostname WITHOUT port. Wildcard prefix
    *   `*.example.com` matches any single-label subdomain. Empty list
    *   (default) disables enforcement for backward compatibility.
    *
    * @var array<int,string>
    */
   public static array $allowedHosts = [];

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
            // ! Build Session lazily — do NOT emit Set-Cookie here.
            //   Cookie issuance is deferred until the session is actually
            //   mutated (set/put/delete/pull/forget/flush/regenerate).
            //   Emitting on read-only access is a session-fixation primitive
            //   and a DoS surface on static assets / API probes. See
            //   Security_Audit_Report §3.
            $name = Session::$name;
            $id = $this->Cookies->get($name);

            // ! Strict mode for client-supplied session IDs (RFC 6265 + OWASP
            //   Session Management). Two rejection paths:
            //     1. The cookie is missing or carries an ID that does not
            //        match the canonical hex format — generate a fresh ID
            //        before constructing Session.
            //     2. The format-valid ID does not resolve to an existing
            //        server-issued session file (Handler::read() failed to
            //        load any data) — rotate to a fresh ID before any
            //        first write so persistence cannot adopt an attacker-
            //        chosen ID. This closes the session-fixation primitive
            //        described in `Security_Audit_Report` Finding 6.
            $clientSupplied = $id !== ''
               && preg_match('/^[a-f0-9]{32,64}$/', $id) === 1;

            if ( ! $clientSupplied) {
               $id = bin2hex(random_bytes(16));
            }

            $Session = new Session($id);

            if ($clientSupplied && ! $Session->loaded) {
               $Session->rotate(bin2hex(random_bytes(16)));
            }

            $this->Session = $Session;
         }

         return $this->Session;
      }
   }
   // / Body
   /**
    * The Request Body input.
    */
   public string $input {
      get {
         if ($this->Body->input === null) {
            // @ Materialize Body->input via Body->parse (side-effect).
            $this->receive();
         }
         return $this->Body->input ?? '';
      }
   }

   /**
    * @internal Backing storage for $fields.
    *
    * @var array<string, array<string>|bool|float|int|string>
    */
   private array $_fields = [];
   /**
    * @internal Backing storage for $files.
    *
    * @var array<string, array<string, bool|int|string|array<int|string, bool|int|string>>>
    */
   private array $_files = [];
   /**
    * The Request fields (parsed body for application/x-www-form-urlencoded
    * and multipart/form-data; raw decoded array for application/json).
    *
    * Replaces the legacy $post property; no superglobal $_POST is used
    * anywhere in the HTTP_Server_CLI request lifecycle.
    *
    * @var array<string, array<string>|bool|float|int|string>
    */
   public array $fields {
      get {
         if ($this->method === 'POST' && $this->_fields === [] && ! $this->Body->streaming) {
            /** @var array<string, array<string>|bool|float|int|string>|null $input */
            $input = $this->input();
            return $input ?? [];
         }

         return $this->_fields;
      }
      set { $this->_fields = $value; }
   }
   /**
    * The Request files (uploaded multipart parts written to disk by the
    * streaming decoder). Replaces the legacy $_FILES superglobal.
    *
    * @var array<string, array<string, bool|int|string|array<int|string, bool|int|string>>>
    */
   public array $files {
      get { return $this->_files; }
      set { $this->_files = $value; }
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
            if ($auth instanceof Basic) {
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
            if ($auth instanceof Basic) {
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
   // HTTP Bearer Authentication
   /**
    * Bearer token extracted from the `Authorization` header.
    *
    * The value is resolved lazily and cached per request. Assigning this
    * property lets tests or middleware inject a token without reparsing
    * headers.
    */
   public string $token {
      get {
         if ($this->authTokenParsed === false) {
            $authorization = $this->Header->get('Authorization');
            if (is_string($authorization) && stripos($authorization, 'Bearer ') === 0) {
               $this->authToken = trim(substr($authorization, 7));
            }

            $this->authTokenParsed = true;
         }

         return $this->authToken;
      }
      set {
         $this->authToken = $value;
         $this->authTokenParsed = true;
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

   // * Metadata
   public readonly string $on;
   public readonly string $at;
   public readonly int $time;
   public static int $multiparts = 0;
   /**
    * Authenticated principal exposed by router authentication guards.
    */
   public mixed $identity = null;
   /**
    * Verified authentication claims exposed by token-based guards.
    *
    * @var array<string,mixed>
    */
   public array $claims = [];
   /**
    * Verified token headers exposed by token-based guards.
    *
    * @var array<string,mixed>
    */
   public array $tokenHeaders = [];
   // @ Connection management
   public bool $closeConnection = false;

   private string $authUsername = '';
   private string $authPassword = '';
   private bool $authParsed = false;
   private null|Basic $authCredentials = null;
   /**
    * Cached Bearer token parsed from the Authorization header.
    */
   private string $authToken = '';
   private bool $authTokenParsed = false;


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
      $this->_fields = [];
      $this->_files = [];

      // * Metadata
      $this->on = date("Y-m-d");
      $this->at = date("H:i:s");
      $this->time = time();

   }

   public function __clone ()
   {
      // @ Per-request data: never bleed across cached connections.
      $this->_fields = [];
      $this->_files = [];
      $this->authUsername = '';
      $this->authPassword = '';
      $this->authParsed = false;
      $this->authCredentials = null;
      $this->authToken = '';
      $this->authTokenParsed = false;
      $this->identity = null;
      $this->claims = [];
      $this->tokenHeaders = [];

      $this->Session = null;

      // ! Deep-clone mutable sub-objects so handler/middleware mutations on
      //   this Request (e.g. `$Request->Body->raw = ...`) do NOT contaminate
      //   the Decoder_ L1 cache template and bleed into future connections
      //   that send byte-identical headers.
      //   See tests/Security/7.01-decoder_cache_shallow_clone_subobject_bleed.test.php
      $this->Body = clone $this->Body;
      $this->Header = clone $this->Header;
   }
   public function reboot (): void
   {
      // @ Reset per-request data accumulators.
      $this->_fields = [];
      $this->_files = [];
      $this->authUsername = '';
      $this->authPassword = '';
      $this->authParsed = false;
      $this->authCredentials = null;
      $this->authToken = '';
      $this->authTokenParsed = false;
      $this->identity = null;
      $this->claims = [];
      $this->tokenHeaders = [];

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
    * @return States
    */
   public function decode (Packages $Package, string &$buffer, int $size): States
   {
      // @ Centralized HTTP/1.1 framing parse (Recommendation #1).
      //   A SINGLE linear scan over the request head produces the canonical
      //   `Frame` value object: request line, lowercased fields map, and
      //   every framing decision (CL / TE / Expect / Content-Type /
      //   Connection / Host duplicate). On rejection the parser has already
      //   called `$Package->reject()` (which sets `$Package->rejected`) and
      //   returns `null`. Incomplete buffers also return `null` (no reject)
      //   so we wait for more bytes — the two cases are disambiguated via
      //   `$Package->rejected` (Recommendation #2).
      $Frame = Frame::parse($Package, $buffer, $size);
      if ($Frame === null) {
         $Package->consumed = 0;
         return $Package->rejected ? States::Rejected : States::Incomplete;
      }

      $separator_position = $Frame->separatorPosition;
      $length = $separator_position + 4;
      $header_raw = $Frame->headerRaw;
      $protocol = $Frame->protocol;

      // @ Body decoder dispatch — fed entirely from `Frame` framing decisions.
      if ($Frame->chunked) {
         $this->Body->waiting = true;
         $this->Body->length = 0;

         $Decoder = new Decoder_Chunked;
         $Decoder->init();

         $initialBody = substr($buffer, $separator_position + 4);
         if ($initialBody !== '') {
            $Decoder->feed($initialBody);
         }

         $Package->Decoder = $Decoder;
      }

      // @ Expect: 100-continue — write the interim response only after
      //   `Frame::parse()` has validated CL bound and rejected the
      //   Expect+chunked combo. Socket I/O stays out of the parser.
      if ($Frame->expectContinue) {
         @fwrite($Package->Connection->Socket, "HTTP/1.1 100 Continue\r\n\r\n");
      }

      // @ Body buffer setup when Content-Length is known.
      if ($Frame->contentLength !== null) {
         $content_length = $Frame->contentLength;
         $length += $content_length;

         $multipartBoundary = $Frame->multipartBoundary;
         $isMultipart = $multipartBoundary !== '';

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
            if ($isMultipart) {
               if ($initialLength >= $content_length) {
                  // @ Complete body available: process immediately via streaming decoder
                  $this->Body->downloaded = $initialLength;
                  $this->Body->length = $content_length;
                  $this->Body->waiting = true;
                  $this->Body->streaming = true;

                  $Decoder = new Decoder_Downloading;
                  $Decoder->init($multipartBoundary);
                  // @ Simulate decode call with the full body data.
                  // @ Body is fully consumed here; do NOT attach the decoder
                  // to the Connection — otherwise the next request on the
                  // same connection would dispatch through this stale
                  // decoder and trigger an extra Request::__construct,
                  // whose __destruct clears the current $_FILES superglobal.
                  if ($Decoder->decode($Package, $initialBody, $initialLength) !== States::Complete) {
                     $Package->consumed = 0;
                     return States::Rejected;
                  }
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
      $this->method = $Frame->method;
      // URI
      $this->URI = $Frame->URI;
      // protocol
      $this->protocol = $protocol;

      // @ Host allowlist enforcement (audit #10).
      //   `Frame::parse()` already rejected duplicate Host and a missing Host
      //   on HTTP/1.1. Allowlist enforcement is decoupled because it depends
      //   on a static config and is opt-in (default: empty list).
      //   Hot-path guard: `static::$allowedHosts === []` short-circuits.
      if (static::$allowedHosts !== [] && $Frame->hostValue !== '') {
         $hostValue = strtolower($Frame->hostValue);
         // Strip port (Host = uri-host [":" port] per RFC 9110 §7.2).
         //   IPv6 literals are bracketed; for name hosts use the last colon.
         if ($hostValue[0] === '[') {
            $rb = strpos($hostValue, ']');
            $hostName = $rb === false ? $hostValue : substr($hostValue, 0, $rb + 1);
         }
         else {
            $colon = strrpos($hostValue, ':');
            $hostName = $colon === false ? $hostValue : substr($hostValue, 0, $colon);
         }

         $allowed = false;
         foreach (static::$allowedHosts as $entry) {
            if ($entry === $hostName) {
               $allowed = true;
               break;
            }
            // Wildcard prefix `*.example.com` matches `a.example.com` only.
            if (
               strlen($entry) > 2
               && $entry[0] === '*' && $entry[1] === '.'
               && str_ends_with($hostName, substr($entry, 1))
               && strpos(
                  $hostName,
                  '.',
                  0
               ) === strlen($hostName) - (strlen($entry) - 1)
            ) {
               $allowed = true;
               break;
            }
         }

         if (! $allowed) {
            $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
            $Package->consumed = 0;
            return States::Rejected;
         }
      }

      // @ Connection management (RFC 9112 §9.3) — already decided by Frame.
      $this->closeConnection = $Frame->closeConnection;

      // # Request Header
      // raw — exposes the (possibly X-Bootgly-Test-stripped) header block.
      $this->Header->define(raw: $header_raw);
      // fields — adopt the lowercased map produced by the same scan.
      $this->Header->adopt($Frame->fields);

      // # Request Body
      $this->Body->position = $separator_position + 4;

      // @ return Request length
      $Package->consumed = $length;
      return States::Complete;
   }

   /**
    * Receive the input data from the request.
    *
    * Parsing is strictly dispatched on the `Content-Type` media-type. A body
    * declared `application/json` MUST NOT silently fall through to
    * `parse_str()` on parse failure — otherwise an attacker can submit an
    * invalid-JSON / valid-urlencoded payload to inject arbitrary keys into
    * `$Request->post` (mass-assignment, CSRF-token confusion, `_method`
    * override). An unknown / missing Content-Type yields `[]`.
    *
    * @return array<string>|null
    */
   public function input (): array|null
   {
      $input = $this->input;
      if ($input === '') {
         return [];
      }

      $contentType = $this->Header->get('Content-Type') ?? '';

      // @ JSON — strict: on parse failure, return []; never reinterpret.
      if ($contentType !== '' && stripos($contentType, 'json') !== false) {
         try {
            $decoded = json_decode(
               json: $input,
               associative: true,
               depth: 512,
               flags: JSON_THROW_ON_ERROR
            );
            /** @var array<string> $inputs */
            $inputs = is_array($decoded) ? $decoded : [];
            return $inputs;
         }
         catch (JsonException) {
            return [];
         }
      }

      // @ application/x-www-form-urlencoded — the only media-type where
      //   parse_str() is a valid parser for the body.
      if (
         $contentType !== ''
         && stripos($contentType, 'application/x-www-form-urlencoded') !== false
      ) {
         /** @var array<string> $inputs */
         $inputs = [];
         parse_str(string: $input, result: $inputs);
         return $inputs; // @phpstan-ignore return.type
      }

      // @ Unknown / missing Content-Type — refuse to guess.
      return [];
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
      // : parsed files || null
      if ($key === null) {
         /** @var array<string,array<string,bool|int|string|array<int|string,bool|int|string>>> $files */
         $files = $this->_files;

         return $files;
      }

      if ( isSet($this->_files[$key]) ) {
         /** @var array<string,bool|int|string|array<int|string,bool|int|string>> $file */
         $file = $this->_files[$key];
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
      // @ Materialize Body->input from Body->raw (validates JSON / handles CT).
      //   Side-effect: $this->input property hook stops recursing once
      //   $Body->input is non-null.
      $content_type = $this->Header->get('Content-Type');
      $this->Body->parse(
         content: 'raw',
         type: $content_type
      );

      // @ Pure parser: returns parsed body as array (or [] for unknown CT).
      //   Stored on the Request to back $fields without populating $_POST.
      $parsed = $this->input();
      if ($parsed !== null) {
         $this->_fields = $parsed;
      }

      // : parsed fields || null
      if ($key === null) {
         return $this->_fields;
      }

      if (isSet($this->_fields[$key])) {
         $value = $this->_fields[$key];

         if (is_array($value)) {
            /** @var array<string,mixed> $value */
            return $value;
         }

         return (string) $value;
      }

      return null;
   }

   // HTTP Authentication
   /**
    * Parse supported HTTP `Authorization` credentials.
    *
    * Supports Basic credentials only. Bearer tokens are exposed through the
    * `$token` property and verified by router authentication guards.
    *
    * @return Basic|null
    */
   public function authenticate (): Basic|null
   {
      if ($this->authParsed) {
         return $this->authCredentials;
      }

      $this->authParsed = true;

      $authorization = $this->Header->get('Authorization');

      if (! is_string($authorization)) {
         return null;
      }

      // ? Basic credentials.
      if (stripos($authorization, 'Basic ') === 0) {
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

         $this->authCredentials = new Basic($username, $password);
         return $this->authCredentials;
      }

      return null;
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
      if (empty($this->_files) === false) {
         // @ Clear cache
         clearstatcache();

         // @ Delete temp files + release aggregate-cap reservations
         array_walk_recursive($this->_files, function ($value, $key) {
            if ($key === 'tmp_name' && is_string($value) && $value !== '') {
               if (is_file($value) === true) {
                  unlink($value);
               }

               Downloads::discard($value);
            }
         });

         $this->_files = [];
      }
   }
}
