<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\HTTP\Server;


use Bootgly\Path;

use Bootgly\Web\TCP\Server\Packages;
use Bootgly\CLI\HTTP\Server;
use Bootgly\CLI\HTTP\Server\Request\_\ {
   Meta,
   Content,
   Header
};

use Bootgly\CLI\HTTP\Server\Request\Downloader;
use Bootgly\Web\protocols\HTTP\Request\Ranging;

/**
 * * Data
 * @property string $address       127.0.0.1
 * @property string $port          52252
 *
 * @property string $scheme        http, https
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
 * @ Path
 * @property object $Path
 * @property string $path          /test/foo
 * @property array $paths          ['test', 'foo']
 * @ Query
 * @property object $Query
 * @property string $query          query=abc&query2=xyz
 * @property array $queries        ['query' => 'abc', 'query2' => 'xyz']
 * ? Meta / Authentication
 * @property string $user          boot
 * @property string $password      gly
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
 * @property string $host          v1.lab.bootgly.com
 * @property string $domain        bootgly.com
 * @property string $subdomain     v1.lab
 * @property array $subdomains     ['lab', 'v1']
 *
 * @property string $on            2020-03-10 (Y-m-d)
 * @property string $at            17:16:18 (H:i:s)
 * @property int $timestamp        1586496524
 *
 * @property bool $secure          true
 * @property bool $fresh           true
 * @property bool $stale           false
 */

#[\AllowDynamicProperties]
class Request
{
   use Ranging;


   // * Config
   private string $base;

   // * Data
   // public string $raw;
   // ...

   // * Meta
   // ...
   // public string $length;

   private Downloader $Downloader;


