<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use function array_keys;
use function in_array;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function max;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function trim;
use InvalidArgumentException;


/**
 * SQL database configuration.
 */
class Config extends \Bootgly\ADI\Database\Config
{
   public const string DEFAULT_MIGRATIONS = '_bootgly_migrations';
   public const int DEFAULT_STATEMENTS = 256;
   public const float DEFAULT_ROUTING_STICKY = 5.0;
   /**
    * Session parameter names a startup message carries on its own or refuses:
    * the driver sends `user`/`database` from the connection config, and
    * `options` and `replication` change what the session is.
    */
   public const array RESERVED_PARAMETERS = [
      'database',
      'options',
      'replication',
      'user',
   ];
   /**
    * Session parameters the PostgreSQL driver sends in every startup message,
    * name => value, in this order: the formats its encoder writes and its
    * decoder reads. Each is a name a connection pooler such as PgBouncer
    * tracks by default, so a pooler accepts the startup message as is.
    */
   public const array PINNED_PARAMETERS = [
      // # Every value is read and written in UTF8.
      'client_encoding' => 'UTF8',
      // # Dates and timestamps decode from ISO text.
      'DateStyle' => 'ISO',
      // # Literals quoted by doubling `'` (Schema defaults) keep backslashes literal.
      'standard_conforming_strings' => 'on',
   ];
   /**
    * Session parameters `parameters` refuses, in any letter case, name => the
    * reason: the pinned ones — each needs its entry here, or the startup
    * message could carry it twice.
    */
   public const array FIXED_PARAMETERS = [
      'client_encoding' => 'the encoding every value is read and written in',
      'DateStyle' => 'the date and time text its decoder reads',
      'standard_conforming_strings' => 'the literal quoting its Schema writes',
   ];
   /**
    * Largest PostgreSQL startup message, in bytes after its length word — the
    * server's `MAX_STARTUP_PACKET_LENGTH`: a longer one is dropped unanswered.
    */
   public const int STARTUP_LIMIT = 10000;

   // * Config
   /** Migration repository table name. */
   public string $migrations;
   /** Maximum number of prepared statements cached per connection. */
   public int $statements;
   /** @var array{sticky:float} Process-wide best-effort seconds to keep reads on primary after any write. */
   public array $routing;
   /**
    * Session parameters sent at connection start, name => value — PostgreSQL
    * carries them in the startup message (`search_path`, `TimeZone`,
    * `statement_timeout`, `application_name`, ...). Validated once here and
    * read-only after: every value reaches the wire as written.
    *
    * @var array<string,string>
    */
   public private(set) array $parameters;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Create a SQL configuration value.
    *
    * @param array<string,mixed> $config
    */
   public function __construct (array $config = [])
   {
      parent::__construct($config);

      $migrations = $config['migrations'] ?? self::DEFAULT_MIGRATIONS;
      $statements = $config['statements'] ?? self::DEFAULT_STATEMENTS;
      $routing = $config['routing'] ?? [];

      if (is_array($routing) === false) {
         $routing = [];
      }

      // * Config
      $migrations = is_scalar($migrations) ? trim((string) $migrations) : '';
      $this->migrations = $migrations === '' ? self::DEFAULT_MIGRATIONS : $migrations;
      $this->statements = is_scalar($statements) ? max(0, (int) $statements) : self::DEFAULT_STATEMENTS;
      $this->routing = [
         'sticky' => is_scalar($routing['sticky'] ?? null) ? max(0.0, (float) $routing['sticky']) : self::DEFAULT_ROUTING_STICKY,
      ];
      $this->parameters = $this->admit($config['parameters'] ?? null);

      $this->complete($config['replicas'] ?? []);
      $this->confine();

      // ? Every endpoint's connection strings and parameters must reach its
      //   driver as written, in a message the server reads.
      $this->fit($this->driver, $this->username, $this->database, $this->parameters);

      foreach ($this->replicas as $replica) {
         /** @var array<string,string> $parameters */
         $parameters = $replica['parameters'];

         $this->fit(
            is_string($replica['driver'] ?? null) ? $replica['driver'] : $this->driver,
            is_string($replica['username'] ?? null) ? $replica['username'] : $this->username,
            is_string($replica['database'] ?? null) ? $replica['database'] : $this->database,
            $parameters,
            is_string($replica['host'] ?? null) ? $replica['host'] : ''
         );
      }
   }

