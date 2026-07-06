<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail\Message;


use function base64_encode;
use function chunk_split;
use function explode;
use function implode;
use function mb_str_split;
use function preg_match;
use function preg_replace;
use function quoted_printable_encode;
use function str_replace;
use function strlen;
use function strpbrk;
use function strrpos;
use function substr;
use InvalidArgumentException;

use Bootgly\ACI\Mail\Message\Address;


/**
 * MIME entity encoder: RFC 2047 encoded-words for headers, quoted-printable
 * and wrapped base64 for bodies, header folding. Stateless and pure —
 * everything is byte-exact testable.
 */
class Encoder
{
   /**
    * Raw-byte cap per RFC 2047 chunk: 39 bytes → base64 52 chars →
    * encoded-word 64 chars ≤ the 75-char word limit (§2), and
    * `"Subject: "` + 64 = 73 ≤ the 76-char encoded-word line limit.
    */
   private const int CHUNK_LIMIT = 39;
   /**
    * Header folding target (RFC 5322 recommends 78; hard cap is 998).
    */
   private const int LINE_LIMIT = 78;


   /**
    * Guard a user-supplied header value against header injection.
    */
   public function check (string $value): string
   {
      // ? Guard: no CR/LF/NUL may reach a header
      if (strpbrk($value, "\r\n\0") !== false) {
         throw new InvalidArgumentException(
            'Mail header contains illegal control bytes (CR, LF or NUL).'
         );
      }

      // :
      return $value;
   }

   /**
    * RFC 2047 B-encoding: pure printable ASCII passes through unchanged;
    * anything else becomes `=?UTF-8?B?…?=` words (chunked on UTF-8
    * character boundaries) joined with a folded continuation.
    */
   public function encode (string $text): string
   {
      // ?: Pure printable ASCII passes through unchanged
      if (preg_match('/[^\x20-\x7E]/', $text) !== 1) {
         return $text;
      }

      // @ Chunk on UTF-8 character boundaries, capped at CHUNK_LIMIT bytes
      $chunks = [];
      $chunk = '';
      foreach (mb_str_split($text) as $character) {
         if ($chunk !== '' && strlen($chunk) + strlen($character) > self::CHUNK_LIMIT) {
            $chunks[] = $chunk;
            $chunk = '';
         }

         $chunk .= $character;
      }
      if ($chunk !== '') {
         $chunks[] = $chunk;
      }

      // @ Encode each chunk as one encoded-word
      foreach ($chunks as $index => $bytes) {
         $encoded = base64_encode($bytes);
         $chunks[$index] = "=?UTF-8?B?{$encoded}?=";
      }

      // : Words joined with a folded continuation (leading-space WSP)
      return implode("\r\n ", $chunks);
   }

   /**
    * Format an Address for a header: bare email, atom-safe name,
    * quoted-string name, or RFC 2047 encoded-word name.
    */
   public function format (Address $Address): string
   {
      $name = $Address->name;

      // ?: Bare email
      if ($name === '') {
         return $Address->email;
      }

      // ?: Non-ASCII name → encoded-word
      if (preg_match('/[^\x20-\x7E]/', $name) === 1) {
         $encoded = $this->encode($name);

         return "{$encoded} <{$Address->email}>";
      }

      // ?: Atom-safe ASCII name
      if (preg_match('/^[A-Za-z0-9 .\-_]+$/', $name) === 1) {
         return "{$name} <{$Address->email}>";
      }

      // : Quoted-string name (escape backslash, then the quote)
      $quoted = str_replace(['\\', '"'], ['\\\\', '\\"'], $name);

      return "\"{$quoted}\" <{$Address->email}>";
   }

   /**
    * Quoted-printable body (RFC 2045). EOLs are normalized to CRLF first —
    * a lone LF would otherwise be encoded as `=0A`.
    */
   public function quote (string $text): string
   {
      // ! Normalize every EOL style to CRLF
      $text = preg_replace("/\r\n|\r|\n/", "\r\n", $text) ?? $text;

      // : Native RFC 2045 encoder (soft breaks at 76)
      return quoted_printable_encode($text);
   }

   /**
    * Wrapped base64 body: 76-char lines with CRLF (trailing CRLF included).
    */
   public function wrap (string $bytes): string
   {
      // :
      return chunk_split(base64_encode($bytes), 76, "\r\n");
   }

   /**
    * Fold header lines longer than 78 chars at the last space within the
    * limit — the space becomes the continuation's leading WSP. Already
    * folded input (e.g. from encode()) is never re-broken; a line without
    * a foldable space stays long (RFC 5322 hard cap is 998).
    */
   public function fold (string $line): string
   {
      $folded = [];

      // @@ Fold each physical line independently
      foreach (explode("\r\n", $line) as $physical) {
         while (strlen($physical) > self::LINE_LIMIT) {
            $window = substr($physical, 0, self::LINE_LIMIT + 1);
            $break = strrpos($window, ' ');

            // ? No foldable space within the limit — leave the line long
            if ($break === false || $break === 0) {
               break;
            }

            $folded[] = substr($physical, 0, $break);
            $physical = substr($physical, $break);
         }

         $folded[] = $physical;
      }

      // :
      return implode("\r\n", $folded);
   }
}
