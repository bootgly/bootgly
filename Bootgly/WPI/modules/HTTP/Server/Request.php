<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\modules\HTTP\Server;


use Bootgly\ABI\__String\Path;

use Bootgly\WPI\modules\HTTP\Request\Ranging;

use Bootgly\WPI\modules\HTTP\Server;
use Bootgly\WPI\modules\HTTP\Server\Request\_\Meta;
use Bootgly\WPI\modules\HTTP\Server\Request\_\Content;
use Bootgly\WPI\modules\HTTP\Server\Request\_\Header;

use Bootgly\WPI\modules\HTTP\Server\Request\Session;

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
 * @ URI
 * @property string $identifier    (URI) /test/foo?query=abc&query2=xyz
 * @ URL
 * @property string $locator       (URL) /test/foo
 * @ URN
 * @property string $name          (URN) foo
 * @ Path
 * @property object $Path
 * @property string $path          /test/foo
 * @property array $paths          ['test', 'foo']
 * @ Query
 * @property object $Query
 * @property string $query         query=abc&query2=xyz
 * @property array $queries        ['query' => 'abc', 'query2' => 'xyz']
 * ? Header
 * @property object Header         ->{'X-Header'}
 * @ Host
 * @property string $host          v1.lab.bootgly.com
 * @property string $domain        bootgly.com
 * @property string $subdomain     v1.lab
 * @property array $subdomains     ['lab', 'v1']
 * @ Authorization (Basic)
 * @property string $username      boot
 * @property string $password      gly
 * @ Accept-Language
 * @property string $language      pt-BR
 * ? Header / Cookie
 * @property object $Cookie
 * @property array $cookies
 * ? Content
 * @property object Content
 * 
 * @property string $input
 * @property array $inputs
 * 
 * @property array $post
 * 
 * @property array $files
 *
 *
 * * Meta
 * @property string $on            2020-03-10 (Y-m-d)
 * @property string $at            17:16:18 (H:i:s)
 * @property int $time             1586496524
 *
 * @property bool $secure          true
 * 
 * @property bool $fresh           true
 * @property bool $stale           false
 */

#[\AllowDynamicProperties]
class Request
{
   use Ranging;


   public Meta $Meta;
   public Header $Header;
   public Content $Content;

   // * Config
   private string $base;

   // * Data
   // ...

   // * Meta
   public readonly string $on;
   public readonly string $at;
   public readonly int $time;
   // ...

   public Session $Session;


