<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw;


use function explode;
use function implode;
use function is_array;
use function is_string;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header\Cookies;


class Header
{
   // ! `final`: `Request::assume()` resets this via `unset()` on every reused
   //   request — final guarantees no subclass hook can observe that unset.
   protected final Cookies $Cookies;

   // * Config
   // ... inherited

   // * Data
   /**
    * Plain protected property (was a public get-hook): internal readers on
    * the cache-hit path (`assume()`, `get()`, …) access the backing store
    * directly — no hook frame per request (the hook cost ~2.2% of worker
    * CPU). External reads (`$Header->fields`) fall back to `__get`, which
    * keeps the lazy `build()` semantics.
    *
    * @var array<string, string|array<int,string>>
    */
   protected array $fields;
   // ! `protected(set)` (not readonly): the per-connection Request reuse
   //   (`Request::assume()`) re-populates these on every served request —
   //   readonly would pin the first request's head forever. External
   //   immutability is preserved by the asymmetric visibility.
   public protected(set) string $raw;

   // * Metadata
   public protected(set) null|int|false $length;
   protected bool $built;


   public function __construct ()
   {
      // * Config
      // ... inherited

      // * Data
      // fields
      $this->fields = [];

      // * Metadata
      // built
      $this->built = false;
      #$this->length = strlen($raw);
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         case 'Cookies':
            return $this->Cookies ??= new Cookies($this);

         // * Config
         // ..

         // * Data
         // public $raw (readonly)
         case 'fields':
            if ($this->built === false) {
               $this->build();
            }

            return $this->fields;

         // * Metadata
         // public length (readonly)
      }

      return null;
   }

   public function define (string $raw): void
   {
      // * Data
      $this->raw = $raw;
      // * Metadata
      $this->length = strlen($raw);
   }

   /**
    * Assume the decoded head of a cached template Header.
    *
    * Used by `Request::assume()` on the per-connection cache-hit path:
    * overwrites every decode-derived member of this (reused) instance with the
    * template's, so no state from the previous request on this connection can
    * survive. Array/string assignments are COW — the template stays untouched
    * by later mutations on this instance.
    */
   public function assume (self $Template): void
   {
      // * Data
      // ? Decoder_ templates are always adopted at parse time (built=true);
      //   this guard only fires for hand-made templates (tests, future uses).
      if ($Template->built === false) {
         $Template->build();
      }
      $this->fields = $Template->fields;
      $this->raw = $Template->raw;

      // * Metadata
      $this->length = $Template->length;
      $this->built = true;
      // Cookies parse lazily from `fields`; drop the previous request's
      // instance so the next access re-binds to the assumed fields.
      unset($this->Cookies);
   }

   /**
    * Adopt a pre-parsed `$fields` map (already lowercased per RFC 9110 §5.1)
    * produced by `Request\Frame::parse()`. Skips the lazy `build()` reparse —
    * the centralized framing parser is the single source of truth.
    *
    * @param array<string, string|array<int,string>> $fields
    */
   public function adopt (array $fields): void
   {
      $this->fields = $fields;
      $this->built = true;
   }

      /**
    * Get a field from the Request Header
    *
    * @param string $name 
    *
    * @return string|null
    */
   public function get (string $name): ?string
   {
      if ($this->built === false) {
         $this->build();
      }

      // ! Field names were normalized to lowercase at parse time (RFC 9110 §5.1).
      $key = strtolower($name);
      $value = $this->fields[$key] ?? null;

      if ($value === null) {
         return null;
      }

      if (is_array($value)) {
         $normalized = [];
         foreach ($value as $entry) {
            $entryString = (string) $entry;
            if ($entryString === '') {
               continue;
            }

            $normalized[] = $entryString;
         }

         if ($normalized === []) {
            return null;
         }

         $glue = $key === 'cookie' ? '; ' : ', ';

         return implode($glue, $normalized);
      }

      $stringValue = (string) $value;

      return $stringValue === '' ? null : $stringValue;
   }

   /**
    * Append a field to the Request Header
    *
    * @param string $name Field name
    * @param string $value Field value
    *
    * @return bool 
    */
   public function append (string $name, string $value): bool
   {
      if ($this->built === false) {
         $this->build();
      }

      $key = strtolower($name);

      if ( isSet($this->fields[$key]) ) {
         return false;
      }

      $this->fields[$key] = $value;

      return true;
   }

   /**
    * Build fields from the Request Header
    *
    * @return bool 
    */
   public function build (): bool
   {
      $fields = [];

      foreach (explode("\r\n", $this->raw ?? '') as $field) {
         if ($field === '') {
            continue;
         }

         $sepPos = strpos($field, ':');
         if ($sepPos === false) {
            continue;
         }

         $key = substr($field, 0, $sepPos);
         if ($key === '' || strpos($key, ' ') !== false || strpos($key, "\t") !== false) {
            continue;
         }

         $value = trim(substr($field, $sepPos + 1), " \t");

         // @ Normalize field name to lowercase (RFC 9110 §5.1: case-insensitive).
         //   Stored once at parse time so get() / cookie parsing / duplicate
         //   detection are all O(1) lowercase lookups.
         $key = strtolower($key);

         if ( isSet($fields[$key]) ) {
            if ( is_string($fields[$key]) ) {
               $fields[$key] = [
                  $fields[$key]
               ];
            }

            $fields[$key][] = $value;
         }
         else {
            $fields[$key] = $value;
         }
      }

      // * Data
      $this->fields = $fields;
      // * Metadata
      $this->built = true;

      return true;
   }
}
