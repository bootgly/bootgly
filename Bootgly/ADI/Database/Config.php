<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use function filter_var;
use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function trim;
use InvalidArgumentException;


/**
 * ADI-native database configuration.
 *
 * API config scopes may build this value, but this class deliberately has no
 * API dependency so the ADI layer remains standalone.
 */
class Config
{
   public const string DEFAULT_DRIVER = 'pgsql';
   public const string DEFAULT_HOST = '127.0.0.1';
   public const int DEFAULT_PORT = 5432;
   public const string DEFAULT_DATABASE = 'bootgly';
   public const string DEFAULT_USERNAME = 'postgres';
   public const string DEFAULT_PASSWORD = '';
   public const float DEFAULT_TIMEOUT = 30.0;
   public const int DEFAULT_POOL_MIN = 0;
   public const int DEFAULT_POOL_MAX = 8;
   public const string SECURE_DISABLE = 'disable';
   public const string SECURE_PREFER = 'prefer';
   public const string SECURE_REQUIRE = 'require';
   public const string SECURE_VERIFY_CA = 'verify-ca';
   public const string SECURE_VERIFY_FULL = 'verify-full';
   public const string DEFAULT_SECURE_MODE = self::SECURE_PREFER;
   public const string DEFAULT_SECURE_PEER = '';
   public const string DEFAULT_SECURE_CAFILE = '';
   public const string DEFAULT_SECURE_KEY = '';
   public const array SECURE_MODES = [
      self::SECURE_DISABLE,
      self::SECURE_PREFER,
      self::SECURE_REQUIRE,
      self::SECURE_VERIFY_CA,
      self::SECURE_VERIFY_FULL,
   ];

   // * Config
   public string $driver;
   public string $host;
   public int $port;
   public string $database;
   public string $username;
   public string $password;
   public float $timeout;
   /**
    * TLS settings plus `key`: a pinned server RSA public key (inline PEM or
    * file path) required by the MySQL password exchange over plaintext.
    *
    * @var array{mode:string,verify:bool,name:bool,peer:string,cafile:string,key:string}
    */
   public array $secure;
   /** @var array{min:int,max:int} */
   public array $pool;
   /** @var array<int,array<string,mixed>> */
   public array $replicas;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Create a configuration value.
    *
    * @param array<string,mixed> $config
    */
   public function __construct (array $config = [])
   {
      $pool = $config['pool'] ?? [];
      if (is_array($pool) === false) {
         $pool = [];
      }

      $secure = $config['secure'] ?? [];
      if (is_array($secure) === false) {
         $secure = [];
      }

      $driver = $config['driver'] ?? self::DEFAULT_DRIVER;
      $host = $config['host'] ?? self::DEFAULT_HOST;
      $port = $config['port'] ?? self::DEFAULT_PORT;
      $database = $config['database'] ?? self::DEFAULT_DATABASE;
      $username = $config['username'] ?? self::DEFAULT_USERNAME;
      $password = $config['password'] ?? self::DEFAULT_PASSWORD;
      $timeout = $config['timeout'] ?? self::DEFAULT_TIMEOUT;
      $poolMin = $pool['min'] ?? self::DEFAULT_POOL_MIN;
      $poolMax = $pool['max'] ?? self::DEFAULT_POOL_MAX;
      $secureMode = $secure['mode'] ?? self::DEFAULT_SECURE_MODE;
      $securePeer = $secure['peer'] ?? self::DEFAULT_SECURE_PEER;
      $secureCA = $secure['cafile'] ?? self::DEFAULT_SECURE_CAFILE;
      $secureKey = $secure['key'] ?? self::DEFAULT_SECURE_KEY;

      // * Config
      $this->driver = is_scalar($driver) ? (string) $driver : self::DEFAULT_DRIVER;
      $this->host = is_scalar($host) ? (string) $host : self::DEFAULT_HOST;
      $this->port = is_scalar($port) ? (int) $port : self::DEFAULT_PORT;
      $this->database = is_scalar($database) ? (string) $database : self::DEFAULT_DATABASE;
      $this->username = is_scalar($username) ? (string) $username : self::DEFAULT_USERNAME;
      $this->password = is_scalar($password) ? (string) $password : self::DEFAULT_PASSWORD;
      $this->timeout = is_scalar($timeout) ? (float) $timeout : self::DEFAULT_TIMEOUT;

      $secureMode = $this->validate(is_scalar($secureMode) ? (string) $secureMode : self::DEFAULT_SECURE_MODE);
      // ! Verification is what `verify-ca`/`verify-full` ADD: `prefer` and
      //   `require` encrypt without it unless `verify`/`name` opt in — the
      //   libpq/MySQL `sslmode` contract these names come from. A default that
      //   verified refused every stock MySQL 8 (TLS on, self-signed) at first contact.
      $strict = $secureMode === self::SECURE_VERIFY_CA || $secureMode === self::SECURE_VERIFY_FULL;
      $secureVerify = $this->cast($secure['verify'] ?? null, 'verify') ?? $strict;
      $secureName = $this->cast($secure['name'] ?? null, 'name') ?? $secureVerify;

      if ($secureMode === self::SECURE_VERIFY_CA) {
         $secureVerify = true;
         $secureName = false;
      }

      if ($secureMode === self::SECURE_DISABLE) {
         $secureVerify = false;
         $secureName = false;
      }

      if ($secureMode === self::SECURE_VERIFY_FULL) {
         $secureVerify = true;
         $secureName = true;
      }

      $secureCA = is_scalar($secureCA) ? (string) $secureCA : self::DEFAULT_SECURE_CAFILE;
      // ? A `cafile` is only ever read by a verifying handshake — PHP loads it
      //   with `verify_peer` alone — so one under an unverified mode would be
      //   silently unused. Refused here, at config time, naming the way out.
      if ($secureCA !== '' && $secureVerify === false && $secureMode !== self::SECURE_DISABLE) {
         throw new InvalidArgumentException(
            "Database TLS cafile requires certificate verification: use mode `verify-ca` or `verify-full`, or set `verify` to true."
         );
      }

      $this->secure = [
         'mode' => $secureMode,
         'verify' => $secureVerify,
         'name' => $secureName,
         'peer' => is_scalar($securePeer) && (string) $securePeer !== '' ? (string) $securePeer : $this->host,
         'cafile' => $secureCA,
         'key' => is_scalar($secureKey) ? (string) $secureKey : self::DEFAULT_SECURE_KEY,
      ];
      $this->pool = [
         'min' => is_scalar($poolMin) ? (int) $poolMin : self::DEFAULT_POOL_MIN,
         'max' => is_scalar($poolMax) ? (int) $poolMax : self::DEFAULT_POOL_MAX,
      ];
      $this->replicas = $this->normalize($config['replicas'] ?? []);
   }

