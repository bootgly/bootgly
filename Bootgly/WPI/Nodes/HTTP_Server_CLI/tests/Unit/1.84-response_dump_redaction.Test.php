<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Unit;


use function assert;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function sort;
use function str_contains;
use function var_dump;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header\Cookie;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header\Cookies;


/**
 * A response header holds credentials wherever a field can sit: the fields a
 * route set, the prepared and preset ones, the queued `Set-Cookie` lines and
 * their memos, and the built block on the wire. The Response keeps two more
 * copies of that block — its encode cache, which outlives the request on the
 * worker's reused Response. A debug dump of the Header, of its Cookies, of a
 * Cookie or of an encoded Response masks the credential fields
 * (`HTTP::SECRET_HEADERS`) and every cookie value, while the other fields
 * still show — and the dump never changes what the response sends.
 */
return new Test(
   description: 'Response header: debug dumps mask the credential fields, the cookies, the built block and its encode cache',
   test: function () {
      // ! SYNTH credentials — never real ones
      $field = 'SYNTH-RESPONSE-AUTHORIZATION-1.84';
      $prepared = 'SYNTH-RESPONSE-PROXY-AUTHORIZATION-1.84';
      $preset = 'SYNTH-RESPONSE-PRESET-COOKIE-1.84';
      $session = 'SYNTH-RESPONSE-SESSION-COOKIE-1.84';
      $value = 'SYNTH-RESPONSE-COOKIE-VALUE-1.84';
      $wired = 'SYNTH-RESPONSE-WIRE-COOKIE-1.84';
      $Dumper = new Dumper('plain');
      // ! No limit may hide a leak
      $Dumper->depth = 32;
      $Dumper->strings = 1_000_000;
      $Dumper->items = 10_000;
      // ! Each debug channel
      $Channels = [
         'var_dump' => static function (object $Object): string {
            ob_start();
            var_dump($Object);

            return (string) ob_get_clean();
         },
         'print_r' => static fn (object $Object): string => print_r($Object, true),
         'debug_zval_dump' => static function (object $Object): string {
            ob_start();
            debug_zval_dump($Object);

            return (string) ob_get_clean();
         },
         'Dumper' => static fn (object $Object): string => $Dumper->dump($Object),
      ];

      // ! A built response head: every source of a field
      $Header = new Header;
      $Header->set('Authorization', $field);
      $Header->set('X-Visible', 'visible-response-field-1.84');
      $Header->prepare(['Proxy-Authorization' => $prepared]);
      $Header->preset('Set-Cookie', "remember={$preset}");
      $Header->Cookies->append(new Cookie('PHPSID', $session));
      $Header->build();
      $Cookie = new Cookie('PHPSID', $value);
      // ! An encoded response: the HTTP/1.1 fast lane caches the wire — Set-Cookie included
      $Request = new Request;
      $Request->Header->define("Host: visible.example.invalid\r\n");
      /** @var Connection $Connection The fast lane never touches the connection */
      $Connection = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
      $Response = new Response;
      $Response->reset($Connection, $Request);
      $Response->Header->set('X-Visible', 'visible-response-field-1.84');
      $Response->Header->Cookies->append(new Cookie('PHPSID', $wired));
      $length = null;
      $wire = $Response->encode($Connection, $length);

      // ! The holders, what each must mask and what each must still show
      $Holders = [
         'Response\Raw\Header' => [$Header, [$field, $prepared, $preset, $session], ['visible-response-field-1.84']],
         'Response\Raw\Header\Cookies' => [$Header->Cookies, [$field, $prepared, $preset, $session], ['visible-response-field-1.84']],
         'Header\Cookie' => [$Cookie, [$value], ['PHPSID']],
         'Response' => [$Response, [$wired], ['visible-response-field-1.84']],
      ];

      // @@ Every holder through every channel
      $leaks = [];
      $missing = [];
      foreach ($Holders as $holder => [$Holder, $secrets, $shown]) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Holder);

            foreach ($secrets as $secret) {
               if (str_contains($output, $secret) === true) {
                  $leaks[] = "{$secret} in {$holder} via {$channel}";
               }
            }
            foreach ([...$shown, Secret::MASK] as $expected) {
               if (str_contains($output, $expected) === false) {
                  $missing[] = "{$expected} in {$holder} via {$channel}";
               }
            }
         }
      }
      $leaked = implode(', ', $leaks);
      $absent = implode(', ', $missing);
      yield assert(
         assertion: $leaks === [] && $missing === [],
         description: "No credential reaches var_dump, print_r, debug_zval_dump or Dumper, while the other fields show (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ Dumping never writes
      $cookies = implode("\n", $Header->Cookies->cookies);
      $cached = (new ReflectionProperty(Response::class, 'wire'))->getValue($Response);
      yield assert(
         assertion: $Header->get('Authorization') === $field
            && $Header->get('Proxy-Authorization') === $prepared
            && str_contains($Header->raw, $preset) === true
            && str_contains($Header->raw, $session) === true
            && str_contains($cookies, $session) === true
            && str_contains($Cookie->build(), $value) === true
            && str_contains($wire, $wired) === true
            && $cached === $wire,
         description: 'The live fields, the built block, the queued cookies, the Cookie value and the encode cache are untouched by the dumps'
      );

      // @ The one way: the trait's __debugInfo, #[Secret] on the stored properties only
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $Expected = [
         Header::class => [$Header, [
            'builtFields', 'builtPrepared', 'builtPreset', 'builtQueued',
            'fields', 'prepared', 'preparedRaw', 'preparedSanitized', 'preset', 'queued', 'raw'
         ]],
         Cookies::class => [$Header->Cookies, ['cookies']],
         Cookie::class => [$Cookie, ['value']],
         Response::class => [$Response, ['wire', 'wireHeader']],
      ];
      $wrong = [];
      foreach ($Expected as $class => [$Object, $properties]) {
         $Own = new ReflectionMethod($Object, '__debugInfo');
         $marked = [];
         foreach ((new ReflectionClass($class))->getProperties() as $Property) {
            if ($Property->getAttributes(Secret::class) === []) {
               continue;
            }

            $marked[] = $Property->name;
            if ($Property->isStatic() === true || $Property->isVirtual() === true) {
               $wrong[] = "{$class}::\${$Property->name} is static or virtual";
            }
         }
         sort($marked);
         if ($marked !== $properties) {
            $listed = implode(', ', $marked);
            $wrong[] = "{$class} marks [{$listed}]";
         }
         if ($Own->getFileName() !== $Trait->getFileName() || $Own->getStartLine() !== $Trait->getStartLine()) {
            $wrong[] = "{$class} does not dump through Redactable";
         }
      }
      $listed = implode('; ', $wrong);
      yield assert(
         assertion: $wrong === [],
         description: "The response Header, its Cookies, Cookie and the Response dump through the Redactable trait, #[Secret] on the stored properties only ({$listed})"
      );
   }
);
