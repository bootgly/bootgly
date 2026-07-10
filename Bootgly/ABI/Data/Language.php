<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data;


use function array_keys;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function preg_match;
use function rtrim;
use function scandir;
use function strtr;
use Fiber;
use Throwable;
use WeakMap;

use Bootgly\ABI\Data\Language\Locales;


/**
 * i18n minimal contract: `translate()` + message catalogs + locale
 * negotiation.
 *
 * Keys are natural-source strings — the message in the `$source` language
 * is the key, and any miss returns it verbatim, so English works with zero
 * catalog files. A catalog file is `{root}/{locale}/{domain}.php` returning
 * `array<string,string>` (source message → translation).
 */
class Language
{
   // * Config
   /**
    * The language the translatable source strings (keys) are written in.
    * Lookups targeting it short-circuit the catalogs.
    */
   public static string $source = 'en';
   /**
    * The active locale. Plain public static — per-request code assigns it
    * directly (the Web platform re-negotiates it on every request).
    */
   public static string $locale = 'en';

   // * Data
   /**
    * Registered catalog roots. Managed via `load()`; roots registered
    * later take priority. Read directly as the "i18n enabled" probe.
    *
    * @var array<int,string>
    */
   public static array $roots = [];

   // * Metadata
   /**
    * Lazily required catalogs, keyed `"{root}\0{tag}\0{domain}"` — a
    * missing file caches as `[]`, so each (root, tag, domain) touches the
    * filesystem once per worker.
    *
    * @var array<string,array<string,string>>
    */
   private static array $catalogs = [];
   /**
    * Available locales — the roots' locale directory names (normalized),
    * cached until the next `load()`.
    *
    * @var null|array<int,string>
    */
   private static null|array $locales = null;
   /**
    * Locale directory names keyed `"{root}\0{normalizedTag}"` — lets a
    * non-canonical directory (`pt_BR/`, `PT-br/`) load for its normalized
    * tag.
    *
    * @var array<string,string>
    */
   private static array $directories = [];
   /**
    * Per-Fiber locale contexts — deferred work translates under the locale
    * of the request that scheduled it, immune to interleaved requests
    * reassigning `Language::$locale` while the Fiber is suspended.
    *
    * @var null|WeakMap<object,string> Keys are the bound Fibers.
    */
   private static null|WeakMap $Contexts = null;


   /**
    * Register a catalogs root directory (`{root}/{locale}/{domain}.php`).
    * Roots registered later take priority over earlier ones.
    */
   public static function load (string $root): void
   {
      // ?
      $root = rtrim($root, '/');
      if ($root === '' || in_array($root, self::$roots, true) === true) {
         return;
      }

      // @
      self::$roots[] = $root;
      self::$locales = null;
   }

   /**
    * Bind a locale to the current Fiber. Deferred/asynchronous work calls
    * this when a job starts so its translations resolve under the locale
    * of the request that scheduled it. No-op outside a Fiber.
    */
   public static function bind (string $locale): void
   {
      // ?
      $Fiber = Fiber::getCurrent();
      if ($Fiber === null) {
         return;
      }

      // @
      self::$Contexts ??= new WeakMap;
      self::$Contexts[$Fiber] = $locale;
   }

   /**
    * Drop the current Fiber's locale binding (job finished or the Fiber
    * returned to its pool). No-op outside a Fiber or without a binding.
    */
   public static function unbind (): void
   {
      // ?
      $Fiber = Fiber::getCurrent();
      if ($Fiber === null || self::$Contexts === null) {
         return;
      }

      // @
      unset(self::$Contexts[$Fiber]);
   }

   /**
    * Resolve the effective locale: the current Fiber's binding when one
    * exists, else `Language::$locale`.
    */
   public static function resolve (): string
   {
      // ?:
      $Fiber = Fiber::getCurrent();
      if ($Fiber !== null && self::$Contexts !== null && isSet(self::$Contexts[$Fiber]) === true) {
         return self::$Contexts[$Fiber];
      }

      // :
      return self::$locale;
   }