   /**
    * Normalize read replica endpoint configs.
    *
    * @return array<int,array<string,mixed>>
    */
   private function normalize (mixed $replicas): array
   {
      if (is_array($replicas) === false) {
         return [];
      }

      $normalized = [];

      foreach ($replicas as $replica) {
         $replica = $this->accept($replica);

         if ($replica === null) {
            continue;
         }

         $host = (string) $replica['host'];

         $secure = $replica['secure'];
         if (is_array($secure) === false) {
            $secure = [];
         }

         $pool = $replica['pool'];
         if (is_array($pool) === false) {
            $pool = [];
         }

         $secureMode = $secure['mode'] ?? $this->secure['mode'];
         $securePeer = $secure['peer'] ?? self::DEFAULT_SECURE_PEER;
         $secureCA = $secure['cafile'] ?? $this->secure['cafile'];
         $secureKey = $secure['key'] ?? $this->secure['key'];
         $secureMode = $this->validate(is_scalar($secureMode) ? (string) $secureMode : $this->secure['mode']);
         // ! A replica that declares its own `mode` derives its flags from it,
         //   exactly like the primary; one that declares neither `mode` nor
         //   `verify` inherits the primary's resolved flags.
         $inherited = is_scalar($secure['mode'] ?? null) === false;
         $strict = $secureMode === self::SECURE_VERIFY_CA || $secureMode === self::SECURE_VERIFY_FULL;
         $verify = $this->cast($secure['verify'] ?? null, 'verify', $host);
         $secureVerify = $verify ?? ($inherited ? $this->secure['verify'] : $strict);
         $secureName = $this->cast($secure['name'] ?? null, 'name', $host)
            ?? ($inherited && $verify === null ? $this->secure['name'] : $secureVerify);

         if ($secureMode === self::SECURE_VERIFY_CA) {
            $secureVerify = true;
            $secureName = false;
         }

         if ($secureMode === self::SECURE_DISABLE) {
            $secureVerify = false;
            $secureName = false;
         }

         if ($secureMode === self::SECURE_VERIFY_FULL) {
            $secureVerify = true;
            $secureName = true;
         }

         // ? An inherited `cafile` is the primary's to verify with: a replica
         //   that resolves unverified drops it, so nothing looks pinned that no
         //   handshake reads — and the normalized endpoint, fed back through this
         //   constructor by `SQL`, never trips the refusal below on its own.
         if (isset($secure['cafile']) === false && $secureVerify === false) {
            $secureCA = self::DEFAULT_SECURE_CAFILE;
         }
         // ? Same refusal as the primary's, for a `cafile` the replica itself declared
         if (isset($secure['cafile']) && is_scalar($secureCA) && (string) $secureCA !== '' && $secureVerify === false && $secureMode !== self::SECURE_DISABLE) {
            throw new InvalidArgumentException(
               "Database TLS cafile requires certificate verification: use mode `verify-ca` or `verify-full`, or set `verify` to true (replica {$host})."
            );
         }

         $endpoint = [
            'driver' => is_scalar($replica['driver']) ? (string) $replica['driver'] : $this->driver,
            'host' => $host,
            'port' => is_scalar($replica['port']) ? (int) $replica['port'] : $this->port,
            'database' => is_scalar($replica['database']) ? (string) $replica['database'] : $this->database,
            'username' => is_scalar($replica['username']) ? (string) $replica['username'] : $this->username,
            'password' => is_scalar($replica['password']) ? (string) $replica['password'] : $this->password,
            'timeout' => is_scalar($replica['timeout']) ? (float) $replica['timeout'] : $this->timeout,
            'secure' => [
               'mode' => $secureMode,
               'verify' => $secureVerify,
               'name' => $secureName,
               'peer' => is_scalar($securePeer) && (string) $securePeer !== '' ? (string) $securePeer : (string) $host,
               'cafile' => is_scalar($secureCA) ? (string) $secureCA : self::DEFAULT_SECURE_CAFILE,
               'key' => is_scalar($secureKey) ? (string) $secureKey : self::DEFAULT_SECURE_KEY,
            ],
            'pool' => [
               'min' => is_scalar($pool['min'] ?? null) ? (int) $pool['min'] : $this->pool['min'],
               'max' => is_scalar($pool['max'] ?? null) ? (int) $pool['max'] : $this->pool['max'],
            ],
         ];

         $normalized[] = $endpoint;
      }

      return $normalized;
   }

