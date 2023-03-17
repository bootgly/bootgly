# Benchmark Bootgly HTTP Server (CLI) - Guide

## Test suggestion in localhost with 512 connections

### *If the machine has 24 CPU cores:*

### Without JIT enabled: ~40% of CPU

`wrk -t10 -c514 -d10s http://localhost:8080`

### With JIT enabled: ~50% of CPU

`wrk -t12 -c514 -d10s http://localhost:8080`
