<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\XMLResource;


use function assert;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\XML;


/**
 * Visibility double: only `$name` may reach the XML output — a `(array)`
 * cast would also leak `$secret`/`$token` (json_encode parity check).
 */
class Exposed
{
   public string $name = 'pub';
   protected string $secret = 'prot';
   private string $token = 'priv'; // @phpstan-ignore property.onlyWritten
}


return new Specification(
   description: 'Response XML resource: dependency-free array→XML encoding',
   test: function () {
      $declaration = '<?xml version="1.0" encoding="UTF-8"?>';

      // @ Non-string scalar → text inside the root element (strings are treated
      //   as pre-encoded, mirroring the JSON resource — see the passthrough case)
      $Response = new Response;
      new XML($Response)->send(42);
      yield assert(
         assertion: $Response->Body->raw === "{$declaration}<response>42</response>",
         description: 'Non-string scalar payload is wrapped in <response> text'
      );

      // @ Content-Type is application/xml
      yield assert(
         assertion: $Response->Header->type === 'application/xml',
         description: 'XML resource sets Content-Type application/xml'
      );

      // @ Associative array → named elements
      $Response = new Response;
      new XML($Response)->send(['name' => 'Bootgly', 'version' => '0.21']);
      yield assert(
         assertion: $Response->Body->raw === "{$declaration}<response><name>Bootgly</name><version>0.21</version></response>",
         description: 'Associative keys become elements'
      );

      // @ Nested array → nested elements
      $Response = new Response;
      new XML($Response)->send(['user' => ['id' => '1', 'name' => 'Ada']]);
      yield assert(
         assertion: $Response->Body->raw === "{$declaration}<response><user><id>1</id><name>Ada</name></user></response>",
         description: 'Nested arrays recurse into nested elements'
      );

      // @ Numeric / list keys → <item>
      $Response = new Response;
      new XML($Response)->send(['a', 'b', 'c']);
      yield assert(
         assertion: $Response->Body->raw === "{$declaration}<response><item>a</item><item>b</item><item>c</item></response>",
         description: 'Numeric list keys become <item> elements'
      );

      // @ null → empty element; bool → literal true/false
      $Response = new Response;
      new XML($Response)->send(['nothing' => null, 'ok' => true, 'off' => false]);
      yield assert(
         assertion: $Response->Body->raw === "{$declaration}<response><nothing></nothing><ok>true</ok><off>false</off></response>",
         description: 'null is an empty element; booleans are true/false literals'
      );

      // @ XML-escaping of <, &, "
      $Response = new Response;
      new XML($Response)->send(['html' => '<a href="x">&']);
      yield assert(
         assertion: $Response->Body->raw === "{$declaration}<response><html>&lt;a href=&quot;x&quot;&gt;&amp;</html></response>",
         description: 'Special characters are XML-escaped'
      );

      // @ Key sanitization → valid XML element names
      $Response = new Response;
      new XML($Response)->send(['first name' => 'x', '1abc' => 'y']);
      yield assert(
         assertion: $Response->Body->raw === "{$declaration}<response><first_name>x</first_name><_1abc>y</_1abc></response>",
         description: 'Invalid name chars sanitized; leading digit gets an underscore prefix'
      );

      // @ Pre-encoded XML string passes through untouched (still application/xml)
      $Response = new Response;
      new XML($Response)->send('<already><encoded/></already>');
      yield assert(
         assertion: $Response->Body->raw === '<already><encoded/></already>'
            && $Response->Header->type === 'application/xml',
         description: 'A non-empty string payload is sent as-is'
      );

      // @ Objects expose ONLY public properties (json_encode parity) — the
      //   Accept header must not select a leakier representation
      $Response = new Response;
      new XML($Response)->send(new Exposed);
      yield assert(
         assertion: $Response->Body->raw === "{$declaration}<response><name>pub</name></response>",
         description: 'Private/protected object properties never reach the XML output'
      );

      // @ A reference cycle is truncated by the depth cap instead of
      //   recursing forever and killing the worker
      $cyclic = ['x' => 1];
      $cyclic['self'] = &$cyclic;
      $Response = new Response;
      new XML($Response)->send($cyclic);
      yield assert(
         assertion: str_contains($Response->Body->raw, '<x>1</x>')
            && str_contains($Response->Body->raw, '</response>'),
         description: 'A self-referencing payload encodes without infinite recursion'
      );
   }
);
