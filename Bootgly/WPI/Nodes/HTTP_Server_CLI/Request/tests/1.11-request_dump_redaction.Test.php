<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Tests;


use function assert;
use function base64_encode;
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

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Authentications\Basic;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Frame;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header\Cookies;


/**
 * A request carries its credentials in five places: the parsed header fields
 * and the raw header block (Authorization, Proxy-Authorization, Cookie, an
 * API key), the HTTP/1 frame the parser hands them over in, the parsed
 * cookies, the Basic password and the Bearer token the Request caches.
 * A debug dump of the Request, of its Header, of a Frame, of its Cookies or
 * of a Basic credential shows none of them, while every other field still shows — and
 * the dump never changes a live value.
 */
return new Test(
   description: 'Request: debug dumps mask the credential header fields, the raw head, the cookies, the Basic password and the Bearer token',
   test: function () {
      // ! SYNTH credentials — never real ones
      $password = 'SYNTH-REQUEST-BASIC-PASSWORD-1.11';
      $basic = base64_encode("visible-user:{$password}");
      $proxy = 'SYNTH-REQUEST-PROXY-AUTHORIZATION-1.11';
      $session = 'SYNTH-REQUEST-COOKIE-SESSION-1.11';
      $token = 'SYNTH-REQUEST-BEARER-TOKEN-1.11';
      $key = 'SYNTH-REQUEST-API-KEY-1.11';
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

      // ! A parsed head: Basic credentials, a proxy credential, a session cookie
      $Request = new Request;
      $Request->Header->define(implode("\r\n", [
         "Authorization: Basic {$basic}",
         "Proxy-Authorization: Basic {$proxy}",
         "Cookie: PHPSID={$session}; theme=dark",
         "X-Api-Key: {$key}",
         'Accept: text/html',
         ''
      ]));
      $Request->Header->build();
      // ! What a request parses lazily: the Basic pair, the cookies; the Bearer token is injected
      $parsed = $Request->username === 'visible-user'
         && $Request->Cookies->get('PHPSID') === $session;
      $Request->token = $token;
      $Basic = new Basic('visible-user', $password);
      // ! The HTTP/1 parse result, as `Frame::parse()` leaves it for `Header::adopt()`
      $Frame = new Frame;
      $Frame->method = 'GET';
      $Frame->headerRaw = "Authorization: Basic {$basic}\r\nX-Api-Key: {$key}\r\nAccept: text/html";
      $Frame->fields = ['authorization' => "Basic {$basic}", 'x-api-key' => $key, 'accept' => 'text/html'];

      // ! The holders, what each must mask and what each must still show
      $Holders = [
         'Request' => [$Request, [$password, $basic, $proxy, $session, $token, $key], ['visible-user', 'text/html']],
         'Request\Raw\Header' => [$Request->Header, [$basic, $proxy, $session, $key], ['text/html']],
         'Request\Frame' => [$Frame, [$basic, $key], ['text/html', 'GET']],
         'Request\Raw\Header\Cookies' => [$Request->Cookies, [$session], ['text/html']],
         'Authentications\Basic' => [$Basic, [$password], ['visible-user']],
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
         assertion: $parsed === true && $leaks === [] && $missing === [],
         description: "No credential reaches var_dump, print_r, debug_zval_dump or Dumper, while the other fields show (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ Dumping never writes
      yield assert(
         assertion: $Request->password === $password
            && $Request->token === $token
            && $Request->Header->get('Proxy-Authorization') === "Basic {$proxy}"
            && str_contains($Request->Header->raw, $proxy) === true
            && $Request->Cookies->get('PHPSID') === $session
            && $Basic->password === $password
            && $Frame->fields['x-api-key'] === $key
            && str_contains($Frame->headerRaw, $key) === true,
         description: 'The live password, token, header fields, raw head, frame and cookies are untouched by the dumps'
      );

      // @ The one way: the trait's __debugInfo, #[Secret] on the stored properties only
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $Expected = [
         Request::class => [$Request, ['authPassword', 'authToken']],
         Header::class => [$Request->Header, ['fields', 'raw']],
         Frame::class => [$Frame, ['fields', 'headerRaw']],
         Cookies::class => [$Request->Cookies, ['cookies']],
         Basic::class => [$Basic, ['password']],
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
         description: "Request, its Header, a Frame, its Cookies and Basic dump through the Redactable trait, #[Secret] on the stored properties only ({$listed})"
      );
   }
);