   /**
    * Fit one endpoint to its driver. Every SQL driver frames `username` and
    * `database` as NUL-terminated strings — the PostgreSQL startup message,
    * the MySQL handshake response, the SQLite file path — so a NUL in either
    * would end the value early and turn the rest into fields the config never
    * named. Only PostgreSQL sends session parameters — MySQL's handshake and
    * SQLite's file open carry none — and its whole startup message, framed
    * exactly as the encoder frames it, must fit `STARTUP_LIMIT`. `$host`
    * names the replica the endpoint belongs to.
    *
    * @param array<string,string> $parameters
    *
    * @throws InvalidArgumentException when the endpoint cannot reach its driver as written
    */
   private function fit (string $driver, string $username, string $database, array $parameters, string $host = ''): void
   {
      // !
      $where = $host === '' ? '' : " (replica {$host})";

      // ?
      foreach (['username' => $username, 'database' => $database] as $key => $value) {
         if (str_contains($value, "\0")) {
            throw new InvalidArgumentException("Database `{$key}` must not contain a NUL byte{$where}.");
         }
      }
      if ($driver !== 'pgsql') {
         // ? An unregistered name (an alias such as `postgres`) is named as
         //   such: it is no driver at all, not one without session parameters.
         if ($parameters !== [] && in_array($driver, ['mysql', 'sqlite'], true) === false) {
            throw new InvalidArgumentException(
               "Database `parameters` need the pgsql driver: `{$driver}` is not a built-in driver name (pgsql, mysql or sqlite): session parameters reach only the built-in pgsql driver{$where}."
            );
         }
         if ($parameters !== []) {
            throw new InvalidArgumentException(
               "Database `parameters` are PostgreSQL only: the {$driver} driver has no startup session parameters, so set the session after connecting instead{$where}."
            );
         }

         return;
      }

      // @ Protocol version, then every `name\0value\0` pair, then the terminator.
      $size = 4 + strlen("user\0{$username}\0database\0{$database}\0") + 1;
      $named = false;

      foreach (self::PINNED_PARAMETERS + $parameters as $name => $value) {
         $named = $named || strtolower($name) === 'application_name';
         $size += strlen($name) + strlen($value) + 2;
      }

      if ($named === false) {
         $size += strlen("application_name\0Bootgly\0");
      }

      if ($size > self::STARTUP_LIMIT) {
         $limit = self::STARTUP_LIMIT;

         throw new InvalidArgumentException(
            "Database startup message is {$size} bytes, over PostgreSQL's {$limit}-byte limit: shorten `parameters`, `username` or `database`{$where}."
         );
      }
   }

   /**
    * Confine every pool whose database is private to the handle that opens it.
    *
    * The `sqlite3` extension opens one database per driver instance and the pool builds one
    * driver per connection. For `:memory:` and for the empty name that database is private
    * to its handle, so a second connection is a second, empty database and a row written
    * through one is invisible to the other with no error anywhere. A file database is shared
    * between handles and keeps the pool it was given.
    */
   private function confine (): void
   {
      if ($this->check($this->driver, $this->database)) {
         $this->pool = $this->narrow($this->pool);
      }

      // @ A replica carries its own driver, its own database and its own pool.
      foreach ($this->replicas as $id => $replica) {
         $driver = $replica['driver'] ?? null;
         $database = $replica['database'] ?? null;
         $pool = $replica['pool'] ?? null;

         if (is_string($driver) === false || is_string($database) === false) {
            continue;
         }

         if (is_array($pool) === false || $this->check($driver, $database) === false) {
            continue;
         }

         $min = $pool['min'] ?? self::DEFAULT_POOL_MIN;
         $max = $pool['max'] ?? self::DEFAULT_POOL_MAX;

         $this->replicas[$id]['pool'] = $this->narrow([
            'min' => is_scalar($min) ? (int) $min : self::DEFAULT_POOL_MIN,
            'max' => is_scalar($max) ? (int) $max : self::DEFAULT_POOL_MAX,
         ]);
      }
   }

   /**
    * Check whether a database is private to the handle that opens it.
    */
   private function check (string $driver, string $database): bool
   {
      // ?: Only SQLite mints a database per handle, and only under these two names —
      //    every other name is a file that all handles share.
      return $driver === 'sqlite' && ($database === ':memory:' || $database === '');
   }

   /**
    * Narrow one pool configuration down to a single connection.
    *
    * @param array{min:int,max:int} $pool
    * @return array{min:int,max:int}
    */
   private function narrow (array $pool): array
   {
      // ? A pool of one, or of none, is already confined — and only a pool narrowed here
      //   can end up with a floor above its ceiling.
      if ($pool['max'] > 1) {
         $pool['max'] = 1;

         if ($pool['min'] > $pool['max']) {
            $pool['min'] = $pool['max'];
         }
      }

      return $pool;
   }

