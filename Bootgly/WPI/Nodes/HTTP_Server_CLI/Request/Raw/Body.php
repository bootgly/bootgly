<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw;


use function json_validate;
use function preg_match;
use function trim;


class Body
{
   // * Config
   // ...

   // * Data
   public string $raw;
   public string|null $input;

   // * Metadata
   public int|null $length;
   public int|null $position;
   public int|null $downloaded;
   public bool $waiting;
   public bool $streaming;


   public function __construct ()
   {
      // * Config
      // ... inherited

      // * Data
      $this->raw = '';
      $this->input = null;

      // * Metadata
      $this->length = null;
      $this->position = null;
      $this->downloaded = null;
      // ---
      $this->waiting = false;
      $this->streaming = false;
   }

   /**
    * Assume the decoded body of a cached template Body.
    *
    * Used by `Request::assume()` on the per-connection cache-hit path:
    * unconditional straight-line overwrite of every member, so no body state
    * from the previous request on this connection can survive. String
    * assignments are COW — the template stays untouched by later mutations.
    */
   public function assume (self $Template): void
   {
      // * Data
      $this->raw = $Template->raw;
      $this->input = $Template->input;

      // * Metadata
      $this->length = $Template->length;
      $this->position = $Template->position;
      $this->downloaded = $Template->downloaded;
      $this->waiting = $Template->waiting;
      $this->streaming = $Template->streaming;
   }

   public function parse (string $content, ?string $type): bool|string
   {
      if ($type === null) {
         return false;
      }

      switch ($content) {
         case 'Form-data':
            // @ Parse Form-data (boundary)
            $matched = preg_match('/boundary="?(\S+)"?/', $type, $match);

            if ($matched === 1) {
               $boundary = trim('--' . $match[1], '"');

               return $boundary;
            }

            return false;
         case 'raw':
            // @ Check if Body downloaded length is minor than Body length
            if ($this->downloaded < $this->length) {
               $this->waiting = true;
               return false;
            }

            $this->waiting = false;

            $this->input = $this->raw;

            switch ($type) {
               // @ Parse raw - JSON
               case 'application/json':
                  if (json_validate($this->raw) === false) {
                     return false;
                  }

                  return true;
               // @ Parse raw - URL Encoded
               case 'application/x-www-form-urlencoded':
                  return true;
            }
      }

      return false;
   }
}
