<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw;


use function array_key_exists;
use function gmdate;
use function implode;
use function preg_match;
use function str_replace;
use function strtolower;
use function time;

use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header as HeaderBase;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header\Cookies;


class Header extends HeaderBase
{
   // * Data
   public string $raw;
   // Fields
   /** @var array<string,string|true> */
   protected array $preset {
      get => $this->preset;
      set {
         $normalized = [];

         foreach ($value as $key => $presetValue) {
            if ($presetValue === true) {
               $normalized[$key] = true;
               continue;
            }

            $normalized[$key] = (string) $presetValue;
         }

         $this->preset = $normalized;
      }
   }
   /** @var array<string,string> */
   protected array $prepared;
   /** @var array<string,string> */
   protected array $fields;

   // * Metadata
   protected bool $sent;
   // Fields
   /** @var array<int,string> */
   protected array $queued;
   protected int $built;
   protected bool $dirty;

   public Cookies $Cookies;


   public function __construct ()
   {
      // * Data
      $this->raw = '';
      // Fields
      $this->preset = [
         'Server' => 'Bootgly',
         'Date' => true
      ];
      $this->prepared = [];
      $this->fields = [];

      // * Metadata
      $this->sent = false;
      // Fields
      $this->queued = [];
      $this->built = 0;
      $this->dirty = true;

      // /
      $this->Cookies = new Cookies($this);
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         // * Config
         // ...

         // * Data
         // Fields
         case 'preset':
            return $this->preset;
         case 'prepared':
            return $this->prepared;
         case 'fields':
            return $this->fields;

         // * Metadata
         case 'sent':
            return $this->sent;
         // Fields
         case 'queued':
            return $this->queued;
         case 'built':
            return $this->built;

         default:
            return $this->get($name);
      }
   }
   public function __set (string $name, mixed $value): void
   {
      switch ($name) {
         // * Config
         // ...

         // * Data
         // Fields
         case 'prepared':
            break;
         // case 'fields':

         // * Metadata
         case 'sent':
            $this->sent = (bool) $value;
            break;
         // Fields
         case 'queued':
         case 'built':
            break;
      }
   }
   public function __isSet (string $name): bool
   {
      return isSet($this->fields[$name]);
   }

   public function reset (): void
   {
      // * Metadata
      // Fields
      $this->built = 0;
   }
   public function clean (): void
   {
      // * Data
      // Fields
      if ($this->fields !== []) {
         $this->fields = [];
         $this->dirty = true;
      }
      if ($this->prepared !== []) {
         $this->prepared = [];
         $this->dirty = true;
      }
      // * Metadata
      // Fields
      if ($this->queued !== []) {
         $this->queued = [];
      }
   }
   /**
    * Validate a response header field name against the RFC 9110 §5.1
    * `token` production: `1*tchar` where `tchar` is one of
    * `!#$%&'*+.^_`|~0-9A-Za-z-`. CRLF is implicitly excluded, closing the
    * response-splitting primitive when application code passes
    * attacker-controlled bytes into a header NAME (custom routing,
    * locale tags, A/B headers, etc.).
    *
    * Reject-over-mutate is intentional: silently truncating an injected
    * name would still give the attacker partial control of the on-wire
    * header line.
    */
   private static function validate (string $field): bool
   {
      if ($field === '') {
         return false;
      }

      // ! ASCII-only token regex; preg_match returns 1 on full match.
      return preg_match("/^[!#\$%&'*+.^_`|~0-9A-Za-z-]+\$/D", $field) === 1;
   }

   public function preset (string $name, string|null $value = null): void
   {
      $preset = $this->preset;

      if ($value !== null) {
         $preset[$name] = $value;
      }
      else {
         unset($preset[$name]);
      }

      $this->preset = $preset;
   }
   /**
    * @param array<string, string> $fields
    */
   public function prepare (array $fields): void // @ Prepare to build
   {
      // ! Validate names against RFC 9110 token syntax and strip CRLF from
      //   values before they reach build() — prepare() is a bulk entry
      //   point that previously emitted attacker-controlled bytes verbatim.
      $sanitized = [];

      foreach ($fields as $name => $value) {
         $name = str_replace(["\r", "\n"], '', (string) $name);

         if (! self::validate($name)) {
            continue;
         }

         $sanitized[$name] = str_replace(["\r", "\n"], '', (string) $value);
      }

      if ($sanitized !== $this->prepared) {
         $this->prepared = $sanitized;
         $this->dirty = true;
      }
   }
   public function translate (string $field, int|float|string ...$values): string
   {
      switch ($field) {
         case 'Content-Range':
            // @ bytes Context
            // !
            $start = $values[0];
            $end = $values[1];
            $size = $values[2];

            if ($end !== '*') {
               $end = (int) $end;
               $size = (int) $size;
   
               if ($end > $size - 1) {
                  $end += 1;
               }
            }

            return "bytes {$start}-{$end}/{$size}";
         default:
            return '';
      }
   }

   public function get (string $name): string
   {
      if (array_key_exists($name, $this->fields)) {
         return (string) $this->fields[$name];
      }

      $lower = strtolower($name);
      if (array_key_exists($lower, $this->fields)) {
         return (string) $this->fields[$lower];
      }

      return '';
   }

   public function set (string $field, string $value): bool
   {
      // ! Strip CRLF from the field name AND validate against RFC 9110 token
      //   syntax to prevent HTTP response splitting via attacker-controlled
      //   header names. Reject invalid names to surface bugs visibly.
      $field = str_replace(["\r", "\n"], '', $field);

      if (! self::validate($field)) {
         return false;
      }

      // ! Strip CRLF from header values to prevent HTTP response splitting
      $value = str_replace(["\r", "\n"], '', $value);

      if (! isSet($this->fields[$field]) || $this->fields[$field] !== $value) {
         $this->fields[$field] = $value;
         $this->dirty = true;
      }

      return true;
   }
   public function remove (string $field): bool
   {
      if ( isSet($this->fields[$field]) ) {
         unset($this->fields[$field]);
         $this->dirty = true;
         return true;
      }

      return false;
   }
   public function append (string $field, string $value = '', ? string $separator = ', '): void
   {
      // ! Strip CRLF from header values to prevent HTTP response splitting
      $field = str_replace(["\r", "\n"], '', $field);
      $value = str_replace(["\r", "\n"], '', $value);

      // ! Reject invalid RFC 9110 tokens silently (signature is void).
      if (! self::validate($field)) {
         return;
      }

      $separator ??= ', ';

      if ( isSet($this->fields[$field]) ) {
         $this->fields[$field] .= $separator . $value;
      } else {
         $this->fields[$field] = $value;
      }

      $this->dirty = true;
   }
   public function queue (string $field, string $value = ''): bool
   {
      // ! Strip CRLF from header values to prevent HTTP response splitting
      $field = str_replace(["\r", "\n"], '', $field);
      $value = str_replace(["\r", "\n"], '', $value);

      if (! self::validate($field)) {
         return false;
      }

      $this->queued[] = "$field: $value";
      $this->dirty = true;

      return true;
   }

   public function build (): true // @ raw
   {
      // ?
      if ($this->dirty === false && time() === $this->built) {
         return true;
      }

      // @
      // @ Build headers
      $queued = $this->queued;

      // ?! Hot path: most responses have no user fields/prepared — skip array merge.
      if ($this->fields === [] && $this->prepared === []) {
         // Preset only
         foreach ($this->preset as $name => $value) {
            $value = ($value === true) ? match ($name) {
               'Date' => gmdate('D, d M Y H:i:s \G\M\T'),
               default => ''
            } : (string) $value;

            $queued[] = "$name: $value";
         }

         // @ Default Content-Type (preset never carries it)
         if (! array_key_exists('Content-Type', $this->preset)) {
            $queued[] = 'Content-Type: text/html; charset=UTF-8';
         }

         $this->raw = implode("\r\n", $queued);

         $this->built = time();
         $this->dirty = false;

         return true;
      }

      $fields = $this->preset + $this->fields + $this->prepared;

      // Fields
      foreach ($fields as $name => $value) {
         // Dynamic fields
         $value = ($value === true) ? match ($name) {
            'Date' => gmdate('D, d M Y H:i:s \G\M\T'),
            default => ''
         } : (string) $value;

         $queued[] = "$name: $value";
      }

      // @ Set default Content-Type if not present
      if (! array_key_exists('Content-Type', $fields)) {
         $queued[] = 'Content-Type: text/html; charset=UTF-8';
      }

      $this->raw = implode("\r\n", $queued);

      $this->built = time();
      $this->dirty = false;

      return true;
   }
}
