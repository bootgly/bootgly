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


use Bootgly\Web\HTTP;

use Bootgly\Path;
use Bootgly\Requestable;
use Bootgly\Web\HTTP\Server\_\Connections\Data;
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
 * ! Resource
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
 * 
 * ! HTTP
 * @property string $method        GET, POST, ...
 * @property string $protocol      HTTP/1.1
 * @property string $language      pt-BR
 * @property string $user          slayer
 * @property string $password      tech
 * @property string $raw
 * ? Header
 * @property object Header         ->{'X-Header'}
 * ? Header/Cookie
 * @property object $Cookie
 * @property array $cookies
 * ? Content
 * @property object Content
 * @property string $input
 * @property array $post
 * @property array $files
 * ? Content/Uploaded
 * @property object Uploaded
 * 
 * 
 * * Meta
 * @property string $on            2020-03-10 (Y-m-d)
 * @property string $at            17:16:18 (H:i:s)
 * @property int $time             1586496524
 * @property bool $secure          true
 * @property bool $fresh           true
 * @property bool $stale           false
 */
class Request
{
   public HTTP\Server $Server;

   // * Config
   private string $base;
   // * Data
   // ...
   // * Meta
   // ...

   public Session $Session;


   public function __construct (HTTP\Server $Server)
   {
      $this->Server = $Server;

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
      $parsed = $this->parse($name);

      if ($parsed) {
         return $parsed;
      }

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
         // ! Resource
         // ? URI
         case 'uri':
         case 'URI': // TODO with __String/URI?
         case 'identifier': // @ base
            $identifier = $_SERVER['REQUEST_URI'];

            $this->uri = $identifier;
            // $this->URI = $identifier;
            $this->identifier = $identifier;

            return $identifier;

         // ? URL
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

         // ? URN
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
         // ? URL/Path
         case 'path':
            return $this->locator;
         case 'Path':
            return new Path($this->locator);
         case 'paths':
            return $this->Path->paths;
         // ? URI/Query
         case 'parameters': // TODO move to $Route->params ?
         case 'params':     // TODO move to $Route->params ?
         case 'query':
            return @$_SERVER['REDIRECT_QUERY_STRING'];
         case 'queries':
            parse_str($this->query, $queries);
            return $this->queries = $queries;

         // ! HTTP
         case 'method':
            return $_SERVER['REQUEST_METHOD'];
         case 'protocol':
            return $_SERVER['SERVER_PROTOCOL'];
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

         case 'raw':
            $raw = "$this->method $this->uri $this->protocol\r\n";
            $raw .= $this->Header->raw;
            $raw .= "\r\n";
            $raw .= $this->input;
            $this->raw = $raw;
            return $raw;
         // ? Header
         case 'Header':
            return $this->Header = new class {
               public array $fields;


               public function __construct () {
                  $fields = false;

                  if (\PHP_SAPI !== 'cli') {
                     $fields = apache_request_headers();
                  }

                  if ($fields !== false) {
                     $fields = array_change_key_case($fields, CASE_LOWER);
                  } else {
                     $fields = [];
                  }

                  $this->fields = $fields;
               }
               public function __get (string $name) {
                  switch ($name) {
                     case 'raw':
                        $raw = '';
                        foreach ($this->fields as $name => $value) {
                           $raw .= "$name: $value\r\n";
                        }
                        return $raw;
                     default:
                        $name = strtolower($name);
                        return @$this->fields[$name];
                  }
               }
               public function __set (string $fieldName, ?string $fieldValue = null)
               {
                  $fieldName = strtolower($fieldName);

                  if ($fieldValue === null) {
                     $this->fields[$fieldName] = true;
                  } else {
                     $this->fields[$fieldName] = $fieldValue;
                  }
               }

               public function get (string $name) {
                  return @$this->$name;
               }
            };
         case 'headers':
            return $this->Header->fields;
         // case 'ips': // TODO ips based in Header X-Forwarded-For
         // ? Header/Cookie
         case 'Cookie':
            return $this->Cookie = new class
            {
               public array $cookies;


               public function __construct () {
                  $this->cookies = $_COOKIE;
               }

               public function __get (string $name) {
                  switch ($name) {
                     default:
                        return @$this->cookies[$name];
                  }
               }
            };
         case 'cookies':
            return $this->Cookie->cookies;
         // ? Content
         case 'Content':
            return $this->Content = new class
            {
               public string $input;
               public string $raw;

 
               public function __construct ()
               {
                  $this->input = '';
                  $this->raw = '';

                  if (\PHP_SAPI !== 'cli') {
                     $this->input = file_get_contents('php://input');
                  }
               }
            };

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
         // ? Content/Uploaded
         case 'Uploaded':
            break; // TODO implement
         // * Meta
         case 'on':
            return $this->on = date("Y-m-d");
         case 'at':
            return $this->at = date("H:i:s");
         case 'time':
            return $this->time = $_SERVER['REQUEST_TIME'];

         case 'secure':
            return $this->scheme === 'https';

         case 'fresh':
            if ($this->method !== 'GET' && $this->method !== 'HEAD') {
               return false;
            }

            // TODO 2xx or 304 as per rfc2616 14.26 ?
            // $status = $this->Server->Response->code;
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
               $eTag = $this->Server->Response->Header->get('etag');

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
               $lastModified = $this->Server->Response->Header->get('last-modified');
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

   private function parse (string $name) // TODO (WIP)
   {
      if (Data::$parsed || ! Data::$input)
         return false;

      Data::$parsing = true;

      /**
       * Example:
       * 
       * GET /path/to?query1=value1 HTTP/1.1
       * User-Agent: PostmanRuntime/7.30.0
       * Accept: text/html
       * Postman-Token: 32993fbd-236c-48f1-a078-1442e354d7eb
       * Host: localhost
       * Accept-Encoding: gzip, deflate, br
       * Connection: keep-alive
       * 
       */

      // Parse meta only
      switch ($name) {
         case 'method':
         case 'uri':
         case 'protocol':
            $meta = strstr(Data::$input, "\r\n", true);

            [$method, $uri, $procotol] = explode(' ', $meta, 3);

            switch ($name) {
               case 'method':
                  $this->method = $method;
                  break;
               case 'uri':
                  $this->uri = $uri ?? '/';
                  break;
               case 'protocol':
                  $this->protocol = $procotol;
                  break;
            }
      }

      Data::$parsing = false;
      Data::$parsed = true;

      return $this->$name;
      /*
      if ( is_string(Data::$input) ) {
         Data::$input = preg_split("/\r\n|\n|\r/", Data::$input);
      }

      $header = false;
      foreach (Data::$input as $line => $text) {
         if ($line < Data::$pointer) continue;

         Data::$pointer = $line;

         // @ HTTP Meta
         if ($line === 0) {
            [$this->method, $this->uri, $this->procotol] = explode(' ', $text, 3);

            switch ($name) {
               case 'method':
                  return $this->method;
               case 'uri':
                  return $this->uri;
               case 'protocol':
                  return $this->protocol;
            }
         }

         // @ HTTP Header
         if ($header === false) {
            if ($text !== '' && $line > 0) { // @ Request Header
               if ($line === 1)
                  $this->Header; // @ Create Request Header object
   
               [$fieldName, $fieldValue] = explode(': ', $text);

               $this->Header->$fieldName = $fieldValue;
            } else if ($line > 1) {
               $this->Content; // @ Create Request Content object
               $header = true;
            }
         }

         // @ HTTP Content
         $this->Content->raw .= $text . "\n";
      }
      */

      return true;
   }
   public function reset ()
   {
      unset($this->method);
      unset($this->uri);
      unset($this->protocol);

      // ? Content
      #unset($this->Content->length);
      #unset($this->Content);
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