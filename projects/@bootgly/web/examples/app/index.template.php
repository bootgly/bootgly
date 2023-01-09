<!DOCTYPE html>
<html lang="pt-BR">
   <head>
      <title>@>> $title;</title>

      <meta charset="utf-8">
      <meta name="description" content="@>> $description;">
      <meta name="format-detection" content="telephone=no">
      <meta name="msapplication-tap-highlight" content="no">
      <meta name="viewport" content="initial-scale=1, width=device-width">

      <link rel="icon" type="image/png" sizes="128x128" href="icons/favicon-128x128.png">
      <link rel="icon" type="image/png" sizes="96x96" href="icons/favicon-96x96.png">
      <link rel="icon" type="image/png" sizes="32x32" href="icons/favicon-32x32.png">
      <link rel="icon" type="image/png" sizes="16x16" href="icons/favicon-16x16.png">
      <link rel="icon" type="image/ico" href="favicon.ico">
   </head>
   <body>
      <div id="ifs-1">
         @if ($testA):
         <span>if</span>
         @elseif ($test1):
         <span>else if #1</span>
         @else:
         <span>else</span>
         @if;
      </div>

      <div id="loops-foreach-1">
         @foreach ($items as $key => $item):
            @if ($@->index === 1):
               @>> 'First!';
            @if;
            @>> $@->index;
         @foreach;
      </div>
      <div id="loops-for-1">
         @for ($i = 0; $i <= 10; $i++):
            @break in $i === 3;

            @>> $i;
         @for;
      </div>
      <div id="loops-while-1">
         @while $tenth:
            @>> $tenth--;
         @while;
      </div>
   </body>
</html>