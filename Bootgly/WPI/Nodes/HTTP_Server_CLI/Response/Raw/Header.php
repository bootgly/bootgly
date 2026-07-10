<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw;


use function array_key_exists;
use function gmdate;
use function implode;
use function preg_match;
use function str_replace;
use function strlen;
use function strncasecmp;
use function strtolower;
use function time;

use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header as HeaderBase;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header\Cookies;


class Header extends HeaderBase
{
   // * Data
   public string $raw;
   // # Default Content-Type emitted by build() when no explicit Content-Type header is
   //   set (via set()/preset/prepare). A per-response value: reset every request by
   //   clean(), so the persistent worker never leaks one route's media type into the
   //   next response. A plain property (no hook): build() compares it against
   //   `$builtType` in both fast-return guards, so a change is detected without a
   //   `dirty` flag, and the value is CRLF-stripped where it is serialized.
   public string $type = 'text/html; charset=UTF-8';
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
   // # prepare() memo — last raw input and its sanitized result. A response that
   //   re-sends the same constant header(s) (e.g. a fixed Content-Type on a hot
   //   route) reuses the cached result and skips re-sanitizing every request.
   //   Survives clean()/clone; deterministic, so reuse is security-equivalent.
   /** @var array<string,string> */
   private array $preparedRaw = [];
   /** @var array<string,string> */
   private array $preparedSanitized = [];
   // # Per-response preset mask — lowercased names remove()d for THIS response
   //   only. preset is worker-persistent config: it must never be mutated by a
   //   single response; clean() lifts the mask.
   /** @var array<string,true> */
   private array $masked = [];
   // # build() memo — the header inputs (fields/prepared/queued) captured at the last
   //   serialization. When they are byte-identical on a later request within the same
   //   second, build() reuses the cached `$raw` instead of re-serializing — so a route
   //   that returns a stable header set (e.g. a fixed Content-Type) is as cheap as one
   //   that returns none. Cross-second falls through to rebuild the per-second Date.
   private string $builtType = '';
   /** @var array<string,string|bool> */
   private array $builtPreset = [];
   /** @var array<string,string> */
   private array $builtFields = [];
   /** @var array<string,string> */
   private array $builtPrepared = [];
   /** @var array<int,string> */
   private array $builtQueued = [];
   /** @var array<string,true> */
   private array $builtMasked = [];
   // # Date header value, shared by every response and rebuilt once per second
   private static int $stamped = 0;
   private static string $stamp = '';

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
   public function __clone ()
   {
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
      // # Restore the framework default media type so a per-response value set by a
      //   resource (e.g. Plaintext → text/plain) never carries into the next response.
      //   No dirty needed: build() compares $type against $builtType (see build()).
      $this->type = 'text/html; charset=UTF-8';
      // # Lift the per-response preset mask (see remove())
      if ($this->masked !== []) {
         $this->masked = [];
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
      // ? Fast path — identical input to the last prepare(). Sanitizing is
      //   deterministic, so reuse the cached result instead of re-validating and
      //   re-stripping every request. clean() empties $prepared per request, so
      //   re-apply the cached value here.
      if ($fields === $this->preparedRaw) {
         if ($this->prepared !== $this->preparedSanitized) {
            $this->prepared = $this->preparedSanitized;
            $this->dirty = true;
         }

         return;
      }

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

      // : Memoize this raw input → sanitized output for the next identical call.
      $this->preparedRaw = $fields;
      $this->preparedSanitized = $sanitized;

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
      $removed = false;
      $lower = strtolower($field);

      // ! Header identity is case-insensitive (RFC 9110 §5.1): a removal must
      //   cover every case variant in every serialization source, or a stale
      //   field survives on the wire (e.g. a `content-length` next to chunked
      //   framing — a request-smuggling class of bug).
      foreach ($this->fields as $name => $value) {
         if (strtolower($name) === $lower) {
            unset($this->fields[$name]);
            $removed = true;
         }
      }
      // ? prepare()d fields serialize like set() ones — removing a field
      //   must cover both sources (per-request only: the prepare() cache
      //   restores the full sanitized set on the next request)
      foreach ($this->prepared as $name => $value) {
         if (strtolower($name) === $lower) {
            unset($this->prepared[$name]);
            $removed = true;
         }
      }
      // ? queue()d lines serialize verbatim — match on the field-name prefix
      $prefix = "$lower:";
      $length = strlen($prefix);
      foreach ($this->queued as $index => $line) {
         if (strncasecmp($line, $prefix, $length) === 0) {
            unset($this->queued[$index]);
            $removed = true;
         }
      }
      // ? preset is worker-persistent config — mask it for this response
      //   instead of mutating it (clean() lifts the mask)
      foreach ($this->preset as $name => $value) {
         if (strtolower($name) === $lower) {
            $this->masked[$lower] = true;
            $removed = true;
         }
      }

      if ($removed) {
         $this->dirty = true;
      }

      return $removed;
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

   /**
    * Format the RFC 9110 `Date` header value, cached per second.
    *
    * Dirty responses rebuild their header block on every request, so the
    * shared formatted string saves one gmdate() call per response. Public:
    * it is the canonical per-second Date source — the route cache patches
    * stored wire bytes with it.
    */
   public static function stamp (): string
   {
      $now = time();
      // ?
      if ($now !== self::$stamped) {
         self::$stamped = $now;
         self::$stamp = gmdate('D, d M Y H:i:s \G\M\T');
      }

      // :
      return self::$stamp;
   }

   public function build (): true // @ raw
   {
      // ? Fast return — nothing the block depends on changed since the last build this
      //   second. `dirty` covers fields/prepared/queued mutations; `type` is a plain
      //   property (no dirty flag), so it is compared directly here and in the cache.
      if (
         $this->dirty === false
         && time() === $this->built
         && $this->type === $this->builtType
      ) {
         return true;
      }

      // ? Content-cache: even when `dirty` was set (clean()/prepare() churn the same
      //   constant headers every request), the previously built `$raw` is still exact
      //   when the header inputs are byte-identical and we are in the same second.
      if (
         time() === $this->built
         && $this->type === $this->builtType
         && $this->prepared === $this->builtPrepared
         && $this->fields === $this->builtFields
         && $this->queued === $this->builtQueued
         && $this->preset === $this->builtPreset
         && $this->masked === $this->builtMasked
      ) {
         $this->dirty = false;

         return true;
      }

      // @
      // @ Build headers
      $queued = $this->queued;

      // ! Capture every input this build serializes (preset + fields + prepared +
      //   queued; Date is gated by the same-second check). The next request reuses
      //   `$raw` via the content-cache above only when ALL of them are byte-identical —
      //   so a different header set, cookie, or preset on a later request never leaks
      //   the cached block (no cross-request contamination on the persistent worker).
      $this->builtType = $this->type;
      $this->builtPreset = $this->preset;
      $this->builtFields = $this->fields;
      $this->builtPrepared = $this->prepared;
      $this->builtQueued = $this->queued;
      $this->builtMasked = $this->masked;

      // ? Apply the per-response preset mask (see remove()) — a copy-on-write
      //   local: the persistent preset itself is never mutated
      $preset = $this->preset;
      if ($this->masked !== []) {
         foreach ($preset as $name => $value) {
            if ( isSet($this->masked[strtolower($name)]) ) {
               unset($preset[$name]);
            }
         }
      }

      // ! Strip CRLF from the default media type at the single point it is serialized
      //   (response-splitting guard). Done here — on real rebuild only, never on the
      //   cached fast returns above — so a plain `$type` write stays allocation-free.
      $type = str_replace(["\r", "\n"], '', $this->type);

      // ?! Hot path: most responses have no user fields/prepared — skip array merge.
      if ($this->fields === [] && $this->prepared === []) {
         // Preset only
         foreach ($preset as $name => $value) {
            $value = ($value === true) ? match ($name) {
               'Date' => self::stamp(),
               default => ''
            } : (string) $value;

            $queued[] = "$name: $value";
         }

         // @ Default Content-Type (preset never carries it)
         if (! array_key_exists('Content-Type', $preset)) {
            $queued[] = "Content-Type: {$type}";
         }

         $this->raw = implode("\r\n", $queued);

         $this->built = time();
         $this->dirty = false;

         return true;
      }

      $fields = $preset + $this->fields + $this->prepared;

      // Fields
      foreach ($fields as $name => $value) {
         // Dynamic fields
         $value = ($value === true) ? match ($name) {
            'Date' => self::stamp(),
            default => ''
         } : (string) $value;

         $queued[] = "$name: $value";
      }

      // @ Set default Content-Type if not present
      if (! array_key_exists('Content-Type', $fields)) {
         $queued[] = "Content-Type: {$type}";
      }

      $this->raw = implode("\r\n", $queued);

      $this->built = time();
      $this->dirty = false;

      return true;
   }
}
