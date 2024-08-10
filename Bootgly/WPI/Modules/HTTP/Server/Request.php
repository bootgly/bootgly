<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server;


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

use Bootgly\WPI\Modules\HTTP\Server\Requestable;
use Bootgly\WPI\Modules\HTTP\Server\Request\Raw;
use Bootgly\WPI\Modules\HTTP\Server\Request\Ranging;
use Bootgly\WPI\Modules\HTTP\Server\Request\Raw\Header;
use Bootgly\WPI\Modules\HTTP\Server\Request\Raw\Header\Cookies;
use Bootgly\WPI\Modules\HTTP\Server\Request\Raw\Body;


/**
 * @property Header $Header
 * @property Body $Body
 */
abstract class Request extends Raw
{
   use Requestable;
   use Ranging;


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
      get => $this->Header->fields['cf-connecting-ip'] ?? $_SERVER['REMOTE_ADDR'];
   }
   /**
    * The port of the HTTP Client.
    */
   public int $port {
      get => $_SERVER['REMOTE_PORT'] ?? 0;
   }
   /**
    * The scheme of the Request.
    */
   public string $scheme {
      get {
         if (isSet($_SERVER['HTTP_X_FORWARDED_PROTO']) === true) {
            $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
         }
         else if (empty($_SERVER['HTTPS']) === false) {
            $scheme = 'https';
         }
         else {
            $scheme = 'http';
         }

         return $scheme;
      }
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
      get => $_SERVER['REQUEST_METHOD'] ?? '';
   }
   /**
    * The Request URI (Uniform Resource Identifier).
    */
   public string $URI {
      get => $_SERVER['REDIRECT_URI'] ?? $_SERVER['REQUEST_URI'] ?? '';
   }
   /**
    * The Request protocol.
    */
   public string $protocol {
      get => $_SERVER['SERVER_PROTOCOL'] ?? '';
   }
   // ^ Resource
   /**
    * The Request URL (Uniform Resource Locator).
    */
   public string $URL {
      get {
         $locator = strtok($this->URI, '?');

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
         $host = $_SERVER['HTTP_HOST'] ?? $this->Header->get('Host');

         return $host;
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
         $Header = $this->Header->get('X-Forwarded-For');

         if ($Header) {
            $IPs = explode(',', $Header);
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
   public string $econding {
      get => $this->encodings[0] ?? '';
   }

   // HTTP Caching Specification (RFC 7234)
   public bool $fresh {
      get => $this->freshen();
   }
   public bool $stale {
      get => ! $this->fresh;
   }
}
