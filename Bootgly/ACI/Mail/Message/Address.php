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


use function preg_match;
use function preg_replace;
use function strlen;
use function strpbrk;
use function substr;
use function trim;
use InvalidArgumentException;


/**
 * A parsed mail address: `local@domain` with an optional display name.
 *
 * Accepts `user@example.com`, `Name <user@example.com>` and
 * `"Quoted, Name" <user@example.com>` forms. The email must carry a
 * non-empty local part and domain — bare local names are rejected.
 */
final class Address
{
   // * Data
   /**
    * Bare email (local@domain).
    */
   public readonly string $email;
   /**
    * Display name ('' when absent).
    */
   public readonly string $name;


   public function __construct (string $address)
   {
      // ? Guard: no control bytes may enter a header through an address
      if (strpbrk($address, "\r\n\0") !== false) {
         throw new InvalidArgumentException(
            'Mail address contains illegal control bytes (CR, LF or NUL).'
         );
      }

      // @ Split `Name <email>` — no match means a bare email
      if (preg_match('/^(.*)<([^<>]+)>\s*$/s', $address, $matches) === 1) {
         $name = trim($matches[1]);
         $email = trim($matches[2]);

         // ? Unwrap a quoted-string display name (`"Last, First"`)
         if (strlen($name) >= 2 && $name[0] === '"' && $name[-1] === '"') {
            $name = substr($name, 1, -1);
            $name = preg_replace('/\\\\(.)/s', '$1', $name) ?? $name;
         }
         // ? An unquoted display name must not carry angle brackets
         //   (`Name <a@b> <c@d>` is malformed, not a name)
         elseif (strpbrk($name, '<>') !== false) {
            throw new InvalidArgumentException("Invalid mail address: `{$address}`.");
         }
      }
      else {
         $name = '';
         $email = trim($address);
      }

      // ? Validate `local@domain`: single @, non-empty sides, no
      //   whitespace/brackets/quotes (UTF-8 bytes allowed — SMTPUTF8 is
      //   the transport's concern)
      if (preg_match('/^[^\s@<>"]+@[^\s@<>"]+$/', $email) !== 1) {
         throw new InvalidArgumentException("Invalid mail address: `{$address}`.");
      }

      // * Data
      $this->email = $email;
      $this->name = $name;
   }
}
