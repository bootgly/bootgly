<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;


use Bootgly\WPI\Modules\HTTP;


class Meta
{
   // * Config
   // ...

   // * Data
   protected string $protocol;
   protected int|string $status;

   // * Meta
   private string $raw;
   // @ Status
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
      // ...

      // @
      // raw
      $this->raw = $this->protocol . ' ' . $this->status;
      // @ status
      // code
      unset($this->code);
   }
   public function __get (string $name)
   {
      switch ($name) {
         // * Data
         case 'protocol': return $this->protocol;
         case 'status': return $this->status;

         // * Meta
         case 'raw': return $this->raw;
         // @ Status
         case 'code':
            if ( isSet($this->code) && $this->code !== 0 ) {
               return $this->code;
            }

            #$code = \array_search($this->status, HTTP::RESPONSE_STATUS);
            @[$code, $message] = explode(' ', $this->status);

            $this->code = (int) $code;

            break;
         case 'message':
            if (isset($this->message) && $this->message !== '') {
               return $this->message;
            }

            @[$code, $message] = explode(' ', $this->status);

            $this->message = $message;

            break;

         default:
            return null;
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
               (string) $value => \array_search($value, HTTP::RESPONSE_STATUS) . ' ' . $value,
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
         // @ Status
         case 'code':
         case 'message':
            break;

         default:
            null;
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
