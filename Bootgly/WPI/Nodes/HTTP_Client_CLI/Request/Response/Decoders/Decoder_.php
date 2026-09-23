<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders;


use function count;
use function ctype_digit;
use function end;
use function explode;
use function key;
use function ltrim;
use function preg_replace;
use function str_contains;
use function strcasecmp;
use function strcmp;
use function strcspn;
use function strlen;
use function strncmp;
use function strpos;
use function strspn;
use function strstr;
use function strtolower;
use function substr;
use function substr_count;
use function trim;

use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoder;


class Decoder_ extends Decoder
{
   /**
    * The response head — status line, field lines and the blank line — is capped whatever
    * `HTTP_Client_CLI::$maxResponseBytes` allows: an upstream that never ends its header block
    * is refused instead of buffered until the timeout.
    */
   public const int MAX_HEADER_BYTES = 65536;
   /**
    * Control characters a reason phrase must not carry (RFC 9112 §4 — HTAB is allowed).
    */
   private const string CTL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0a\x0b\x0c\x0d\x0e\x0f"
      . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";
   /**
    * `tchar` (RFC 9110 §5.6.2) — the only octets a field name may carry.
    */
   private const string TCHAR =
      "!#$%&'*+-.^_`|~"
      . '0123456789'
      . 'abcdefghijklmnopqrstuvwxyz'
      . 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';


