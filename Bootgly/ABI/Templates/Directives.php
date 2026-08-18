<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Templates;


use function array_keys;
use function filemtime;
use function implode;
use function is_array;
use function is_string;
use function sha1;
use Closure;

use const Bootgly\ABI\BOOTSTRAP_FILENAME;
use Bootgly\ABI\Code\__String\Path;
use Bootgly\ABI\Resources;


class Directives
{
   use Resources;


   // * Config
   // ...

   // * Data
   /** @var array<string,Closure> */
   protected array $directives;

   // * Metadata
   /** @var array<string> */
   protected array $names;
   // @ Regex
   protected string $tokens;
   // @ Cache
   /**
    * Identity of this directive set — every registered pattern, in order, plus the
    * mtime of each file that defines what those patterns emit. Compiled template
    * caches are keyed with it, so a cache produced by a different compiler is never
    * reused.
    */
   protected string $fingerprint;


   public function __construct ()
   {
      $resource = __DIR__ . '/Template/directives/';
      $bootstrap = require($resource . BOOTSTRAP_FILENAME);

      // ! Only these files decide what a directive emits, and only a stat of each
      //   one can see an edit — a pattern list alone would miss a rewritten callback
      $stamps = [(string) filemtime($resource . BOOTSTRAP_FILENAME)];

      $directives = $bootstrap['directives'];
      foreach ($directives as $name => $value) {
         // @ Register directive name
         if (is_string($name) === true) {
            $this->names[] = $name;
         }

         // @ Set directive value
         $directive = [];
         if (is_string($value) === true) {
            $filename = Path::normalize($value);
            $file = $resource . $filename . '.directive.php';

            $stamps[] = (string) filemtime($file);
            $directive = require($file);
         }
         else if (is_array($value) === true) {
            $directive = $value;
         }

         foreach ($directive as $pattern => $Closure) {
            $this->directives[$pattern] = $Closure;
         }
      }

      $this->tokens = implode('|', $this->names);

      // ---

      $patterns = implode("\0", array_keys($this->directives));
      $this->fingerprint = sha1(implode('.', $stamps) . "\0" . $patterns);
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         // * Data
         case 'directives':
            return $this->directives;

         // * Metadata
         case 'names':
            return $this->names;
         // @ Regex
         case 'tokens':
            return $this->tokens;
         // @ Cache
         case 'fingerprint':
            return $this->fingerprint;

         default:
            return null;
      }
   }

   public function extend (string $pattern, Closure $Callback, null|string $name = null): void
   {
      if ($name) {
         $this->names[] = $name;
      }

      // ? First writer wins — a registered pattern is never replaced
      if (isSet($this->directives[$pattern]) === true) {
         return;
      }

      // @
      $this->directives[$pattern] = $Callback;

      // : The compiler changed, so every cache compiled without this directive is
      //   stale. Folded in registration order, because that order decides the output.
      $this->fingerprint = sha1("{$this->fingerprint}\0{$pattern}");
   }
}
