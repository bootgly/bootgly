<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI;


use function is_array;
use function is_string;
use Closure;

use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoder;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_;


class Request
{
   public protected(set) Header $Header;
   public protected(set) Body $Body;


   // * Config
   // ...

   // * Data
   // | HTTP Request
   public string $method;
   public string $URI;
   public string $protocol;
   /**
    * @var array<string,string|array<int,string>>
    */
   public array $headers {
      get => $this->Header->fields;
   }
   public string $body {
      get => $this->Body->raw;
   }
   // | Transport
   public Response $Response;
   /** @var Decoder */
   public Decoder $Decoder;

   // * Metadata
   // | Transport
   public string $pendingBuffer;
   /** Connection state: 'idle' | 'waiting' | 'waiting-100-continue' | 'redirect' */
   public string $connectionState;
   public bool $completed;
   public int $bytesReceived;
   public null|Closure $onComplete;
   // | Redirect
   public int $redirectCount;
   public string $originalMethod;
   public string $originalBody;
   /** @var array{host:string,port:int,path:string,ssl:bool}|null */
   public null|array $redirectTarget;
   // | Timeout
   public float $sentAt;
   // | Retry
   public int $retryCount;


   public function __construct ()
   {
      $this->Header = new Header;
      $this->Body = new Body;

      // * Config
      // ...

      // * Data
      $this->method = 'GET';
      $this->URI = '/';
      $this->protocol = 'HTTP/1.1';

      // | Transport
      $this->Response = new Response;
      $this->Decoder = new Decoder_;

      // * Metadata
      $this->pendingBuffer = '';
      $this->connectionState = 'idle';
      $this->completed = false;
      $this->bytesReceived = 0;
      $this->onComplete = null;
      // | Redirect
      $this->redirectCount = 0;
      $this->originalMethod = '';
      $this->originalBody = '';
      $this->redirectTarget = null;
      // | Timeout
      $this->sentAt = 0.0;
      // | Retry
      $this->retryCount = 0;
   }

   /**
    * Prepare the Request with method, URI, and optional headers/body.
    *
    * @param string $method HTTP method (GET, POST, PUT, DELETE, PATCH, HEAD, OPTIONS).
    * @param string $URI Request URI (path + optional query string).
    * @param array<string,string> $headers Additional headers to set.
    * @param mixed $body Request body (string, array for JSON, or null).
    *
    * @return self
    */
   public function __invoke (
      string $method = 'GET',
      string $URI = '/',
      array $headers = [],
      mixed $body = null
   ): self
   {
      $this->method = $method;
      $this->URI = $URI;

      // @ Set headers
      foreach ($headers as $name => $value) {
         $this->Header->set($name, $value);
      }

      // @ Set body
      if ($body !== null) {
         if (is_string($body)) {
            $this->Body->encode($body);
            if ($this->Header->get('Content-Type') === null) {
               $this->Header->set('Content-Type', 'text/plain');
            }
         }
         else if (is_array($body)) {
            $this->Body->encode($body, 'json');
            if ($this->Header->get('Content-Type') === null) {
               $this->Header->set('Content-Type', 'application/json');
            }
         }

         $this->Header->set('Content-Length', (string) $this->Body->length);
      }

      return $this;
   }

   /**
    * Reset request state for reuse.
    *
    * @return void
    */
   public function reset (): void
   {
      $this->Header = new Header;
      $this->Body = new Body;

      $this->method = 'GET';
      $this->URI = '/';

      // | Transport
      $this->Response->reset();
      $this->Decoder = new Decoder_;

      $this->pendingBuffer = '';
      $this->connectionState = 'idle';
      $this->completed = false;
      $this->bytesReceived = 0;
      $this->onComplete = null;
      // | Redirect
      $this->redirectCount = 0;
      $this->originalMethod = '';
      $this->originalBody = '';
      $this->redirectTarget = null;
      // | Timeout
      $this->sentAt = 0.0;
      // | Retry
      $this->retryCount = 0;
   }

   /**
    * Clear request headers and body (used for redirect method change).
    *
    * @return void
    */
   public function clear (): void
   {
      $this->Header = new Header;
      $this->Body = new Body;
   }
}
