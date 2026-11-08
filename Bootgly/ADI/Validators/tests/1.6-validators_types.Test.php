<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Validation;
use Bootgly\ADI\Validation\Renderer;
use Bootgly\ADI\Validators\Keys;
use Bootgly\ADI\Validators\Length;
use Bootgly\ADI\Validators\Required;
use Bootgly\ADI\Validators\Text;
use Bootgly\ADI\Validators\UUID;


return new Test(
   description: 'It should validate and describe UUID, Text, Length and Keys',
   test: new Assertions(Case: function (): Generator {
      $check = static fn (object $Rule, array $values): array => array_map(
         static fn (mixed $value): bool => $Rule->validate('f', $value, []), // @phpstan-ignore method.notFound
         $values
      );

      // @ UUID
      yield new Assertion(description: 'UUID passes canonical 8-4-4-4-12 hex in either case, any version')
         ->expect($check(new UUID, [
            '123e4567-e89b-12d3-a456-426614174000',
            'A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11',
            '00000000-0000-0000-0000-000000000000',
         ]))
         ->to->be([true, true, true])
         ->assert();

      yield new Assertion(description: 'UUID fails braces, URN, unhyphenated, short, trailing newline, non-strings')
         ->expect($check(new UUID, [
            '{123e4567-e89b-12d3-a456-426614174000}',
            'urn:uuid:123e4567-e89b-12d3-a456-426614174000',
            '123e4567e89b12d3a456426614174000',
            '123e4567-e89b-12d3-a456-42661417400',
            "123e4567-e89b-12d3-a456-426614174000\n",
            'g23e4567-e89b-12d3-a456-426614174000',
            123,
            null,
         ]))
         ->to->be(array_fill(0, 8, false))
         ->assert();

      // @ Text
      yield new Assertion(description: 'Text passes strings only')
         ->expect($check(new Text, ['', 'a', '12', 12, 1.5, true, [], null]))
         ->to->be([true, true, true, false, false, false, false, false])
         ->assert();

      // @ Length
      yield new Assertion(description: 'Length counts code points, not bytes')
         ->expect($check(new Length(2, 3), ['ab', 'ção', 'açúc', 'a', '日本', '日本語x']))
         ->to->be([true, true, false, false, true, false])
         ->assert();

      yield new Assertion(description: 'Length fails invalid UTF-8 and non-strings')
         ->expect($check(new Length(maximum: 10), ["\xC3\x28", "ab\xFF", 5, ['a']]))
         ->to->be([false, false, false, false])
         ->assert();

      $messages = [];
      foreach ([[null, null], [-1, null], [null, -2], [5, 4]] as [$minimum, $maximum]) {
         try {
            new Length($minimum, $maximum);
            $messages[] = 'built';
         }
         catch (InvalidArgumentException $Exception) {
            $messages[] = $Exception->getMessage();
         }
      }

      yield new Assertion(description: 'Length refuses no bound, negative bounds and an inverted range')
         ->expect($messages)
         ->to->be([
            'Length needs a minimum or a maximum.',
            'Length bounds must not be negative.',
            'Length bounds must not be negative.',
            'Length minimum must not exceed its maximum.',
         ])
         ->assert();

      $Validation = new Validation(
         ['a' => 'x', 'b' => 'xxxx', 'c' => 'xxxx'],
         ['a' => new Length(2), 'b' => new Length(maximum: 3), 'c' => new Length(1, 3)]
      );

      yield new Assertion(description: 'Length messages name the bounds that apply')
         ->expect($Validation->errors)
         ->to->be([
            'a' => ['a must be at least 2 characters.'],
            'b' => ['b must be at most 3 characters.'],
            'c' => ['c must be between 1 and 3 characters.'],
         ])
         ->assert();

      // @ Keys
      yield new Assertion(description: 'Keys passes arrays whose keys are all allowed (as strings)')
         ->expect($check(new Keys(['name', 'email', '0']), [
            ['name' => 'Ada'],
            ['name' => 'Ada', 'email' => 'a@b.c'],
            ['x'],
            ['name' => 'Ada', 'role' => 'admin'],
            ['x', 'y'],
            'name',
         ]))
         ->to->be([true, true, true, false, false, false])
         ->assert();

      $message = '';
      try {
         new Keys(['name', 1]); // @phpstan-ignore argument.type
      }
      catch (InvalidArgumentException $Exception) {
         $message = $Exception->getMessage();
      }

      yield new Assertion(description: 'Keys refuses a key that is not a string')
         ->expect($message)
         ->to->be('Allowed keys must be strings.')
         ->assert();

      $Validation = new Validation(
         ['user' => ['name' => 'Ada', 'is_admin' => true]],
         ['user' => [new Keys(['name', 'email'])], 'user.name' => [new Required, new Text]]
      );

      yield new Assertion(description: 'Keys closes a nested object; the message never echoes the extra key')
         ->expect($Validation->errors)
         ->to->be(['user' => ['user has keys that are not allowed.']])
         ->assert();

      // @ describe()
      yield new Assertion(description: 'The four rules describe their JSON Schema keywords')
         ->expect([
            new UUID()->describe(),
            new Text()->describe(),
            new Length(1, 80)->describe(),
            new Length(maximum: 5)->describe(),
            new Keys(['a', 'b'])->describe(),
            new Keys([])->describe(),
         ])
         ->to->be([
            ['type' => 'string', 'format' => 'uuid'],
            ['type' => 'string'],
            ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
            ['type' => 'string', 'maxLength' => 5],
            ['type' => 'object', 'propertyNames' => ['enum' => ['a', 'b']]],
            ['type' => 'object', 'maxProperties' => 0],
         ])
         ->assert();

      $Renderer = new Renderer([
         'projectId' => [new Required, new UUID],
         'filters' => [new Keys(['status', 'limit'])],
         'filters.status' => [new Text, new Length(maximum: 20)],
      ]);

      yield new Assertion(description: 'A closed object renders properties plus propertyNames')
         ->expect((string) json_encode($Renderer->document['properties'], JSON_UNESCAPED_SLASHES))
         ->to->be(
            '{"projectId":{"type":"string","format":"uuid","minLength":1},'
            . '"filters":{"type":"object","properties":{"status":{"type":"string","maxLength":20}},'
            . '"propertyNames":{"enum":["status","limit"]}}}'
         )
         ->assert();
   })
);
