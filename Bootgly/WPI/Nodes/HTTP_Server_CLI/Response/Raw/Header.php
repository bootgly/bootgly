<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw;


use function array_key_last;
use function count;
use function explode;
use function gmdate;
use function implode;
use function ltrim;
use function preg_match;
use function preg_replace;
use function str_replace;
use function strcasecmp;
use function strcspn;
use function strlen;
use function strncasecmp;
use function strpos;
use function strtolower;
use function substr;
use function time;
use function trim;

use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header as HeaderBase;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header\Cookies;


class Header extends HeaderBase
{
   private const int CONTENT_LENGTH = 1;
   private const int TRANSFER_ENCODING = 2;
   /**
    * The `check()` octet class as a scan set: every octet forbidden in a field
    * value (all C0 except HTAB, plus DEL).
    */
   private const string VALUE_CTL =
      "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0A\x0B\x0C\x0D\x0E\x0F"
      . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F"
      . "\x7F";

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
   //   Publicly readable so the encoder can skip the own() frames entirely
   //   when no framing header was sourced at all (the ordinary hot case).
   public private(set) int $framing = 0;
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
      // ?: In lockstep with get() — isset() must never contradict it, and
      //    the `'' == absent` convention is load-bearing at the consumers
      return $this->get($name) !== '';
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

         $value = str_replace(["\r", "\n"], '', (string) $value);

         // ? Same forbidden-octet gate every other insertion path applies:
         //   stripping CR/LF alone still admitted NUL, vertical tab and the
         //   rest of the C0 range through this bulk entry point.
         if (! self::check($value)) {
            continue;
         }

         $sanitized[$name] = $value;
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
      // ! get() reports the wire: the fallthrough below walks the same
      //   sources build() serializes, in build()'s exact precedence —
      //   queued lines, preset minus the per-response mask, fields, then
      //   prepared — matching case-insensitively like remove()/own() do.
      //   The read side being blind to prepare()d fields is what re-marked
      //   a redirecting auth Fallback to 401 with `Location` on the wire.
      //   Deliberately NOT reported: the default Content-Type fallback —
      //   it is a build()-time serialization of $type, not a written
      //   field, and cache adoption relies on '' meaning "never written".
      $lower = strtolower($name);

      // ? Vary is a comma-list field whose colliding declarations build()
      //   JOINS (RFC 9110 §5.2) — the read must report that same union, not
      //   the first source that happens to hold one. A single declaration
      //   reads verbatim, exactly as it serializes.
      if ($lower === 'vary') {
         $values = [];
         foreach ($this->queued as $line) {
            if (strncasecmp($line, 'vary:', 5) === 0) {
               $values[] = ltrim(substr($line, 5));
            }
         }
         if (isSet($this->masked['vary']) === false) {
            foreach ($this->preset as $presetName => $presetValue) {
               if (strcasecmp((string) $presetName, 'Vary') === 0) {
                  $values[] = $presetValue === true ? '' : (string) $presetValue;
               }
            }
         }
         foreach ($this->fields as $fieldName => $fieldValue) {
            if (strcasecmp($fieldName, 'Vary') === 0) {
               $values[] = (string) $fieldValue;
            }
         }
         foreach ($this->prepared as $preparedName => $preparedValue) {
            if (strcasecmp($preparedName, 'Vary') === 0) {
               $values[] = $preparedValue;
            }
         }

         $count = count($values);
         if ($count < 2) {
            return $values[0] ?? '';
         }

         $value = $values[0];
         for ($index = 1; $index < $count; $index++) {
            $value = self::merge($value, $values[$index]);
         }

         // :
         return $value;
      }

      // ? Queued lines serialize first and own their field identity
      //   (first match wins — Set-Cookie is the one repeatable field)
      $prefix = "$lower:";
      $length = strlen($prefix);
      foreach ($this->queued as $line) {
         if (strncasecmp($line, $prefix, $length) === 0) {
            return ltrim(substr($line, $length));
         }
      }
      // ? Worker presets serialize next, minus the per-response mask;
      //   a `true` value resolves exactly as build() serializes it
      if (isSet($this->masked[$lower]) === false) {
         foreach ($this->preset as $presetName => $value) {
            if (strcasecmp($presetName, $name) === 0) {
               return $value === true
                  ? ($presetName === 'Date' ? self::stamp() : '')
                  : (string) $value;
            }
         }
      }
      // ? set() fields outrank prepare()d ones, as in build()'s union
      foreach ($this->fields as $fieldName => $value) {
         if (strcasecmp($fieldName, $name) === 0) {
            return (string) $value;
         }
      }
      foreach ($this->prepared as $preparedName => $value) {
         if (strcasecmp($preparedName, $name) === 0) {
            return (string) $value;
         }
      }

