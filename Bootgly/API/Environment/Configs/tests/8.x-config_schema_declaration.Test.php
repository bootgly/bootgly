<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Validators\Minimum;
use Bootgly\API\Environment\Configs\Config\Types;
use Bootgly\API\Environment\Configs\Schema;
use Bootgly\API\Environment\Configs\Schema\Field;


return new Test(
   description: 'Config schema: invalid declarations fail at construction',
   test: function () {
      $refuse = function (callable $declare): null|string {
         try {
            $declare();
         }
         catch (InvalidArgumentException $Exception) {
            return $Exception->getMessage();
         }

         return null;
      };

      // @ Field
      $Field = new Field(key: 'APP_PORT', default: 80, cast: Types::Integer, required: true, Rules: [new Minimum(1)]);
      yield assert(
         assertion: $Field->key === 'APP_PORT'
            && $Field->default === 80
            && $Field->cast === Types::Integer
            && $Field->required === true
            && count($Field->Rules) === 1 && $Field->Rules[0] instanceof Minimum,
         description: 'Field keeps its bind() inputs and rules'
      );
      $Blank = new Field;
      yield assert(
         assertion: $Blank->key === '' && $Blank->default === null && $Blank->cast === null
            && $Blank->required === false && $Blank->Rules === [],
         description: 'Field defaults: no key, no default, no cast, optional, no rules'
      );
      yield assert(
         // @phpstan-ignore argument.type
         assertion: $refuse(fn () => new Field(Rules: ['not a rule'])) === 'Config schema rules must be Condition objects.',
         description: 'Field refuses a rule that is not a Condition'
      );

      yield assert(
         assertion: $refuse(fn () => new Field(required: true, default: 'x')) === 'A required config schema field needs a key.',
         description: 'Field refuses a required field without a key (it could never resolve)'
      );
      $forged = $refuse(fn () => new Field(key: "DB_HOST\n- Port: Port must be at most 1."));
      yield assert(
         assertion: $forged === 'Config schema field keys cannot hold control characters or "=".'
            && $refuse(fn () => new Field(key: "A\0B")) === $forged
            && $refuse(fn () => new Field(key: 'A=B')) === $forged,
         description: 'Field refuses keys with control characters or "=" (no forged report lines)'
      );
      yield assert(
         assertion: $refuse(fn () => new Field(key: 'app_port_lower')) === null,
         description: 'Field accepts any other process environment name'
      );

      // @ Schema scope
      foreach (["x`\n- Fake: forged", '', 'a/b', 'a.b'] as $scope) {
         $encoded = (string) json_encode($scope);
         yield assert(
            assertion: $refuse(fn () => new Schema(scope: $scope, Fields: [])) === 'Invalid config schema scope.',
            description: "Schema refuses a scope that is not a scope name: {$encoded}"
         );
      }

      // @ Schema keys
      $cases = [
         'wildcard' => [['Items.*' => new Field], 'Config schema key Items.* cannot hold a wildcard.'],
         'Config property' => [['Database.name' => new Field], 'Config schema key Database.name has an invalid node name.'],
         'protected Config property' => [['value' => new Field], 'Config schema key value has an invalid node name.'],
         'leading digit' => [['1Host' => new Field], 'Config schema key 1Host has an invalid node name.'],
         'empty segment' => [['Database..Host' => new Field], 'Config schema key Database..Host has an invalid node name.'],
         'escaped dot' => [['Data\\.base' => new Field], 'Config schema key Data\\.base has an invalid node name.'],
         'dash' => [['My-SQL' => new Field], 'Config schema key My-SQL has an invalid node name.'],
         'integer key' => [[0 => new Field], 'Config schema keys must be non-empty node paths.'],
         'empty key' => [['' => new Field], 'Config schema keys must be non-empty node paths.'],
         'not a Field' => [['Host' => 'localhost'], 'Config schema key Host must map to a Field.'],
         'value and children' => [
            ['Database' => new Field, 'Database.Host' => new Field],
            'Config schema key Database cannot hold both a value and Database.Host.'
         ],
         'children then value' => [
            ['Database.Host.Port' => new Field, 'Database.Host' => new Field],
            'Config schema key Database.Host cannot hold both a value and Database.Host.Port.'
         ],
      ];
      foreach ($cases as $case => [$Fields, $expected]) {
         // @phpstan-ignore argument.type
         $message = $refuse(fn () => new Schema(scope: 'test', Fields: $Fields));
         yield assert(
            assertion: $message === $expected,
            description: "Schema refuses: {$case}"
         );
      }

      // @ Siblings sharing a container are fine
      yield assert(
         assertion: $refuse(fn () => new Schema(scope: 'test', Fields: [
            'Database.Host' => new Field,
            'Database.Port' => new Field,
            'Database_Name' => new Field,
         ])) === null,
         description: 'sibling paths under one container are accepted'
      );
   }
);
