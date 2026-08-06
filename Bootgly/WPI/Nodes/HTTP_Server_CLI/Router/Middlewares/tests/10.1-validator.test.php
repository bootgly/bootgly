<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Validation;
use Bootgly\ADI\Validators\Confirmed;
use Bootgly\ADI\Validators\Email;
use Bootgly\ADI\Validators\In;
use Bootgly\ADI\Validators\Required;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator\Sources;


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

      // @ Source option is used — the parser lowercases header field names
      //   (RFC 9110 §5.1), while rules keep the user's canonical casing.
      $Request = $createRequest(['X-Token' => '']);
      $Request->headers = ['x-token' => 'secret'];
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

      // @ A canonically-cased non-implicit rule must bind to the lowercased
      //   wire field — an out-of-list value fails closed, not silently open.
      $called = false;
      $Request = $createRequest([]);
      $Request->headers = ['x-api-version' => 'DROP TABLE users'];
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['X-API-Version' => [new In(['2024-01', '2025-06'])]],
         Source: Sources::Headers
      );
      $Result = $Validator->process(
         $Request,
         $Response,
         function (object $Request, object $Response) use (&$called): object {
            $called = true;
            return $Response;
         }
      );

      yield new Assertion(description: 'Header rule should fire on an out-of-list value')
         ->expect($Result->code)
         ->to->be(422)
         ->assert();

      yield new Assertion(description: 'Header rule failure should not call handler')
         ->expect($called)
         ->to->be(false)
         ->assert();

      yield new Assertion(description: 'Header errors should keep the canonical rule key')
         ->expect(str_contains($Result->Body->raw, '"X-API-Version"'))
         ->to->be(true)
         ->assert();

      // @ An absent header still fails Required — folding never invents keys.
      $Request = $createRequest([]);
      $Request->headers = [];
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['X-API-Key' => [new Required]],
         Source: Sources::Headers
      );
      $Result = $Validator->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Absent header should still fail Required')
         ->expect($Result->code)
         ->to->be(422)
         ->assert();

      yield new Assertion(description: 'Absent header error should keep the canonical rule key')
         ->expect(str_contains($Result->Body->raw, '"X-API-Key"'))
         ->to->be(true)
         ->assert();

      // @ Lowercase rule keys keep working unchanged.
      $Request = $createRequest([]);
      $Request->headers = ['x-token' => 'secret'];
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['x-token' => [new Required]],
         Source: Sources::Headers
      );
      $Result = $Validator->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Lowercase header rule keys should keep working')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      // @ Confirmed resolves its companion field in the same folded space.
      $Request = $createRequest([]);
      $Request->headers = ['x-token' => 'secret', 'x-token-repeat' => 'secret'];
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['X-Token' => [new Confirmed(field: 'X-Token-Repeat')]],
         Source: Sources::Headers
      );
      $Result = $Validator->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Confirmed should bind canonical companion headers')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      // @ Confirmed derives its default "_confirmation" companion in the
      //   same folded space — pins fold() and Confirmed::validate() lockstep.
      $Request = $createRequest([]);
      $Request->headers = ['x-token' => 'secret', 'x-token_confirmation' => 'secret'];
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['X-Token' => [new Confirmed]],
         Source: Sources::Headers
      );
      $Result = $Validator->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Confirmed should derive its default companion in the folded space')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      // @ Malformed rule shapes keep Validation's canonical diagnostic —
      //   folding must not intercept them with a generic foreach error.
      $Request = $createRequest([]);
      $Request->headers = ['x-token' => 'secret'];
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['X-Token' => 'not-a-condition'],
         Source: Sources::Headers
      );
      $caught = null;
      try {
         $Validator->process($Request, $Response, $passthrough);
      }
      catch (InvalidArgumentException $Exception) {
         $caught = $Exception->getMessage();
      }

      yield new Assertion(description: 'Malformed header rules should keep Validation diagnostic')
         ->expect($caught)
         ->to->be('Validation rules for X-Token must be Condition objects.')
         ->assert();

      // @ Other sources stay case-sensitive — no folding outside Headers.
      $called = false;
      $Request = $createRequest(['x-token' => 'secret']);
      [, $Response] = $createMocks();
      $Validator = new Validator(
         rules: ['X-Token' => [new Required]],
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

      yield new Assertion(description: 'Fields source should stay case-sensitive')
         ->expect($Result->code)
         ->to->be(422)
         ->assert();

      yield new Assertion(description: 'Fields source case mismatch should not call handler')
         ->expect($called)
         ->to->be(false)
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
