<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Descriptor;
use Bootgly\ADI\Validation\Renderer;
use Bootgly\ADI\Validators\Confirmed;
use Bootgly\ADI\Validators\Email;
use Bootgly\ADI\Validators\In;
use Bootgly\ADI\Validators\Integer;
use Bootgly\ADI\Validators\Keys;
use Bootgly\ADI\Validators\Maximum;
use Bootgly\ADI\Validators\Minimum;
use Bootgly\ADI\Validators\Regex;
use Bootgly\ADI\Validators\Required;
use Bootgly\ADI\Validators\Size;
use Bootgly\ADI\Validators\Text;


return new Test(
   description: 'It should render rule sets as JSON Schema 2020-12 documents',
   test: new Assertions(Case: function (): Generator {
      $encode = static fn (mixed $value): string => (string) json_encode($value, JSON_UNESCAPED_SLASHES);

      // @ Empty rule set
      yield new Assertion(description: 'No rules: an object schema with the 2020-12 dialect')
         ->expect($encode(new Renderer([])->document))
         ->to->be('{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object"}')
         ->assert();

      // @ Flat rules
      $Renderer = new Renderer([
         'email' => [new Required, new Email],
         'age' => [new Integer, new Minimum(18), new Maximum(120)],
         'plan' => new In(['free', 'pro']),
      ]);

      yield new Assertion(description: 'Flat rules: typed properties, keywords pruned by type, required list')
         ->expect($encode($Renderer->document))
         ->to->be(
            '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","properties":{'
            . '"email":{"type":"string","format":"email","minLength":1},'
            . '"age":{"type":"integer","minimum":18,"maximum":120},'
            . '"plan":{"enum":["free","pro"]}},'
            . '"required":["email"]}'
         )
         ->assert();

      yield new Assertion(description: 'Every shipped rule above is expressible: no omissions')
         ->expect($Renderer->omissions)
         ->to->be([])
         ->assert();

      // @ Nested paths and wildcards
      $Renderer = new Renderer([
         'address.city' => [new Required],
         'items' => [new Maximum(10)],
         'items.*.id' => [new Required, new Integer],
         'items.*.tags.*' => [new Regex('/\A[a-z]+\z/')],
      ]);

      yield new Assertion(description: 'Named segments are properties, wildcards are items; Required lifts to named ancestors only')
         ->expect($encode($Renderer->document))
         ->to->be(
            '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","properties":{'
            . '"address":{"type":"object","properties":{"city":{"minLength":1,"minItems":1,"minProperties":1,"not":{"type":"null"}}},"required":["city"]},'
            . '"items":{"type":"array","items":{"type":"object","properties":{'
            . '"id":{"type":"integer"},'
            . '"tags":{"type":"array","items":{"pattern":"^[a-z]+$"}}},'
            . '"required":["id"]},"maxItems":10}},'
            . '"required":["address"]}'
         )
         ->assert();

      // @ Named and wildcard children together: object with additionalProperties
      $Renderer = new Renderer(['meta.*' => [new Integer], 'meta.count' => [new Required]]);

      yield new Assertion(description: 'A wildcard beside named keys renders additionalProperties and allOf')
         ->expect($encode($Renderer->document['properties']))
         ->to->be(
            '{"meta":{"type":"object","properties":{"count":{"allOf":['
            . '{"minLength":1,"minItems":1,"minProperties":1,"not":{"type":"null"}},{"type":"integer"}]}},'
            . '"required":["count"],"additionalProperties":{"type":"integer"}}}'
         )
         ->assert();

      // @ Index segments: left out whole, every Condition listed
      $Renderer = new Renderer(['list.0' => [new Required, new Integer], 'list.*.name' => [new Required], 'codes.200' => new Integer]);

      yield new Assertion(description: 'Index segments are left out; their wildcard sibling still renders as an array')
         ->expect($encode($Renderer->document['properties']))
         ->to->be('{"list":{"type":"array","items":{"type":"object","properties":{"name":{"minLength":1,"minItems":1,"minProperties":1,"not":{"type":"null"}}},"required":["name"]}}}')
         ->assert();

      yield new Assertion(description: 'Every Condition of an index rule key is listed in omissions')
         ->expect($Renderer->omissions)
         ->to->be(['list.0' => [Required::class, Integer::class], 'codes.200' => [Integer::class]])
         ->assert();

      // @ A Descriptor's own `required` keyword (a list) is kept, not taken for the marker
      $Keyword = new class extends Condition implements Descriptor {
         protected string $template = '{field} is invalid.';
         public function validate (string $field, mixed $value, array $data): bool { return true; }
         public function describe (): null|array { return ['type' => 'object', 'required' => ['id']]; }
      };

      yield new Assertion(description: 'A `required` list from a Descriptor stays a keyword')
         ->expect($encode(new Renderer(['user' => $Keyword])->document['properties']))
         ->to->be('{"user":{"type":"object","required":["id"]}}')
         ->assert();

      $Invalid = new class extends Condition implements Descriptor {
         protected string $template = '{field} is invalid.';
         public function validate (string $field, mixed $value, array $data): bool { return true; }
         public function describe (): null|array { return ['required' => false]; }
      };
      $Renderer = new Renderer(['note' => $Invalid]);

      yield new Assertion(description: 'Any other `required` value makes the fragment not expressible')
         ->expect([$encode($Renderer->document['properties']), array_keys($Renderer->omissions)])
         ->to->be(['{"note":{}}', ['note']])
         ->assert();

      // @ A node its own rules make an object: a wildcard beside it renders its values
      $Renderer = new Renderer(['settings' => [new Keys(['theme', 'lang'])], 'settings.*' => [new Text]]);

      yield new Assertion(description: 'Keys plus a wildcard renders an object with additionalProperties, not an array')
         ->expect($encode($Renderer->document['properties']))
         ->to->be('{"settings":{"type":"object","additionalProperties":{"type":"string"},"propertyNames":{"enum":["theme","lang"]}}}')
         ->assert();

      // @ Escaped keys
      yield new Assertion(description: 'Escaped keys render as their literal property names')
         ->expect($encode(new Renderer(['a\\.b' => [new Integer], '\\*' => [new Integer]])->document['properties']))
         ->to->be('{"a.b":{"type":"integer"},"*":{"type":"integer"}}')
         ->assert();

      // @ Conflicting keywords stay, through allOf
      yield new Assertion(description: 'Two values for one keyword both apply through allOf')
         ->expect($encode(new Renderer(['code' => [new Integer, new Minimum(1), new Minimum(5)]])->document['properties']))
         ->to->be('{"code":{"type":"integer","minimum":1,"allOf":[{"minimum":5}]}}')
         ->assert();

      // @ Unmapped rules: omitted and reported, never silently
      $Custom = new class extends Condition {
         public function validate (string $field, mixed $value, array $data): bool
         {
            return true;
         }
      };
      $Renderer = new Renderer([
         'password' => [new Required, new Confirmed],
         'avatar' => [new Size(1024)],
         'token' => [$Custom],
         'name' => [new Regex('/\A[a-z]+\z/i')],
      ]);

      yield new Assertion(description: 'Unmapped rules are listed in omissions by rule key')
         ->expect($Renderer->omissions)
         ->to->be([
            'password' => [Confirmed::class],
            'avatar' => [Size::class],
            'token' => [Condition::class . '@anonymous'],
            'name' => [Regex::class],
         ])
         ->assert();

      yield new Assertion(description: 'A property with only unmapped rules is an empty schema ({}), still listed')
         ->expect($encode($Renderer->document['properties']['avatar'] ?? null))
         ->to->be('{}')
         ->assert();

      // @ A custom Descriptor
      $Slug = new class extends Condition implements Descriptor {
         public function validate (string $field, mixed $value, array $data): bool
         {
            return true;
         }

         public function describe (): null|array
         {
            return ['type' => 'string', 'pattern' => '^[a-z-]+$'];
         }
      };
      $Invalid = new class extends Condition implements Descriptor {
         public function validate (string $field, mixed $value, array $data): bool
         {
            return true;
         }

         public function describe (): null|array
         {
            return ['not', 'a', 'map'];
         }
      };
      $Renderer = new Renderer(['slug' => [$Slug], 'odd' => [$Invalid]]);

      yield new Assertion(description: 'A custom Descriptor contributes its keywords')
         ->expect($encode($Renderer->document['properties']['slug'] ?? null))
         ->to->be('{"type":"string","pattern":"^[a-z-]+$"}')
         ->assert();

      yield new Assertion(description: 'A Descriptor returning a list is omitted and reported')
         ->expect(array_keys($Renderer->omissions))
         ->to->be(['odd'])
         ->assert();

      // @ Malformed rules
      $message = '';
      try {
         new Renderer(['a' => 'x']); // @phpstan-ignore argument.type
      }
      catch (InvalidArgumentException $Exception) {
         $message = $Exception->getMessage();
      }

      yield new Assertion(description: 'A non-Condition rule is refused like in Validation')
         ->expect($message)
         ->to->be('Validation rules for a must be Condition objects.')
         ->assert();

      yield new Assertion(description: 'The rules stay readable on the Renderer')
         ->expect(array_keys($Renderer->rules))
         ->to->be(['slug', 'odd'])
         ->assert();
   })
);
