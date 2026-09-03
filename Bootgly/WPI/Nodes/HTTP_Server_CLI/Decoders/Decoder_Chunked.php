<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use const Bootgly\WPI;
use function ctype_xdigit;
use function explode;
use function hexdec;
use function ltrim;
use function preg_match;
use function rtrim;
use function strcspn;
use function strlen;
use function strpos;
use function strspn;
use function substr;
use function time;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Feeding;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCP_Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


class Decoder_Chunked extends Decoders implements Disconnecting, Feeding
{
   // * Config
   //   Absolute decode deadline in seconds (audit F-6): anchored to `$decoded`
   //   (set once in init()), never refreshed per packet — a slow-drip body
   //   cannot extend it.
   private const int BODY_DEADLINE = 30;
   private const int CHUNK_LINE_LIMIT = 8192;
   /**
    * Octets never valid inside a chunk extension (all C0 plus DEL). HTAB is NOT
    * in the set: RFC 9112 admits it as BWS around the `;`/`=` delimiters and as
    * qdtext (and as a quoted-pair octet) inside a quoted value. The grammar in
    * `check()` decides where it may appear; a blanket octet ban rejected
    * conformant senders.
    */
   private const string EXTENSION_CTL =
      "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0A\x0B\x0C\x0D\x0E\x0F"
      . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F"
      . "\x7F";
   /**
    * `tchar` (RFC 9110 §5.6.2) — the only octets a chunk-ext name or unquoted
    * value may contain.
    */
   private const string TCHAR =
      '!#$%&\'*+-.^_`|~'
      . '0123456789'
      . 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
      . 'abcdefghijklmnopqrstuvwxyz';
   /**
    * `BWS` (RFC 9110 §5.6.3) around the `;` and `=` delimiters — SP and HTAB.
    */
   private const string BWS = " \t";
   /**
    * Maximum SIGNIFICANT hexadecimal digits accepted in a chunk-size (audit
    * M1). 15 digits cap a chunk at 16^15-1 (just under 2^60), which keeps
    * `hexdec()` on the integer side of its float threshold and leaves the
    * aggregate addition far from overflowing. Any longer token is orders of
    * magnitude beyond every servable body cap anyway.
    */
   private const int CHUNK_SIZE_DIGITS = 15;
   private const int TRAILER_LIMIT = 16384;

   // # States
   private const int READ_SIZE = 0;
   private const int READ_DATA = 1;
   private const int READ_TRAILERS = 2;

   // * Data
   //   Owning Request, bound at the decoder install site in Request::decode():
   //   body continuations must never resolve the worker-global Request —
   //   another connection may replace or claim it between transport reads.
   //   Final so `disconnect()` can drop it: an unset is only safe when no
   //   subclass can attach hooks to the slot.
   final public Request $Request;
   private string $buffer = '';
   private string $body = '';

   // * Metadata
   //   Share of the worker-wide unfinished-body budget held by this decoder.
   public protected(set) Bodies $Bodies;
   // Absolute decode start time (set once in init(); NOT refreshed per packet).
   private int $decoded = 0;
   private int $state = self::READ_SIZE;
   private int $chunkSize = 0;
   private int $chunkRead = 0;
   private int $totalSize = 0;


   /**
    * Check a chunk-extension region against RFC 9112 §7.1.1:
    * `*( BWS ";" BWS chunk-ext-name [ BWS "=" BWS chunk-ext-val ] )`, where the
    * name is a token and the value is a token or a quoted-string.
    *
    * Discarding the region unparsed lets a malformed extension mean one thing
    * to Bootgly and another to an intermediary that does parse it — the shape
    * every request-smuggling differential is built on.
    *
    * @param string $extension The region starting at the first `;`.
    */
   private static function check (string $extension): bool
   {
      // !
      $length = strlen($extension);
      $offset = 0;

      // @@ One iteration per `;`-introduced extension
      while ($offset < $length) {
         // ? Every extension opens with its own delimiter
         if ($extension[$offset] !== ';') {
            return false;
         }
         $offset++;
         $offset += strspn($extension, self::BWS, $offset);

         // ! chunk-ext-name = token
         $name = strspn($extension, self::TCHAR, $offset);
         if ($name === 0) {
            return false;
         }
         $offset += $name;

         // ! BWS exists only to introduce the optional `=` value or the next
         //   `;`. Consuming it before knowing which of the two follows accepts
         //   `1;foo ` — unmatched grammar the decoder then discards, which is
         //   exactly the shape an intermediary that DOES parse it reads
         //   differently. So peek past it instead of consuming it.
         $spacing = strspn($extension, self::BWS, $offset);
         $next = $extension[$offset + $spacing] ?? '';

         // ?: End of the region — legal only with no dangling BWS
         if ($next === '') {
            return $spacing === 0;
         }
         // ?: Name-only extension — the delimiter is the next iteration's
         if ($next === ';') {
            $offset += $spacing;
            continue;
         }
         if ($next !== '=') {
            return false;
         }

         $offset += $spacing + 1;
         $offset += strspn($extension, self::BWS, $offset);

         // ---

         // # chunk-ext-val = quoted-string
         if (($extension[$offset] ?? '') === '"') {
            $offset++;
            $closed = false;

            while ($offset < $length) {
               $octet = $extension[$offset];

               if ($octet === '"') {
                  $closed = true;
                  $offset++;
                  break;
               }
               // ! quoted-pair — the escaped octet is consumed with the escape,
               //   so an embedded `\"` never closes the string
               if ($octet === '\\') {
                  $offset += 2;
                  continue;
               }

               $offset++;
            }

            // ? An unterminated quoted-string swallows the rest of the line
            if ($closed === false) {
               return false;
            }
         }
         // # chunk-ext-val = token
         else {
            $value = strspn($extension, self::TCHAR, $offset);
            if ($value === 0) {
               return false;
            }
            $offset += $value;
         }

         // ! Same rule after a value: BWS must introduce the next extension
         $spacing = strspn($extension, self::BWS, $offset);
         $next = $extension[$offset + $spacing] ?? '';

         if ($next === '') {
            return $spacing === 0;
         }
         if ($next !== ';') {
            return false;
         }
         $offset += $spacing;
      }

      // :
      return true;
   }

