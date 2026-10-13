<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Authentications\Basic;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;


/**
 * `Request::shed()` drops the head of an answered request — the raw block,
 * the field map, the parsed cookies, the URI and every derivation of it — and
 * keeps everything else the connection still reads.
 *
 * An idle keep-alive connection otherwise keeps its last parsed head (up to
 * twenty times the bytes the peer sent) outside every ledger until its next
 * request. Pinned three ways:
 *
 * - Behaviour: a populated head is gone after `shed()`; the kept members are
 *   untouched.
 * - Classification parity: every backed Request property is either dropped or
 *   deliberately kept. A property added later must be classified here, or it
 *   could carry a head across the idle time unnoticed.
 * - Straight-line: `shed()` and `Header::clear()` run once per response; a
 *   branch in them can blacklist the tracing-JIT root trace of the request
 *   loop (token-based: no `if`, loop, `match`, `?`, `??` or short-circuit).
 */
return new Test(
   description: 'Request::shed() drops the answered head and keeps the rest',
   test: function () {
      // ! A Request whose head, URI and URI derivations are populated
      $Request = new Request;
      $Reflection = new ReflectionClass($Request);
      foreach ([
         'method' => 'GET',
         'protocol' => 'HTTP/1.1',
         'URI' => '/shed/path?k=v',
         '_URL' => '/shed/path',
         '_URN' => 'path',
         '_query' => 'k=v',
         '_queries' => ['k' => 'v'],
         'exclusions' => ['shed' => true],
         'base' => '/shed',
         'closeConnection' => true,
         'authUsername' => 'user',
         'authPassword' => 'secret-password',
         'authParsed' => true,
         'authCredentials' => new Basic('user', 'secret-password'),
         'authToken' => 'secret-token',
         'authTokenParsed' => true,
         'identity' => 'identity',
         'claims' => ['sub' => 'user'],
         'tokenHeaders' => ['alg' => 'HS256'],
      ] as $name => $value) {
         $Reflection->getProperty($name)->setRawValue($Request, $value);
      }
      $Request->Header->define("host: shed.example\r\ncookie: who=first\r\n");
      $Request->Header->adopt(['host' => 'shed.example', 'cookie' => 'who=first']);
      $cookie = $Request->Cookies->get('who');
      $Request->Body->length = 7;
      $Request->kept = 'attribute';

      // @
      $Request->shed();

      // !
      $raw = static fn (string $name): mixed => $Reflection->getProperty($name)->getRawValue($Request);
      $dropped = [
         'raw' => $Request->Header->raw,
         'length' => $Request->Header->length,
         'fields' => $Request->Header->fields,
         'host' => $Request->Header->get('host'),
         'cookie' => $Request->Cookies->get('who'),
         'URI' => $Request->URI,
         '_URL' => $raw('_URL'),
         '_URN' => $raw('_URN'),
         '_query' => $raw('_query'),
         '_queries' => $raw('_queries'),
         'exclusions' => $raw('exclusions'),
         'credentials' => [
            $raw('authUsername'), $raw('authPassword'), $raw('authParsed'),
            $raw('authCredentials'), $raw('authToken'), $raw('authTokenParsed'),
            $raw('identity'), $raw('claims'), $raw('tokenHeaders'),
         ],
      ];
      $droppedJSON = json_encode($dropped);

      yield assert(
         assertion: $cookie === 'first' && $dropped === [
            'raw' => '',
            'length' => 0,
            'fields' => [],
            'host' => null,
            'cookie' => '',
            'URI' => '',
            '_URL' => null,
            '_URN' => null,
            '_query' => null,
            '_queries' => null,
            'exclusions' => [],
            'credentials' => ['', '', false, null, '', false, null, [], []],
         ],
         description: "The head, its cookies, the URI, its derivations and its credentials are dropped: {$droppedJSON}",
      );

      $kept = [
         'method' => $Request->method,
         'protocol' => $Request->protocol,
         'base' => $raw('base'),
         'closeConnection' => $Request->closeConnection,
         'length' => $Request->Body->length,
         'attribute' => $Request->kept,
      ];
      $keptJSON = json_encode($kept);

      yield assert(
         assertion: $kept === [
            'method' => 'GET',
            'protocol' => 'HTTP/1.1',
            'base' => '/shed',
            'closeConnection' => true,
            'length' => 7,
            'attribute' => 'attribute',
         ],
         description: "Method, protocol, framing, base, Body metadata and attributes stay: {$keptJSON}",
      );

      // # Classification parity
      // ! What `shed()` drops (the Header is cleared in place) …
      $drops = [
         'URI', '_URL', '_URN', '_query', '_queries', 'exclusions',
         'authUsername', 'authPassword', 'authParsed', 'authCredentials',
         'authToken', 'authTokenParsed', 'identity', 'claims', 'tokenHeaders',
      ];
      // ! … and what it keeps on purpose: the Header and Body objects, the
      //   connection truth, framing, timestamps, and the per-request state the
      //   next decode resets (`reset()` / `assume()`) — Session, body fields
      //   (`scrub()` empties them), files, attributes
      $keeps = [
         'Header', 'Body', 'base', 'address', 'peer', 'port', 'scheme', 'method',
         'protocol', 'sessioned', 'Session', 'Bodies', '_fields', '_files',
         'hasFiles', '_on', '_at', 'time', 'attributes', 'closeConnection',
         'stream', 'pinned',
      ];
      $backed = [];
      foreach ($Reflection->getProperties() as $Property) {
         if ($Property->isStatic() || $Property->isVirtual()) {
            continue;
         }
         $backed[] = $Property->getName();
      }
      $classified = [...$drops, ...$keeps];
      $unclassified = array_values(array_diff($backed, $classified));
      $stale = array_values(array_diff($classified, $backed));
      $unclassifiedJSON = json_encode($unclassified);
      $staleJSON = json_encode($stale);

      yield assert(
         assertion: $unclassified === [] && $stale === [],
         description: "Every backed Request property is dropped or deliberately kept (unclassified {$unclassifiedJSON}, stale {$staleJSON})",
      );

      // ! Every backed Header property is reset by `clear()` — `built` aside:
      //   an empty block builds to the same empty map either way. The head
      //   was defined, not adopted, so a public read of `fields` that rebuilt
      //   from a stale raw block would show it.
      $HeaderReflection = new ReflectionClass(Header::class);
      $headers = [];
      foreach ($HeaderReflection->getProperties() as $Property) {
         if ($Property->isStatic() || $Property->isVirtual() || $Property->getName() === 'built') {
            continue;
         }
         $headers[] = $Property->getName();
      }
      sort($headers, SORT_STRING | SORT_FLAG_CASE);
      $Header = new Header;
      $Header->define("x-poison: 1\r\n");
      $Poisoned = $Header->Cookies;
      $Header->clear();
      $Cookies = $HeaderReflection->getProperty('Cookies');
      $cleared = [
         'Cookies' => $Cookies->isInitialized($Header) ? 'initialized' : 'unset',
         'fields' => $Header->fields,
         'length' => $Header->length,
         'raw' => $Header->raw,
      ];
      $clearedJSON = json_encode($cleared);

      yield assert(
         assertion: $headers === array_keys($cleared)
            && $cleared === ['Cookies' => 'unset', 'fields' => [], 'length' => 0, 'raw' => '']
            && $Poisoned instanceof Header\Cookies,
         description: "Header::clear() resets every backed Header property: {$clearedJSON}",
      );

      // # Straight-line pin
      $branches = [];
      foreach ([[Request::class, 'shed'], [Header::class, 'clear']] as [$class, $name]) {
         $Method = new ReflectionMethod($class, $name);
         $lines = file((string) $Method->getFileName());
         $source = implode('', array_slice(
            (array) $lines,
            $Method->getStartLine() - 1,
            $Method->getEndLine() - $Method->getStartLine() + 1
         ));
         foreach (token_get_all("<?php {$source}") as $token) {
            $kind = is_array($token) ? $token[0] : $token;
            if (in_array($kind, [
               T_IF, T_ELSE, T_ELSEIF, T_SWITCH, T_MATCH, T_FOR, T_FOREACH, T_WHILE,
               T_DO, T_COALESCE, T_COALESCE_EQUAL, T_BOOLEAN_AND, T_BOOLEAN_OR,
               T_LOGICAL_AND, T_LOGICAL_OR, T_NULLSAFE_OBJECT_OPERATOR, '?',
            ], true)) {
               $label = is_array($token) ? token_name($token[0]) : $token;
               $branches[] = "{$name}: {$label}";
            }
         }
         $branches[] = $Method->isFinal() ? null : "{$name}: not final";
      }
      $branches = array_values(array_filter($branches));
      $branchesJSON = json_encode($branches);

      yield assert(
         assertion: $branches === [],
         description: "shed() and Header::clear() are final and straight-line: {$branchesJSON}",
      );
   },
);
