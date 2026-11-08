<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validators\Confirmed;
use Bootgly\ADI\Validators\In;
use Bootgly\ADI\Validators\Maximum;
use Bootgly\ADI\Validators\Minimum;
use Bootgly\ADI\Validators\Regex;
use Bootgly\ADI\Validators\Required;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\Config\Types;
use Bootgly\API\Environment\Configs\Schema;
use Bootgly\API\Environment\Configs\Schema\Failure;
use Bootgly\API\Environment\Configs\Schema\Field;


return new Test(
   description: 'Config schema: every failure of a scope in one report that never echoes a value',
   test: function () {
      // @ Clean env (also when the test stops early)
      $names = [
         'SCHEMA_F_PORT', 'SCHEMA_F_WORKERS', 'SCHEMA_F_PASS', 'SCHEMA_F_MODE', 'SCHEMA_F_TOKEN', 'SCHEMA_F_KEY',
         'SCHEMA_F_SECRET', 'SCHEMA_F_CHECK', 'SCHEMA_F_LIMIT',
      ];
      foreach ($names as $name) {
         putenv($name);
      }
      try {

         $secret = 'hunter2-S3CRET-value';
         $Schema = new Schema(scope: 'server', Fields: [
            'Host' => new Field(default: 'localhost'),
            'Port' => new Field(key: 'SCHEMA_F_PORT', default: 8080, cast: Types::Integer, Rules: [
               new Minimum(1),
               new Maximum(65535),
            ]),
            'Workers' => new Field(key: 'SCHEMA_F_WORKERS', default: 4, cast: Types::Integer),
            'Connections.Primary.Password' => new Field(key: 'SCHEMA_F_PASS', default: 'fallback', required: true),
            'Connections.Primary.Mode' => new Field(key: 'SCHEMA_F_MODE', Rules: [new In(['read', 'write'])]),
            'Auth.Token' => new Field(key: 'SCHEMA_F_TOKEN', Rules: [new Regex('/\A[a-f0-9]{64}\z/')]),
            'Auth.Key' => new Field(key: 'SCHEMA_F_KEY', Rules: [new Required]),
         ]);

         // @ Several failures at once: rule, cast, missing required, rule, rule, implicit rule
         putenv('SCHEMA_F_PORT=70000');
         putenv("SCHEMA_F_WORKERS={$secret}");
         putenv("SCHEMA_F_MODE={$secret}");
         putenv("SCHEMA_F_TOKEN={$secret}");

         $Failure = null;
         try {
            $Schema->build();
         }
         catch (Failure $Caught) {
            $Failure = $Caught;
         }

         yield assert(
            assertion: $Failure instanceof Failure && $Failure instanceof RuntimeException,
            description: 'an invalid scope throws one Schema\\Failure (a RuntimeException)'
         );
         if ($Failure === null) {
            return;
         }

         yield assert(
            assertion: $Failure->scope === 'server',
            description: 'the report names the scope'
         );
         yield assert(
            assertion: $Failure->failures === [
               'Port' => ['Port must be at most 65535.'],
               'Workers' => ['Invalid integer config value.'],
               'Connections.Primary.Password' => ['Required config value is missing: SCHEMA_F_PASS'],
               'Connections.Primary.Mode' => ['Connections.Primary.Mode must be one of the allowed values.'],
               'Auth.Token' => ['Auth.Token has an invalid format.'],
               'Auth.Key' => ['Auth.Key is required.'],
            ],
            description: 'every failure is collected, keyed by path, in declaration order'
         );
         yield assert(
            assertion: $Failure->getMessage() === <<<'REPORT'
            Invalid config scope `server` (6 failures):
            - Port: Port must be at most 65535.
            - Workers: Invalid integer config value.
            - Connections.Primary.Password: Required config value is missing: SCHEMA_F_PASS
            - Connections.Primary.Mode: Connections.Primary.Mode must be one of the allowed values.
            - Auth.Token: Auth.Token has an invalid format.
            - Auth.Key: Auth.Key is required.
            REPORT,
            description: 'the message lists one line per reason with scope, path and reason'
         );

         // @ Secret redaction: no rejected value, no default reaches the report
         $encoded = (string) json_encode($Failure->failures);
         $dump = "{$Failure->getMessage()}{$encoded}";
         yield assert(
            assertion: str_contains($dump, $secret) === false
               && str_contains($dump, '70000') === false
               && str_contains($dump, 'fallback') === false,
            description: 'the report never echoes a rejected value or a default'
         );

         // @ A required field ignores its default, also for an empty value
         putenv('SCHEMA_F_PORT');
         putenv('SCHEMA_F_WORKERS');
         putenv('SCHEMA_F_MODE');
         putenv('SCHEMA_F_TOKEN');
         putenv('SCHEMA_F_KEY=present');
         putenv('SCHEMA_F_PASS=');
         $Empty = null;
         try {
            $Schema->build();
         }
         catch (Failure $Caught) {
            $Empty = $Caught;
         }
         yield assert(
            assertion: $Empty !== null
               && $Empty->failures === ['Connections.Primary.Password' => ['Required config value is missing: SCHEMA_F_PASS']]
               && $Empty->getMessage() === "Invalid config scope `server` (1 failure):\n- Connections.Primary.Password: Required config value is missing: SCHEMA_F_PASS",
            description: 'an empty required value fails alone, singular count'
         );

         // @ A field whose bind fails skips its rules (one reason per problem)
         putenv('SCHEMA_F_PASS=set');
         putenv('SCHEMA_F_PORT=abc');
         $Cast = null;
         try {
            $Schema->build();
         }
         catch (Failure $Caught) {
            $Cast = $Caught;
         }
         yield assert(
            assertion: $Cast !== null && $Cast->failures === ['Port' => ['Invalid integer config value.']],
            description: 'a cast refusal is reported once and its rules are not run'
         );

         // @ Optional blank values skip non-implicit rules (ADI Validation semantics)
         putenv('SCHEMA_F_PORT');
         $Valid = $Schema->build();
         yield assert(
            assertion: $Valid->Connections->Primary->Mode->get() === null
               && $Valid->Auth->Token->get() === null
               && $Valid->Port->get() === 8080,
            description: 'absent optional values pass non-implicit rules; defaults are validated'
         );

         // @ The scope-local .env is honoured and its values stay out of the report
         putenv('SCHEMA_F_PASS');
         $previous = Config::swap(['SCHEMA_F_PORT' => "{$secret}"]);
         $Local = null;
         try {
            $Schema->build();
         }
         catch (Failure $Caught) {
            $Local = $Caught;
         }
         finally {
            Config::swap($previous);
         }
         yield assert(
            assertion: $Local !== null
               && array_keys($Local->failures) === ['Port', 'Connections.Primary.Password']
               && str_contains($Local->getMessage(), $secret) === false,
            description: 'local .env values fail without being echoed'
         );

         // @ Cross-field rules see fields that have no rules of their own
         $Confirm = new Schema(scope: 'confirm', Fields: [
            'Secret' => new Field(key: 'SCHEMA_F_SECRET', Rules: [new Confirmed('Check')]),
            'Check' => new Field(key: 'SCHEMA_F_CHECK'),
         ]);
         putenv("SCHEMA_F_SECRET={$secret}");
         putenv("SCHEMA_F_CHECK={$secret}");
         $Confirmed = $Confirm->build();
         yield assert(
            assertion: $Confirmed->Secret->get() === $secret,
            description: 'a Confirmed rule reads a field without rules (every resolved value is visible)'
         );
         putenv('SCHEMA_F_CHECK=other');
         $Mismatch = null;
         try {
            $Confirm->build();
         }
         catch (Failure $Caught) {
            $Mismatch = $Caught;
         }
         yield assert(
            assertion: $Mismatch !== null && $Mismatch->failures === ['Secret' => ['Secret confirmation does not match.']],
            description: 'a mismatching confirmation still fails'
         );

         // @ A default that breaks a rule is reported (defaults are validated)
         $Defaulted = new Schema(scope: 'defaulted', Fields: [
            'Limit' => new Field(key: 'SCHEMA_F_LIMIT', default: 0, cast: Types::Integer, Rules: [new Minimum(1)]),
         ]);
         $Default = null;
         try {
            $Defaulted->build();
         }
         catch (Failure $Caught) {
            $Default = $Caught;
         }
         yield assert(
            assertion: $Default !== null && $Default->failures === ['Limit' => ['Limit must be at least 1.']],
            description: 'a default that breaks a rule fails like a configured value'
         );

         // @ A rule that throws fails its field; the exception and its values are dropped
         $Throwing = new class extends Condition {
            public function validate (string $field, mixed $value, array $data): bool
            {
               throw new LogicException("leak: {$value}");
            }
         };
         $Thrower = new Schema(scope: 'thrower', Fields: [
            'Token' => new Field(key: 'SCHEMA_F_TOKEN', Rules: [$Throwing]),
            'Port' => new Field(key: 'SCHEMA_F_PORT', default: 8080, cast: Types::Integer, Rules: [new Maximum(10)]),
         ]);
         putenv("SCHEMA_F_TOKEN={$secret}");
         $Thrown = null;
         try {
            $Thrower->build();
         }
         catch (Failure $Caught) {
            $Thrown = $Caught;
         }
         yield assert(
            assertion: $Thrown !== null
               && $Thrown->failures === [
                  'Token' => ['A rule of this field could not run.'],
                  'Port' => ['Port must be at most 10.'],
               ]
               && $Thrown->getPrevious() === null
               && str_contains($Thrown->getMessage(), $secret) === false,
            description: 'a throwing rule is one failure of its field; the other fields are still checked'
         );
      }
      finally {
         foreach ($names as $name) {
            putenv($name);
         }
      }
   }
);
