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


trait Requestable
{
   public function __get (string $name)
   {
      /* Waiting for the RFC "Property Accessors" from Nikita Popov (https://wiki.php.net/rfc/property_accessors)
         to organize this mess.
      */
      switch ($name) {
         // * Config
         case 'base':
            return $this->base;

         // * Data
         case 'address':
            return (string) ($this->Raw->Header->fields['cf-connecting-ip'] ?? $_SERVER['REMOTE_ADDR']);
         case 'port':
            return (int) ($_SERVER['REMOTE_PORT'] ?? 0);

         case 'scheme':
            if (isSet($_SERVER['HTTP_X_FORWARDED_PROTO']) === true) {
               $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
            }
            else if (empty($_SERVER['HTTPS']) === false) {
               $scheme = 'https';
            }
            else {
               $scheme = 'http';
            }

            return $this->scheme = $scheme;

            // ! HTTP
         case 'raw':
            $raw = <<<RAW
            {$this->method} {$this->URI} {$this->protocol}
            RAW;
            $raw .= "\r\n";
            $raw .= $this->Raw->Header->raw;
            $raw .= "\r\n";
            $raw .= $this->input;

            $this->raw = $raw;

            return $raw;

         case 'method':
            return $_SERVER['REQUEST_METHOD'] ?? '';
         case 'URI': // (Uniform Resource Identifier)
            return @$_SERVER['REDIRECT_URI'] ?? $_SERVER['REQUEST_URI'] ?? '';
         case 'protocol':
            return $_SERVER['SERVER_PROTOCOL'] ?? '';
         // @ Uniform Resources
         case 'URL': // (Uniform Resource Locator)
            $locator = \strtok($this->URI, '?');

            $locator = \rtrim($locator ?? '/', '/');

            $base = &$this->base;
            if ($base && \substr($locator, 0, \strlen($base)) === $base) {
               // @ Return relative location
               $locator = \substr($locator, \strlen($base));
            }

            $this->URL = $locator;

            return $locator;

         case 'URN': // (Uniform Resource Name)
            $URL = $this->URL;

            // @ Extract the URN after the last slash
            $URN = \substr($URL, \strrpos($URL, '/') + 1);

            $this->URN = $URN;

            return $URN;

         // @ Query
         case 'query':
            $URI = $this->URI;

            $mark = \strpos($URI, '?');
            $query = '';

            if ($mark !== false) {
               $query = \substr($URI, $mark + 1);
            }

            return $this->query = $query;
         case 'queries':
            \parse_str($this->query, $queries);

            return $this->queries = $queries;
         // ? Header
         case 'headers':
            return $this->Raw->Header->fields;
         // @ Host
         case 'host':
            $host = $_SERVER['HTTP_HOST'] ?? $this->Raw->Header->get('Host');

            return $this->host = $host;
         case 'domain':
            $host = $this->host;
  
            $pattern = "/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})(:[\d]+)?$/i";

            if (\preg_match($pattern, $host, $matches)) {
               return $this->domain = @$matches['domain'];
            }

            $colon = \strpos($host, ":");
            if ($colon === false) {
               return $this->domain = $host;
            }
            else {
               return $this->domain = \substr($host, 0, $colon);
            }

         case 'subdomain':
            return $this->subdomain = \rtrim(\strstr($this->host, $this->domain, true), '.');
         case 'subdomains':
            return $this->subdomains = \explode('.', $this->subdomain);

         // @ Authorization (Basic)
         case 'username':
            $authorization = $this->Raw->Header->get('Authorization');

            if (\strpos($authorization, 'Basic') === 0) {
               $encoded_credentials = \substr($authorization, 6);
               $decoded_credentials = \base64_decode($encoded_credentials);

               [$username, $password] = \explode(':', $decoded_credentials, 2);

               $this->password = $password;

               return $this->user = $username;
            }

            return $this->user = null;

         case 'password':
            $authorization = $this->Raw->Header->get('Authorization');

            if (\strpos($authorization, 'Basic') === 0) {
               $encoded_credentials = \substr($authorization, 6);
               $decoded_credentials = \base64_decode($encoded_credentials);

               [$username, $password] = \explode(':', $decoded_credentials, 2);

               $this->user = $username;

               return $this->password = $password;
            }

            return $this->password = null;

         // case 'ips': // TODO ips based in Header X-Forwarded-For
         // ? Header / Cookie
         case 'Cookie':
            return $this->Raw->Header->Cookie;
         case 'cookies':
            return $this->Raw->Header->Cookie->cookies;
         // ? Body
         case 'input':
            return $this->Raw->Body->input;
         case 'inputs':
            return \json_decode($this->input, true);

         case 'post':
            if ($this->method === 'POST' && empty($_POST)) {
               return $this->inputs;
            }

            return $_POST;
         case 'posts':
            return \json_encode($this->post);
         case 'files':
            return $_FILES;
         // * Metadata
         case 'secure':
            return $this->scheme === 'https';

         // HTTP Caching Specification (RFC 7234)
         case 'fresh':
            return $this->freshen();
         case 'stale':
            return ! $this->fresh;
      }
   }
   public function __set (string $name, $value)
   {
      switch ($name) {
         // * Config
         case 'base':
            unSet($this->URL);

            return $this->base = $value;

         default:
            return $this->$name = $value;
      }
   }

