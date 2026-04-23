<!DOCTYPE html>
   <html lang="pt-BR">
   <head>
      <meta charset="UTF-8">
      <meta http-equiv="X-UA-Compatible" content="IE=edge">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title><?= $title ?? ''; ?>></title>
      <meta name="author" content="Rodrigo Vieira (@rodrigoslayertech)">
      <style>
         table, th, td {
            border: 1px solid black;
            border-radius: 5px;
         }
      </style>
   </head>
   <body>
      <div>Testing Bootgly $Response->render(...) method!</div>
      <h2>Template parameters - built-in</h2>
      <p>There are 3 parameters data built-in into WPI Templates: <b>$Request</b>, <b>$Response</b> and <b>$Route</b>!</p>
      <p><b>Examples:</b></p>
      <table>
         <tbody>
            <tr>
               <td>$Request->at</td>
               <td>@> $Request->at;</td>
            </tr>
            <tr>
               <td>$Response->code</td>
               <td>@> $Response->code;</td>
            </tr>
            <tr>
               <td>$Route->path</td>
               <td>@> $Route->path;</td>
            </tr>
         </tbody>
      </table>

      <h2>Template data - built-in</h2>
      <table>
         <thead>
            <tr>
               <th>Key</th>
               <th>Value</th>
            </tr>
         </thead>
         <tbody>
            <tr>
               <td>$undefined1</td>
               <td>@> $undefined1 ?? 'undefined';</td>
            </tr>
            <tr>
               <td><?= $test1 ?? '$test1 undefined' ?></td>
               <td>@> $test2 ?? '$test2 undefined';</td>
            </tr>
         </tbody>
      </table>
      <div><?= $test1 ?? '$test1 undefined' ?></div>
      <div>@> $test2 ?? '$test2 undefined';</div>
   </body>
</html>
