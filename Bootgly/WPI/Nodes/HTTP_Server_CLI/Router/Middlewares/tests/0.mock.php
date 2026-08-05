<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
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
      // Mirrors Response\Raw\Header::$prepared — the store Response::__invoke()
      // writes into; get() falls through it exactly as the real read side does
      /** @var array<string, string> */
      private array $prepared = [];
      /** @param array<string, string> $headers */
      public function __construct (array &$headers)
      {
         $this->headers = &$headers;
      }

      public function get (string $name): string|null
      {
         return $this->headers[$name] ?? $this->prepared[$name] ?? null;
      }
      public function set (string $name, string $value): void
      {
         $this->headers[$name] = $value;
      }
      // Mirrors Response\Raw\Header::prepare() — replaces the prepared set
      /** @param array<string, string> $fields */
      public function prepare (array $fields): void
      {
         $this->prepared = $fields;
      }
      public function append (string $name, string $value = '', string|null $separator = ', '): void
      {
         $separator ??= ', ';

         if (isSet($this->headers[$name])) {
            $this->headers[$name] .= $separator . $value;
         } else {
            $this->headers[$name] = $value;
         }
      }
      // Mirrors Response\Raw\Header::vary() — token-aware Vary merge
      public function vary (string $name): void
      {
         $current = $this->headers['Vary'] ?? '';

         if (\trim($current) === '') {
            $this->headers['Vary'] = $name;

            return;
         }

         foreach (\explode(',', $current) as $token) {
            $token = \trim($token);

            if ($token === '*' || \strcasecmp($token, $name) === 0) {
               return;
            }
         }

         $this->headers['Vary'] = "{$current}, {$name}";
      }
   };

   // ! Request mock
   $Request = new \stdClass;
   $Request->Header = $RequestHeader;
   // @ Default properties
   $Request->method = 'GET';
   $Request->address = '127.0.0.1';
   $Request->peer = '127.0.0.1'; // immutable transport peer (audit F-3)
   $Request->input = '';
   // @ Always present on the real Request (a declared property with hooks), so
   //   a middleware reading it — CSRF looks for its form field here — must not
   //   depend on the caller having passed one in.
   $Request->fields = [];
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
         if ($headers !== null && $headers !== []) {
            // Mirrors the real Response::__invoke(): headers route into
            // Header::prepare(), never into set() — the write path whose
            // invisibility to get() was RES-7
            $this->Header->prepare($headers);
         }
         $this->Body->raw = $body;
         return $this;
      }

      public function authenticate (object $Method): static
      {
         $this->code = 401;

         if ($Method instanceof \Bootgly\WPI\Modules\HTTP\Server\Response\Authentication\Basic) {
            $this->Header->set('WWW-Authenticate', "Basic realm=\"{$Method->realm}\"");
         }

         return $this;
      }
   };

   // :
   return [$Request, $Response];
};
