<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\HTTP\Server\Response;


use Bootgly\CLI\HTTP\Server;


class Meta
{
   // * Config
   // ...

   // * Data
   protected string $protocol;
   protected int|string $status;

   // * Meta
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

      // * Meta
      $this->reset();
   }
   public function __get (string $name)
   {
      switch ($name) {
         // * Meta
         // @ status
         case 'code':
            if ( isSet($this->code) && $this->code !== 0 ) {
               return $this->code;
            }

            $code = array_search($this->status, Server::RESPONSE_STATUS);

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
               (int) $value => $value . ' ' . Server::RESPONSE_STATUS[$value],
               (string) $value => array_search($value, Server::RESPONSE_STATUS) . ' ' . $value,
               default => ''
            };

            @[$code, $message] = explode(' ', $status);

            if ($code && $message) {
               $this->status = $status;
               $this->reset();
            }

            break;
         // * Meta
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
      // * Meta
      // raw
      $this->raw = $this->protocol . ' ' . $this->status;
      // @ status
      // code
      unSet($this->code);
   }
}
