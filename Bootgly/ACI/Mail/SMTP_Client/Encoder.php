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


use function preg_replace;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strpbrk;
use InvalidArgumentException;


/**
 * SMTP command writer and DATA payload transformer. Stateless and pure.
 *
 * Every command the client sends goes through `encode()`, which is also the
 * CRLF-injection guard for the whole client.
 */
class Encoder
{
   /**
    * Encode a command line: `VERB[ argument]\r\n`.
    */
   public function encode (string $verb, string $argument = ''): string
   {
      // ? Guard: no CR/LF/NUL may reach the wire through a command element
      if (
         strpbrk($verb, "\r\n\0") !== false
         || strpbrk($argument, "\r\n\0") !== false
      ) {
         throw new InvalidArgumentException(
            'SMTP command contains illegal control bytes (CR, LF or NUL).'
         );
      }

      // ?: Bare verb (DATA, RSET, QUIT, STARTTLS, ...)
      if ($argument === '') {
         return "{$verb}\r\n";
      }

      // :
      return "{$verb} {$argument}\r\n";
   }

   /**
    * Transform a raw RFC 5322 message into the DATA wire payload:
    * normalize every EOL style to CRLF, ensure a trailing CRLF and apply
    * dot-stuffing (RFC 5321 §4.5.2). Returns the payload only — the caller
    * writes the `.\r\n` terminator.
    */
   public function stuff (string $data): string
   {
      // ! Normalize every EOL style to CRLF (single pass; order matters)
      $data = preg_replace("/\r\n|\r|\n/", "\r\n", $data) ?? $data;

      // ?! Ensure the payload ends with CRLF (the terminator needs its own line)
      if (str_ends_with($data, "\r\n") === false) {
         $data .= "\r\n";
      }

      // @ Dot-stuffing: double any dot at line start
      if (str_starts_with($data, '.') === true) {
         $data = ".{$data}";
      }

      // :
      return str_replace("\r\n.", "\r\n..", $data);
   }
}