   /**
    * Translate a source message to the active (or given) locale.
    *
    * Never throws; on any miss (no catalogs, unknown locale, malformed
    * domain) the message itself is the result. No output escaping is
    * performed here — escaping belongs to output boundaries.
    *
    * @param string $message Natural-source key — the message in the `$source` language.
    * @param array<string,int|float|string|\Stringable> $substitutions `{token}` replacements applied after lookup.
    * @param null|int|float $count Two-form plural fallback (not CLDR): `count == 1` picks the first `|`-separated form, anything else the last; auto-fills `{count}`. Locale-aware plural rules are a future catalog-value evolution — the signature stays.
    * @param string $domain Catalog file name — `app` (default), `validation`, `errors` or project-defined.
    * @param null|string $locale Per-call locale override (does not mutate `Language::$locale`).
    */
   public static function translate (
      string $message,
      array $substitutions = [],
      null|int|float $count = null,
      string $domain = 'app',
      null|string $locale = null
   ): string
   {
      // !
      $line = $message;

      // @ Lookup — only with registered catalogs and a non-source target
      if (self::$roots !== [] && $message !== '') {
         $target = Locales::normalize($locale ?? self::resolve());

         if (
            $target !== ''
            && $target !== Locales::normalize(self::$source)
            && preg_match('/\A[a-zA-Z0-9_-]+\z/', $domain) === 1
         ) {
            // @@ Fallback chain (`pt-BR` → `pt`), most recent root first
            foreach (Locales::chain($target) as $tag) {
               for ($index = count(self::$roots) - 1; $index >= 0; $index--) {
                  $catalog = self::catalog(self::$roots[$index], $tag, $domain);

                  if (isSet($catalog[$message]) === true) {
                     $line = $catalog[$message];
                     break 2;
                  }
               }
            }
         }
      }

      // @ Plural — pick a `|`-separated form when a count is given
      if ($count !== null) {
         $forms = explode('|', $line);
         $line = $count == 1 ? $forms[0] : $forms[count($forms) - 1];

         $substitutions['count'] ??= $count;
      }

      // @ Substitutions — single `strtr()` pass, values cast to string
      if ($substitutions !== []) {
         $replacements = [];
         foreach ($substitutions as $token => $value) {
            // ? A throwing Stringable leaves its token untouched — the
            //   never-throws promise covers user-supplied casts too
            //   (PHPStan cannot model a throwing __toString behind a cast)
            try {
               $replacements["{{$token}}"] = (string) $value;
            }
            catch (Throwable) { // @phpstan-ignore catch.neverThrown
               continue;
            }
         }

         $line = strtr($line, $replacements);
      }

      // :
      return $line;
   }

   /**
    * Choose the best available locale for the ordered preferences.
    *
    * Available = the locale directories of the registered roots. Pure —
    * returns the choice (falling back to `$source`), never mutates state:
    * assignment stays explicit at the call site.
    *
    * @param array<int,string> $preferred Locale tags, most preferred first (e.g. `$Request->languages`).
    */
   public static function negotiate (array $preferred): string
   {
      // ! The source language is always servable (natural-source keys) —
      //   it participates as the first implicit offer, so `['en', 'pt-BR']`
      //   picks `en` and `*` resolves to the source
      $offers = [self::$source, ...self::locales()];

      // :
      return Locales::choose($preferred, $offers) ?? self::$source;
   }

   /**
    * Restore the boot state: drop registered roots and lazy caches;
    * `$source` / `$locale` back to `en`. Test-lifecycle helper — per-request
    * code only reassigns `Language::$locale`.
    */
   public static function reset (): void
   {
      self::$source = 'en';
      self::$locale = 'en';
      self::$roots = [];
      self::$catalogs = [];
      self::$locales = null;
      self::$directories = [];
      self::$Contexts = null;
   }

   /**
    * Lazily require one catalog file, caching misses as `[]`.
    * `$tag` comes normalized from `Locales::chain` and `$domain` is
    * pattern-guarded by `translate()` — both are path-safe by construction.
    *
    * Catalogs are user-supplied data: a file that fails to parse, throws or
    * returns a non-array behaves like a miss, and non-string values are
    * dropped — `translate()` keeps its never-throws promise.
    *
    * @return array<string,string>
    */
   private static function catalog (string $root, string $tag, string $domain): array
   {
      // ?:
      $key = "{$root}\0{$tag}\0{$domain}";
      if (isSet(self::$catalogs[$key]) === true) {
         return self::$catalogs[$key];
      }

      // @
      // ! Resolve non-canonical directory names (`pt_BR/` serves `pt-BR`);
      //   the map is built by the lazy locales() scan — force it once
      self::$locales === null && self::locales();
      $directory = self::$directories["{$root}\0{$tag}"] ?? $tag;

      $file = "{$root}/{$directory}/{$domain}.php";
      try {
         $entries = is_file($file) === true ? require $file : [];
      }
      catch (Throwable) {
         $entries = [];
      }
      $entries = is_array($entries) === true ? $entries : [];

      // ! Keep only string translations (int keys are coerced by PHP itself)
      foreach ($entries as $index => $value) {
         if (is_string($value) === false) {
            unset($entries[$index]);
         }
      }

      // :
      /** @var array<string,string> $entries */
      return self::$catalogs[$key] = $entries;
   }

   /**
    * Scan the registered roots for locale directories, cached until the
    * next `load()`. Returns normalized tags (the negotiable locales) and
    * maps each one to its real directory name per root, so non-canonical
    * names (`pt_BR/`) still load.
    *
    * @return array<int,string>
    */
   private static function locales (): array
   {
      // ?:
      if (self::$locales !== null) {
         return self::$locales;
      }

      // !
      $found = [];

      // @@
      foreach (self::$roots as $root) {
         $entries = is_dir($root) === true ? scandir($root) : false;
         if ($entries === false) {
            continue;
         }

         foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            if (is_dir("{$root}/{$entry}") === false) {
               continue;
            }

            $normalized = Locales::normalize($entry);
            if ($normalized === '') {
               continue;
            }

            // # Normalized tags negotiate; the real directory name loads
            //   (first directory wins per root — scandir is alphabetical)
            $found[$normalized] = true;
            self::$directories["{$root}\0{$normalized}"] ??= $entry;
         }
      }

      // :
      return self::$locales = array_keys($found);
   }
}
