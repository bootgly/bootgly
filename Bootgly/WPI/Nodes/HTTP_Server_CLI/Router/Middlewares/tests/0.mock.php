<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/**
 * Mock factory for HTTP middleware unit tests.
 *
 * @return Closure(array<string, string>, array<string, mixed>): array{object, object}
 */
return function (
   array $requestHeaders = [],
   array $requestProps = []
): array {
   // ! Request Header mock
   $RequestHeader = new class ($requestHeaders) {
      /** @var array<string, string> */
      private array $headers;
      /** @param array<string, string> $headers */
      public function __construct (array $headers)
      {
         $this->headers = $headers;
      }

      public function get (string $name): string|null
      {
         return $this->headers[$name] ?? null;
      }
   };

   // ! Response Header mock
   $responseHeaders = [];
   $ResponseHeader = new class ($responseHeaders) {
      /** @var array<string, string> */
      private array $headers;
      /** @param array<string, string> $headers */
      public function __construct (array &$headers)
      {
         $this->headers = &$headers;
      }

      public function get (string $name): string|null
      {
         return $this->headers[$name] ?? null;
      }
      public function set (string $name, string $value): void
      {
         $this->headers[$name] = $value;
      }
   };

   // ! Request mock
   $Request = new \stdClass;
   $Request->Header = $RequestHeader;
   // @ Default properties
   $Request->method = 'GET';
   $Request->address = '127.0.0.1';
   $Request->input = '';
   $Request->scheme = 'http';
   // @ Override with provided values
   foreach ($requestProps as $prop => $value) {
      $Request->{$prop} = $value;
   }

   // ! Response mock (invocable)
   $Response = new class ($ResponseHeader) {
      public object $Header;
      public object $Body;
      public int $code = 200;

      public function __construct (object $Header)
      {
         $this->Header = $Header;
         $this->Body = (object) ['raw' => ''];
      }
      /** @param array<string, string>|null $headers */
      public function __invoke (int $code = 200, array|null $headers = null, string $body = ''): static
      {
         $this->code = $code;
         $this->Body->raw = $body;
         return $this;
      }
   };

   // :
   return [$Request, $Response];
};
