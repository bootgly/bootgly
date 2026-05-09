<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use const JSON_UNESCAPED_SLASHES;
use function json_encode;
use Closure;

use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Sources;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


class Validator implements Middleware
{
   // * Config
   /**
    * @var array<string,Validators|array<int,Validators>>
    */
   public private(set) array $rules;
   public private(set) Sources $Source;
   public private(set) int $code;
   /**
    * @var null|Closure(Request,Response,Validation):object
    */
   public private(set) null|Closure $fallback;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param array<string,Validators|array<int,Validators>> $rules
    * @param null|Closure(Request,Response,Validation):object $fallback
    */
   public function __construct (
      array $rules,
      Sources $Source,
      int $code = 422,
      null|Closure $fallback = null,
   )
   {
      // * Config
      $this->rules = $rules;
      $this->Source = $Source;
      $this->code = $code;
      $this->fallback = $fallback;
   }

   /**
    * @param Request $Request
    * @param Response $Response
    */
   public function process (object $Request, object $Response, Closure $next): object
   {
      // @ Validate before the route handler.
      $source = match ($this->Source) {
         Sources::Cookies => $Request->cookies,
         Sources::Fields => $Request->fields,
         Sources::Files => $Request->files,
         Sources::Headers => $Request->headers,
         Sources::Queries => $Request->queries,
      };

      $Validation = new Validation($source, $this->rules);

      // ? Valid request — continue pipeline.
      if ($Validation->valid) {
         return $next($Request, $Response);
      }

      // ?: Custom fallback response.
      if ($this->fallback !== null) {
         return ($this->fallback)($Request, $Response, $Validation);
      }

      // @ Fail closed with deterministic JSON errors.
      $body = json_encode(
         value: ['errors' => $Validation->errors],
         flags: JSON_UNESCAPED_SLASHES
      );

      if ($body === false) {
         $body = '{"errors":[]}';
      }

      // :
      return $Response(
         code: $this->code,
         headers: ['Content-Type' => 'application/json'],
         body: $body
      );
   }
}
