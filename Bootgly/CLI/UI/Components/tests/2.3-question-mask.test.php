<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should mask secret answers and never reveal masked defaults',
   test: function () {
      // ! Question with in-memory streams (self-echo redirected to memory)
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "hunter2\n");
      rewind($stream);

      $echo = fopen('php://memory', 'r+');

      $Input = new Input($stream); // @phpstan-ignore-line
      $Input->echo = true;
      $Input->output = $echo; // @phpstan-ignore-line

      $Output = new Output('php://memory');

      // @ Masked answer
      $Question = new Question($Input, $Output);
      $Question->prompt = 'Password';
      $Question->mask = '•';

      yield assert(
         assertion: $Question->ask() === 'hunter2',
         description: 'The mask never leaks into the answer value'
      );

      rewind($echo);
      $echoed = (string) stream_get_contents($echo);

      yield assert(
         assertion: $echoed === "•••••••\n",
         description: 'Each typed character self-echoes the mask'
      );

      // @ Masked default is never revealed by the prompt
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "\n");
      rewind($stream);

      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Question = new Question($Input, $Output);
      $Question->prompt = 'Token';
      $Question->default = 'secret-token';
      $Question->mask = '•';

      $answer = $Question->ask();

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      // @ Valid
      yield assert(
         assertion: $answer === 'secret-token',
         description: 'Empty answer still assumes the real default value'
      );
      yield assert(
         assertion: str_contains($output, 'secret-token') === false,
         description: 'The default value never appears in the rendered prompt'
      );
      yield assert(
         assertion: str_contains($output, '•••') === true,
         description: 'The masked default renders as the mask repeated'
      );
   }
);
