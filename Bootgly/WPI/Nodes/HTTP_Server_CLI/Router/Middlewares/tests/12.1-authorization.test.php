<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\Authorization\Policy as PolicyContract;
use Bootgly\API\Security\Identity;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing\Gate;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization\Policy as PolicyGate;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization\Role;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization\Scope;


return new Specification(
   description: 'It should authorize requests with Scope, Role and Policy gates',
   test: new Assertions(Case: function (): Generator {
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         $Response->Body->raw = 'passed';
         return $Response;
      };

      // @ Empty authorization strategy fails early.
      $failed = false;
      try {
         new Authorization(new Authorizing);
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield new Assertion(description: 'Empty authorization strategy should fail early')
         ->expect($failed)
         ->to->be(true)
         ->assert();

      // @ Scope gate passes exact grants.
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: ['demo:read', 'demo:write'])
      ]);
      $Middleware = new Authorization(new Authorizing(new Scope('demo:read')));
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Granted scope should pass through')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      // @ Scope gate denies missing grants.
      $called = false;
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: ['demo:read'])
      ]);
      $Middleware = new Authorization(new Authorizing(new Scope('demo:delete')));
      $Result = $Middleware->process(
         $Request,
         $Response,
         function (object $Request, object $Response) use (&$called): object {
            $called = true;
            return $Response;
         }
      );

      yield new Assertion(description: 'Denied scope should not call handler')
         ->expect($called)
         ->to->be(false)
         ->assert();

      yield new Assertion(description: 'Denied scope should return 403')
         ->expect($Result->code)
         ->to->be(403)
         ->assert();

      yield new Assertion(description: 'Denied scope should expose forbidden body')
         ->expect($Result->Body->raw)
         ->to->be('Forbidden')
         ->assert();

      // @ Custom fallback keeps forbidden status.
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: [])
      ]);
      $Middleware = new Authorization(
         Authorizing: new Authorizing(new Scope('demo:read')),
         Fallback: function (object $Request, object $Response): object {
            $Response->code = 200;
            $Response->Body->raw = 'custom forbidden body';
            return $Response;
         }
      );
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Custom fallback should stay forbidden')
         ->expect($Result->code)
         ->to->be(403)
         ->assert();

      yield new Assertion(description: 'Custom fallback should keep custom body')
         ->expect($Result->Body->raw)
         ->to->be('custom forbidden body')
         ->assert();

      // @ Role gate accepts role and roles claims.
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'admin-1', claims: ['role' => 'admin'])
      ]);
      $Middleware = new Authorization(new Authorizing(new Role(['owner', 'admin'])));
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Role claim should pass matching role gate')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'editor-1', claims: ['roles' => ['editor', 'publisher']])
      ]);
      $Middleware = new Authorization(new Authorizing(new Role(['editor', 'publisher'], all: true)));
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Roles claim should pass all-role gate')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'editor-1', claims: ['roles' => ['editor']])
      ]);
      $Middleware = new Authorization(new Authorizing(new Role('admin')));
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Missing role should return 403')
         ->expect($Result->code)
         ->to->be(403)
         ->assert();

      // @ Policy gate delegates resource decisions to API policies.
      $Post = (object) ['owner' => 'user-42'];
      $Policy = new class extends PolicyContract {
         public function update (Identity $Identity, mixed $Resource = null): null|bool
         {
            return $Resource->owner === $Identity->id;
         }
      };
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42')
      ]);
      $Middleware = new Authorization(new Authorizing(new PolicyGate(
         Policy: $Policy,
         action: 'update',
         Resource: function (object $Request) use ($Post): object {
            return $Post;
         }
      )));
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Policy gate should pass matching resource')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      $PublishingPolicy = new class extends PolicyContract {
         public function publish (Identity $Identity, mixed $Resource = null): null|bool
         {
            return $Identity->check('demo:publish');
         }
      };
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: ['demo:publish'])
      ]);
      $Middleware = new Authorization(new Authorizing(new PolicyGate(
         Policy: $PublishingPolicy,
         action: 'publish'
      )));
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Policy gate should pass custom policy actions')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      $failed = false;
      try {
         new PolicyGate($PublishingPolicy, 'archive');
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield new Assertion(description: 'Policy gate should reject missing policy actions')
         ->expect($failed)
         ->to->be(true)
         ->assert();

      // @ Gate collection requires every gate and fails fast.
      $FailingGate = new class extends Gate {
         public int $calls = 0;

         public function authorize (object $Request): bool
         {
            $this->calls++;
            return false;
         }
      };
      $SkippedGate = new class extends Gate {
         public int $calls = 0;

         public function authorize (object $Request): bool
         {
            $this->calls++;
            return true;
         }
      };
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: ['demo:read'])
      ]);
      $Middleware = new Authorization(new Authorizing($FailingGate, $SkippedGate));
      $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Authorization gates should fail fast')
         ->expect($FailingGate->calls === 1 && $SkippedGate->calls === 0)
         ->to->be(true)
         ->assert();
   })
);
