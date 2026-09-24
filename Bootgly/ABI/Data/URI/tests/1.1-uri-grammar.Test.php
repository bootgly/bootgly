<?php

namespace Bootgly\ABI\Data\URI\Tests;

use function assert;
use function str_contains;
use function var_export;
use ValueError;

use Bootgly\ABI\Data\URI;
use Bootgly\ACI\Tests\Suite\Test;

/**
 * `URI` parsing — one absolute hierarchical URI with a host, its components,
 * the normalizations applied on parse and every shape it refuses.
 */

return new Test(
   description: 'URI parses absolute hierarchical URIs with a host and refuses everything else',
   test: function () {
      // # Components, lowercased scheme and host, fragment dropped
      $URI = new URI('HTTPS://User@Example.COM:8080/a/./b?q=1#frag');

      yield assert(
         assertion: $URI->scheme === 'https' && $URI->userinfo === 'User' && $URI->host === 'example.com'
            && $URI->port === 8080 && $URI->path === '/a/./b' && $URI->query === 'q=1',
         description: 'scheme and host are lowercased; userinfo, path (not reduced) and query are kept as sent'
      );
      yield assert(
         assertion: $URI->authority === 'User@example.com:8080'
            && (string) $URI === 'https://User@example.com:8080/a/./b?q=1',
         description: 'the authority and the string form recompose the URI without its fragment'
      );

      // # Query: absent vs present and empty
      yield assert(
         assertion: new URI('http://h/x')->query === null && new URI('http://h/x?')->query === ''
            && (string) new URI('http://h/x?') === 'http://h/x?',
         description: 'an absent query is null; a bare `?` is an empty query that survives the round trip'
      );

      // # Ports
      yield assert(
         assertion: new URI('http://h:/x')->port === null && new URI('http://h:080/x')->port === 80
            && new URI('http://h/x')->port === null && new URI('http://h:65535/')->port === 65535,
         description: 'an empty port is null (the scheme default applies); a written port is an integer'
      );

      // # IPv6 literals are canonical and bracketed; the comparison form drops the brackets
      $IPv6 = new URI('http://[0:0::1]:80/');
      $Mapped = new URI('http://[::FFFF:127.0.0.1]/');

      yield assert(
         assertion: $IPv6->host === '[::1]' && $IPv6->hostname === '::1'
            && $Mapped->host === '[::ffff:127.0.0.1]',
         description: "IPv6 literals are canonicalized: {$IPv6->host} / {$Mapped->host}"
      );

      // # The comparison form drops one trailing dot
      yield assert(
         assertion: new URI('http://Example.COM./x')->hostname === 'example.com'
            && new URI('http://example.com./x')->host === 'example.com.',
         description: 'a fully-qualified host compares without its trailing dot, and keeps it in `host`'
      );

      // # Everything that is not an absolute hierarchical URI with a valid host and port
      $invalid = [
         '', '/x', '//h/x', 'g:h', 'http:g', 'mailto:a@b', 'file:///x', 'data:,x', 'http://', 'http://@/',
         '1http://h/', 'http://a@b@c/', "http://ex\xC3\xA4mple/", 'http://h:0/', 'http://h:65536/',
         'http://h:999999/', 'http://h:+80/', 'http://h:8a/', 'http://[::1%25eth0]/', 'http://[1.2.3.4]/',
         'http://[v1.x]/', 'http://[::1/', 'http://[::1]x/', 'http://::1/', 'http://./', 'http://a b/',
         "http://h/\r\nX-Injected: 1", "http://h/\tx", "http://h/\x00", "http://h/\x7F", 'http://h/ x',
         'http://h\\@e/', 'http://h/a\\b',
      ];
      $leaked = null;
      foreach ($invalid as $candidate) {
         if (URI::parse($candidate) !== null) {
            $leaked = $candidate;

            break;
         }
      }

      yield assert(
         assertion: $leaked === null,
         description: 'every invalid shape is refused: ' . var_export($leaked, true)
      );

      // # The constructor throws where `parse()` returns null — without echoing the input
      $message = null;
      try {
         new URI("http://h/\r\nSet-Cookie: x");
      }
      catch (ValueError $Error) {
         $message = $Error->getMessage();
      }

      yield assert(
         assertion: $message !== null && str_contains($message, 'Set-Cookie') === false,
         description: 'the constructor raises a ValueError whose message never echoes the input'
      );
   }
);