   /**
    * Accept one replica config with a non-empty host.
    *
      * @return null|array{driver:mixed,host:string,port:mixed,database:mixed,username:mixed,password:mixed,timeout:mixed,secure:mixed,pool:mixed,statements:mixed}
    */
   protected function accept (mixed $replica): null|array
   {
      if (is_array($replica) === false) {
         return null;
      }

      $host = $replica['host'] ?? null;

      if (is_scalar($host) === false || (string) $host === '') {
         return null;
      }

      return [
         'driver' => $replica['driver'] ?? null,
         'host' => (string) $host,
         'port' => $replica['port'] ?? null,
         'database' => $replica['database'] ?? null,
         'username' => $replica['username'] ?? null,
         'password' => $replica['password'] ?? null,
         'timeout' => $replica['timeout'] ?? null,
         'secure' => $replica['secure'] ?? null,
         'pool' => $replica['pool'] ?? null,
         'statements' => $replica['statements'] ?? null,
      ];
   }

   /**
    * Validate one TLS mode.
    */
   private function validate (string $mode): string
   {
      if (in_array($mode, self::SECURE_MODES, true)) {
         return $mode;
      }

      throw new InvalidArgumentException("Unsupported database TLS mode: {$mode}.");
   }

   /**
    * Read one boolean TLS flag: absent stays `null` (derived from the mode), a
    * boolean or boolean-like scalar is accepted, anything else — an empty or
    * blank string included — is refused: a flag that cannot be read must never
    * resolve to "off" in silence. `$host` names the replica the flag belongs to.
    *
    * @throws InvalidArgumentException when the flag is neither boolean nor absent
    */
   private function cast (mixed $flag, string $key, string $host = ''): null|bool
   {
      // ?
      if ($flag === null) {
         return null;
      }
      if (is_bool($flag)) {
         return $flag;
      }

      $flag = is_string($flag) ? trim($flag) : $flag;
      $parsed = is_scalar($flag) && $flag !== ''
         ? filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
         : null;
      if ($parsed === null) {
         $where = $host === '' ? '' : " (replica {$host})";

         throw new InvalidArgumentException("Database TLS `{$key}` must be a boolean{$where}.");
      }

      // :
      return $parsed;
   }
}
