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


use function array_slice;
use function strpos;
use function strtoupper;
use function substr;
use function trim;

use Bootgly\ACI\Mail\Reply;


/**
 * EHLO capability parser (RFC 5321 §4.1.1.1).
 *
 * Parses the EHLO reply generically: each capability line becomes an
 * uppercased keyword mapped to its parameter string ('' when none) —
 * SIZE, AUTH (including the legacy `AUTH=` form), STARTTLS, PIPELINING,
 * 8BITMIME, SMTPUTF8, ENHANCEDSTATUSCODES, etc. Built with `null` after
 * a HELO fallback (no capabilities).
 */
class Extensions
{
   // * Data
   /**
    * Uppercased capability keyword => parameter string ('' when none).
    * @var array<string,string>
    */
   public private(set) array $extensions = [];


   public function __construct (null|Reply $Reply = null)
   {
      // ? HELO fallback — the server advertises nothing
      if ($Reply === null) {
         return;
      }

      // @@ Parse capability lines (line 0 is the server domain/greeting)
      foreach (array_slice($Reply->lines, 1) as $line) {
         $line = trim($line);

         // ? Skip blank capability lines
         if ($line === '') {
            continue;
         }

         // ! Split `KEYWORD[ parameters]`
         $space = strpos($line, ' ');
         if ($space === false) {
            $keyword = strtoupper($line);
            $parameters = '';
         }
         else {
            $keyword = strtoupper(substr($line, 0, $space));
            $parameters = trim(substr($line, $space + 1));
         }

         // ? Legacy `AUTH=MECH …` form — fold into the AUTH keyword
         if (strpos($keyword, 'AUTH=') === 0) {
            $mechanism = substr($keyword, 5);
            $parameters = $parameters === '' ? $mechanism : "{$mechanism} {$parameters}";
            $keyword = 'AUTH';
         }

         // @ Merge repeated keywords (servers may send AUTH and AUTH= lines)
         $existing = $this->extensions[$keyword] ?? null;
         $this->extensions[$keyword] = match (true) {
            $existing === null, $existing === '' => $parameters,
            $parameters === '' => $existing,
            default => "{$existing} {$parameters}"
         };
      }
   }

   /**
    * Whether the server advertised a capability (case-insensitive).
    */
   public function check (string $extension): bool
   {
      // :
      return isSet($this->extensions[strtoupper($extension)]);
   }

   /**
    * Parameter string of an advertised capability — '' when advertised
    * without parameters, null when not advertised at all.
    */
   public function fetch (string $extension): null|string
   {
      // :
      return $this->extensions[strtoupper($extension)] ?? null;
   }
}
