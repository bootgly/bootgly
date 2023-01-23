<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server;


use Closure;

use Bootgly\Path;

use Bootgly\Web\HTTP\Server;
use Bootgly\Web\HTTP\Server\_\ {
   Meta,
   Content,
   Header
};

use Bootgly\Web\HTTP\Server\Request\Session;


/**
 * * Data
 * @property string $address       127.0.0.1
 * @property string $port          52252
 * 
 * @property string $scheme        http, https
 * @property string $host          v1.lab.slayer.tech
 * @property string $domain        slayer.tech
 * @property string $subdomain     v1.lab
 * @property array $subdomains     ['lab', 'v1']
 * 
 * ! HTTP
 * @property string $raw
 * ? Meta
 * @property string $method        GET, POST, ...
 * @property string $uri           /test/foo?query=abc&query2=xyz
 * @property string $protocol      HTTP/1.1
 * ? Meta / Resource
 * @property string $identifier    (URI) /test/foo?query=abc&query2=xyz
 * @property string $locator       (URL) /test/foo
 * @property string $name          (URN) foo
 * ? Path
 * @property object $Path
 * @property string $path          /test/foo
 * @property array $paths          ['test', 'foo']
 * ? Query
 * @property object $Query
 * @property string $query          query=abc&query2=xyz
 * @property array $queries        ['query' => 'abc', 'query2' => 'xyz']
 * ? Meta / Authentication
 * @property string $user          slayer
 * @property string $password      tech
 * ? Header
 * @property object Header         ->{'X-Header'}
 * @property string $language      pt-BR
 * ? Header / Cookie
 * @property object $Cookie
 * @property array $cookies
 * ? Content
 * @property object Content
 * @property string $input
 * @property array $post
 * @property array $files
 * ? Content / Downloader
 * @property object Downloader
 * 
 * 
 * * Meta
 * @property string $on            2020-03-10 (Y-m-d)
 * @property string $at            17:16:18 (H:i:s)
 * @property int $timestamp        1586496524
 * @property bool $secure          true
 * @property bool $fresh           true
 * @property bool $stale           false
 */

#[\AllowDynamicProperties]
class Request
{
   // * Config
   private string $base;
   // * Data
   // public string $raw;
   // ...
   // * Meta
   // ...
   // public string $length;
   private bool $meta;
   private bool $header;
   private bool $content;

   public Session $Session;


   public function __construct ()
   {
      // * Config
      $this->base = '';
      // TODO pre-defined filters
      // $this->Filter->sanitize(...) | $this->Filter->validate(...)
      // * Data
      /* ... dynamically ... */
      // * Meta

      $this->Session = new Session;
   }

