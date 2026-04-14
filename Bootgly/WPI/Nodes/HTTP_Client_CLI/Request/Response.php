<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Raw\Header;


class Response
{
   public protected(set) Header $Header;
   public protected(set) Body $Body;


   // * Config
   // ...

   // * Data
   // | HTTP Response
   public string $protocol;
   public int $code;
   public string $status;
   /**
    * @var array<string,string|array<int,string>>
    */
   public array $headers {
      get => $this->Header->fields;
   }
   public string $body {
      get => $this->Body->raw;
   }

   // * Metadata
   public bool $closeConnection;


   public function __construct ()
   {
      $this->Header = new Header;
      $this->Body = new Body;

      // * Config
      // ...

      // * Data
      $this->protocol = 'HTTP/1.1';
      $this->code = 0;
      $this->status = '';

      // * Metadata
      $this->closeConnection = false;
   }

   /**
    * Reset response state for reuse.
    *
    * @return void
    */
   public function reset (): void
   {
      $this->Header = new Header;
      $this->Body = new Body;

      $this->protocol = 'HTTP/1.1';
      $this->code = 0;
      $this->status = '';
      $this->closeConnection = false;
   }

   /**
    * Clone support.
    */
   public function __clone ()
   {
      $this->Header = clone $this->Header;
      $this->Body = clone $this->Body;
   }
}
