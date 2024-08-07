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


class Body
{
   // * Config
   // ...

   // * Data
   public string $raw;
   public ? string $input;

   // * Metadata
   public ? int $length;
   public null|int|false $position;
   public ? int $downloaded;
   public bool $waiting;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      $this->raw = '';
      $this->input = null;

      // * Metadata
      $this->length = null;
      $this->position = null;
      $this->downloaded = null;
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
                  // TODO implement json_validate (PHP 8.3)

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