   /**
    * Parse the head of an HTTP/1.x response and frame its body (RFC 9112 §6.3).
    *
    * The framing is strict: a malformed status line, field line, `Content-Length` or
    * `Transfer-Encoding` fails the response (`'Invalid Response'`) instead of being guessed at,
    * and a head past `MAX_HEADER_BYTES` fails it (`'Response Header Fields Too Large'`).
    *
    * @return null|array{protocol: string, code: int, status: string, headerRaw: string, bodyRaw: string, bodyLength: int, bodyDownloaded: int, bodyWaiting: bool, chunked: bool, closeConnection: bool, interim: bool, consumed: int}|array{failed: true, status: string, consumed: int}
    */
   public function decode (string $buffer, int $size, null|string $method = null): null|array
   {
      /** @var array<string, array{protocol: string, code: int, status: string, headerRaw: string, bodyRaw: string, bodyLength: int, bodyDownloaded: int, bodyWaiting: bool, chunked: bool, closeConnection: bool, interim: bool, consumed: int}> $cache */
      static $cache = [];

      // ? Check local cache (safe for all methods except HEAD)
      if ($method !== 'HEAD' && $size <= 2048 && isSet($cache[$buffer])) {
         return $cache[$buffer];
      }

      // @ Find end of header section
      $separator = strpos($buffer, "\r\n\r\n");
      if ($separator === false) {
         // ? An unterminated head past the cap never completes — refused
         //   whatever maxResponseBytes allows (M2)
         if ($size > self::MAX_HEADER_BYTES) {
            return $this->refuse('Response Header Fields Too Large', $size);
         }

         return null; // @ Incomplete headers, wait for more data
      }

      // @ Minimum consumed bytes = header section + CRLFCRLF separator
      $headerSectionLength = $separator + 4;
      if ($headerSectionLength > self::MAX_HEADER_BYTES) {
         return $this->refuse('Response Header Fields Too Large', $size);
      }

      // ? A bare LF or CR in the head: a tolerant peer and this parser would
      //   disagree on line boundaries. A CRLF-framed head has exactly as many
      //   LFs and CRs as CRLFs.
      $CRLFs = substr_count($buffer, "\r\n", 0, $headerSectionLength);
      if (
         substr_count($buffer, "\n", 0, $headerSectionLength) !== $CRLFs
         || substr_count($buffer, "\r", 0, $headerSectionLength) !== $CRLFs
      ) {
         return $this->refuse('Invalid Response', $size);
      }

      // # Parse status-line — `HTTP/1.DIGIT SP 3DIGIT [SP reason-phrase]`
      //   (a higher 1.x minor is processed as 1.1 — RFC 9112 §2.3; a code
      //   from 600 to 999 is processed like a 5xx — RFC 9110 §15 — and keeps
      //   its value; 000–099 would read as this client's "no response")
      $statusLine = (string) strstr($buffer, "\r\n", true);
      $metaLength = strlen($statusLine);
      if (
         $metaLength < 12
         || strncmp($statusLine, 'HTTP/1.', 7) !== 0
         || ctype_digit($statusLine[7]) === false
         || $statusLine[8] !== ' '
         || ctype_digit(substr($statusLine, 9, 3)) === false
         || ($metaLength > 12 && $statusLine[12] !== ' ')
      ) {
         return $this->refuse('Invalid Response', $size);
      }

      $protocol = substr($statusLine, 0, 8);
      $code     = (int) substr($statusLine, 9, 3);
      $status   = $metaLength > 13 ? substr($statusLine, 13) : '';
      if ($code < 100 || strcspn($status, self::CTL) !== strlen($status)) {
         return $this->refuse('Invalid Response', $size);
      }

      // # Extract header section (between status-line CRLF and CRLFCRLF) —
      //   empty when the status line is followed by the blank line itself
      $headerRaw = $separator > $metaLength
         ? substr($buffer, $metaLength + 2, $separator - $metaLength - 2)
         : '';

      // ! obs-fold (RFC 9112 §5.2): a user agent replaces each fold with SP
      //   before interpreting the field — the framing below reads the same
      //   unfolded fields the application gets
      if (str_contains($headerRaw, "\r\n ") || str_contains($headerRaw, "\r\n\t")) {
         $headerRaw = (string) preg_replace('/\r\n[ \t]+/', ' ', $headerRaw);
      }

      // @@ One scan over the field lines — every response, no-body ones included
      $contentLength = null;
      $transferCodings = [];
      $transferEncoding = false;
      $close = false;
      $keepAlive = false;
      if ($headerRaw !== '') {
         foreach (explode("\r\n", $headerRaw) as $line) {
            $colon = strpos($line, ':');
            // ? A field line without a name, or whose name is not a token —
            //   whitespace before the colon (`Content-Length : 5`), a NUL, a
            //   VT — is refused, never skipped
            if (
               $colon === false
               || $colon === 0
               || strspn($line, self::TCHAR, 0, $colon) !== $colon
            ) {
               return $this->refuse('Invalid Response', $size);
            }

            $value = trim(substr($line, $colon + 1), " \t");
            if (str_contains($value, "\0")) {
               return $this->refuse('Invalid Response', $size);
            }

            // ? Only the framing fields are interpreted here
            $name = match ($colon) {
               10, 14, 17 => strtolower(substr($line, 0, $colon)),
               default    => ''
            };

            switch ($name) {
               case 'content-length':
                  // @@ `1*DIGIT`, possibly repeated as a list or across lines —
                  //    every value must be the same number (RFC 9110 §8.6)
                  foreach (explode(',', $value) as $element) {
                     $element = trim($element, " \t");
                     if ($element === '' || ctype_digit($element) === false) {
                        return $this->refuse('Invalid Response', $size);
                     }

                     $digits = ltrim($element, '0');
                     if ($digits === '') {
                        $digits = '0';
                     }
                     // ? Past PHP_INT_MAX the cast would silently saturate
                     if (
                        strlen($digits) > 19
                        || (strlen($digits) === 19 && strcmp($digits, '9223372036854775807') > 0)
                     ) {
                        return $this->refuse('Invalid Response', $size);
                     }

                     $length = (int) $digits;
                     if ($contentLength !== null && $contentLength !== $length) {
                        return $this->refuse('Invalid Response', $size);
                     }
                     $contentLength = $length;
                  }
                  break;
               case 'transfer-encoding':
                  // @@ Codings merged across lines; empty list elements ignored
                  $codings = 0;
                  foreach (explode(',', $value) as $coding) {
                     $coding = strtolower(trim($coding, " \t"));
                     if ($coding !== '') {
                        $transferCodings[] = $coding;
                        $codings++;
                     }
                  }
                  if ($codings === 0) {
                     return $this->refuse('Invalid Response', $size);
                  }
                  $transferEncoding = true;
                  break;
               case 'connection':
                  // @@ A token list — `close` anywhere in it closes (HCLI-16)
                  foreach (explode(',', $value) as $option) {
                     $option = trim($option, " \t");
                     if (strcasecmp($option, 'close') === 0) {
                        $close = true;
                     }
                     else if (strcasecmp($option, 'keep-alive') === 0) {
                        $keepAlive = true;
                     }
                  }
                  break;
            }
         }
      }

      // @ Connection management — a message framed by both Transfer-Encoding
      //   and Content-Length may be a smuggling attempt: never reuse it
      $closeConnection = $close
         || ($protocol === 'HTTP/1.0' && $keepAlive === false)
         || ($transferEncoding && $contentLength !== null);

      // @ RFC 9112 §6.3 — Determine message body length (normative order)

      // --- Rule 1: HEAD and responses with no defined body (1xx, 204, 304) ---
      //     Content-Length / Transfer-Encoding were syntax-checked above, and
      //     never frame these
      $isInformational = $code < 200;
      $noBody = ($method === 'HEAD')
         || $isInformational
         || ($code === 204)
         || ($code === 304);

      if ($noBody) {
         $parsed = [
            'protocol'        => $protocol,
            'code'            => $code,
            'status'          => $status,
            'headerRaw'       => $headerRaw,
            'bodyRaw'         => '',
            'bodyLength'      => 0,
            'bodyDownloaded'  => 0,
            'bodyWaiting'     => false,
            'chunked'         => false,
            'closeConnection' => $closeConnection,
            'interim'         => $isInformational,
            'consumed'        => $headerSectionLength,
         ];

         // @ Cache small no-body responses (safe for all methods except HEAD) —
         //   keyed on the WHOLE buffer, so a small head followed by a large
         //   leftover must never become a key
         if ($method !== 'HEAD' && $size <= 2048) {
            $cache[$buffer] = $parsed;
            if (count($cache) > 512) {
               unset($cache[key($cache)]);
            }
         }

         return $parsed;
      }

      // ? HTTP/1.0 cannot carry Transfer-Encoding — faulty framing (RFC 9112 §6.1)
      if ($transferEncoding && $protocol === 'HTTP/1.0') {
         return $this->refuse('Invalid Response', $size);
      }

      // --- Rule 3: Transfer-Encoding (takes precedence over Content-Length) ---
      // chunked = Transfer-Encoding list is non-empty AND last coding is "chunked"
      $chunked = $transferCodings !== [] && end($transferCodings) === 'chunked';

      // --- Rule 4: Content-Length (ignored when Transfer-Encoding is present) ---
      if ($transferEncoding) {
         $contentLength = null;
      }

      // @ Body handling
      $bodyRaw        = '';
      $bodyLength     = 0;
      $bodyDownloaded = 0;
      $bodyWaiting    = false;
      $consumed       = $headerSectionLength;

      if ($chunked) {
         // --- Rule 3a: chunked Transfer-Encoding ---
         // consumed = header section only; body ownership transferred to Decoder_Chunked
         $bodyWaiting  = true;
         $initialBody  = substr($buffer, $separator + 4);
         if ($initialBody !== '') {
            $bodyRaw        = $initialBody;
            $bodyDownloaded = strlen($initialBody);
         }
      }
      else if ($transferEncoding) {
         // --- Rule 3b: non-chunked Transfer-Encoding (e.g. identity, gzip applied alone) ---
         // Body length determined by connection close; treat same as close-delimited
         $bodyData = substr($buffer, $separator + 4);
         if ($bodyData !== '') {
            $bodyRaw        = $bodyData;
            $bodyDownloaded = strlen($bodyData);
            $bodyLength     = $bodyDownloaded;
         }
         $consumed    = 0; // keep headers in $pendingBuffer for re-parse on next read
         $bodyWaiting = true;  // finalized by disconnect handler (Phase 5)
      }
      else if ($contentLength !== null) {
         // --- Rule 4: Content-Length ---
         $bodyLength = $contentLength;

         if ($contentLength > 0) {
            $bodyData = substr($buffer, $separator + 4, $contentLength);
            if ($bodyData !== '') {
               $bodyRaw        = $bodyData;
               $bodyDownloaded = strlen($bodyData);
            }

            if ($bodyDownloaded < $contentLength) {
               $bodyWaiting = true;
            }
         }

         // ? Charge header+body only once the body is fully buffered — a
         //   partial body keeps everything in $pendingBuffer for re-parse
         //   on the next read, like the close-delimited siblings (HCLI-10)
         $consumed = $bodyWaiting ? 0 : $consumed + $contentLength;
      }
      else {
         // --- Rule 5: close-delimited (HTTP/1.0 style, no framing metadata) ---
         // Accumulate in $pendingBuffer until connection closes (Phase 5 finalizes)
         $bodyData = substr($buffer, $separator + 4);
         if ($bodyData !== '') {
            $bodyRaw        = $bodyData;
            $bodyDownloaded = strlen($bodyData);
            $bodyLength     = $bodyDownloaded;
         }
         $consumed    = 0; // keep headers in $pendingBuffer for re-parse on next read
         $bodyWaiting = true;  // finalized by disconnect handler (Phase 5)
      }

      $parsed = [
         'protocol'        => $protocol,
         'code'            => $code,
         'status'          => $status,

         'headerRaw'       => $headerRaw,

         'bodyRaw'         => $bodyRaw,
         'bodyLength'      => $bodyLength,
         'bodyDownloaded'  => $bodyDownloaded,
         'bodyWaiting'     => $bodyWaiting,

         'chunked'         => $chunked,
         'closeConnection' => $closeConnection,
         'interim'         => false,
         'consumed'        => $consumed,
      ];

      // @ Cache small, complete responses (safe for all methods except HEAD)
      if ($method !== 'HEAD' && $size <= 2048 && $consumed > 0 && ! $bodyWaiting) {
         $cache[$buffer] = $parsed;
         if (count($cache) > 512) {
            unset($cache[key($cache)]);
         }
      }

      return $parsed;
   }

   /**
    * Fail the response: its framing cannot be trusted.
    *
    * @return array{failed: true, status: string, consumed: int}
    */
   private function refuse (string $status, int $size): array
   {
      return [
         'failed'   => true,
         'status'   => $status,
         'consumed' => $size,
      ];
   }
}