   public function init (): void
   {
      $this->Bodies = new Bodies;
      $this->buffer = '';
      $this->body = '';
      $this->decoded = time();
      $this->state = self::READ_SIZE;
      $this->chunkSize = 0;
      $this->chunkRead = 0;
      $this->totalSize = 0;
   }

   /**
    * Drop everything this decoder retains and hand its share of the worker
    * budget back. Every terminal path — completion, rejection, timeout and
    * transport teardown — runs through here, so the ledger cannot drift.
    */
   private function reset (): void
   {
      $this->body = '';
      $this->buffer = '';
      $this->Bodies->release();
   }

   /**
    * Transport teardown (`Connection::close()`), on every close path including
    * an abrupt peer EOF. Without this the accumulated chunk data stays
    * reachable from the closed Package until the cycle collector happens to
    * run — precisely the window a burst of half-sent bodies exploits.
    */
   public function disconnect (): void
   {
      $this->reset();

      if ( isSet($this->Request) ) {
         $this->Request->Body->waiting = false;
         unset($this->Request);
      }
   }

   public function feed (string $data): void
   {
      $this->buffer .= $data;
   }

   /**
    * Has the absolute decode deadline been reached? (audit F-6)
    *
    * Anchored to `$decoded` (set once in `init()`), so it is a hard cap from the
    * start of the chunked body. Unlike a per-packet sliding window, a slow drip
    * cannot push the deadline back.
    */
   public function expire (): bool
   {
      return (time() - $this->decoded) >= self::BODY_DEADLINE;
   }

