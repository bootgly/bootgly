<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail\SMTP_Client;


use function count;
use function preg_match;
use function strlen;
use function strpos;
use function substr;

use Bootgly\ACI\Mail\Exceptions\ProtocolException;
use Bootgly\ACI\Mail\Reply;


/**
 * Incremental SMTP reply parser (RFC 5321 §4.2).
 *
 * Feed raw bytes as they arrive; complete replies (single or multiline
 * `250-…`/`250 …`) are returned as `Reply` values while partial data stays
 * buffered. Pure and socket-free.
 */
class Decoder
{
   /**
    * Maximum bytes per reply line (RFC 5321 minimum is 512; generous for
    * long EHLO/AUTH lines).
    */
   private const int LINE_LIMIT = 4096;
   /**
    * Maximum lines per reply (EHLO can be long; caps memory).
    */
   private const int LINES_LIMIT = 128;

   // * Data
   /**
    * Unconsumed bytes (partial line) awaiting more input.
    */
   public private(set) string $buffer = '';

   // * Metadata
   /**
    * Code of the in-progress multiline reply (0 = none).
    */
   private int $code = 0;
   /**
    * Accumulated text lines of the in-progress reply.
    * @var array<int,string>
    */
   private array $lines = [];


   /**
    * Consume bytes and return every complete Reply they finish.
    *
    * @return array<int,Reply>
    */
   public function decode (string $bytes = ''): array
   {
      // !
      $this->buffer .= $bytes;
      $Replies = [];

      // @@ Extract complete lines from the buffer
      while (($position = strpos($this->buffer, "\n")) !== false) {
         $line = substr($this->buffer, 0, $position);
         $this->buffer = substr($this->buffer, $position + 1);

         // ! Tolerate bare LF: strip a single trailing CR when present
         if ($line !== '' && $line[-1] === "\r") {
            $line = substr($line, 0, -1);
         }

         // ? Guard: line length
         if (strlen($line) > self::LINE_LIMIT) {
            $limit = self::LINE_LIMIT;

            throw new ProtocolException("SMTP reply line exceeds the {$limit}-byte limit.");
         }

         // @ Parse `<code><separator><text>` (bare `<code>` is a valid final line)
         if (preg_match('/^(\d{3})([ -])(.*)$/', $line, $matches) === 1) {
            $code = (int) $matches[1];
            $separator = $matches[2];
            $text = $matches[3];
         }
         elseif (preg_match('/^\d{3}$/', $line) === 1) {
            $code = (int) $line;
            $separator = ' ';
            $text = '';
         }
         else {
            throw new ProtocolException("Malformed SMTP reply line: `{$line}`.");
         }

         // ? Guard: a multiline reply must keep a single code
         if ($this->code !== 0 && $code !== $this->code) {
            throw new ProtocolException(
               "Mixed codes in a multiline SMTP reply: `{$this->code}` then `{$code}`."
            );
         }

         $this->lines[] = $text;

         // ? Guard: reply line count
         if (count($this->lines) > self::LINES_LIMIT) {
            $limit = self::LINES_LIMIT;

            throw new ProtocolException("SMTP reply exceeds the {$limit}-line limit.");
         }

         // ? Intermediate line — keep accumulating
         if ($separator === '-') {
            $this->code = $code;
            continue;
         }

         // ---

         // @ Final line — emit the Reply and reset the accumulation
         $status = '';
         if (preg_match('/^([245]\.\d{1,3}\.\d{1,3})(?: |$)/', $this->lines[0], $matches) === 1) {
            $status = $matches[1];
         }

         $Replies[] = new Reply($code, $status, $this->lines);

         $this->code = 0;
         $this->lines = [];
      }

      // ? Guard: an unterminated line must not grow unbounded
      if (strlen($this->buffer) > self::LINE_LIMIT) {
         $limit = self::LINE_LIMIT;

         throw new ProtocolException("SMTP reply line exceeds the {$limit}-byte limit.");
      }

      // :
      return $Replies;
   }

   /**
    * Discard buffered bytes and any partial multiline state.
    *
    * Mandatory after the STARTTLS `220` before enabling crypto — plaintext
    * bytes buffered past that reply must never survive into the TLS session.
    */
   public function reset (): void
   {
      $this->buffer = '';
      $this->code = 0;
      $this->lines = [];
   }
}
