<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\Request\_;


class Content
{
   // * Config
   // -
   // * Data
   public string $raw;
   public string $input;
   // * Meta
   public ? int $length;
   public null|int|false $position;
   public ? int $downloaded;
   public bool $waiting;


   public function __construct ()
   {
      // * Config
      // -
      // * Data
      $this->raw = '';
      $this->input = '';
      // * Meta
      $this->length = null;
      $this->position = null;
      $this->downloaded = null;
      $this->waiting = false;


      if (\PHP_SAPI !== 'cli') {
         $this->input = file_get_contents('php://input');
      }
   }

   public function parse (string $content = 'raw', string $type) : bool|string
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
            switch ($type) {
               // @ Parse Raw - JSON
               case 'application/json':
                  $_POST = (array) json_decode($this->raw, true);
                  return true;
               // @ Parse Raw - URL Encoded (x-www-form-urlencoded), Text, etc.
               default:
                  parse_str($this->raw, $_POST);
                  return true;
            }
      }

      return false;
   }
}