   public function __get ($name)
   {
      // TODO move to @/resources
      switch ($name) {
         // * Config
         case 'base':
            return $this->base;

         // * Data
         case 'ip': // TODO IP->...
         case 'address':
            if (@$this->headers['cf-connecting-ip']) {
               return $this->headers['cf-connecting-ip'];
            }
            return $_SERVER['REMOTE_ADDR'];
         case 'port':
            return $_SERVER['REMOTE_PORT'];

         case 'scheme':
            return $this->scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? (!empty($_SERVER['HTTPS']) ? "https" : "http");
         case 'host': // TODO create secure method to get host
            return $this->host = $_SERVER['HTTP_HOST'];
         case 'hostname':
            return $this->host;

         case 'domain': // TODO validate all cases
            $pattern = "/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$/i";
            if ( preg_match($pattern, $this->host, $matches) ){
               return $this->domain = @$matches['domain'];
            } break;

         case 'subdomain': // TODO validate all cases
            return $this->subdomain = rtrim(strstr($this->host, $this->domain, true), '.');
         case 'subdomains':
            return $this->subdomains = explode('.', $this->subdomain);
         // TODO Domain with __String/Domain
         // TODO Domain->sub, Domain->second (second-level), Domain->top (top-level), Domain->root, tld, ...

         // ! HTTP
         case 'raw': // TODO refactor
            $raw = "$this->method $this->uri $this->protocol\r\n";

            $raw .= $this->Header->raw;
            $raw .= "\r\n";

            $raw .= $this->input;

            $this->raw = $raw;

            return $raw;
         // ? Meta
         case 'Meta':
            return $this->Meta = new Meta;
         case 'method':
            return $_SERVER['REQUEST_METHOD'];
         // case 'uri': break;
         case 'protocol':
            return $_SERVER['SERVER_PROTOCOL'];

         // ? Meta / Resource
         // @ URI
         case 'uri':
         case 'URI': // TODO with __String/URI?
         case 'identifier': // @ base
            if (\PHP_SAPI !== 'cli')
               $identifier = @$_SERVER['REQUEST_URI'];
            else
               $identifier = $this->uri ?? '';

            $this->uri = $identifier;
            // $this->URI = $identifier;
            $this->identifier = $identifier;

            return $identifier;

         // @ URL
         case 'url':
         case 'URL': // TODO with __String/URL?
         case 'locator':
            #$locator = @$_SERVER['REDIRECT_URL'];

            #if ($locator === '/index.php') 
            $locator = strtok($this->uri, '?');

            $locator = rtrim($locator ?? '/', '/');

            if ($this->base && substr($locator, 0, strlen($this->base)) == $this->base)
               $locator = substr($locator, strlen($this->base)); // Return relative location

            $this->url = $locator;
            // $this->URL = $locator;
            $this->locator = $locator;

            return $locator;

         // @ URN
         case 'urn':
         case 'URN':
         case 'name':
            $name = $this->Path->current;

            $this->urn = $name;

            // $this->URN = $name;
            $this->name = $name;

            return $name;
         // TODO dir, directory, Dir, Directories, ... ?
         // TODO file, File ?
         // @ Path
         case 'path':
            return $this->locator;
         case 'Path':
            return new Path($this->locator);
         case 'paths':
            return $this->Path->paths;
         // @ Query
         case 'parameters': // TODO move to $Route->params ?
         case 'params':     // TODO move to $Route->params ?
         case 'query':
            return @$_SERVER['REDIRECT_QUERY_STRING'];
         case 'queries':
            parse_str($this->query, $queries);
            return $this->queries = $queries;
         // ? Meta / Authentication
         case 'user':
            return $this->user = $_SERVER['PHP_AUTH_USER'] ?? null;
         case 'username':
            return $this->user;

         case 'password':
            return $this->password = $_SERVER['PHP_AUTH_PW'] ?? null;
         case 'pass':
            return $this->password;
         case 'pw':
            return $this->password;
         // ? Header
         case 'Header':
            return $this->Header = new Header;
         case 'headers':
            return $this->Header->fields;
         case 'language': // TODO refactor
            $httpAcceptLanguage = @$_SERVER['HTTP_ACCEPT_LANGUAGE'];

            if ($httpAcceptLanguage === null) {
               return null;
            }

            preg_match_all(
               '/([a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(1|0\.[0-9]+))?/i',
               $httpAcceptLanguage,
               $matches
            );
      
            $language = '';
            if ( count($matches[1]) ) {
               $languages = array_combine($matches[1], $matches[4]);
               foreach ($languages as $language => $weight) {
                  if ($weight === '') {
                     $languages[$language] = 1;
                  }
               }
               arsort($languages, SORT_NUMERIC);
               $language = array_keys($languages);
               $language = array_merge($language, $languages);
               $language = $language[0];
            }

            return $this->language = $language;
         // case 'ips': // TODO ips based in Header X-Forwarded-For
         // ? Header / Cookie
         case 'Cookie':
            return $this->Header->Cookie;
         case 'cookies':
            return $this->Cookie->cookies;
         // ? Content
         case 'Content':
            return $this->Content = new Content;
         case 'contents':
         case 'input':
            return $this->Content->input;
         case 'inputs':
            return json_decode($this->input, true);

         case 'post':
            if ( $this->method === 'POST' && empty($_POST) ) {
               return $this->inputs;
            }

            return $_POST;
         case 'posts':
            return json_encode($this->post);
         case 'files':
            return $_FILES;
         // ? Content / Downloader
         case 'Downloader':
            // TODO implement
            return $this->Content->Downloader;
         // * Meta
         case 'on':
            return $this->on = date("Y-m-d");
         case 'at':
            return $this->at = date("H:i:s");
         case 'timestamp':
            return $this->time = $_SERVER['REQUEST_TIME'];

         case 'secure':
            return $this->scheme === 'https';

         case 'fresh':
            if ($this->method !== 'GET' && $this->method !== 'HEAD') {
               return false;
            }

            // TODO 2xx or 304 as per rfc2616 14.26 ?
            // $status = Server::$Response->code;
            // if ( ($status >= 200 && $status < 300) || $status === 304) {
            //    return false;
            // }

            $modifiedSince = $this->Header->get('if-modified-since');
            $noneMatch = $this->Header->get('if-none-match');
            if (!$modifiedSince && !$noneMatch) {
               return false;
            }

            // @ cache-control
            $cacheControl = $this->Header->get('cache-control');
            if ($cacheControl && preg_match('/(?:^|,)\s*?no-cache\s*?(?:,|$)/', $cacheControl)) {
               return false;
            }

            // @ if-none-match
            if ($noneMatch && $noneMatch !== '*') {
               $eTag = Server::$Response->Header->get('etag');

               if (!$eTag) {
                  return false;
               }

               $eTagStale = true;

               // ? HTTP Parse Token List
               $matches = [];
               $start = 0;
               $end = 0;
               // gather tokens
               for ($i = 0; $i < strlen($noneMatch); $i++) {
                  switch ($noneMatch[$i]) {
                     case ' ':
                        if ($start === $end) {
                           $start = $end = $i + 1;
                        }
                        break;
                     case ',':
                        $matches[] = substr($noneMatch, $start, $end);
                        $start = $end = $i + 1;
                        break;
                     default:
                        $end = $i + 1;
                        break;
                     }
               }
               // final token
               $matches[] = substr($noneMatch, $start, $end);

               for ($i = 0; $i < count($matches); $i++) {
                  $match = $matches[$i];
                  if ($match === $eTag || $match === 'W/'.$eTag || 'W/'.$match === $eTag) {
                     $eTagStale = false;
                     break;
                  }
               }

               if ($eTagStale) {
                  return false;
               }
            }

            // @ if-modified-since
            if ($modifiedSince) {
               $lastModified = Server::$Response->Header->get('last-modified');
               $modifiedStale = !$lastModified && (strtotime($lastModified) < strtotime($modifiedSince));

               if ($modifiedStale) {
                  return false;
               }
            }

            return true;
         case 'stale':
            return !$this->fresh;
      }
   }
   public function __set ($name, $value)
   {
      switch ($name) {
         case 'base': // TODO refactor
            unSet($this->url);
            unSet($this->locator);
            return $this->base = $value;
         default:
            return $this->$name = $value;
      }
   }

