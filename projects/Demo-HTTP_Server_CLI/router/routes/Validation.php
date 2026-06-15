<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Bootgly\WPI;


use function is_string;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Email;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Extension;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Integer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Maximum;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\MIME;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Minimum;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Regex;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Required;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Size;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Sources;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\BodyParser;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator;


return static function (Request $Request, Response $Response, Router $Router)
{
   // ! Input Validation examples
   // # Custom rule object available to middleware rules.
   $Custom = new class extends Validators {
      /**
       * @param array<string,mixed> $data
       */
      public function validate (string $field, mixed $value, array $data): bool
      {
         return is_string($value) && $value === 'bootgly';
      }

      public function format (string $field): string
      {
         return "{$field} must match the demo invite code.";
      }
   };

   // # Overview
   yield $Router->route('/validation', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'plain' => [
            'method' => 'POST',
            'path' => '/validation/plain',
            'body' => [
               'email' => 'user@example.com',
               'age' => '18',
            ],
         ],
         'middleware' => [
            'method' => 'POST',
            'path' => '/validation/middleware',
            'body' => [
               'email' => 'user@example.com',
               'age' => '18',
            ],
         ],
         'query' => [
            'method' => 'GET',
            'path' => '/validation/query?page=1&filter=active',
         ],
         'files' => [
            'method' => 'POST',
            'path' => '/validation/files',
            'body' => 'multipart/form-data with avatar file',
         ],
         'fallback' => [
            'method' => 'POST',
            'path' => '/validation/fallback',
            'body' => [
               'email' => 'invalid',
            ],
         ],
         'custom' => [
            'method' => 'POST',
            'path' => '/validation/custom',
            'body' => [
               'code' => 'bootgly',
            ],
         ],
      ]);
   }, GET);

   // # No validation — data access still uses the regular Request source.
   yield $Router->route('/validation/plain', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'validated' => false,
         'fields' => $Request->fields,
      ]);
   }, POST, middlewares: [new BodyParser]);

   // # Fail-closed validation — middleware rejects before the handler.
   yield $Router->route('/validation/middleware', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'created' => true,
         'fields' => $Request->fields,
      ]);
   }, POST, middlewares: [
      new BodyParser,
      new Validator(rules: [
         'email' => [new Required, new Email],
         'age' => [new Required, new Integer, new Minimum(18), new Maximum(120)],
      ], Source: Sources::Fields),
   ]);

   // # Custom fallback — route stays fail-closed, but failure response is customized.
   yield $Router->route('/validation/fallback', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'created' => true,
         'fields' => $Request->fields,
      ]);
   }, POST, middlewares: [
      new BodyParser,
      new Validator(
         rules: [
            'email' => [new Required, new Email],
         ],
         Source: Sources::Fields,
         fallback: function (Request $Request, Response $Response, Validation $Validation): Response {
            $Response->code(400);

            return $Response->JSON->send([
               'created' => false,
               'fields' => $Request->fields,
               'errors' => $Validation->errors,
            ]);
         }
      ),
   ]);

   // # Query validation — middleware validates query-string data instead of body fields.
   yield $Router->route('/validation/query', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'filtered' => true,
         'queries' => $Request->queries,
      ]);
   }, GET, middlewares: [
      new Validator(rules: [
         'page' => [new Integer, new Minimum(1)],
         'filter' => [new Regex('/\A[a-z0-9_-]+\z/')],
      ], Source: Sources::Queries),
   ]);

   // # File validation — middleware validates uploaded files explicitly.
   yield $Router->route('/validation/files', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'uploaded' => true,
         'files' => $Request->files,
      ]);
   }, POST, middlewares: [
      new BodyParser,
      new Validator(rules: [
         'avatar' => [
            new Required,
            new Size(2 * 1024 * 1024),
            new MIME(['image/jpeg', 'image/png']),
            new Extension(['jpg', 'jpeg', 'png']),
         ],
      ], Source: Sources::Files),
   ]);

   // # Custom rule — middleware can receive custom Condition objects too.
   yield $Router->route('/validation/custom', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'accepted' => true,
         'fields' => $Request->fields,
      ]);
   }, POST, middlewares: [
      new BodyParser,
      new Validator(rules: [
         'code' => [new Required, $Custom],
      ], Source: Sources::Fields),
   ]);
};
