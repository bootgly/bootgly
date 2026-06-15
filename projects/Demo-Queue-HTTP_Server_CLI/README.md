# Demo — Queue + HTTP_Server_CLI

Enqueue background jobs from HTTP routes and process them in a separate worker process.
The request returns **instantly**; the slow work (here, "sending an email") runs later in
`bootgly queue run`.

```
HTTP request ──► route handler ──► Queues::dispatch(SendEmail, payload) ──► (responds now)
                                                │
                                          workdata/queues/emails
                                                │
                              bootgly queue run ─┴─► SendEmail->handle()  ──► workdata/queue-demo.log
```

## Files

| File | Role |
|---|---|
| `Demo-Queue-HTTP_Server_CLI.project.php` | boots `HTTP_Server_CLI`, registers the router, configures the queue (`Queues::boot`) |
| `router/Queue.SAPI.php` | routes — `/email/:to` enqueues a job and responds immediately |
| `SendEmail.php` | the `Queues\Handler` that runs in the worker (logs proof of work) |
| `queues.php` | worker config + **handler loading** (the worker `require`s it) |

## Run it

**1. Start the server** (daemon):

```bash
bootgly project Demo-Queue-HTTP_Server_CLI        # add -i to run in the foreground
```

**2. Enqueue jobs** over HTTP — each returns at once with a job id:

```bash
curl http://127.0.0.1:8083/email/alice@example.com
curl http://127.0.0.1:8083/email/bob@example.com
curl http://127.0.0.1:8083/queue                  # → {"queue":"emails","ready":2}
```

**3. Process them** with a worker. Run it **from this project directory** so it loads the
project's `queues.php` (which `require`s `SendEmail`):

```bash
cd projects/Demo-Queue-HTTP_Server_CLI
bootgly queue run emails                           # Ctrl+C to stop
tail -f ../../workdata/queue-demo.log              # watch jobs being processed
```

**Stop the server:**

```bash
bootgly project Demo-Queue-HTTP_Server_CLI stop
```

## Notes

- **Why a separate process?** The HTTP route only **enqueues** (a quick local write) and replies
  immediately — it never blocks the event loop. The blocking part runs in `queue run`, which you
  can scale out to several workers (a job is claimed atomically, never processed twice).
- **Handler loading.** The worker process does not boot the web project, so it cannot autoload
  `projects\…\SendEmail`. `queues.php` `require`s it — that is why you run the worker from the
  project directory (`queue run` looks for `queues.php` in the current directory).
- **Switch to Redis** for cross-host workers: set `'driver' => 'redis'` (plus `host`/`port`) in
  both `Queues::boot([...])` (the project) and `queues.php` (the worker).
- See the **[Queues guide](https://docs.bootgly.com/guide/queues/overview/)** for the full API.
