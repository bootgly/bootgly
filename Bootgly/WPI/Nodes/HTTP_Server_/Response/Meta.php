<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_\Response;


use Bootgly\WPI\Modules\HTTP;


class Meta
{
   // * Config
   // ...

   // * Data
   protected string $protocol;
   protected int|string $status;

   // * Metadata
   private string $raw;
   // @ status
   private int $code;
   private string $message;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      $this->protocol = 'HTTP/1.1';
      $this->status = '200 OK';

      // * Metadata
      $this->reset();
   }
   public function __get (string $name)
   {
      switch ($name) {
         // * Metadata
         // @ status
         case 'code':
            if ( isSet($this->code) && $this->code !== 0 ) {
               return $this->code;
            }

            $code = array_search($this->status, HTTP::RESPONSE_STATUS);

            $this->code = $code;

            break;

         default:
            return $this->$name;
      }
   }
   public function __set (string $name, $value)
   {
      switch ($name) {
         // * Data
         case 'protocol':
            break;
         case 'status':
            $status = match ($value) {
               (int) $value => $value . ' ' . HTTP::RESPONSE_STATUS[$value],
               (string) $value => array_search($value, HTTP::RESPONSE_STATUS) . ' ' . $value
            };

            @[$code, $message] = explode(' ', $status);

            if ($code && $message) {
               $this->status = $status;
               $this->reset();
            }

            break;
         // * Metadata
         case 'raw':
         // @ status
         case 'code':
         case 'message':
            break;

         default:
            $this->$name = $value;
      }
   }

   public function reset ()
   {
      // * Metadata
      // raw
      $this->raw = $this->protocol . ' ' . $this->status;
      // @ status
      // code
      unSet($this->code);
   }
}
