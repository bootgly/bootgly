<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server;


use Bootgly\ABI\IO\FS\File;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Modules\HTTP\Server\Response\Authentication;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw;


abstract class Response extends Raw
{
   const PROTOCOL = 'HTTP/1.1';

   // * Config
   // ...

   // * Data
   // @ Content
   protected null|string|array $content = '';
   protected File $File;
   protected array $files = [];
   // @ status
   protected int $code = 200;

   // * Metadata
   // @ status
   protected string $message = 'OK';
   protected string $status = '200 OK';
   protected string $response = self::PROTOCOL . ' 200 OK';


   abstract public function __construct (int $code = 200, ? array $headers = null, string $body = '');
   abstract public function __invoke (int $code = 200, array $headers = [], string $body = '') : self;

   /**
    * Appends the provided data to the body of the response.
    *
    * @param mixed $body The data that should be appended to the response body.
    *
    * @return self The Response instance, for chaining
    */
   abstract public function append ($body) : self;
   /**
    * Set the HTTP Server Response code.
    *
    * @param int $code 
    *
    * @return self The Response instance, for chaining 
    */
   public function code (int $code): self
   {
      // * Data
      // @ status
      $this->code = $code;

      $status = $code . ' ' . HTTP::RESPONSE_STATUS[$code];

      @[$code, $message] = explode(' ', $status);

      if ($code && $message) {
         // * Metadata
         // @ status
         $this->message = $message;
         $this->status = $status;
         $this->response = self::PROTOCOL . ' ' . $status;
      }

      return $this;
   }
   /**
    * Renders the specified view with the provided data.
    *
    * @param string $view The view to render.
    * @param array|null $data The data to provide to the view.
    * @param \Closure|null $callback Optional callback.
    *
    * @return self Returns Response
    */
   abstract public function render (string $view, ? array $data = null, ? \Closure $callback = null) : self;
   /**
    * Send the response
    *
    * @param mixed|null $body The body of the response.
    * @param mixed ...$options Additional options for the response
    *
    * @return self The Response instance, for chaining
    */
   abstract public function send ($body = null, ...$options) : self;
   /**
    * Start a file upload from the Server to the Client
    *
    * @param string|File $file The file to be uploaded
    * @param int $offset The data offset.
    * @param int|null $length The length of the data to upload.
    * 
    * @return self The Response instance, for chaining
    */
   abstract public function upload (string|File $file, int $offset = 0, ? int $length = null) : self;

   /**
    * Authenticate the user with the provided authentication method.
    *
    * @param Authentication $Method The authentication method to use.
    *
    * @return self The Response instance, for chaining
    */
   abstract public function authenticate (Authentication $Method) : self;

   /**
    * Redirects to a new URI. Default return is 307 for GET (Temporary Redirect) and 303 (See Other) for POST.
    *
    * @param string $URI The new URI to redirect to.
    * @param ? int $code The HTTP status code to use for the redirection.
    *
    * @return self Returns Response.
    */
   abstract public function redirect (string $URI, ? int $code = null) : self;
}
