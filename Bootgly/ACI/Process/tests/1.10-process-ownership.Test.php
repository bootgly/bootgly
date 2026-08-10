<?php

use Bootgly\ACI\Process;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Process: master identity and child topology remain instance-owned across forks',
   skip: function_exists('pcntl_fork') === false,
   test: function () {
      $parentPID = getmypid();
      $Process = new Process('ProcessOwnershipTest', (string) $parentPID);
      $Sockets = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP
      );
      if ($Sockets === false) {
         throw new RuntimeException('Could not create the worker ownership channel.');
      }

      $Process->fork(1, function (Process $Worker, int $index) use ($Sockets, $parentPID): void {
         fclose($Sockets[0]);
         $Nested = new Process('NestedProcessOwnershipTest', (string) getmypid());
         $result = [
            'index' => $index,
            'master' => $Worker->master,
            'level_before' => $Worker->level,
            'children' => $Worker->Children->PIDs,
            'nested_master' => $Nested->master,
            'nested_level' => $Nested->level,
            'level_after' => $Worker->level,
            'claim_rejected' => $Worker->claim() === false,
            'parent' => $parentPID,
            'worker' => getmypid(),
         ];
         fwrite($Sockets[1], json_encode($result, JSON_THROW_ON_ERROR));
         fclose($Sockets[1]);
      });

      fclose($Sockets[1]);
      $JSON = stream_get_contents($Sockets[0]);
      fclose($Sockets[0]);
      $workerPIDs = array_values($Process->Children->PIDs);
      $workerPID = $workerPIDs[0] ?? -1;
      $status = 0;
      $waited = $workerPID > 0 ? pcntl_waitpid($workerPID, $status) : -1;
      if ($workerPID > 0) {
         $Process->Children->remove($workerPID);
      }
      $result = is_string($JSON) ? json_decode($JSON, true) : null;

      yield assert(
         assertion: $waited === $workerPID
            && pcntl_wifexited($status)
            && pcntl_wexitstatus($status) === 0
            && is_array($result)
            && ($result['master'] ?? null) === $parentPID
            && ($result['level_before'] ?? null) === 'child'
            && ($result['children'] ?? null) === []
            && ($result['nested_master'] ?? null) === $workerPID
            && ($result['nested_level'] ?? null) === 'master'
            && ($result['level_after'] ?? null) === 'child'
            && ($result['claim_rejected'] ?? null) === true,
         description: 'a nested Process neither reclassifies its worker nor grants it sibling authority'
      );

      $Daemon = new Process('ProcessClaimTest', (string) $parentPID);
      $ClaimSockets = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP
      );
      if ($ClaimSockets === false) {
         throw new RuntimeException('Could not create the daemon claim channel.');
      }
      $claimPID = pcntl_fork();
      if ($claimPID === 0) {
         fclose($ClaimSockets[0]);
         $claimed = $Daemon->claim();
         fwrite($ClaimSockets[1], json_encode([
            'claimed' => $claimed,
            'master' => $Daemon->master,
            'level' => $Daemon->level,
            'pid' => getmypid(),
         ], JSON_THROW_ON_ERROR));
         fclose($ClaimSockets[1]);
         exit(0);
      }
      fclose($ClaimSockets[1]);
      $claimJSON = stream_get_contents($ClaimSockets[0]);
      fclose($ClaimSockets[0]);
      $claimStatus = 0;
      $claimWaited = $claimPID > 0 ? pcntl_waitpid($claimPID, $claimStatus) : -1;
      $claim = is_string($claimJSON) ? json_decode($claimJSON, true) : null;

      yield assert(
         assertion: $claimWaited === $claimPID
            && pcntl_wifexited($claimStatus)
            && pcntl_wexitstatus($claimStatus) === 0
            && is_array($claim)
            && ($claim['claimed'] ?? null) === true
            && ($claim['master'] ?? null) === $claimPID
            && ($claim['level'] ?? null) === 'master'
            && ($claim['pid'] ?? null) === $claimPID,
         description: 'a pre-worker daemon fork can explicitly claim its own instance identity'
      );
   }
);