   public function __construct ()
   {
      // * Config
      $this->base = '';
      // TODO pre-defined filters
      // $this->Filter->sanitize(...) | $this->Filter->validate(...)

      // * Data
      // ... dynamically

      // * Meta
      // ...

      $this->Downloader = new Downloader($this);
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
         case 'address': // @ CLI OK | Non-CLI OK
            // @ Parse CloudFlare remote ip headers
            if ( isSet($this->headers['cf-connecting-ip']) ) {
               return $this->headers['cf-connecting-ip'];
            }

            return $_SERVER['REMOTE_ADDR'];
         case 'port': // @ CLI OK | Non-CLI OK
            return $_SERVER['REMOTE_PORT'];

         case 'scheme': // @ CLI ? | Non-CLI OK?
            // TODO CLI
            $scheme = '';

            return $this->scheme = $scheme;

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

            if ($this->base && substr($locator, 0, strlen($this->base)) == $this->base) {
               $locator = substr($locator, strlen($this->base)); // Return relative location
            }

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
            return $_POST;
         case 'posts':
            return json_encode($this->post);
         case 'files':
            return $_FILES;
         // * Meta
         case 'host': // @ CLI OK | Non-CLI OK?
            // ! FIX bad performance
            $host = $this->Header->get('Host');

            return $this->host = $host;
         case 'hostname': // alias
            return $this->host;
         case 'domain':
            // TODO validate all cases
            $pattern = "/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})(:[\d]+)?$/i";

            if ( preg_match($pattern, $this->host, $matches) ){
               return $this->domain = @$matches['domain'];
            }

            break;

         case 'subdomain':
            // TODO validate all cases
            return $this->subdomain = rtrim(strstr($this->host, $this->domain, true), '.');
         case 'subdomains':
            return $this->subdomains = explode('.', $this->subdomain);
         // TODO Domain with __String/Domain
         // TODO Domain->sub, Domain->second (second-level), Domain->top (top-level), Domain->root, tld, ...
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

   public function reset ()
   {
      // ? Request
      unSet($this->method);
      unSet($this->uri);
      unSet($this->protocol);

      // ? Request Meta
      $this->Meta->__construct();
      // ? RequestHeader
      $this->Header->__construct();
      // ? RequestContent
      $this->Content->__construct();
   }

   public function boot (Packages $Package, string &$buffer, int $length) : int // @ return Request length
   {
      // @ Check Request raw separator
      $separatorPosition = strpos($buffer, "\r\n\r\n");
      if ($separatorPosition === false) { // @ Check if the Request raw has a separator
         // @ Check Request raw length
         if ($length >= 16384) {
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
         }

         return 0;
      }

      $length = $separatorPosition + 4; // @ Boot Request length

      // ? Request Meta
      // @ Boot Request Meta raw
      // Sample: GET /path HTTP/1.1
      $metaRaw = strstr($buffer, "\r\n", true);
      #$metaRaw = strtok($buffer, "\r\n");

      @[$method, $uri, $protocol] = explode(' ', $metaRaw, 3);

      // @ Check Request Meta
      if (! $method || ! $uri || ! $protocol) {
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
      // uri
      // protocol

      // @ Prepare Request Meta length
      $metaLength = strlen($metaRaw);

      // ? Request Header
      // @ Boot Request Header raw
      $headerRaw = substr($buffer, $metaLength + 2, $separatorPosition - $metaLength);

      // @ Prepare Request Header length
      $headerLength = strlen($headerRaw);

      // ? Request Content
      // @ Prepare Request Content length if possible
      if ( $_ = strpos($headerRaw, "\r\nContent-Length: ") ) {
         $contentLength = (int) substr($headerRaw, $_ + 18, 10);
      } else if (preg_match("/\r\ncontent-length: ?(\d+)/i", $headerRaw, $match) === 1) {
         $contentLength = $match[1];
      } else if (stripos($headerRaw, "\r\nTransfer-Encoding:") !== false) {
         $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
         return 0;
      }

      // @ Set Request Content raw / length if possible
      if ( isSet($contentLength) ) {
         $length += $contentLength; // @ Add Request Content length

         if ($length > 10485760) { // @ 10 megabytes
            $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
            return 0;
         }

         if ($method === 'POST') {
            $this->Content->raw = substr($buffer, $separatorPosition + 4, $contentLength);
            $this->Content->downloaded = strlen($this->Content->raw);

            #if ($contentLength > $this->Content->downloaded) {
            #   $this->Content->waiting = true;
            #   return 0;
            #}
         }

         $this->Content->length = $contentLength;
      }

      // @ Set Request
      // ? Request
      $_SERVER['REMOTE_ADDR'] = $Package->Connection->ip;
      $_SERVER['REMOTE_PORT'] = $Package->Connection->port;
      // ? Request Meta
      // raw
      $this->Meta->raw = $metaRaw;

      // method
      $this->method = $method;
      // uri
      $this->uri = $uri;
      // protocol
      $this->protocol = $protocol;

      // length
      $this->Meta->length = $metaLength;
      // ? Request Header
      $this->Header->raw = $headerRaw;

      $this->Header->length = $headerLength;
      // ? Request Content
      $this->Content->position = $separatorPosition + 4;

      // @ return Request length
      return $length;
   }
   public function download (? string $key = null) : array|null
   {
      if ( empty($this->files) ) {
         $boundary = $this->Content->parse('Form-data', $this->Header->get('Content-Type'));

         if ($boundary) {
            $this->Downloader->downloading($boundary);
         }
      }

      if ($key === null) {
         return $this->files;
      }

      if ( isSet($this->files[$key]) ) {
         return $this->files[$key];
      }

      return null;
   }
   public function receive (? string $key = null) : array|null
   {
      if ( empty($this->post) ) {
         $parsed = $this->Content->parse('raw', $this->Header->get('Content-Type'));

         if ($parsed) {
            $this->Downloader->downloading($parsed);
         }
      }

      if ($key === null) {
         return $this->post;
      }

      if ( isSet($this->post[$key]) ) {
         return $this->post[$key];
      }

      return null;
   }

   // TODO implement https://www.php.net/manual/pt_BR/ref.filter.php
   public function filter (int $type, string $var_name, int $filter, array|int $options)
   {
      return filter_input($type, $var_name, $filter, $options);
   }
   public function sanitize ()
   {
      // TODO
   }
   public function validate ()
   {
      // TODO
   }

   public function __destruct ()
   {
      // @ Delete files downloaded by server in temp folder
      if ( ! empty($_FILES) ) {
         clearstatcache();

         array_walk_recursive($_FILES, function ($value, $key) {
            if (is_file($value) && $key === 'tmp_name') {
               unlink($value);
            }
         });
      }
   }
}
