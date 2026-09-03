<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data;


use function count;
use function explode;
use function implode;
use function min;
use function preg_match;
use function strcmp;
use function strlen;
use Stringable;
use ValueError;


/**
 * Semantic Versioning 2.0.0 value.
 *
 * One parsed version — its three numbers, its pre-release identifiers and its
 * build metadata — ordered exactly as §11 of the specification orders it:
 * the numbers first, a pre-release below its own release, then the
 * identifiers left to right (numeric before alphanumeric, numeric by value,
 * alphanumeric by ASCII, the longer list winning an otherwise equal run),
 * with build metadata never taking part. PHP's `version_compare` is not that
 * ordering (`1.0.0-beta.10` ranks below `1.0.0-beta.9` there) and neither is
 * a sort of tag names.
 *
 * A leading `v` is accepted on input (`v1.0.0-beta.6`, the shape of every
 * Bootgly tag) and never reproduced: the canonical form is the bare version.
 * A number of more than 18 digits — legal by the grammar, unrepresentable as
 * an exact PHP integer — is refused rather than misordered.
 */
final class SemVer implements Stringable
{
   /** The SemVer 2.0.0 grammar, plus the `v` prefix git tags carry. */
   public const string PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
      . '(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)'
      . '(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?'
      . '(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D';
   /** Digits a version number may carry — what a 64-bit integer holds exactly. */
   public const int DIGITS = 18;

   // * Data
   // # Numbers
   public private(set) int $major;
   public private(set) int $minor;
   public private(set) int $patch;
   // # Labels
   /** @var list<string> Pre-release identifiers, dot-split (`['beta', '6']`); empty on a release. */
   public private(set) array $prerelease;
   /** Build metadata after the `+`, empty when absent — never part of precedence. */
   public private(set) string $build;
   // # State
   /** A release: no pre-release identifiers. */
   public bool $stable {
      get => $this->prerelease === [];
   }


   /**
    * Parse one semantic version.
    *
    * @param string $version A SemVer 2.0.0 string, with or without a leading `v`.
    *
    * @throws ValueError When the string is not a semantic version.
    */
   public function __construct (string $version)
   {
      // ?
      if (preg_match(self::PATTERN, $version, $matches) !== 1) {
         throw new ValueError("Invalid semantic version: `{$version}`");
      }
      // ? A number a PHP integer cannot hold exactly would saturate and compare
      //   equal to another — refused rather than misordered
      if (strlen($matches[1]) > self::DIGITS || strlen($matches[2]) > self::DIGITS || strlen($matches[3]) > self::DIGITS) {
         throw new ValueError("Semantic version number beyond " . self::DIGITS . " digits: `{$version}`");
      }

      // * Data
      $this->major = (int) $matches[1];
      $this->minor = (int) $matches[2];
      $this->patch = (int) $matches[3];
      $this->prerelease = ($matches[4] ?? '') === '' ? [] : explode('.', $matches[4]);
      $this->build = $matches[5] ?? '';
   }

   /**
    * Parse one semantic version, or nothing when the string is not one.
    *
    * The form for untrusted input — a tag name, a config value — where "not a
    * version" is an ordinary answer rather than an error.
    *
    * @param string $version A candidate string, with or without a leading `v`.
    *
    * @return null|self
    */
   public static function parse (string $version): null|self
   {
      // ?:
      if (preg_match(self::PATTERN, $version, $matches) !== 1) {
         return null;
      }
      if (strlen($matches[1]) > self::DIGITS || strlen($matches[2]) > self::DIGITS || strlen($matches[3]) > self::DIGITS) {
         return null;
      }

      // :
      return new self($version);
   }

   /**
    * Order this version against another by SemVer §11 precedence.
    *
    * @param self $Other
    *
    * @return int `-1` when this version is lower, `1` when higher, `0` when
    *             both have the same precedence (build metadata may differ).
    */
   public function compare (self $Other): int
   {
      // @ Numbers
      $order = $this->major <=> $Other->major
         ?: $this->minor <=> $Other->minor
         ?: $this->patch <=> $Other->patch;
      if ($order !== 0) {
         return $order;
      }

      // ? A pre-release ranks below its release
      if ($this->prerelease === [] || $Other->prerelease === []) {
         return ($this->prerelease === []) <=> ($Other->prerelease === []);
      }

      // @@ Identifiers left to right
      $count = min(count($this->prerelease), count($Other->prerelease));
      for ($index = 0; $index < $count; $index++) {
         $mine = $this->prerelease[$index];
         $theirs = $Other->prerelease[$index];

         // ! The grammar leaves two shapes: all digits without a leading zero,
         //   or at least one letter or hyphen — so digits-only is "numeric"
         $numeric = preg_match('/^\d+$/', $mine) === 1;
         $numeral = preg_match('/^\d+$/', $theirs) === 1;

         $order = match (true) {
            // ? Numeric against numeric — by value (length first, as no
            //   leading zero is possible, so the value never overflows)
            $numeric && $numeral => strlen($mine) <=> strlen($theirs) ?: strcmp($mine, $theirs) <=> 0,
            // ? Numeric identifiers always rank below alphanumeric ones
            $numeric !== $numeral => $numeric ? -1 : 1,
            // ? Alphanumeric against alphanumeric — ASCII order
            default => strcmp($mine, $theirs) <=> 0,
         };
         if ($order !== 0) {
            return $order;
         }
      }

      // : Every shared identifier equal — the longer list ranks higher
      return count($this->prerelease) <=> count($Other->prerelease);
   }

   /**
    * The canonical form: `major.minor.patch[-prerelease][+build]`, no `v`.
    */
   public function __toString (): string
   {
      $version = "{$this->major}.{$this->minor}.{$this->patch}";

      if ($this->prerelease !== []) {
         $version .= '-' . implode('.', $this->prerelease);
      }
      if ($this->build !== '') {
         $version .= "+{$this->build}";
      }

      // :
      return $version;
   }
}