   public const ACCEPTS_TYPES = 1;
   public const ACCEPTS_LANGUAGES = 2;
   public const ACCEPTS_CHARSETS = 4;
   public const ACCEPTS_ENCODINGS = 8;
   public function negotiate (int $with = self::ACCEPTS_TYPES) : array
   {
      switch ($with) {
         case self::ACCEPTS_TYPES:
            // @ Accept
            $header = (
               @$_SERVER['HTTP_ACCEPT']
               ?? $this->Raw->Header->get('Accept')
            );
            $pattern = '/([\w\/\+\*.-]+)(?:;\s*q\s*=\s*(\d*(?:\.\d+)?))?/i';

            break;
         case self::ACCEPTS_CHARSETS:
            // @ Accept-Charset
            $header = (
               @$_SERVER['HTTP_ACCEPT_CHARSET']
               ?? $this->Raw->Header->get('Accept-Charset')
            );
            $pattern = '/([a-z0-9]{1,8}(?:[-_][a-z0-9]{1,8}){0,3})\s*(?:;\s*q\s*=\s*(\d*(?:\.\d+)?))?/i';

            break;
         case self::ACCEPTS_LANGUAGES:
            // @ Accept-Language
            $header = (
               @$_SERVER['HTTP_ACCEPT_LANGUAGE']
               ?? $this->Raw->Header->get('Accept-Language')
            );
            $pattern = '/([a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(\d*(?:\.\d+)?))?/i';

            break;
         case self::ACCEPTS_ENCODINGS:
            // @ Accept-Encoding
            $header = (
               @$_SERVER['HTTP_ACCEPT_ENCODING']
               ?? $this->Raw->Header->get('Accept-Encoding')
            );
            $pattern = '/([a-z0-9]{1,8}(?:[-_][a-z0-9]{1,8}){0,3})\s*(?:;\s*q\s*=\s*(\d*(?:\.\d+)?))?/i';

            break;
      }

      // @ Validate header
      if ( empty($header) ) {
         return [];
      }

      // @ Validate RegEx
      \preg_match_all(
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

      \uasort($results, function ($a, $b) {
         return $b <=> $a;
      });

      $results = \array_merge(\array_keys($results), $results);

      return $results;
   }

   public function freshen () : bool
   {
      if ($this->method !== 'GET' && $this->method !== 'HEAD') {
         return false;
      }

      $if_modified_since = $this->Raw->Header->get('If-Modified-Since');
      $if_none_match = $this->Raw->Header->get('If-None-Match');
      if ( ! $if_modified_since && ! $if_none_match ) {
         return false;
      }

      // @ cache-control
      $cache_control = $this->Raw->Header->get('Cache-Control');
      if ($cache_control && \preg_match('/(?:^|,)\s*?no-cache\s*?(?:,|$)/', $cache_control)) {
         return false;
      }

      // @ if-none-match
      if ($if_none_match && $if_none_match !== '*') {
         $entity_tag = $this->Server::$Response->Raw->Header->get('ETag');

         if ( ! $entity_tag ) {
            return false;
         }

         $entity_tag_stale = true;

         // ? HTTP Parse Token List
         $matches = [];
         $start = 0;
         $end = 0;
         // @ Gather tokens
         for ($i = 0; $i < \strlen($if_none_match); $i++) {
            switch ($if_none_match[$i]) {
               case ' ':
                  if ($start === $end) {
                     $start = $end = $i + 1;
                  }
                  break;
               case ',':
                  $matches[] = \substr($if_none_match, $start, $end);
                  $start = $end = $i + 1;
                  break;
               default:
                  $end = $i + 1;
                  break;
            }
         }
         // final token
         $matches[] = \substr($if_none_match, $start, $end);

         for ($i = 0; $i < \count($matches); $i++) {
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
         $last_modified = $this->Server::$Response->Raw->Header->get('Last-Modified');
         if ($last_modified === '') {
            return false;
         }

         $last_modified_time = \strtotime($last_modified);
         $if_modified_since_time = \strtotime($if_modified_since);
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
}