   /**
    * Complete SQL-specific replica configuration.
    */
   private function complete (mixed $replicas): void
   {
      foreach ($this->replicas as $id => $replica) {
         $this->replicas[$id]['statements'] = $this->statements;
         $this->replicas[$id]['parameters'] = $this->parameters;
      }

      if (is_array($replicas) === false) {
         return;
      }

      $id = 0;

      foreach ($replicas as $raw) {
         $replica = $this->accept($raw);

         if ($replica === null || is_array($raw) === false) {
            continue;
         }

         if (isset($this->replicas[$id]) === false) {
            break;
         }

         $statements = $replica['statements'];
         $this->replicas[$id]['statements'] = is_scalar($statements) ? max(0, (int) $statements) : $this->statements;

         // ! A replica inherits the primary's parameters name by name, like
         //   `secure` and `pool`: its own entry replaces the primary's under
         //   the same name, in any letter case — PostgreSQL reads parameter
         //   names case-insensitively, so both spellings would set one value.
         $parameters = $this->parameters;

         foreach ($this->admit($raw['parameters'] ?? null, $replica['host']) as $name => $value) {
            foreach (array_keys($parameters) as $inherited) {
               if (strtolower($inherited) === strtolower($name)) {
                  unset($parameters[$inherited]);
               }
            }

            $parameters[$name] = $value;
         }

         $this->replicas[$id]['parameters'] = $parameters;
         $id++;
      }
   }

   /**
    * Admit the session parameters of one endpoint: absent is none; anything
    * else is a map of identifier names to string or integer values, refused
    * whole — naming the key — at the first entry the startup message could
    * not carry as written. `$host` names the replica the map belongs to.
    *
    * @throws InvalidArgumentException when the map or one of its entries is refused
    *
    * @return array<string,string>
    */
   private function admit (mixed $parameters, string $host = ''): array
   {
      // ?
      if ($parameters === null) {
         return [];
      }

      // !
      $where = $host === '' ? '' : " (replica {$host})";

      if (is_array($parameters) === false) {
         throw new InvalidArgumentException("Database `parameters` must be an array of session parameter names to values{$where}.");
      }

      $admitted = [];
      $seen = [];

      // @@
      foreach ($parameters as $name => $value) {
         $name = (string) $name;
         $key = strtolower($name);

         // ? A name is a PostgreSQL setting identifier: one with a NUL, `=`
         //   or a space in it would end the key early on the wire.
         if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/D', $name) !== 1) {
            throw new InvalidArgumentException(
               "Database parameter `{$name}` is not a valid name: use letters, digits, `_` and `.`, starting with a letter or `_`{$where}."
            );
         }
         // ? A fixed name (every pinned one among them) would change the text
         //   the driver writes or reads.
         foreach (self::FIXED_PARAMETERS as $fixed => $reason) {
            if ($key === strtolower($fixed)) {
               $pin = self::PINNED_PARAMETERS[$fixed];
               $reason = "the driver fixes {$fixed} to {$pin}, {$reason}";

               throw new InvalidArgumentException(
                  "Database parameter `{$name}` cannot be set: {$reason}{$where}."
               );
            }
         }
         if (in_array($key, self::RESERVED_PARAMETERS, true)) {
            throw new InvalidArgumentException(
               "Database parameter `{$name}` is reserved: the startup message sends it from the connection config or refuses it{$where}."
            );
         }
         // ? `_pq_.` names are protocol extension requests, not settings: the
         //   server would answer one with a negotiation message instead.
         if (str_starts_with($key, '_pq_.')) {
            throw new InvalidArgumentException(
               "Database parameter `{$name}` is reserved for protocol extensions{$where}."
            );
         }
         if (isset($seen[$key])) {
            throw new InvalidArgumentException(
               "Database parameter `{$name}` is given twice (as `{$seen[$key]}`): parameter names are case-insensitive{$where}."
            );
         }
         // ? A string is sent as written and an integer in its decimal form.
         //   Floats and booleans have no single text form PostgreSQL agrees
         //   on (`0.30000000000000004`, `1.0E+25`, `false` as ''): write them
         //   as strings (`'0.5'`, `'on'`).
         if (is_int($value)) {
            $value = (string) $value;
         }
         if (is_string($value) === false) {
            throw new InvalidArgumentException(
               "Database parameter `{$name}` must be a string or an integer{$where}."
            );
         }
         // ? A NUL ends the value on the wire and starts a key the config never named.
         if (str_contains($value, "\0")) {
            throw new InvalidArgumentException(
               "Database parameter `{$name}` must not contain a NUL byte{$where}."
            );
         }
         // ? Floats decode from the server's text: 1 to 3 extra digits keep
         //   them exact (3 on PostgreSQL before 12), 0 or less rounds them —
         //   the way to restore exactness when a role or database lowers it
         if ($key === 'extra_float_digits' && preg_match('/^[1-3]$/D', $value) !== 1) {
            throw new InvalidArgumentException(
               "Database parameter `{$name}` must be 1, 2 or 3: floats decode from the server's text, and 0 or less rounds them{$where}."
            );
         }

         $seen[$key] = $name;
         $admitted[$name] = $value;
      }

      // :
      return $admitted;
   }
}
