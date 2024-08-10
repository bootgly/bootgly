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


use function trim;
use function preg_match;
use function parse_str;
use function json_validate;

use Bootgly\WPI\Modules\HTTP\Server\Request\Raw;


class Body extends Raw\Body
{
   // * Config
   // ... inherited

   // * Data
   // ... inherited

   // * Metadata
   // ... inherited
   public bool $waiting;


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
   }

   public function parse (string $content, string $type): bool|string
   {
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

                  $_POST = $this->raw;

                  return true;
               // @ Parse raw - URL Encoded
               case 'application/x-www-form-urlencoded':
                  parse_str($this->raw, $_POST);

                  return true;
            }
      }

      return false;
   }
}
