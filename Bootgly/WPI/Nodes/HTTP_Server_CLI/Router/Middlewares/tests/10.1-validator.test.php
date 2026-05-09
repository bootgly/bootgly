<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Email;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Required;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Sources;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator;


return new Specification(
   description: 'It should fail closed when request validation fails',
   test: new Assertions(Case: function (): Generator {
      // ! Request mock with source arrays.
      $createRequest = function (array $source): object {
         return new class ($source) {
            /** @var array<string,mixed> */
            public array $cookies;
            /** @var array<string,mixed> */
            public array $fields;
            /** @var array<string,mixed> */
            public array $files;
            /** @var array<string,mixed> */
            public array $headers;
            /** @var array<string,mixed> */
            public array $queries;

            /** @param array<string,mixed> $source */
            public function __construct (array $source)
            {
               $this->cookies = $source;
               $this->fields = $source;
               $this->files = $source;
               $this->headers = $source;
               $this->queries = $source;
            }
         };
      };

      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         $Response->Body->raw = 'passed';
         return $Response;
      };

      // @ Valid request passes through.
      $Request = $createRequest(['email' => 'user@example.com']);
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['email' => [new Required, new Email]],
         Source: Sources::Fields
      );
      $Result = $Validator->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Valid request should pass through')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      yield new Assertion(description: 'Valid request should keep status 200')
         ->expect($Result->code)
         ->to->be(200)
         ->assert();

      // @ Invalid request short-circuits with 422.
      $called = false;
      $Request = $createRequest(['email' => 'invalid']);
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['email' => [new Required, new Email]],
         Source: Sources::Fields
      );
      $Result = $Validator->process(
         $Request,
         $Response,
         function (object $Request, object $Response) use (&$called): object {
            $called = true;
            return $Response;
         }
      );

      yield new Assertion(description: 'Invalid request should return configured status')
         ->expect($Result->code)
         ->to->be(422)
         ->assert();

      yield new Assertion(description: 'Invalid request should not call handler')
         ->expect($called)
         ->to->be(false)
         ->assert();

      yield new Assertion(description: 'Invalid request should expose JSON errors')
         ->expect(str_contains($Result->Body->raw, 'email must be a valid email address.'))
         ->to->be(true)
         ->assert();

      // @ Invalid request may use custom fallback.
      $Request = $createRequest(['email' => 'invalid']);
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['email' => [new Required, new Email]],
         Source: Sources::Fields,
         fallback: function (object $Request, object $Response, Validation $Validation): object {
            $Response->code = 409;
            $Response->Body->raw = $Validation->errors['email'][0] ?? 'fallback';

            return $Response;
         }
      );
      $Result = $Validator->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Invalid request should support custom fallback status')
         ->expect($Result->code)
         ->to->be(409)
         ->assert();

      yield new Assertion(description: 'Invalid request should support custom fallback body')
         ->expect($Result->Body->raw)
         ->to->be('email must be a valid email address.')
         ->assert();

      // @ Source option is used.
      $Request = $createRequest(['X-Token' => '']);
      $Request->headers = ['X-Token' => 'secret'];
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['X-Token' => [new Required]],
         Source: Sources::Headers
      );
      $Result = $Validator->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Validator should use configured source')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      // @ Files source is explicit too.
      $Request = $createRequest(['avatar' => ['name' => 'bootgly.png']]);
      $Request->fields = [];
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['avatar' => [new Required]],
         Source: Sources::Files
      );
      $Result = $Validator->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Validator should support file source')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      // @ Error status is configurable.
      $Request = $createRequest(['X-Token' => '']);
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['X-Token' => [new Required]],
         Source: Sources::Fields,
         code: 400
      );
      $Result = $Validator->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Validator should reject configured source')
         ->expect($Result->Body->raw !== 'passed')
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'Validator should support configured error status')
         ->expect($Result->code)
         ->to->be(400)
         ->assert();
   })
);