      // : Absent from every serialization source
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

      // ? The forbidden-octet check already existed but was reachable only
      //   from preset insertion, so NUL, vertical tab and the rest of the C0
      //   range reached the wire through set() (audit M8).
      if (! self::check($value)) {
         return false;
      }

      // ? Field identity is case-insensitive (RFC 9110 §5.1). Storage is keyed
      //   by the supplied casing, so `Content-Type` and `content-type` used to
      //   serialize as two independent lines and leave the recipient to pick.
      //   Replace the existing case variant in place instead (audit M8).
      if (isSet($this->fields[$field]) === false) {
         foreach ($this->fields as $existing => $ignored) {
            if (strcasecmp($existing, $field) === 0) {
               unset($this->fields[$existing]);
               $this->dirty = true;
               break;
            }
         }
      }

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

      // ? own() is encoder-oriented but public, and CR/LF stripping alone let
      //   NUL/VT/DEL through where every other insertion path rejects them.
      if ($value !== null && ! self::check($value)) {
         return false;
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
      // ? Same forbidden-octet gate as set() (audit M8).
      if (! self::check($value)) {
         return;
      }

      $separator ??= ', ';
      // ? The separator is caller-controlled and lands in the serialized value
      //   exactly like the value does, so it needs the SAME gate: a `\r\n` in
      //   it splits the field into a second response header the application
      //   never wrote. Checked before any state changes, so a rejected call
      //   leaves no framing/dirty side effect behind.
      if (! self::check($separator)) {
         return;
      }

      $this->framing |= self::classify($field);

      // ? Append onto the existing case variant rather than starting a second
      //   independent line for the same case-insensitive field (audit M8).
      if (isSet($this->fields[$field]) === false) {
         foreach ($this->fields as $existing => $ignored) {
            if (strcasecmp($existing, $field) === 0) {
               $field = $existing;
               break;
            }
         }
      }

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
    * The canonical entry point for every Vary writer — and source-aware: a
    * Vary declared through prepare()/queue()/preset() joins the merge, so
    * one canonical field reaches the wire instead of two colliding
    * declarations build() would have to discard one of.
    */
   public function vary (string $field): void
   {
      // ! Strip CRLF + validate against RFC 9110 token syntax (response-
      //   splitting guard, same policy as set()/append())
      $field = str_replace(["\r", "\n"], '', $field);

      if (! self::validate($field)) {
         return;
      }

      // ! Collect the effective Vary across EVERY serialization source, in
      //   build()'s precedence order — queued lines, preset minus the
      //   per-response mask, fields, prepared. A declaration living outside
      //   $fields used to be invisible here, so vary() started a second
      //   independent list and build()'s case-insensitive collision
      //   handling silently dropped one of the declared cache dimensions.
      $tokens = [];
      $listed = [];
      $wildcard = false;
      $foreign = false;
      $collect = static function (string $value) use (&$tokens, &$listed, &$wildcard): void {
         foreach (explode(',', $value) as $token) {
            $token = trim($token);

            if ($token === '') {
               continue;
            }
            if ($token === '*') {
               $wildcard = true;
               continue;
            }

            $lower = strtolower($token);
            if (isSet($listed[$lower])) {
               continue;
            }

            $listed[$lower] = true;
            $tokens[] = $token;
         }
      };

      foreach ($this->queued as $line) {
         if (strncasecmp($line, 'vary:', 5) === 0) {
            $foreign = true;
            $collect(substr($line, 5));
         }
      }
      if (isSet($this->masked['vary']) === false) {
         foreach ($this->preset as $name => $value) {
            if (strcasecmp((string) $name, 'Vary') === 0) {
               $foreign = true;
               $collect($value === true ? '' : (string) $value);
            }
         }
      }
      $key = null;
      foreach ($this->fields as $name => $value) {
         if (strcasecmp((string) $name, 'Vary') === 0) {
            if ($key === null) {
               $key = $name;
            }
            $collect((string) $value);
         }
      }
      foreach ($this->prepared as $name => $value) {
         if (strcasecmp((string) $name, 'Vary') === 0) {
            $foreign = true;
            $collect($value);
         }
      }

      // ? Fields-only — the common case (the encode-time Accept-Language on
      //   every i18n response lands here): merge in place, exactly as before
      if ($foreign === false) {
         // ? No Vary yet — start the list
         if ($key === null) {
            $this->fields['Vary'] = $field;
            $this->dirty = true;

            return;
         }
         // ? Empty value — replace instead of leading with a separator
         if (trim((string) $this->fields[$key]) === '') {
            $this->fields[$key] = $field;
            $this->dirty = true;

            return;
         }
         // ? `*` covers all request fields; an existing token (any case)
         //   is kept as-is
         if ($wildcard || isSet($listed[strtolower($field)])) {
            return;
         }
         // ? `*` as the argument is the wildcard, not a token — collapse
         //   the list (RFC 9110 §12.5.5: never `*` beside field names)
         if ($field === '*') {
            $this->fields[$key] = '*';
            $this->dirty = true;

            return;
         }

         $this->fields[$key] = "{$this->fields[$key]}, {$field}";
         $this->dirty = true;

         return;
      }

      // @@ A non-fields source declared Vary — consolidate the union into
      //    one canonical field. own() deletes every case variant from
      //    prepared and queued and masks the preset for this response;
      //    classify('Vary') is 0, so no framing state is touched.
      // ? `*` as the argument is the wildcard, not a token (RFC 9110
      //   §12.5.5: never `*` beside field names)
      if ($field === '*') {
         $wildcard = true;
      }
      else if ($wildcard === false && isSet($listed[strtolower($field)]) === false) {
         $tokens[] = $field;
      }

      // :
      $this->own('Vary', $wildcard ? '*' : implode(', ', $tokens));
   }

   public function queue (string $field, string $value = ''): bool
   {
      // ! Strip CRLF from header values to prevent HTTP response splitting
      $field = str_replace(["\r", "\n"], '', $field);
      $value = str_replace(["\r", "\n"], '', $value);

      if (! self::validate($field)) {
         return false;
      }
      // ? Same forbidden-octet gate as set()/append()/preset.
      if (! self::check($value)) {
         return false;
      }
      $this->framing |= self::classify($field);

      // ? Set-Cookie is intentionally repeatable — every other field is a
      //   case-insensitive singleton, so replace the queued variant instead of
      //   emitting a second line the recipient has to choose between. The gap
      //   the unset leaves behind stays: every reader iterates, and the other
      //   removal paths (own(), del()) already leave theirs.
      if (strcasecmp($field, 'Set-Cookie') !== 0) {
         $length = strlen($field) + 1;
         foreach ($this->queued as $index => $line) {
            if (strncasecmp($line, "{$field}:", $length) === 0) {
               unset($this->queued[$index]);
            }
         }
      }

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

   /**
    * Union two comma-delimited Vary token lists.
    *
    * RFC 9110 §5.2: multiple declarations of a list-based field are
    * equivalent to their comma-join, so a Vary collision across sources
    * joins instead of discarding one declared cache dimension. Tokens are
    * deduplicated case-insensitively and any `*` member absorbs the whole
    * list (§12.5.5).
    */
   private static function merge (string $current, string $extra): string
   {
      $tokens = [];
      $listed = [];

      foreach (explode(',', "{$current},{$extra}") as $token) {
         $token = trim($token);

         if ($token === '') {
            continue;
         }
         // ?: `*` covers every request field — the list collapses
         if ($token === '*') {
            return '*';
         }

         $lower = strtolower($token);
         if (isSet($listed[$lower])) {
            continue;
         }

         $listed[$lower] = true;
         $tokens[] = $token;
      }

      // :
      return implode(', ', $tokens);
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

      // ! Strip every octet forbidden in a field value from the default media type
      //   at the single point it is serialized. `$type` is a public property with no
      //   gate of its own, so CR/LF (response splitting) and NUL/VT/DEL (parser
      //   differentials) can only be removed here — the same class `check()` rejects
      //   for set()/append()/prepare()/queue()/preset(). Done on real rebuild only,
      //   never on the cached fast returns above, and the scan is a single C call so
      //   a clean `$type` stays allocation-free.
      $type = $this->type;
      if (strcspn($type, self::VALUE_CTL) !== strlen($type)) {
         $type = (string) preg_replace('/[\x00-\x08\x0A-\x1F\x7F]/', '', $type);
      }

      // ! Queued lines are serialized FIRST, so they own their field identity.
      //   Without seeding the case-insensitive set from them, a queued `X-Policy`
      //   and a mapped `x-policy` both reach the wire and the recipient chooses
      //   which policy applies — the same defect the map-to-map union closed.
      //   `Set-Cookie` is the one documented repeatable field and never
      //   participates in the identity set.
      $seen = [];
      $varyQueued = null;
      foreach ($queued as $index => $line) {
         $colon = strpos($line, ':');
         if ($colon === false) {
            continue;
         }

         $key = strtolower(substr($line, 0, $colon));
         if ($key !== 'set-cookie') {
            $seen[$key] = true;
         }
         // ! Vary is a comma-list field — a later map declaration joins
         //   this line (see merge()) instead of silently vanishing
         if ($key === 'vary') {
            $varyQueued = $index;
         }
      }

      // ?! Hot path: most responses have no user fields/prepared — skip array merge.
      if ($this->fields === [] && $this->prepared === []) {
         // Preset only
         foreach ($preset as $name => $value) {
            // ? Field identity is case-insensitive across EVERY source, so a
            //   name already queued owns the line and suppresses this one —
            //   except Vary, a comma-list field whose second declaration
            //   joins the queued line (RFC 9110 §5.2) instead of vanishing.
            $key = strtolower((string) $name);
            if (isSet($seen[$key])) {
               if ($key === 'vary' && $varyQueued !== null) {
                  $line = $queued[$varyQueued];
                  $colon = (int) strpos($line, ':');
                  $joined = self::merge(
                     substr($line, $colon + 1),
                     $value === true ? '' : (string) $value
                  );
                  $prefix = substr($line, 0, $colon);
                  $queued[$varyQueued] = "{$prefix}: {$joined}";
               }
               continue;
            }
            $seen[$key] = true;

            $value = ($value === true) ? match ($name) {
               'Date' => self::stamp(),
               default => ''
            } : (string) $value;

            $queued[] = "$name: $value";
            // ! A just-emitted preset Vary line must be joinable by a later
            //   case-variant preset declaration (preset() keys by exact
            //   casing), exactly like an original queued line
            if ($key === 'vary') {
               $varyQueued = array_key_last($queued);
            }
         }

         // @ Default Content-Type — suppressed by a queued or preset one under
         //   ANY casing
         if (isSet($seen['content-type']) === false) {
            $queued[] = "Content-Type: {$type}";
         }

         $this->raw = implode("\r\n", $queued);

         $this->built = time();
         $this->dirty = false;

         return true;
      }

      // ! Union the three maps case-insensitively. `+` keys on exact case, so
      //   `Content-Type` in one map and `content-type` in another serialized as
      //   two independent lines and left the recipient to choose which policy
      //   applies. Precedence is unchanged — the earliest map still wins, as
      //   `+` did — and the winner's own casing is what reaches the wire. `$seen`
      //   already carries the queued names, which serialize ahead of every map.
      $fields = [];
      $varyName = null;
      foreach ([$preset, $this->fields, $this->prepared] as $map) {
         foreach ($map as $name => $value) {
            $key = strtolower((string) $name);

            if (isSet($seen[$key])) {
               // ? Vary is a comma-list field — a colliding declaration
               //   joins the earlier holder (RFC 9110 §5.2) instead of
               //   vanishing: a handler that queues or prepares a Vary
               //   AFTER the last vary() call (e.g. after the CORS
               //   middleware declared Origin) keeps every dimension
               if ($key === 'vary') {
                  $extra = $value === true ? '' : (string) $value;

                  if ($varyName !== null) {
                     // ? The holder needs the same `true` guard as the extra
                     //   side — `(string) true` would fabricate a `1` token
                     $holder = $fields[$varyName] === true ? '' : (string) $fields[$varyName];
                     $fields[$varyName] = self::merge($holder, $extra);
                  }
                  else if ($varyQueued !== null) {
                     $line = $queued[$varyQueued];
                     $colon = (int) strpos($line, ':');
                     $joined = self::merge(substr($line, $colon + 1), $extra);
                     $prefix = substr($line, 0, $colon);
                     $queued[$varyQueued] = "{$prefix}: {$joined}";
                  }
               }
               continue;
            }

            if ($key === 'vary') {
               $varyName = $name;
            }
            $seen[$key] = true;
            $fields[$name] = $value;
         }
      }

      // Fields
      foreach ($fields as $name => $value) {
         // Dynamic fields
         $value = ($value === true) ? match ($name) {
            'Date' => self::stamp(),
            default => ''
         } : (string) $value;

         $queued[] = "$name: $value";
      }

      // @ Set default Content-Type if not present, under ANY casing
      if (isSet($seen['content-type']) === false) {
         $queued[] = "Content-Type: {$type}";
      }

      $this->raw = implode("\r\n", $queued);

      $this->built = time();
      $this->dirty = false;

      return true;
   }
}