   public function input ($Package, Closure $reject) : int // @ return Request Content length
   {
      static $input = []; // @ Instance cache variable

      $buffer = $Package::$input;

      // @ Check cache $input and return
      if ( ! isSet($buffer[512]) && isSet($input[$buffer]) ) {
         #$this->cached = true;
         return $input[$buffer];
      }

      // @ Set the position of Content starts
      $this->Content->position = strpos($buffer, "\r\n\r\n");

      // @ Check if Request has Content (body)
      if ($this->Content->position === false) {
         // @ Judge whether the package length exceeds the limit.
         if (strlen($buffer) >= 16384) {
            $reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
         }

         return 0;
      }

      // @ Boot Request Content length
      $length = $this->Content->position + 4;

      // ? Meta
      // @ Boot Meta
      // Sample: GET /path HTTP/1.1
      #$meta = strstr($buffer, "\r\n", true);
      $meta = strtok($buffer, "\r\n");
      @[$method, $uri, $procotol] = explode(' ', $meta, 3);

      // @ Check Meta
      // method
      switch ($method) {
         case 'GET':
         case 'POST':
         case 'OPTIONS':
         case 'HEAD':
         case 'DELETE':
         case 'PUT':
         case 'PATCH':
            break;
         default:
            $reject("HTTP/1.1 405 Method Not Allowed\r\n\r\n");
            return 0;
      }
      // @ Set Meta
      // raw
      $this->Meta->raw = $meta;
      // method
      $this->method = $method;
      // uri
      $this->uri = $uri ?? '/';
      // protocol
      $this->protocol = $procotol;
      // length
      $this->Meta->length = strlen($meta) + 2;

      // ? Header
      // @ Set Header
      $this->Header->raw = substr(
         $buffer,
         $this->Meta->length,
         ($this->Content->position - $this->Meta->length)
      );
      $this->Header->length = strlen($this->Header->raw);

      // @ Try to set Content Length
      if ( $_ = strpos($this->Header->raw, "\r\nContent-Length: ") ) {
         $this->Content->length = (int) substr($this->Header->raw, $_ + 18, 10);
      } else if (preg_match("/\r\ncontent-length: ?(\d+)/i", $this->Header->raw, $match) === 1) {
         $this->Content->length = $match[1];
      } else if (stripos($this->Header->raw, "\r\nTransfer-Encoding:") !== false) {
         $reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return 0;
      }

      if ($this->Content->length !== null) {
         $length += $this->Content->length;

         if ($length > 10485760) {
            $reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
            return 0;
         }
      }

      // @ Write to cache $input
      if ( ! isSet($buffer[512]) ) {
         $input[$buffer] = $length;

         if (count($input) > 512) {
            unSet($input[key($input)]);
         }
      }

      return $length;
   }
   public function reset ()
   {
      // ? Meta
      unset($this->method);
      unset($this->uri);
      unset($this->protocol);

      // ? Meta
      $this->Meta->__construct();
      // ? Header
      $this->Header->__construct();
      // ? Content
      $this->Content->__construct();
   }

