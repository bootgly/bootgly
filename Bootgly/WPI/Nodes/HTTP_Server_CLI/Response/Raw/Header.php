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
use function explode;
use function gmdate;
use function implode;
use function preg_match;
use function str_replace;
use function strcasecmp;
use function strlen;
use function strncasecmp;
use function strtolower;
use function time;
use function trim;

use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header as HeaderBase;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header\Cookies;


class Header extends HeaderBase
{
   private const int CONTENT_LENGTH = 1;
   private const int TRANSFER_ENCODING = 2;

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
            // ! Enforce the same invariant for trusted subclasses and internal
            //   property writes. Public preset() validates before changing any
            //   framing/memo metadata; this hook is the final storage boundary.
            //   PHP converts an all-decimal string key to int; casting it back
            //   preserves such RFC-valid field names at this boundary.
            $normalizedName = (string) $key;
            if (! self::validate($normalizedName)) {
               return;
            }

            if ($presetValue === true) {
               $normalized[$normalizedName] = true;
               continue;
            }

            $normalizedValue = (string) $presetValue;
            if (! self::check($normalizedValue)) {
               return;
            }

            $normalized[$normalizedName] = $normalizedValue;
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
   // # Sticky per-response flag: queue() ran during this response. Unlike
   //   `$queued !== []`, it survives remove() emptying the queue — clean()
   //   uses it to reset the Cookies accumulator without paying a method
   //   call on responses that never queued a line.
   protected bool $enqueued;
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
   // # Framing-source bitmasks. The current mask makes encoder ownership a
   //   zero-scan fast return for ordinary responses; prepared/preset masks
   //   restore the correct state across memoized prepare() and clean().
   private int $framing = 0;
   private int $preparedFraming = 0;
   private int $presetFraming = 0;
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
      $this->framing = 0;
      $this->preparedFraming = 0;
      $this->presetFraming = 0;

