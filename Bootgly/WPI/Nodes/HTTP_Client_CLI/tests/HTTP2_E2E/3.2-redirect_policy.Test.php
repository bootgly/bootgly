<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Test(
   description: 'It should decide HTTP/2 redirects exactly like HTTP/1.1: policy, hop cap, scheme and 303 headers',
   test: new Assertions(Case: function (): Generator {
      $client = static function (null|Closure $Redirection = null, int $maxRedirects = 10): HTTP_Client_CLI {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: 8087, enableHTTP2: true));
         $Client->timeout = 3;
         $Client->maxRedirects = $maxRedirects;
         $Client->Redirection = $Redirection;

         return $Client;
      };

      // @ The policy is asked on a same-origin stream hop too
      $calls = [];
      $Response = $client(static function (string $host, int $port, bool $secure) use (&$calls): bool {
         $calls[] = [$host, $port, $secure];

         return true;
      })->request('GET', '/redirect');

      yield new Assertion(description: 'a same-origin h2 hop is followed after the policy saw it: ' . json_encode([$Response->code, $calls]))
         ->expect([$Response->code, $Response->Body->raw, $Response->protocol, $calls])
         ->to->be([200, 'landed', 'HTTP/2', [['127.0.0.1', 8087, false]]])
         ->assert();

      // @ A refusal fails the request
      $Response = $client(static fn (string $host, int $port, bool $secure): bool => false)->request('GET', '/redirect');

      yield new Assertion(description: "a refused h2 hop fails loudly: {$Response->code} {$Response->status}")
         ->expect([$Response->code, $Response->status])
         ->to->be([0, 'Redirect Refused'])
         ->assert();

      // @ Another origin, refused by the policy before any dial
      $asked = [];
      $Response = $client(static function (string $host, int $port, bool $secure) use (&$asked): bool {
         $asked[] = [$host, $port, $secure];

         return false;
      })->request('GET', '/cross');

      yield new Assertion(description: 'a cross-origin h2 hop reaches the policy as the target: ' . json_encode([$Response->status, $asked]))
         ->expect([$Response->code, $Response->status, $asked])
         ->to->be([0, 'Redirect Refused', [['127.0.0.2', 8087, false]]])
         ->assert();

      // @ The hop cap and a refused scheme
      $Response = $client(maxRedirects: 3)->request('GET', '/loop');

      yield new Assertion(description: "a self-loop fails past maxRedirects: {$Response->code} {$Response->status}")
         ->expect([$Response->code, $Response->status])
         ->to->be([0, 'Too Many Redirects'])
         ->assert();

      $Response = $client()->request('GET', '/gopher');

      yield new Assertion(description: "a gopher:// Location is refused: {$Response->code} {$Response->status}")
         ->expect([$Response->code, $Response->status])
         ->to->be([0, 'Redirect Refused'])
         ->assert();

      // @ A same-origin 303 keeps the caller's headers and drops only the payload (HCLI-13)
      $Response = $client()->request('POST', '/see-other', ['Authorization' => 'Bearer SECRET'], 'card=4111');

      yield new Assertion(description: "a same-origin h2 303 keeps Authorization: {$Response->Body->raw}")
         ->expect($Response->Body->raw)
         ->to->be('method=GET;authorization=Bearer SECRET;content-type=absent;body=')
         ->assert();
   })
);