   public function __construct ()
   {
      $this->Meta = new Meta;
      $this->Header = new Header;
      $this->Content = new Content;

      // * Config
      $this->base = '';
      // TODO pre-defined filters
      // $this->Filter->sanitize(...) | $this->Filter->validate(...)

      // * Data
      // ... dynamically

      // * Meta
      $this->on = date("Y-m-d");
      $this->at = date("H:i:s");
      $this->time = $_SERVER['REQUEST_TIME'];


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
            // @ Parse CloudFlare remote ip headers
            if ( isSet($this->headers['cf-connecting-ip']) ) {
               return $this->headers['cf-connecting-ip'];
            }

            return $_SERVER['REMOTE_ADDR'];
         case 'port':
            return $_SERVER['REMOTE_PORT'];

         case 'scheme':
            if ( isSet($_SERVER['HTTP_X_FORWARDED_PROTO']) ) {
               $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
            } else if ( ! empty($_SERVER['HTTPS']) ) {
               $scheme = 'https';
            } else {
               $scheme = 'http';
            }

            return $this->scheme = $scheme;

         // ! HTTP
         case 'raw':
            $raw = $this->Meta->raw;
            $raw .= $this->Header->raw;
            $raw .= "\r\n";
            $raw .= $this->input;

            $this->raw = $raw;

            return $raw;
         // ? Meta
         case 'method':
            return $_SERVER['REQUEST_METHOD'];
         // case 'uri': ...
         case 'protocol':
            return $_SERVER['SERVER_PROTOCOL'];

         // @ URI
         case 'uri':
         case 'URI': // TODO with __String/URI?
         case 'identifier': // @ base
            return $_SERVER['REDIRECT_URI'] ?? @$_SERVER['REQUEST_URI'];

         // @ URL
         case 'url':
         case 'URL': // TODO with __String/URL?
         case 'locator':
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
         case 'query':
            $uri = $this->uri;

            $mark = strpos($uri, '?');
            $query = '';

            if ($mark !== false) {
               $query = substr($uri, $mark + 1);
            }

            return $this->query = $query;
         case 'queries':
            parse_str($this->query, $queries);

            return $this->queries = $queries;
         // ? Header
         case 'headers':
            return $this->Header->fields;
         // @ Host
         case 'host':
            $host = $_SERVER['HTTP_HOST'] ?? $this->Header->get('Host');

            return $this->host = $host;
         case 'hostname': // alias
            return $this->host;
         case 'domain':
            // TODO validate all cases
            $pattern = "/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})(:[\d]+)?$/i";

            if (preg_match($pattern, $this->host, $matches)) {
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

         // @ Authorization (Basic)
         case 'username':
            $authorization = $this->Header->get('Authorization');

            if (strpos($authorization, 'Basic') === 0) {
               $encodedCredentials = substr($authorization, 6);
               $decodedCredentials = base64_decode($encodedCredentials);

               [$username, $password] = explode(':', $decodedCredentials, 2);

               $this->password = $password;

               return $this->user = $username;
            }

            return $this->user = null;

         case 'password':
            $authorization = $this->Header->get('Authorization');

            if (strpos($authorization, 'Basic') === 0) {
               $encodedCredentials = substr($authorization, 6);
               $decodedCredentials = base64_decode($encodedCredentials);

               [$username, $password] = explode(':', $decodedCredentials, 2);

               $this->user = $username;

               return $this->password = $password;
            }

            return $this->password = null;

         // @ Accept-Language
         case 'language':
            // TODO move to method?
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
         // * Meta
         case 'secure':
            return $this->scheme === 'https';

         // HTTP Caching Specification (RFC 7234)
         case 'fresh':
            if ($this->method !== 'GET' && $this->method !== 'HEAD') {
               return false;
            }

            $ifModifiedSince = $this->Header->get('If-Modified-Since');
            $ifNoneMatch = $this->Header->get('If-None-Match');
            if (!$ifModifiedSince && !$ifNoneMatch) {
               return false;
            }

            // @ cache-control
            $cacheControl = $this->Header->get('Cache-Control');
            if ( $cacheControl && preg_match('/(?:^|,)\s*?no-cache\s*?(?:,|$)/', $cacheControl) ) {
               return false;
            }

            // @ if-none-match
            if ($ifNoneMatch && $ifNoneMatch !== '*') {
               $eTag = Server::$Response->Header->get('ETag');

               if (!$eTag) {
                  return false;
               }

               $eTagStale = true;

               // ? HTTP Parse Token List
               $matches = [];
               $start = 0;
               $end = 0;
               // @ Gather tokens
               for ($i = 0; $i < strlen($ifNoneMatch); $i++) {
                  switch ($ifNoneMatch[$i]) {
                     case ' ':
                        if ($start === $end) {
                           $start = $end = $i + 1;
                        }
                        break;
                     case ',':
                        $matches[] = substr($ifNoneMatch, $start, $end);
                        $start = $end = $i + 1;
                        break;
                     default:
                        $end = $i + 1;
                        break;
                  }
               }
               // final token
               $matches[] = substr($ifNoneMatch, $start, $end);

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
            if ($ifModifiedSince) {
               $lastModified = Server::$Response->Header->get('Last-Modified');
               if ($lastModified === '') {
                  return false;
               }

               $lastModifiedTime = strtotime($lastModified);
               $ifModifiedSinceTime = strtotime($ifModifiedSince);
               if ($lastModifiedTime === false || $ifModifiedSinceTime === false) {
                  return false;
               }

               $modifiedStale = $lastModifiedTime > $ifModifiedSinceTime;
               if ($modifiedStale) {
                  return false;
               }
            }

            return true;
         case 'stale':
            return ! $this->fresh;
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
}
