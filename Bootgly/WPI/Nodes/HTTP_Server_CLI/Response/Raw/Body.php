<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw;


class Body
{
   // * Config
   // ...

   // * Data
   public string $raw;

   // * Metadata
   public int $length;
   // TODO move
   public static array $mimes; // @ 'html' => 'text/html'


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      $this->raw = '';

      // * Metadata
      $this->length = 0;
      // mimes
      /*
      $items = file(__DIR__ . '/resources/mime.types', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

      foreach ($items as $content) {
         if ( preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match) ) {
            $type       = $match[1];
            $extensions = explode(' ', substr($match[2], 0, -1));

            foreach ($extensions as $extension) {
               static::$mimes[$extension] = $type;
            }
         }
      }
      */
   }

   public function __get (string $name) : string
   {
      switch ($name) {
         case 'chunked':
            return \dechex($this->length) . "\r\n$this->raw\r\n";

         default:
            return '';
      }
   }
}
