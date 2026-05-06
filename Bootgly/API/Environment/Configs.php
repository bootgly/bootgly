<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment;


use const BOOTGLY_ROOT_BASE;
use const INI_SCANNER_RAW;
use function array_key_exists;
use function file_exists;
use function getenv;
use function is_scalar;
use function is_string;
use function parse_ini_file;
use function preg_match;
use InvalidArgumentException;

use Bootgly\ABI\IO\FS\File;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\Scopes;


/**
 * Loads Bootgly configuration scopes from filesystem directories.
 *
 * A scope maps to `configs/<scope>/<scope>.config.php` plus optional `.env`
 * files. Scope names are validated, paths are contained with `File::guard()`,
 * and `.env` values are kept local to this loader instance.
 *
 * `.env` variable names are validated before they reach `Config::bind()`.
 * Scopes may further restrict local variables with `allow()` and reserve keys
 * for runtime-only injection with `lock()`.
 *
 * Nested config values are accessed by object navigation on the returned
 * `Config`; `Configs::get()` is intentionally scope-only.
 *
 * Security: `<scope>.config.php` is trusted PHP code executed with `require`.
 * See `docs/TRUST_BOUNDARY.md`.
 */
class Configs
{
   // * Config
   public protected(set) string $basedir;

   // * Data
   public Scopes $Scopes;
   /** @var array<string,string> */
   private array $variables = [];
   /** @var array<string,array<string,true>> */
   private array $allowed = [];
   /** @var array<string,array<string,true>> */
   private array $locked = [];

   // * Metadata
   // ...


   /**
    * Create a config loader for a base directory.
    *
    * When omitted, the framework `configs/` directory is used.
    */
   public function __construct (string $basedir = '')
   {
      // * Config
      $this->basedir = $basedir !== ''
         ? $basedir
         : BOOTGLY_ROOT_BASE . '/configs/';

      // * Data
      $this->Scopes = new Scopes;
   }

   /**
    * Detect the active config environment from `BOOTGLY_ENV`.
    *
    * Invalid environment names are ignored so they cannot affect path
    * selection for `.env.<environment>` files.
    */
   protected function detect (): string
   {
      $env = getenv('BOOTGLY_ENV');

      if ($env === false || $env === '') {
         return '';
      }

      return $this->check($env) ? $env : '';
   }

   /**
    * Validate a scope, environment or `.env` variable name.
    *
    * Scope/environment names accept single path-safe segments. Variable names
    * accept uppercase shell-style keys only.
    */
   protected function check (string $value, string $format = 'scope'): bool
   {
      if ($format === 'variable') {
         return preg_match('/\A[A-Z_][A-Z0-9_]*\z/', $value) === 1;
      }

      return preg_match('/\A[A-Za-z0-9_-]+\z/', $value) === 1;
   }

   /**
    * Allow only the listed local `.env` variable names for a scope.
    *
    * When an allowlist exists, any extra `.env` key fails the scope load. This
    * protects against typos and cross-scope config contamination.
    *
      * @param array<int,mixed> $keys
    * @throws InvalidArgumentException when scope or key names are invalid.
    */
   public function allow (string $scope, array $keys): self
   {
      if ($this->check($scope) === false) {
         throw new InvalidArgumentException('Invalid config scope.');
      }

      $this->allowed[$scope] ??= [];

      foreach ($keys as $key) {
         if (is_string($key) === false || $this->check($key, 'variable') === false) {
            throw new InvalidArgumentException('Invalid config variable name.');
         }

         $this->allowed[$scope][$key] = true;
      }

      return $this;
   }

   /**
    * Reserve local `.env` variable names for runtime-only injection.
    *
    * Locked keys may still be provided by the real process environment. They
    * cannot be supplied by `.env` or `.env.<environment>` files.
    *
      * @param array<int,mixed> $keys
    * @throws InvalidArgumentException when scope or key names are invalid.
    */
   public function lock (string $scope, array $keys): self
   {
      if ($this->check($scope) === false) {
         throw new InvalidArgumentException('Invalid config scope.');
      }

      $this->locked[$scope] ??= [];

      foreach ($keys as $key) {
         if (is_string($key) === false || $this->check($key, 'variable') === false) {
            throw new InvalidArgumentException('Invalid config variable name.');
         }

         $this->locked[$scope][$key] = true;
      }

      return $this;
   }