      // * Metadata
      $this->sent = false;
      // Fields
      $this->queued = [];
      $this->enqueued = false;
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
         case 'masked':
            return $this->masked;
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
         // ! Without this, build()'s dirty-gated same-second fast return
         //   reuses a $raw block still carrying the previous response's
         //   queued lines (e.g. another client's Set-Cookie). The content
         //   cache below it compares $queued against $builtQueued, so a
         //   genuinely identical header set stays as cheap as before.
         $this->dirty = true;
      }
      // ? Gated on the sticky flag, not the live queue — remove('Set-Cookie')
      //   after append() empties $queued, but the Cookies accumulator still
      //   holds the appended cookie and would grow for the worker lifetime
      if ($this->enqueued) {
         $this->enqueued = false;
         $this->Cookies->reset();
      }

      // ? Per-request sources were cleared above. Only worker-persistent
      //   preset framing can require canonicalization on the next response.
      $this->framing = $this->presetFraming;
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
   /**
    * Check a response field value against RFC 9110 field-content bytes.
    *
    * All C0 controls except HTAB, plus DEL, are forbidden. HTAB remains valid
    * whitespace and bytes 0x80-0xFF remain compatible `obs-text`; the regex
    * deliberately has no UTF-8 mode so arbitrary permitted octets are checked
    * byte-for-byte.
    */
   private static function check (string $value): bool
   {
      return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 0;
   }

   /**
    * Classify encoder-owned framing names without allocating a lowercase copy
    * for the overwhelmingly common non-framing response header.
    */
   private static function classify (string $field): int
   {
      return match (strlen($field)) {
         14 => strcasecmp($field, 'Content-Length') === 0 ? self::CONTENT_LENGTH : 0,
         17 => strcasecmp($field, 'Transfer-Encoding') === 0 ? self::TRANSFER_ENCODING : 0,
         default => 0,
      };
   }

   public function preset (string $name, string|null $value = null): void
   {
      // ! Presets survive Response::reset()/Header::clean(), so accepting one
      //   injected line poisons every later response served by this worker.
      //   Reject atomically: never normalize attacker bytes into a different
      //   persistent field. Null remains an exact removal operation so legacy
      //   invalid entries can still be deleted during a rolling migration.
      if ($value !== null && (! self::validate($name) || ! self::check($value))) {
         return;
      }

      $preset = $this->preset;

      if ($value !== null) {
         $preset[$name] = $value;
      }
      else {
         unset($preset[$name]);
      }

      // ? No-op fast path — an identical map keeps the raw memo valid
      if ($preset === $this->preset) {
         return;
      }

      $this->preset = $preset;
      $this->presetFraming = 0;
      foreach ($preset as $presetName => $presetValue) {
         $this->presetFraming |= self::classify($presetName);
      }
      $this->framing |= $this->presetFraming;
      // ! build()'s first same-second fast return is gated on dirty alone —
      //   without this, a preset add/replace/removal serves the previous
      //   response's raw block for the rest of the second (and a removed
      //   cookie preset would pass stash()'s current-state scan while the
      //   stale wire still carries the cookie).
      $this->dirty = true;
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
         $this->framing |= $this->preparedFraming;

         return;
      }

      // ! Validate names against RFC 9110 token syntax and strip CRLF from
      //   values before they reach build() — prepare() is a bulk entry
      //   point that previously emitted attacker-controlled bytes verbatim.
      $sanitized = [];
      $framing = 0;

      foreach ($fields as $name => $value) {
         $name = str_replace(["\r", "\n"], '', (string) $name);

         if (! self::validate($name)) {
            continue;
         }

         $sanitized[$name] = str_replace(["\r", "\n"], '', (string) $value);
         $framing |= self::classify($name);
      }

      // : Memoize this raw input → sanitized output for the next identical call.
      $this->preparedRaw = $fields;
      $this->preparedSanitized = $sanitized;
      $this->preparedFraming = $framing;
      $this->framing |= $framing;

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
      $this->framing |= self::classify($field);

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
   /**
    * Give the encoder exclusive ownership of one response field.
    *
    * Every case variant is removed from prepared, queued and preset sources.
    * When a canonical value is provided, exactly one canonical field remains
    * in the mutable field map. An existing canonical entry is updated in place
    * so framework-generated file/range headers keep their stable wire order.
    *
    * @internal Response encoders are the intended caller.
    */
   public function own (string $field, null|string $value = null): bool
   {
      $framing = self::classify($field);
      if ($value === null && $framing !== 0 && ($this->framing & $framing) === 0) {
         return true;
      }

      $field = str_replace(["\r", "\n"], '', $field);
      if (! self::validate($field)) {
         return false;
      }

      if ($value !== null) {
         $value = str_replace(["\r", "\n"], '', $value);
      }

      $changed = false;
      $retained = false;
      $lower = strtolower($field);

      // ? Preserve the canonical fields-map slot when possible. This keeps
      //   legitimate framework-owned file/range header ordering stable while
      //   still deleting every application-controlled case variant.
      foreach ($this->fields as $name => $current) {
         if (strtolower($name) !== $lower) {
            continue;
         }

         if ($value !== null && $name === $field && $retained === false) {
            $retained = true;
            if ($current !== $value) {
               $this->fields[$name] = $value;
               $changed = true;
            }
            continue;
         }

         unset($this->fields[$name]);
         $changed = true;
      }

      // ! Prepared and queued sources are always application-controlled at
      //   encode time. No variant may survive beside canonical framing.
      foreach ($this->prepared as $name => $current) {
         if (strtolower($name) === $lower) {
            unset($this->prepared[$name]);
            $changed = true;
         }
      }

      $prefix = "$lower:";
      $length = strlen($prefix);
      foreach ($this->queued as $index => $line) {
         if (strncasecmp($line, $prefix, $length) === 0) {
            unset($this->queued[$index]);
            $changed = true;
         }
      }

      // ? Presets are worker-persistent configuration. Mask matching fields
      //   for this response; clean() lifts the mask on the next request.
      foreach ($this->preset as $name => $current) {
         if (strtolower($name) === $lower && isSet($this->masked[$lower]) === false) {
            $this->masked[$lower] = true;
            $changed = true;
         }
      }

      if ($value !== null && $retained === false) {
         $this->fields[$field] = $value;
         $changed = true;
      }

      if ($changed) {
         $this->dirty = true;
      }

      // ? A removal fully canonicalized this field for the current response.
      //   A retained value stays marked: a public caller may invoke own()
      //   before encode(), and the encoder must still independently verify it.
      if ($framing !== 0) {
         if ($value === null) {
            $this->framing &= ~$framing;
         }
         else {
            $this->framing |= $framing;
         }
      }

      return true;
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
      $this->framing |= self::classify($field);

      $separator ??= ', ';

      if ( isSet($this->fields[$field]) ) {
         $this->fields[$field] .= $separator . $value;
      } else {
         $this->fields[$field] = $value;
      }

      $this->dirty = true;
   }
   /**
    * Declare a request field name in the `Vary` response header.
    *
    * Token-aware (RFC 9110 §12.5.5): the current value is treated as a
    * comma-delimited, case-insensitive field-name list — a superstring
    * token (`X-Accept-Language-Experiment`) does not satisfy
    * `Accept-Language`, an already-listed token (any case) is never
    * duplicated and a `*` wildcard already covers every request field.
    * The canonical entry point for every Vary writer.
    */
   public function vary (string $field): void
   {
      // ! Strip CRLF + validate against RFC 9110 token syntax (response-
      //   splitting guard, same policy as set()/append())
      $field = str_replace(["\r", "\n"], '', $field);

      if (! self::validate($field)) {
         return;
      }

      // ? Locate the current Vary field under any case variant — writing a
      //   second case variant would serialize two Vary lines
      $key = null;
      foreach ($this->fields as $name => $value) {
         if (strcasecmp((string) $name, 'Vary') === 0) {
            $key = $name;
            break;
         }
      }

      // ? No Vary yet — start the list
      if ($key === null) {
         $this->fields['Vary'] = $field;
         $this->dirty = true;

         return;
      }

      $current = (string) $this->fields[$key];

      // ? Empty value — replace instead of leading with a separator
      if (trim($current) === '') {
         $this->fields[$key] = $field;
         $this->dirty = true;

         return;
      }

      // @@ Token scan — `*` covers all request fields; an existing token
      //    (case-insensitive) is kept as-is
      foreach (explode(',', $current) as $token) {
         $token = trim($token);

         if ($token === '*' || strcasecmp($token, $field) === 0) {
            return;
         }
      }

      // :
      $this->fields[$key] = "{$current}, {$field}";
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
      $this->framing |= self::classify($field);

      $this->queued[] = "$field: $value";
      $this->enqueued = true;
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
