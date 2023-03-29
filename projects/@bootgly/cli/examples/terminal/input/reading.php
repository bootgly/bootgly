<?php
namespace Bootgly\CLI;


require '/home/rodrigo/bootgly/bootgly-php-framework/@/autoload.php';


use Bootgly\CLI;


$Input = CLI::$Terminal->Input;
$Output = CLI::$Terminal->Output;


$Input->reading(
   // Terminal Client API
   CAPI: function ($read, $write) // Client Input { $read, $write }
   {
      // * Config
      $timeout = 10;
      // * Data
      $line = '';
      // * Meta
      $started = microtime(true);

      while (true) {
         // @ Autoread each character from Terminal Input (in non-blocking mode)
         // This is as if the terminal input buffer is disabled
         $char = $read(length: 1); // Only length === 1 is acceptable (for now)

         // @ Check timeout
         if (microtime(true) - $started > $timeout) {
            echo "Client: `Terminal Input Timeout! Closing Client...`\n\n";
            exit(0);
         }

         // @ Parse char
         // No data
         if ($char === '') {
            usleep(100000);
            continue;
         }
         // EOL
         if ($char === "\n") {
            // @ Write user data to Terminal Input (Client => Server)
            $write(data: $line);

            $line = '';

            continue;
            #break;
         }
         // ...
         echo "$char\n";
         $line .= $char;
      }
   },
   // Terminal Server API
   SAPI: function ($reading) // Server Input { $reading }
   use ($Output)
   {
      // * Config
      $timeout = 10;

      while (true) {
         $error = false;

         // @ Reading input data from the Client Terminal
         foreach ($reading(timeout: $timeout) as $data) {
            // @ Write user data to Terminal Output (Server => Client)
            if ($data) {
               $Output->render(data: "\nYou entered: @#red:" . json_encode($data) . " @;\n\n");
               break;
            } else if ($data === null) {
               $Output->write(data: "Server: `No data received from Client.`\n");
            } else {
               $error = true;
               $Output->write(data: "Unexpected data from Client. Client is dead?\n\n");
            }
         }

         if ($error) break;
      }
   }
);

echo "Finish example...\n";