   /**
    * Check whether a path escapes this loader's base directory.
    *
    * Returns `true` when the path must be blocked.
    */
   protected function guard (string $path): bool
   {
      $File = new File($path, $this->basedir);

      return $File->guard($path);
   }

   /**
    * Load a `.env` file into the local config environment map.
    *
    * Values are not exported with `putenv()` and are visible only while the
    * matching `.config.php` file is being required. Invalid, disallowed or
    * locked keys fail closed.
    */
   protected function inject (string $scope, string $file): bool
   {
      if (file_exists($file) === false) {
         return true;
      }

      if ($this->guard($file)) {
         return false;
      }

      $variables = parse_ini_file($file, false, INI_SCANNER_RAW);

      if ($variables === false) {
         return false;
      }

      foreach ($variables as $key => $value) {
         $name = (string) $key;

         if ($this->check($name, 'variable') === false) {
            return false;
         }

         if (array_key_exists($scope, $this->allowed) && isset($this->allowed[$scope][$name]) === false) {
            return false;
         }

         if (isset($this->locked[$scope][$name])) {
            return false;
         }

         if (is_scalar($value) === false) {
            return false;
         }

         $this->variables[$name] = (string) $value;
      }

      return true;
   }

   // ! Scope
   /**
    * Get a loaded scope, lazy-loading it when needed.
    *
    * This method accepts only scope names. Dot-notation is not supported;
    * use object navigation on the returned `Config` instead.
    *
    * @return mixed `Config` when the scope exists; `$default` otherwise.
    */
   public function get (string $key, mixed $default = null): mixed
   {
      // ? Scope only — nested access uses object navigation
      if ($this->check($key) === false) {
         return $default;
      }

      // ? Lazy load
      if ($this->Scopes->check($key) === false) {
         $this->load($key);
      }

      $Config = $this->Scopes->get($key);

      // ? Scope not found
      if ($Config === null) {
         return $default;
      }

      // @
      return $Config;
   }

   /**
    * Load and register one config scope.
    *
    * The loader reads `.env`, then `.env.<BOOTGLY_ENV>`, then executes the
    * trusted PHP config file for the scope.
    */
   public function load (string $scope): bool
   {
      if ($this->check($scope) === false) {
         return false;
      }

      $dir = "{$this->basedir}$scope/";

      if ($this->guard($dir)) {
         return false;
      }

      // @ Detect active environment
      $environment = $this->detect();

      // @ Reset local config environment
      $this->variables = [];

      // @ Load .env files into local config environment
      // 1) Shared
      if ($this->inject($scope, "$dir.env") === false) {
         $this->variables = [];

         return false;
      }
      // 2) Environment-specific override
      if ($environment !== '' && $this->inject($scope, "$dir.env.$environment") === false) {
         $this->variables = [];

         return false;
      }

      // @ Load PHP config file
      $file = "$dir$scope.config.php";
      try {
         $Config = $this->include($file);
      }
      finally {
         $this->variables = [];
      }

      if ($Config === null) {
         return false;
      }

      // @ Register
      $this->Scopes->add($Config);

      return true;
   }

   // @
   /**
    * Require one trusted PHP config file and return its root `Config`.
    *
    * The local `.env` map is temporarily exposed through `Config::swap()` so
    * `Config::bind()` can resolve file-local values without mutating process
    * environment.
    */
   protected function include (string $file): null|Config
   {
      if (file_exists($file) === false) {
         return null;
      }

      if ($this->guard($file)) {
         return null;
      }

      $previous = Config::swap($this->variables);
      try {
         // @ Execute trusted PHP config code. See docs/TRUST_BOUNDARY.md.
         $Config = require $file;
      }
      finally {
         Config::swap($previous);
      }

      if ($Config instanceof Config === false) {
         return null;
      }

      // @ Navigate to root
      while ($Config->parent !== null) {
         $Config = $Config->parent;
      }

      return $Config;
   }
}
