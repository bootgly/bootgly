<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


use function array_key_exists;
use function array_pop;
use function array_values;
use function bin2hex;
use function ini_get;
use function is_array;
use function is_object;
use function is_scalar;
use function is_string;
use function random_bytes;
use function random_int;
use function serialize;
use function session_get_cookie_params;
use function unserialize;
use RuntimeException;

use Bootgly\ABI\Events\Emitter;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header\Cookie;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Committing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handler;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Regenerators;


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
   /** Whether this object loaded or successfully created its current ID. */
   protected bool $persisted = false;
   /** Opaque revision of the exact persisted snapshot loaded by this object. */
   protected null|string $revision = null;
   public protected(set) string $id;
   /**
    * True when the supplied ID matched an existing server-issued session
    * record and its data was successfully read. Used by `Request->Session`
    * to enforce strict mode: unknown client-supplied IDs are rotated to
    * a fresh server-generated ID before any first write.
    */
   public protected(set) bool $loaded = false;
   protected bool $isSafe = true;

   // ! Tracks whether `Set-Cookie: PHPSID=<id>` has already been appended
   //   to the current response. Cookie issuance is deferred until the
   //   session is actually mutated (see `emit()`).
   protected bool $cookieEmitted = false;


   public function __construct (string $id)
   {
      // ? Init
      if (self::$initialized === false) {
         static::init();
      }

      // ! Handler
      if (Handler::$instance === null) {
         Handler::init();
      }

      // @ Read
      $this->id = $id;

      $Handler = Handler::$instance;
      $revision = null;
      $data = $Handler instanceof Committing
         ? $Handler->fetch($id, $revision)
         : $Handler?->read($id);
      if (is_string($data)) {
         if ($Handler instanceof Committing && is_string($revision) === false) {
            throw new RuntimeException(
               'Atomic Session handlers must return a revision for every fetched record.'
            );
         }

         $decoded = self::decode($data);
         if ($decoded !== null) {
            $this->data = $decoded;
            $this->loaded = true;
            $this->persisted = true;
            $this->revision = $revision;
         }
      }

      // @ Events — session established (guarded: zero-alloc when no listeners)
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Start) && $Emitter->emit(Events::Start, $id);
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
      $this->emit();
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
      $this->emit();
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
      $this->emit();
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
      $this->emit();
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
      $this->emit();
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
      $Handler = Handler::$instance;
      if ($this->persisted) {
         if (
            $Handler instanceof Committing === false
            || is_string($this->revision) === false
         ) {
            throw new RuntimeException(
               'Loaded Session handlers must implement atomic commit/revoke semantics.'
            );
         }

         $revoked = $Handler->revoke($oldId, $this->revision);
         if ($revoked === false) {
            // ! Another request already changed or revoked this authenticated snapshot.
            //   Never migrate its stale data into a fresh session ID.
            $this->invalidate();

            return;
         }
      }
      elseif ($Handler !== null) {
         $Handler->destroy($oldId);
      }

      // @ Generate new session ID
      $newId = bin2hex(random_bytes(16));
      $this->id = $newId;
      $this->needSave = true;
      $this->persisted = false;
      $this->revision = null;

      // @ Update Set-Cookie header on current response
      $name = static::$name;
      $Cookie = new Cookie($name, $newId);
      // ? Lifetime 0 = browser-session cookie (omit Max-Age) — PHP setcookie semantics
      $Cookie->expiration = static::$cookieLifetime > 0 ? static::$cookieLifetime : null;
      $Cookie->path = static::$cookiePath;
      $Cookie->domain = static::$domain;
      $Cookie->secure = static::$secure;
      $Cookie->HTTP_only = static::$httpOnly;
      $Cookie->same_site = static::$sameSite;

      HTTP_Server_CLI::$Response->Header->Cookies->append($Cookie);
      $this->cookieEmitted = true;

      // ! Security invariants run synchronously outside the replaceable,
      //   stoppable application event bus. Ordinary observers still receive
      //   the Regenerate event below after privilege-bound state is safe.
      Regenerators::execute($this);

      // @ Events — session id rotated (guarded). Include this Session so
      //   security middleware can rotate state bound to the old privilege
      //   context without coupling Session to middleware-specific keys.
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Regenerate)
         && $Emitter->emit(Events::Regenerate, $oldId, $newId, $this);
   }

   /**
    * Emit `Set-Cookie: PHPSID=<id>` on the current response exactly once.
    *
    * Called from every mutator (`set`, `put`, `delete`, `forget`, `flush`)
    * so that cookie issuance is tied to actual session usage rather than
    * mere instantiation. Read-only access to the session therefore never
    * emits `Set-Cookie`, closing the fixation + DoS surface described in
    * the Security_Audit_Report §3.
    *
    * @return void
    */
   protected function emit (): void
   {
      if ($this->cookieEmitted === true) {
         return;
      }

      $Cookie = new Cookie(static::$name, $this->id);
      // ? Lifetime 0 = browser-session cookie (omit Max-Age) — PHP setcookie semantics
      $Cookie->expiration = static::$cookieLifetime > 0 ? static::$cookieLifetime : null;
      $Cookie->path = static::$cookiePath;
      $Cookie->domain = static::$domain;
      $Cookie->secure = static::$secure;
      $Cookie->HTTP_only = static::$httpOnly;
      $Cookie->same_site = static::$sameSite;

      HTTP_Server_CLI::$Response->Header->Cookies->append($Cookie);
      $this->cookieEmitted = true;
   }

   /**
    * Replace the session ID without touching the storage backend or
    * emitting a Set-Cookie. Used by `Request->Session` strict mode to
    * discard an unknown client-supplied ID before any persistence.
    *
    * Cookie issuance is still deferred to `emit()` and only fires on the
    * next mutation, preserving the read-only no-Set-Cookie invariant.
    */
   public function rotate (string $newId): void
   {
      // ! rotate() is the strict-mode path for an unknown, unloaded ID. If a
      //   caller applies it to persisted state, discard that state so this
      //   helper cannot migrate a stale authenticated snapshot.
      if ($this->persisted) {
         $this->data = [];
         $this->needSave = false;
      }

      $this->id = $newId;
      $this->loaded = false;
      $this->persisted = false;
      $this->revision = null;
      $this->cookieEmitted = false;
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

   /**
    * Persist the session to the handler (or destroy it when emptied).
    *
    * Called by the server right before the response is encoded so that
    * persistence is deterministic — `__destruct` timing is GC-bound
    * (reference cycles can defer it past subsequent requests) and remains
    * only as a safety net.
    *
    * @return void
    */
   public function save (): void
   {
      // ?
      if ($this->needSave === false || Handler::$instance === null) {
         return;
      }

      try {
         // @
         if (empty($this->data)) {
            $Handler = Handler::$instance;
            if ($this->persisted) {
               if (
                  $Handler instanceof Committing === false
                  || is_string($this->revision) === false
               ) {
                  throw new RuntimeException(
                     'Loaded Session handlers must implement atomic commit/revoke semantics.'
                  );
               }

               if ($Handler->revoke($this->id, $this->revision) === false) {
                  $this->invalidate();

                  return;
               }
            }
            elseif ($Handler->destroy($this->id) === false) {
               throw new RuntimeException('Failed to destroy the Session record.');
            }
            $this->persisted = false;
            $this->revision = null;

            // @ Events — session destroyed (emptied on save) (guarded)
            $Emitter = Emitter::$Instance;
            $Emitter->check(Events::Destroy) && $Emitter->emit(Events::Destroy, $this->id);
         }
         else {
            $Handler = Handler::$instance;
            $payload = serialize($this->data);

            // ! Loaded sessions must never fall back to unconditional write().
            //   The opaque revision binds this save to the exact snapshot read,
            //   so in-place logout/downgrade and revocation both win over stale data.
            if ($Handler instanceof Committing) {
               $revision = $this->revision;
               $saved = $Handler->commit($this->id, $payload, $revision);
            }
            elseif ($this->persisted === false) {
               $saved = $Handler->write($this->id, $payload);
               if ($saved === false) {
                  throw new RuntimeException('Failed to persist the new Session record.');
               }
               $revision = null;
            }
            else {
               throw new RuntimeException(
                  'Loaded Session handlers must implement atomic commit/revoke semantics.'
               );
            }

            if ($saved === false) {
               // ! A conditional conflict means another request revoked or
               //   claimed this ID. Drop the stale snapshot and move to a new,
               //   empty ID so later application code cannot retry as create.
               $this->invalidate();

               return;
            }

            $this->persisted = true;
            $this->revision = $revision;
         }
      }
      finally {
         // ! Neither a stale conflict nor a backend exception may be retried
         //   later from __destruct() against changed global handler state.
         $this->needSave = false;
      }
   }

   /** Discard one stale snapshot and select a fresh, empty local ID. */
   private function invalidate (): void
   {
      $this->data = [];
      $this->id = bin2hex(random_bytes(16));
      $this->loaded = false;
      $this->persisted = false;
      $this->revision = null;
      $this->needSave = false;
      $this->cookieEmitted = false;
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
      // ! `secure`/`httpOnly`/`sameSite` are framework-owned (audit F-9): they
      //   are NOT sourced from `php.ini`. Stock PHP ships
      //   `session.cookie_secure` and `session.cookie_httponly` OFF, which
      //   would silently downgrade the safe class defaults (`true`/`true`),
      //   sending the session cookie over plain HTTP and exposing it to JS.
      //   HTTP-only development relaxes explicitly via `Session::$secure = false`.

      // * Metadata
      self::$initialized = true;
   }

   /**
    * Decode scalar/array-only session state without instantiating PHP classes.
    * Cyclic or excessively large graphs fail closed during the bounded scan.
    *
    * @return null|array<string,mixed>
    */
   private static function decode (string $payload): null|array
   {
      $data = @unserialize($payload, [
         'allowed_classes' => false,
         'max_depth' => 64,
      ]);
      if (is_array($data) === false) {
         return null;
      }

      $safe = [];
      foreach ($data as $key => $value) {
         if (is_string($key) === false) {
            return null;
         }
         $safe[$key] = $value;
      }

      /** @var array<int,mixed> $pending */
      $pending = array_values($safe);
      $count = 0;
      while ($pending !== []) {
         if (++$count > 10_000) {
            return null;
         }

         $value = array_pop($pending);
         if (is_object($value)) {
            return null;
         }
         if (is_array($value) === false) {
            continue;
         }

         foreach ($value as $item) {
            if (is_object($item)) {
               return null;
            }
            if (is_array($item)) {
               $pending[] = $item;
            }
         }
      }

      return $safe;
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

      // @ Safety net — the server normally persists via save() pre-response
      if ($this->needSave && Handler::$instance) {
         $this->save();
      } elseif (static::$autoUpdateTimestamp && $this->persisted) {
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
