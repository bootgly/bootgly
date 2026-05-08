<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fixtures;


use function strlen;

use Bootgly\ACI\Tests\Fixture;


/**
 * Builder fixture — composes raw HTTP/1.1 request bytes for E2E tests.
 *
 * Methods are single-word verbs (Bootgly naming rule).
 *
 *   $Req = new Request();
 *   $bytes = $Req
 *      ->update(
 *         method: 'POST',
 *         path: '/login',
 *         headers: ['Authorization' => 'Bearer abc123'],
 *         body: '{"u":"a"}'
 *      )
 *      ->render();
 */
final class Request extends Fixture
{
   public string $method = 'GET';
   public string $path = '/';
   public string $version = '1.1';
   public string $host = 'localhost';
   /**
    * @var array<string, string>
    */
   public array $headers = [];
   public string $body = '';


   /**
    * @param null|array<string, string> $headers
    */
   public function update (
      null|string $method = null,
      null|string $path = null,
      null|string $host = null,
      null|array $headers = null,
      null|string $body = null,
      null|string $version = null,
   ): self
   {
      if ($method !== null) {
         $this->method = $method;
      }
      if ($path !== null) {
         $this->path = $path;
      }
      if ($host !== null) {
         $this->host = $host;
      }
      if ($headers !== null) {
         foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
         }
      }
      if ($body !== null) {
         $this->body = $body;
      }
      if ($version !== null) {
         $this->version = $version;
      }

      return $this;
   }

   /**
    * Render the configured request as raw HTTP/1.1 bytes.
    */
   public function render (): string
   {
      $head = "{$this->method} {$this->path} HTTP/{$this->version}\r\n";
      $head .= "Host: {$this->host}\r\n";

      $headers = $this->headers;
      if ($this->body !== '' && ! isset($headers['Content-Length'])) {
         $headers['Content-Length'] = (string) strlen($this->body);
      }

      foreach ($headers as $name => $value) {
         $head .= "{$name}: {$value}\r\n";
      }

      return $head . "\r\n" . $this->body;
   }
}
