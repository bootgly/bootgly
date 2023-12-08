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
   protected string $status;

   // * Metadata
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

      // * Metadata
      $this->raw = 'HTTP/1.1 200 OK';
      // @ Status
      $this->code = 200;
      $this->message = 'OK';
   }
   public function __get (string $name)
   {
      switch ($name) {
         // * Data
         case 'protocol': return $this->protocol;
         case 'status': return $this->status;

         // * Metadata
         case 'raw': return $this->raw;
         // @ Status
         case 'code': return $this->code;
         case 'message': return $this->message;

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
               // * Data
               $this->status = $status;
               // * Metadata
               $this->raw = $this->protocol . ' ' . $status;
               // @ Status
               $this->code = $code;
               $this->message = $message;
            }

            break;

         // * Metadata
         case 'raw':
         // @ Status
         case 'code':
            $code = (int) $value;
            $message = HTTP::RESPONSE_STATUS[$code];

            // * Metadata
            $this->raw = <<<RAW
            {$this->protocol} {$code} {$message}
            RAW;
            // @ Status
            $this->code = $code;
            $this->message = $message;

            break;
         case 'message':
            break;

         default:
            null;
      }
   }
}
