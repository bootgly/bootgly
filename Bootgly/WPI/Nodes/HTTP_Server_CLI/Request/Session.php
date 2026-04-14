<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


use function array_key_exists;
use function bin2hex;
use function extension_loaded;
use function ini_get;
use function is_array;
use function is_scalar;
use function random_bytes;
use function random_int;
use function session_get_cookie_params;

use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header\Cookie;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handler;


class Session
{
   // * Config
   public static string $name = 'PHPSID';
   public static bool $autoUpdateTimestamp = false;
   public static int $lifetime = 1440;
   // # Cookie
   public static int $cookieLifetime = 1440;
   public static string $cookiePath = '/';
   public static string $domain = '';
   /**
    * Transmit session cookie only over HTTPS.
    * ⚠️ Set to false in local HTTP-only development environments.
    */
   public static bool $secure = true;
   public static bool $httpOnly = true;
   /**
    * SameSite attribute for the session cookie.
    * 'Lax' protects against CSRF while allowing top-level navigations.
    * Set to 'Strict' for maximum CSRF protection.
    */
   public static string $sameSite = 'Lax';
   // # GC
   /** @var array{int,int} */
   public static array $gcProbability = [1, 20000];

   // * Data
   // ...

   // * Metadata
   private static bool $initialized = false;

   /** @var array<string,mixed> */
   protected array $data = [];
   protected bool $needSave = false;
   public protected(set) string $id;
   protected bool $isSafe = true;
   /** @var array{callable-string,callable-string} */
   protected array $serializer = ['serialize', 'unserialize'];


   public function __construct (string $id)
   {
      // ? Init
      if (self::$initialized === false) {
         static::init();
      }

      // ! Serializer
      if (
         extension_loaded('igbinary') && ini_get('session.serialize_handler') === 'igbinary'
      ) {
         $this->serializer = ['igbinary_serialize', 'igbinary_unserialize'];
      }

      // ! Handler
      if (Handler::$instance === null) {
         Handler::init();
      }

      // @ Read
      $this->id = $id;

      if (Handler::$instance && $data = Handler::$instance->read($id)) {
         $this->data = (array) ($this->serializer[1])($data);
      }
   }

   /**
    * Get session data by name.
    *
    * @param string $name
    * @param mixed $default
    *
    * @return mixed
    */
   public function get (string $name, mixed $default = null): mixed
   {
      // :
      return $this->data[$name] ?? $default;
   }

   /**
    * Store data in the session.
    *
    * @param string $name
    * @param mixed $value
    */
   public function set (string $name, mixed $value): void
   {
      // @
      $this->data[$name] = $value;
      $this->needSave = true;
   }

   /**
    * Delete an item from the session.
    *
    * @param string $name
    */
   public function delete (string $name): void
   {
      // @
      unset($this->data[$name]);
      $this->needSave = true;
   }

   /**
    * Retrieve and delete an item from the session.
    *
    * @param string $name
    * @param mixed $default
    *
    * @return mixed
    */
   public function pull (string $name, mixed $default = null): mixed
   {
      // @
      $value = $this->get($name, $default);
      $this->delete($name);

      // :
      return $value;
   }

   /**
    * Store data in the session (bulk).
    *
    * @param array<string, mixed>|string $key
    * @param mixed $value
    */
   public function put (array|string $key, mixed $value = null): void
   {
      // ?
      if (!is_array($key)) {
         $this->set($key, $value);
         return;
      }

      // @
      foreach ($key as $k => $v) {
         $this->data[$k] = $v;
      }

      $this->needSave = true;
   }

   /**
    * Remove data from the session (bulk).
    *
    * @param array<int, string>|string $name
    */
   public function forget (array|string $name): void
   {
      // ?
      if (is_scalar($name)) {
         $this->delete($name);
         return;
      }

      // @
      foreach ($name as $key) {
         unset($this->data[$key]);
      }

      $this->needSave = true;
   }

   /**
    * List all the data in the session.
    *
    * @return array<string, mixed>
    */
   public function list (): array
   {
      // :
      return $this->data;
   }

   /**
    * Remove all data from the session.
    *
    * @return void
    */
   public function flush (): void
   {
      // @
      $this->needSave = true;
      $this->data = [];
   }

   /**
    * Regenerate the session ID to prevent session fixation attacks.
    *
    * Must be called immediately after authenticating a user or elevating privileges.
    * Destroys the old session, generates a new cryptographically random ID,
    * migrates session data and updates the Set-Cookie header on the current response.
    *
    * @return void
    */
   public function regenerate (): void
   {
      // @ Destroy old session data
      $oldId = $this->id;
      if (Handler::$instance) {
         Handler::$instance->destroy($oldId);
      }

      // @ Generate new session ID
      $newId = bin2hex(random_bytes(16));
      $this->id = $newId;
      $this->needSave = true;

      // @ Update Set-Cookie header on current response
      $name = static::$name;
      $Cookie = new Cookie($name, $newId);
      $Cookie->expiration = static::$cookieLifetime;
      $Cookie->path = static::$cookiePath;
      $Cookie->domain = static::$domain;
      $Cookie->secure = static::$secure;
      $Cookie->HTTP_only = static::$httpOnly;
      $Cookie->same_site = static::$sameSite;

      HTTP_Server_CLI::$Response->Header->Cookies->append($Cookie);
   }

   /**
    * Determine if an item exists in the session (null = false).
    *
    * @param string $name
    *
    * @return bool
    */
   public function has (string $name): bool
   {
      // :
      return isset($this->data[$name]);
   }

   /**
    * Check if an item is present, even if its value is null.
    *
    * @param string $name
    *
    * @return bool
    */
   public function check (string $name): bool
   {
      // :
      return array_key_exists($name, $this->data);
   }

   // ---

   /**
    * Init session defaults from PHP config.
    *
    * @return void
    */
   protected static function init (): void
   {
      // @ GC
      if (
         ($gcProbability = (int) ini_get('session.gc_probability'))
         && ($gcDivisor = (int) ini_get('session.gc_divisor'))
      ) {
         static::$gcProbability = [$gcProbability, $gcDivisor];
      }

      if ($gcMaxLifeTime = ini_get('session.gc_maxlifetime')) {
         self::$lifetime = (int) $gcMaxLifeTime;
      }

      // @ Cookie
      $sessionCookieParams = session_get_cookie_params();
      static::$cookieLifetime = $sessionCookieParams['lifetime'];
      static::$cookiePath = $sessionCookieParams['path'];
      static::$domain = $sessionCookieParams['domain'];
      static::$secure = $sessionCookieParams['secure'];
      static::$httpOnly = $sessionCookieParams['httponly'];

      // * Metadata
      self::$initialized = true;
   }

   // ---

   /** @param array<string,mixed> $data */
   public function __unserialize (array $data): void
   {
      $this->isSafe = false;
   }

   public function __destruct ()
   {
      // ?
      if (!$this->isSafe) {
         return;
      }

      // @ save
      if ($this->needSave && Handler::$instance) {
         if (empty($this->data)) {
            Handler::$instance->destroy($this->id);
         } else {
            Handler::$instance->write($this->id, ($this->serializer[0])($this->data));
         }
      } elseif (static::$autoUpdateTimestamp) {
         // ?
         if (Handler::$instance !== null) {
            Handler::$instance->touch($this->id);
         }
      }

      $this->needSave = false;

      if (random_int(1, static::$gcProbability[1]) <= static::$gcProbability[0]) {
         Handler::$instance?->purge(static::$lifetime);
      }
   }
}
