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


interface Response
{
   // ...Used to define and indentify subclasses (instance of).

   public function __construct (int $code = 200, ? array $headers = null, string $body = '');
   public function __invoke (int $code = 200, array $headers = [], string $body = '') : self;

   /**
    * Appends the provided data to the body of the response.
    *
    * @param mixed $body The data that should be appended to the response body.
    *
    * @return Response The Response instance, for chaining
    */
   public function append ($body);

   /**
    * Renders the specified view with the provided data.
    *
    * @param string $view The view to render.
    * @param array|null $data The data to provide to the view.
    * @param Closure|null $callback Optional callback.
    *
    * @return Response Returns Response
    */
   public function render (string $view, ? array $data = null, ? \Closure $callback = null) : self;
   /**
    * Send the response
    *
    * @param mixed|null $body The body of the response.
    * @param mixed ...$options Additional options for the response
    *
    * @return Response The Response instance, for chaining
    */
   public function send ($body = null, ...$options) : self;
   /**
    * Start a file upload from the Server to the Client
    *
    * @param string|File $file The file to be uploaded
    * 
    * @return Response The Response instance, for chaining
    */
   public function upload (string|File $file) : self;

   /**
    * Sets the authentication headers for basic authentication with 401 (Unauthorized) HTTP status code.
    *
    * @param string $realm The realm string to set in the WWW-Authenticate header. Default is "Protected area".
    *
    * @return Response Returns Response.
    */
   public function authenticate (string $realm = 'Protected area') : self;
   /**
    * Redirects to a new URI. Default return is 307 for GET (Temporary Redirect) and 303 (See Other) for POST.
    *
    * @param string $URI The new URI to redirect to.
    * @param ? int $code The HTTP status code to use for the redirection.
    *
    * @return Response Returns Response.
    */
   public function redirect (string $URI, ? int $code = null) : self;

   /**
    * Definitively terminates the HTTP Response.
    *
    * @param int|string|null $status The status of the response.
    *
    * @return void
    */
   public function end (int|string|null $status = null) : void;
}
