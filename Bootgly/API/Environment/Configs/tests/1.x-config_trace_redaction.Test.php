<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\Config\Types;


return new Test(
   description: 'Config: cast failures keep the value and the default out of exception traces',
   test: function () {
      $secret = 'hunter2-T4ACE-secret';
      // ! Whether any frame of the trace carries the secret as an argument
      $leaks = function (Throwable $Throwable) use ($secret): bool {
         foreach ($Throwable->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
               if (is_scalar($argument) && str_contains((string) $argument, $secret)) {
                  return true;
               }
            }
         }

         return false;
      };
      $capture = function (callable $action): null|Throwable {
         try {
            $action();
         }
         catch (Throwable $Throwable) {
            return $Throwable;
         }

         return null;
      };

      // @ Traces with arguments, as with the PHP built-in default
      $ignore = ini_get('zend.exception_ignore_args');
      ini_set('zend.exception_ignore_args', '0');
      putenv('TRACE_PORT');
      try {
         // @ A rejected environment value
         putenv("TRACE_PORT={$secret}");
         $Env = $capture(fn () => new Config(scope: 'test')->Port->bind(key: 'TRACE_PORT', cast: Types::Integer));
         putenv('TRACE_PORT');
         yield assert(
            assertion: $Env instanceof InvalidArgumentException
               && $Env->getTrace() !== []
               && $leaks($Env) === false,
            description: 'a rejected env value is not a trace argument'
         );

         // @ A rejected default
         $Default = $capture(fn () => new Config(scope: 'test')->Port->bind(key: 'TRACE_PORT', default: $secret, cast: Types::Integer));
         yield assert(
            assertion: $Default instanceof InvalidArgumentException && $leaks($Default) === false,
            description: 'a rejected default is not a trace argument'
         );

         // @ The redacted frames say so
         $redacted = false;
         foreach ($Default?->getTrace() ?? [] as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
               if ($argument instanceof SensitiveParameterValue) {
                  $redacted = true;
               }
            }
         }
         yield assert(
            assertion: $redacted,
            description: 'the redacted arguments appear as SensitiveParameterValue'
         );

         // @ A direct cast
         $Direct = $capture(fn () => Types::Boolean->cast($secret));
         yield assert(
            assertion: $Direct instanceof InvalidArgumentException
               && $Direct->getMessage() === 'Invalid boolean config value.'
               && $leaks($Direct) === false,
            description: 'Types::cast() keeps its message and drops the value from the trace'
         );
      }
      finally {
         putenv('TRACE_PORT');
         ini_set('zend.exception_ignore_args', (string) $ignore);
      }
   }
);
