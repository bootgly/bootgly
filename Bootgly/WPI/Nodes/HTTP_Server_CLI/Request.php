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
use function count;
use function json_decode;
use function strtotime;
use function base64_decode;
use function preg_match_all;
use function uasort;
use function array_merge;
use function array_keys;
use function strtok;
use function rtrim;
use function substr;
use function strlen;
use function strrpos;
use function preg_match;
use function strpos;
use function parse_str;
use function explode;
use function strstr;
use function array_map;
use function array_unique;
use function array_filter;
use function filter_var;
use function date;
use function time;
use function array_walk_recursive;
use function clearstatcache;
use function is_file;
use function unlink;
use function stripos;
use AllowDynamicProperties;
use JsonException;

use const Bootgly\WPI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Waiting;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header\Cookies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Downloader;


#[AllowDynamicProperties]
class Request
{
   public protected(set) Header $Header;
   public protected(set) Body $Body;

   // * Config
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
    */
   public string $address {
      get {
         $header_field = $this->Header->fields['cf-connecting-ip'] ?? null;
         if (is_string($header_field)) {
            return $header_field;
         }

         return $this->address;
      }
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
   public string $URL {
      get {
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

         return $locator;
      }
   }
   /**
    * The Request URN (Uniform Resource Name).
    */
   public string $URN {
      get {
         $URL = $this->URL;

         // @ Extract the URN after the last slash
         $URN = substr($URL, strrpos($URL, '/') + 1);

         return $URN;
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
         $IPs = [];
         $field = $this->Header->get('X-Forwarded-For');

         if ($field && is_string($field)) {
            $IPs = explode(',', $field);
            $IPs = array_map('trim', $IPs);
            $IPs = array_unique($IPs);
            $IPs = array_filter($IPs, function($IP) {
               return filter_var($IP, FILTER_VALIDATE_IP) !== false;
            });
         }
         else {
            $IPs = [$this->address];
         }

         return $IPs;
      }
   }
   // Query
   /**
    * The Request query.
    */
   public string $query {
      get {
         $URI = $this->URI;

         $mark = strpos($URI, '?');
         $query = '';

         if ($mark !== false) {
            $query = substr($URI, $mark + 1);
         }

         return $query;
      }
   }
   /**
    * The Request queries.
    *
    * @var array<string>
    */
   public array $queries {
      get {
         parse_str($this->query, $queries);

         return $queries;
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
    * @var array<string>
    */
   public array $cookies {
      get => $this->Cookies->cookies;
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
    * @var array<string>|string
    */
   public array|string $post {
      get {
         if ($this->method === 'POST' && $_POST === []) {
            return $this->input();
         }

         return $_POST;
      }
   }
   /**
    * The Request files.
    *
    * @var array<string>
    */
   public array $files {
      get => $_FILES;
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
   public string $secure {
      get => $this->scheme === 'https';
   }
   // | HTTP
   // HTTP Basic Authentication
   public string $username {
      get {
         $username = $this->authenticate()->username;

         return $username;
      }
      set {
         $this->username = $value;
      }
   }
   public string $password {
      get {
         $password = $this->authenticate()->password;

         return $password;
      }
      set {
         $this->password = $value;
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

   /** @var array<string> */
   protected array $_SERVER;

   // * Metadata
   public readonly string $on;
   public readonly string $at;
   public readonly int $time;
   public static int $multiparts = 0;

   private Downloader $Downloader;


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


      $this->Downloader = new Downloader($this);
   }

   public function __clone ()
   {
      $this->_SERVER = $_SERVER;
   }
   public function reboot (): void
   {
      if ( isSet($this->_SERVER) ) {
         $_SERVER = $this->_SERVER;
      }
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

      // ? Request Meta (first line of HTTP Header)
      // @ Get Request Meta raw
      // Sample: GET /path HTTP/1.1
      $meta_raw = strstr($buffer, "\r\n", true);

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
         default:
            $Package->reject("HTTP/1.1 405 Method Not Allowed\r\n\r\n");
            return 0;
      }
      // URI
      // protocol

      // @ Set Request Meta length
      $meta_length = strlen($meta_raw);

      // ? Request Header
      // @ Get Request Header raw
      $header_raw = substr($buffer, $meta_length + 2, $separator_position - $meta_length);

      // ? Request Body
      // @ Set Request Body length if possible
      if ( $_ = strpos($header_raw, "\r\nContent-Length: ") ) {
         $content_length = (int) substr($header_raw, $_ + 18, 10);
      }
      else if (preg_match("/\r\ncontent-length: ?(\d+)/i", $header_raw, $match) === 1) {
         $content_length = $match[1];
      }
      else if (stripos($header_raw, "\r\nTransfer-Encoding:") !== false) {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return 0;
      }

      // @ Set Request Body raw if possible
      if ( isSet($content_length) ) {
         $length += $content_length; // @ Add Request Body length

         if ($length > 10485760) { // @ 10 megabytes
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
            return 0;
         }

         if ($content_length > 0) {
            // @ Check if HTTP content is not empty
            if ($size >= $separator_position + 4) {
               $this->Body->raw = substr($buffer, $separator_position + 4, $content_length);
               $this->Body->downloaded = strlen($this->Body->raw);
            }

            if ($content_length > $this->Body->downloaded) {
               $this->Body->waiting = true;
               HTTP_Server_CLI::$Decoder = new Decoder_Waiting;
            }
         }

         $this->Body->length = $content_length;
      }

      // @ Set Request
      // ! Request
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

      // ! Request Header
      // raw
      $this->Header->define(raw: $header_raw);
      // host
      #$_SERVER['HTTP_HOST'] = $this->Header->get('HOST');

      // ! Request Body
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
   public function download (? string $key = null): array|null
   {
      // ?
      $boundary = $this->Body->parse(
         content: 'Form-data',
         type: $this->Header->get('Content-Type')
      );

      // @ Set FILES data
      if ($boundary) {
         $this->Downloader->downloading($boundary);
      }

      // :
      if ($key === null) {
         return $_FILES;
      }

      if ( isSet($_FILES[$key]) ) {
         return $_FILES[$key];
      }

      return null;
   }
   /**
    * Receive the request body data.
    *
    * @return array<array<string>>|string|null The request method.
    */
   public function receive (? string $key = null): array|string|null
   {
      $parsed = $this->Body->parse(
         content: 'raw',
         type: $this->Header->get('Content-Type')
      );

      // @ Set POST data
      if ($parsed) {
         $this->Downloader->downloading($parsed);
      }

      // : parsed $_POST || null
      if ($key === null) {
         return $_POST;
      }

      if ( isSet($_POST[$key]) ) {
         return $_POST[$key];
      }

      return null;
   }

   // HTTP Basic Authentication
   public function authenticate (): object|null
   {
      /** @var string|null $authorization */
      $authorization = $this->Header->get('Authorization');

      $username = '';
      $password = '';
      if (strpos($authorization, 'Basic') === 0) {
         $encoded_credentials = substr($authorization, 6);
         $decoded_credentials = base64_decode($encoded_credentials);

         [$username, $password] = explode(':', $decoded_credentials, 2);

         $this->username = $username;
         $this->password = $password;
      }

      return new class ($username, $password) {
         public function __construct 
         (
            public string $username,
            public string $password
         ){}
      };
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
      }

      // @ Validate header
      if ( empty($header) ) {
         return [];
      }

      // @ Validate RegEx
      preg_match_all(
         $pattern ?? self::ACCEPTS_TYPES,
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

      $results = array_merge(array_keys($results), $results);

      return $results;
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
      if ($if_modified_since) {
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