   public function decode (Packages $Package, string $buffer, int $size): States
   {
      /** @var TCP_Packages $Package */
      $WPI = WPI;
      /** @var Server $Server */
      $Server = $WPI->Server;

      $Request = $this->Request;
      $Body = $Request->Body;

      if (! $Body->waiting) {
         $Package->Decoder = null;
         return $Server::$Decoder->decode($Package, $buffer, $size); // @phpstan-ignore method.nonObject
      }

      // ? Absolute decode deadline (audit F-6): anchored to the decode start,
      //   NOT refreshed per packet, so a slow drip cannot hold the worker
      //   buffer/connection indefinitely.
      if ($this->expire()) {
         $Body->waiting = false;

         $this->reset();

         $Package->Decoder = null;
         $Package->consumed = 0;
         $Package->reject("HTTP/1.1 408 Request Timeout\r\n\r\n");
         return States::Rejected;
      }

      // @ Append the current transport read. `$carried` belongs to earlier
      //   decode calls; only raw wire bytes after it may be reported through
      //   Package::$consumed when this call completes.
      $carried = strlen($this->buffer);
      $this->buffer .= $buffer;

      // ? `Request::$maxBodySize` bounds THIS body; the worker budget bounds
      //   the sum of every unfinished one. Both the decoded body and the
      //   not-yet-parsed wire behind it survive across reads, so both count.
      //   Checked right after the append, so the reservation covers exactly
      //   what is now held; a refusal drops all of it.
      if ($this->Bodies->reserve(strlen($this->body) + strlen($this->buffer)) === false) {
         $Body->waiting = false;
         $this->reset();
         $Package->Decoder = null;
         $Package->consumed = 0;
         $Package->reject("HTTP/1.1 503 Service Unavailable\r\n\r\n");
         return States::Rejected;
      }

      // @ Process chunks
      while (true) {
         switch ($this->state) {
            case self::READ_SIZE:
               // @ Find the chunk size line (\r\n terminated)
               $pos = strpos($this->buffer, "\r\n");
               $lineLength = $pos === false ? strlen($this->buffer) : $pos;

               // ? Chunk-size and extension metadata is not decoded body data,
               //   so enforce an independent cap before parsing the size. The
               //   delimiter offset check also rejects an oversized line that
               //   arrives complete in one transport read.
               if ($lineLength > self::CHUNK_LINE_LIMIT) {
                  $Package->reject("HTTP/1.1 431 Request Header Fields Too Large\r\n\r\n");
                  $Body->waiting = false;
                  $this->reset();
                  $Package->Decoder = null;
                  $Package->consumed = 0;
                  return States::Rejected;
               }

               if ($pos === false) {
                  $Package->consumed = $size;
                  return States::Incomplete; // Need more data
               }

               $sizeLine = substr($this->buffer, 0, $pos);
               $this->buffer = substr($this->buffer, $pos + 2);

               // @ Strip chunk extensions (RFC 9112 §7.1.1)
               $semiPos = strpos($sizeLine, ';');
               if ($semiPos !== false) {
                  // ? RFC 9112 §7.1.1 chunk-ext is `*( ";" token [ "=" ( token
                  //   / quoted-string ) ] )`. The extension region used to be
                  //   discarded unparsed, so a NUL or vertical tab rode along
                  //   inside it — the exact byte a differential intermediary
                  //   may normalize differently. Validate the octets AND the
                  //   grammar before dropping the region: an intermediary that
                  //   parses `1;foo="unterminated` differently from a decoder
                  //   that discards it disagrees about where the body ends.
                  $extension = substr($sizeLine, $semiPos);

                  if (
                     strcspn($extension, self::EXTENSION_CTL) !== strlen($extension)
                     || self::check($extension) === false
                  ) {
                     $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                     $Body->waiting = false;
                     $this->reset();
                     $Package->Decoder = null;
                     $Package->consumed = 0;
                     return States::Rejected;
                  }

                  // ! BWS may also precede the FIRST `;`, which puts it on the
                  //   size side of the split — `1 ;foo=bar` is conformant, and
                  //   leaving it attached made `ctype_xdigit()` reject the size.
                  //   Only TRAILING BWS is removed, so `5 garbage` still fails.
                  $sizeLine = rtrim(substr($sizeLine, 0, $semiPos), self::BWS);
               }

               // ! RFC 9112 §7.1 — chunk-size = 1*HEXDIG (no signs, no
               //   whitespace, no `0x` prefix). `hexdec` silently truncates
               //   on invalid chars, accepting `-1`, `0x10`, `5 garbage`,
               //   `0e0`. `ctype_xdigit` on the exact size is a single
               //   C-call that rejects every such variant at near-zero cost.
               if ($sizeLine === '' || ! ctype_xdigit($sizeLine)) {
                  $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  $Body->waiting = false;
                  $this->reset();
                  $Package->Decoder = null;
                  $Package->consumed = 0;
                  return States::Rejected;
               }

               // ! RFC 9112 §7.1 permits leading zeros, so significance — not
               //   token length — carries the magnitude. `hexdec()` returns a
               //   FLOAT from 2^64 up, and the int cast collapses that float to
               //   0, which would then read as the terminal chunk and hand the
               //   bytes behind it to the pipeline (audit M1). Bound the
               //   significant digits BEFORE converting, so every accepted
               //   token converts exactly.
               $significant = ltrim($sizeLine, '0');

               if (strlen($significant) > self::CHUNK_SIZE_DIGITS) {
                  $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                  $Body->waiting = false;
                  $this->reset();
                  $Package->Decoder = null;
                  $Package->consumed = 0;
                  return States::Rejected;
               }

               $chunkSize = $significant === '' ? 0 : (int) hexdec($significant);

               if ($chunkSize === 0) {
                  // @ A zero chunk starts the terminal section; completion
                  //   still requires the optional trailers and final CRLF.
                  $this->state = self::READ_TRAILERS;
                  break;
               }

               // @ Validate total size against the configurable cap (audit F-6:
               //   honors `Request\Configs(maxBodySize:)`; was a hard-coded 10 MB constant).
               //   Compared as a remainder, never as a sum, so the aggregate
               //   itself can never overflow into a passing value (audit M1).
               if ($chunkSize > Server\Request::$maxBodySize - $this->totalSize) {
                  $Package->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                  $Body->waiting = false;

                  // @ Clean up instance state to prevent cross-request leakage
                  $this->reset();

                  $Package->Decoder = null;

                  $Package->consumed = 0;
                  return States::Rejected;
               }

               $this->chunkSize = $chunkSize;
               $this->chunkRead = 0;
               $this->state = self::READ_DATA;
               break;

            case self::READ_DATA:
               $remaining = $this->chunkSize - $this->chunkRead;
               $available = strlen($this->buffer);

               if ($available === 0) {
                  $Package->consumed = $size;
                  return States::Incomplete; // Need more data
               }

               $toRead = ($available < $remaining) ? $available : $remaining;
               $this->body .= substr($this->buffer, 0, $toRead);
               $this->buffer = substr($this->buffer, $toRead);
               $this->chunkRead += $toRead;
               $this->totalSize += $toRead;

               if ($this->chunkRead < $this->chunkSize) {
                  $Package->consumed = $size;
                  return States::Incomplete; // Need more data for this chunk
               }

               // @ Consume trailing \r\n after chunk data (RFC 9112 §7.1 —
               //   `chunk = chunk-size [ext] CRLF chunk-data CRLF`). The
               //   previous code blindly skipped 2 bytes without asserting
               //   they were CRLF, letting attacker-chosen framing corrupt
               //   body boundaries.
               if (strlen($this->buffer) < 2) {
                  $Package->consumed = $size;
                  return States::Incomplete; // Need the trailing CRLF
               }
               if ($this->buffer[0] !== "\r" || $this->buffer[1] !== "\n") {
                  $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                  $Body->waiting = false;
                  $this->reset();
                  $Package->Decoder = null;
                  $Package->consumed = 0;
                  return States::Rejected;
               }
               $this->buffer = substr($this->buffer, 2);

               $this->state = self::READ_SIZE;
               break;

            case self::READ_TRAILERS:
               $length = strlen($this->buffer);
               if ($length < 2) {
                  $Package->consumed = $size;
                  return States::Incomplete;
               }

               if ($this->buffer[0] === "\r" && $this->buffer[1] === "\n") {
                  // @ Empty trailer section: the leading CRLF is its complete
                  //   terminator. Everything after it belongs to the pipeline.
                  $this->buffer = substr($this->buffer, 2);
               }
               else {
                  $trailerEnd = strpos($this->buffer, "\r\n\r\n");
                  if ($trailerEnd === false) {
                     if ($length > self::TRAILER_LIMIT) {
                        $Package->reject("HTTP/1.1 431 Request Header Fields Too Large\r\n\r\n");
                        $Body->waiting = false;
                        $this->reset();
                        $Package->Decoder = null;
                        $Package->consumed = 0;
                        return States::Rejected;
                     }

                     $Package->consumed = $size;
                     return States::Incomplete;
                  }

                  if ($trailerEnd > self::TRAILER_LIMIT) {
                     $Package->reject("HTTP/1.1 431 Request Header Fields Too Large\r\n\r\n");
                     $Body->waiting = false;
                     $this->reset();
                     $Package->Decoder = null;
                     $Package->consumed = 0;
                     return States::Rejected;
                  }

                  $trailers = substr($this->buffer, 0, $trailerEnd);
                  foreach (explode("\r\n", $trailers) as $line) {
                     // @ RFC 9110 field-line syntax. Requiring a token field
                     //   name prevents a request line from being accepted as
                     //   a trailer when the final empty line is missing.
                     if (preg_match(
                        "/\\A[!#\$%&'*+\\-.^_`|~0-9A-Za-z]+:[\\x09\\x20-\\x7E\\x80-\\xFF]*\\z/D",
                        $line,
                     ) !== 1) {
                        $Package->reject("HTTP/1.1 400 Bad Request\r\n\r\n");
                        $Body->waiting = false;
                        $this->reset();
                        $Package->Decoder = null;
                        $Package->consumed = 0;
                        return States::Rejected;
                     }
                  }

                  $this->buffer = substr($this->buffer, $trailerEnd + 4);
               }

               // @ Convert the raw parser cursor into a current-call cursor.
               //   Parsed bytes carried from previous reads are excluded;
               //   bytes after the terminal section stay in `$this->buffer`
               //   until the cursor is captured, then the TCP pipeline owns
               //   the untouched suffix from its original input.
               $wireConsumed = $carried + $size - strlen($this->buffer);
               $consumed = $wireConsumed > $carried
                  ? $wireConsumed - $carried
                  : 0;
               if ($consumed > $size) {
                  $consumed = $size;
               }

               $Body->raw = $this->body;
               $Body->length = $this->totalSize;
               $Body->downloaded = $this->totalSize;
               $Body->waiting = false;

               $this->reset();
               $Package->Decoder = null;
               $Package->consumed = $consumed;

               // ! Restore the worker-global Request before the response cycle:
               //   `Encoder_::encode()` serializes `Server::$Request` (mirror
               //   of the L1-hit path in `Decoder_::decode()`).
               Server::$Request = $Request;
               return States::Complete;
         }
      }
   }
}
