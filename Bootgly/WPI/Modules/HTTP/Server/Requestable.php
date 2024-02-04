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
         // TODO IP->...
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
         // ? Meta
         case 'method':
            return $_SERVER['REQUEST_METHOD'] ?? '';
         // case 'URI': ...
         case 'protocol':
            return $_SERVER['SERVER_PROTOCOL'] ?? '';
         // @ Uniform Resources
         case 'URI': // (Uniform Resource Identifier)
            return @$_SERVER['REDIRECT_URI'] ?? $_SERVER['REQUEST_URI'] ?? '';

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
            $URN = substr($URL, strrpos($URL, '/') + 1);

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
         case 'hostname': // alias
            return $this->host;
         case 'domain':
            // TODO validate all cases
            $pattern = "/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})(:[\d]+)?$/i";

            if (\preg_match($pattern, $this->host, $matches)) {
               return $this->domain = @$matches['domain'];
            }

            break;

         case 'subdomain':
            // TODO validate all cases
            return $this->subdomain = \rtrim(\strstr($this->host, $this->domain, true), '.');
         case 'subdomains':
            return $this->subdomains = \explode('.', $this->subdomain);
            // TODO Domain with __String/Domain
            // TODO Domain->sub, Domain->second (second-level), Domain->top (top-level), Domain->root, tld, ...

         // @ Authorization (Basic)
         case 'username':
            $authorization = $this->Raw->Header->get('Authorization');

            if (\strpos($authorization, 'Basic') === 0) {
               $encodedCredentials = \substr($authorization, 6);
               $decodedCredentials = \base64_decode($encodedCredentials);

               [$username, $password] = \explode(':', $decodedCredentials, 2);

               $this->password = $password;

               return $this->user = $username;
            }

            return $this->user = null;

         case 'password':
            $authorization = $this->Raw->Header->get('Authorization');

            if (\strpos($authorization, 'Basic') === 0) {
               $encodedCredentials = \substr($authorization, 6);
               $decodedCredentials = \base64_decode($encodedCredentials);

               [$username, $password] = \explode(':', $decodedCredentials, 2);

               $this->user = $username;

               return $this->password = $password;
            }

            return $this->password = null;

         // @ Accept-Language
         case 'language':
            // TODO move to method?
            $httpAcceptLanguage = @$_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? $this->Raw->Header->get('Accept-Language');

            if ($httpAcceptLanguage === null) {
               return null;
            }

            \preg_match_all(
               '/([a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(1|0\.[0-9]+))?/i',
               $httpAcceptLanguage,
               $matches
            );

            $language = '';
            if (\count($matches[1])) {
               $languages = \array_combine($matches[1], $matches[4]);
               foreach ($languages as $language => $weight) {
                  if ($weight === '') {
                     $languages[$language] = 1;
                  }
               }
               \arsort($languages, SORT_NUMERIC);
               $language = \array_keys($languages);
               $language = \array_merge($language, $languages);
               $language = $language[0];
            }

            return $this->language = $language;
            // case 'ips': // TODO ips based in Header X-Forwarded-For
            // ? Header / Cookie
         case 'Cookie':
            return $this->Raw->Header->Cookie;
         case 'cookies':
            return $this->Cookie->cookies;
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

   public function freshen () : bool
   {
      if ($this->method !== 'GET' && $this->method !== 'HEAD') {
         return false;
      }

      $ifModifiedSince = $this->Raw->Header->get('If-Modified-Since');
      $ifNoneMatch = $this->Raw->Header->get('If-None-Match');
      if (!$ifModifiedSince && !$ifNoneMatch) {
         return false;
      }

      // @ cache-control
      $cacheControl = $this->Raw->Header->get('Cache-Control');
      if ($cacheControl && \preg_match('/(?:^|,)\s*?no-cache\s*?(?:,|$)/', $cacheControl)) {
         return false;
      }

      // @ if-none-match
      if ($ifNoneMatch && $ifNoneMatch !== '*') {
         $eTag = $this->Server::$Response->Raw->Header->get('ETag');

         if (!$eTag) {
            return false;
         }

         $eTagStale = true;

         // ? HTTP Parse Token List
         $matches = [];
         $start = 0;
         $end = 0;
         // @ Gather tokens
         for ($i = 0; $i < \strlen($ifNoneMatch); $i++) {
            switch ($ifNoneMatch[$i]) {
               case ' ':
                  if ($start === $end) {
                     $start = $end = $i + 1;
                  }
                  break;
               case ',':
                  $matches[] = \substr($ifNoneMatch, $start, $end);
                  $start = $end = $i + 1;
                  break;
               default:
                  $end = $i + 1;
                  break;
            }
         }
         // final token
         $matches[] = \substr($ifNoneMatch, $start, $end);

         for ($i = 0; $i < \count($matches); $i++) {
            $match = $matches[$i];
            if ($match === $eTag || $match === 'W/' . $eTag || 'W/' . $match === $eTag) {
               $eTagStale = false;
               break;
            }
         }

         if ($eTagStale) {
            return false;
         }
      }

      // @ if-modified-since
      if ($ifModifiedSince) {
         $lastModified = $this->Server::$Response->Raw->Header->get('Last-Modified');
         if ($lastModified === '') {
            return false;
         }

         $lastModifiedTime = \strtotime($lastModified);
         $ifModifiedSinceTime = \strtotime($ifModifiedSince);
         if ($lastModifiedTime === false || $ifModifiedSinceTime === false) {
            return false;
         }

         $modifiedStale = $lastModifiedTime > $ifModifiedSinceTime;
         if ($modifiedStale) {
            return false;
         }
      }

      return true;
   }
}
