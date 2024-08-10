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

use Bootgly\WPI\Modules\HTTP\Server\Response\Authenticable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Bootable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Extendable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Redirectable;
use Bootgly\WPI\Modules\HTTP\Server\Response\Renderable;


abstract class Response
{
   use Authenticable;
   use Bootable;
   use Extendable;
   use Redirectable;
   use Renderable;


   const PROTOCOL = 'HTTP/1.1';

   // * Config
   // ...

   // * Data
   // @ Content
   /** @var null|string|array<mixed> */
   protected null|string|array $content = '';
   protected File $File;
   /** @var array<mixed> */
   protected array $files = [];
   // @ status
   protected int $code = 200;

   // * Metadata
   // @ status
   protected string $message = 'OK';
   protected string $status = '200 OK';
   protected string $response = self::PROTOCOL . ' 200 OK';

   /**
    * Construct a new Response instance.
    *
    * @param int $code The status code of the response.
    * @param array<string>|null $headers The headers of the response.
    * @param string $body The body of the response.
    */
   abstract public function __construct (int $code = 200, ? array $headers = null, string $body = '');
   /**
    * Prepare the response for sending.
    *
    * @param int $code The status code of the response.
    * @param array<string> $headers The headers of the response.
    * @param string $body The body of the response.
    *
    * @return self The Response instance, for chaining 
    */
   abstract public function __invoke (int $code = 200, array $headers = [], string $body = ''): self;

   /**
    * Get the specified property from the Response or Response Resource.
    *
    * @param string $name The name of the property or Response Resource to get.
    *
    * @return bool|string|int|array<mixed>|self The value of the property or the Response instance, for chaining.
    */
   abstract public function __get (string $name): bool|string|int|array|self;
   /**
    * Set the HTTP Server Response code.
    *
    * @param int $code 
    *
    * @return self The Response instance, for chaining 
    */
   abstract public function code (int $code): self;
   /**
    * Send the response
    *
    * @param mixed|null $body The body of the response.
    * @param mixed ...$options Additional options for the response
    *
    * @return self The Response instance, for chaining
    */
   abstract public function send ($body = null, ...$options): self;
   /**
    * Start a file upload from the Server to the Client
    *
    * @param string|File $file The file to be uploaded
    * @param int $offset The data offset.
    * @param int|null $length The length of the data to upload.
    * 
    * @return self The Response instance, for chaining
    */
   abstract public function upload (string|File $file, int $offset = 0, ? int $length = null): self;
}
