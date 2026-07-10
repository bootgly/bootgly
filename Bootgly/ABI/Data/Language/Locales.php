<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\Language;


use function explode;
use function implode;
use function preg_match;
use function str_replace;
use function str_starts_with;
use function strcspn;
use function strlen;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use function ucfirst;


/**
 * Pure locale-tag math: normalization, fallback chains and best-match
 * choice (RFC 4647 lookup scheme, lite). Stateless — the stateful
 * negotiation against loaded catalogs lives in `Language::negotiate`.
 */
class Locales
{
   /**
    * Normalize a locale tag to canonical form.
    *
    * Accepts BCP 47 tags and POSIX environment values:
    * `pt_BR.UTF-8` → `pt-BR`, `PT-br` → `pt-BR`, `en_US@euro` → `en-US`,
    * `C` / `POSIX` / empty / malformed → `''` (no locale).
    * Language subtag lowercase; two-letter subtags UPPERCASE (region);
    * four-letter subtags Titlecase (script); others lowercase.
    */
   public static function normalize (string $locale): string
   {
      // ! Strip POSIX codeset / modifier (`pt_BR.UTF-8@euro` → `pt_BR`)
      $locale = substr($locale, 0, strcspn($locale, '.@'));
      $locale = trim(str_replace('_', '-', $locale));

      // ? Empty or POSIX pseudo-locales carry no language
      if ($locale === '') {
         return '';
      }
      $lowercased = strtolower($locale);
      if ($lowercased === 'c' || $lowercased === 'posix') {
         return '';
      }

      // @ Validate and case-normalize each subtag
      $subtags = explode('-', $lowercased);
      foreach ($subtags as $index => $subtag) {
         // ? Subtags are 1-8 alphanumeric characters; the first is alphabetic
         $pattern = $index === 0 ? '/\A[a-z]{1,8}\z/' : '/\A[a-z0-9]{1,8}\z/';
         if (preg_match($pattern, $subtag) !== 1) {
            return '';
         }

         // # Region (`br` → `BR`) and script (`hant` → `Hant`) casing
         if ($index > 0) {
            $subtags[$index] = match (strlen($subtag)) {
               2 => strtoupper($subtag),
               4 => ucfirst($subtag),
               default => $subtag
            };
         }
      }

      // :
      return implode('-', $subtags);
   }

   /**
    * Expand a normalized locale into its lookup chain by progressive
    * subtag truncation: `pt-BR` → `['pt-BR', 'pt']`; `pt` → `['pt']`.
    *
    * @return array<int,string>
    */
   public static function chain (string $locale): array
   {
      // !
      $chain = [];

      // @@
      while ($locale !== '') {
         $chain[] = $locale;

         $position = strrpos($locale, '-');
         $locale = $position === false ? '' : substr($locale, 0, $position);
      }

      // :
      return $chain;
   }

   /**
    * Choose the first available locale satisfying the ordered preferences.
    *
    * Deterministic passes per preference — client order wins over server
    * order: `*` → first available; exact match; truncation (`pt-BR`
    * matches available `pt`); regional expansion (`pt` matches the first
    * available `pt-*`). Returns the available entry as given.
    *
    * Excluded ranges (client `q=0`) remove the offers they cover before
    * any matching — including the `*` wildcard pass — unless a more
    * specific preferred range re-includes the offer (RFC 9110 §12.5.4:
    * the quality of the most specific matching range applies).
    *
    * @param array<int,string> $preferred Locale tags, most preferred first.
    * @param array<int,string> $available Server/catalog locales.
    * @param array<int,string> $excluded Refused ranges (`q=0`); `*` refuses every offer not explicitly preferred.
    */
   public static function choose (
      array $preferred, array $available, array $excluded = []
   ): null|string
   {
      // ? Nothing offered
      if ($available === []) {
         return null;
      }

      // ! Normalized view of the offers, preserving the original entries
      $offers = [];
      foreach ($available as $offer) {
         $normalized = self::normalize($offer);
         if ($normalized === '') {
            continue;
         }

         $offers[$normalized] ??= $offer;
      }
      if ($offers === []) {
         return null;
      }

      // ? Client exclusions — drop covered offers before matching
      if ($excluded !== []) {
         $offers = self::filter($offers, $preferred, $excluded);

         if ($offers === []) {
            return null;
         }
      }

      // @@
      foreach ($preferred as $tag) {
         // # Full wildcard → the server's first valid offer
         if (trim($tag) === '*') {
            foreach ($offers as $offer) {
               return $offer;
            }
         }

         $tag = self::normalize($tag);
         if ($tag === '') {
            continue;
         }

         // # Exact match, then truncation (`pt-BR` → `pt`)
         foreach (self::chain($tag) as $parent) {
            if (isSet($offers[$parent])) {
               return $offers[$parent];
            }
         }

         // # Regional expansion (`pt` → first available `pt-*`)
         $prefix = "{$tag}-";
         foreach ($offers as $normalized => $offer) {
            if (str_starts_with($normalized, $prefix)) {
               return $offer;
            }
         }
      }

      // :
      return null;
   }

   /**
    * Filter offers against refused ranges (`q=0`), keeping an offer when
    * a strictly more specific preferred range re-includes it — RFC 9110
    * §12.5.4: the quality of the most specific matching range applies
    * (`pt;q=0, pt-BR;q=0.5` refuses `pt` but keeps `pt-BR`).
    *
    * A range covers an offer when it equals the offer or is a subtag
    * prefix of it (`pt` covers `pt-BR`); `*` covers every offer at the
    * lowest specificity. Specificity = range length.
    *
    * @param array<string,string> $offers Normalized tag → original offer.
    * @param array<int,string> $preferred Accepted ranges (`q>0`).
    * @param array<int,string> $excluded Refused ranges (`q=0`).
    *
    * @return array<string,string> The surviving offers.
    */
   private static function filter (array $offers, array $preferred, array $excluded): array
   {
      // ! Normalized refused ranges with their specificity
      $refusals = [];
      $wildcard = false;
      foreach ($excluded as $range) {
         if (trim($range) === '*') {
            $wildcard = true;
            continue;
         }

         $range = self::normalize($range);
         if ($range !== '') {
            $refusals[$range] = strlen($range);
         }
      }

      // ? Nothing parseable to refuse
      if ($wildcard === false && $refusals === []) {
         return $offers;
      }

      // ! Normalized accepted ranges (a preferred `*` normalizes to `''`
      //   and is skipped — only specific ranges re-include an offer)
      $accepts = [];
      foreach ($preferred as $tag) {
         $tag = self::normalize($tag);
         if ($tag !== '') {
            $accepts[$tag] = strlen($tag);
         }
      }

      // @@ Most specific matching range decides each offer
      foreach ($offers as $normalized => $offer) {
         // # A `*` refusal matches everything at specificity 1
         $refusal = $wildcard === true ? 1 : 0;
         foreach ($refusals as $range => $length) {
            if ($normalized === $range || str_starts_with($normalized, "{$range}-")) {
               $refusal = $length > $refusal ? $length : $refusal;
            }
         }
         if ($refusal === 0) {
            continue;
         }

         $accepted = 0;
         foreach ($accepts as $range => $length) {
            if ($normalized === $range || str_starts_with($normalized, "{$range}-")) {
               $accepted = $length > $accepted ? $length : $accepted;
            }
         }

         if ($refusal > $accepted) {
            unset($offers[$normalized]);
         }
      }

      // :
      return $offers;
   }
}