   // TODO implement https://www.php.net/manual/pt_BR/ref.filter.php
   public function filter (int $type, string $var_name, int $filter, array|int $options)
   {
      return filter_input($type, $var_name, $filter, $options);
   }
   public function sanitize ()
   {}
   public function validate ()
   {}

   public function range (int $size, string $header, array $options = ['combine' => true])
   {
      // Validate
      $equalIndex = strpos($header, '=');
      if ($equalIndex === false) {
         return -2; // Malformed header string
      }

      // Split ranges
      $headerRanges = explode(',', substr($header, $equalIndex + 1));
      $ranges = [];

      // Iterate ranges
      for ($i = 0; $i < count($headerRanges); $i++) {
         $range = explode('-', $headerRanges[$i]);
         $start = (int) $range[0];
         $end = (int) $range[1];

         if (is_nan($start) || $range[0] === '') {
            $start = $size - $end;
            $end = $size - 1;
         } else if (is_nan($end) || $range[1] === '') {
            $end = $size - 1;
         }

         // Limit last-byte-pos to current length
         if ($end > $size - 1) {
            $end = $size - 1;
         }

         if (is_nan($start) || is_nan($end) || $start > $end || $start < 0) {
            continue;
         }

         $ranges[] = [
            'start' => $start,
            'end' => $end
         ];
      }

      if (count($ranges) < 1) {
         return -1; // Unsatisifiable range
      }

      $ranges['type'] = substr($header, 0, $equalIndex);

      return $ranges;
   }
}