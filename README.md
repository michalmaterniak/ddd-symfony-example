# symfony8

A Symfony 8.1 application (PHP 8.5 in the container, `>=8.4` required) that models
ledger accounts with entries on the Debit and Credit sides. The project is built
in DDD/CQRS style: state changes go through the Symfony Messenger command bus and (except
for creating an account from the CLI) are processed asynchronously by workers consuming RabbitMQ.

## Tech stack

| Area           | Technology                                                     |
|----------------|----------------------------------------------------------------|
| Framework      | Symfony 8.1 (Console, Messenger, Serializer, Validator, Monolog, Clock, Uid) |
| Persistence    | Doctrine ORM 3 + Doctrine Migrations, PostgreSQL 18            |
| Queues         | Symfony Messenger: RabbitMQ (AMQP) and the Doctrine transport  |
| Environment    | Docker Compose: nginx, php-fpm 8.5, postgres, rabbitmq         |
| Logs (optional)| Filebeat → Logstash → Elasticsearch → Kibana 9.5               |
| Quality        | PHPUnit 13 + Paratest, PHPStan (`level: max`), PHP-CS-Fixer, PHP_CodeSniffer, DAMA Doctrine Test Bundle |

The application is operated through console commands and a single REST endpoint
(`POST /api/v1/account/{accountId}/account-entry`, no authentication).

## Architecture

The code is split into modules (bounded contexts) and layers:

```
src/
├── Account/
│   ├── Domain/          # entities, value objects, events, repository interfaces
│   ├── Application/     # commands, command handlers, event listeners
│   ├── Infrastructure/  # Doctrine repositories, Doctrine types, fixtures
│   └── Presentation/    # console commands (Cli) and HTTP controller (Http)
└── Shared/              # AggregateRoot, DomainEvent, Amount, bus ports, Messenger middleware and retry,
                         # HTTP exception handling, logging and correlation id
```

### Domain model

- **`Account`** (aggregate root) – identifier (UUID), unique name, description, creation
  date, `credit` / `debit` totals and `version` (`#[ORM\Version]`, optimistic locking).
  `recordCredit()` / `recordDebit()` create a new `AccountEntry`, increase the corresponding total
  and record an `AccountCreditUpdated` / `AccountDebitUpdated` event (with the entry amount, without
  the account total). Concurrent writes to the same account end with an `OptimisticLockException`, which
  is retried.
- **`AccountEntry`** – a single entry on an account (a separate entity, not a collection
  in the aggregate): account, amount, side (`DEBIT`/`CREDIT`), effective date
  (`effective_at`) and creation date (`created_at`). Dates are stored as
  `TIMESTAMPTZ` (`Types::DATETIMETZ_IMMUTABLE`), so the offset given with the entry
  is not lost.
- **Value objects** – `AccountId`, `AccountEntryId`, `AccountName`, `AccountDescription`,
  `AccountCredit`, `AccountDebit`, the `AccountEntrySide` enum and the shared `Amount`
  (`Shared/Domain`, a non-negative integer in minor units, i.e. grosze).
  Identifiers, name, description and amount have their own Doctrine types
  (`Infrastructure/Doctrine/Types`), and the entry side is a native enum.

### Messenger

Three buses are configured (`config/packages/messenger.yaml`):

- **`command.bus`** (default) – middleware `CorrelationIdMiddleware`, `doctrine_ping_connection`
  and `doctrine_transaction`; commands (`App\Shared\Application\Command`) are routed
  to the `rabbit` transport. Command handlers are bound to this bus only
  (`#[AsMessageHandler(bus: 'command.bus')]`).
- **`event.bus`** – domain events (`App\Shared\Domain\DomainEvent`) also
  go to `rabbit`, with a default delay of 2 s (`DefaultDelayMiddleware`); events
  with no handlers and no routing do not cause an error. Listeners are bound only
  to this bus (`bus: 'event.bus'`).
- **`query.bus`** – reserved for queries (unused for now).

Every bus starts with `CorrelationIdMiddleware`, which carries the correlation id
across processes (see [Logging](#logging)).

The application uses the buses through its own `CommandBus` and `EventBus` interfaces
(`Shared/Application/Bus`). Both methods (`dispatch()`, `publish()`) accept
an optional `MessageOption` with the `MessageMode::ASYNC` / `MessageMode::SYNC` mode:

- by default (`NullMessageOption`, null object) and for `MessageModeOption::async()`
  the message follows the routing (`rabbit`) with `DispatchAfterCurrentBusStamp`,
  so when dispatched while handling another message it is sent only after the
  `doctrine_transaction` transaction commits,
- `MessageModeOption::sync()` overrides the routing with the `sync` transport (`TransportNamesStamp`):
  the handler runs immediately, in the current process, and its exception propagates to the caller.

A custom `RetryStrategy` retries messages with an increasing delay (1–4 s, max.
4 attempts), and `OptimisticLockException` is retried without limit. Failed
messages end up in the `failed` transport (Doctrine). In the test environment
the transports are replaced with `in-memory://`.

## Running

Docker with Compose is required. PHP is not needed on the host: all commands
are run in the `php` container (`make sh` or `docker compose exec php <cmd>`).
The `hostmanager` container adds the container names (`postgres`, `rabbitmq`, ...)
to `/etc/hosts`.

```bash
make up            # builds and starts nginx, php, postgres, rabbitmq
make down          # stops and removes the containers
make sh            # shell in the php container

# inside the container:
composer install
composer db:reset          # drop + create + migrations
composer dev:reset         # same for dev + fixtures
composer test:reset        # same for the test database
```

Messenger workers (`docker-compose.workers.yml`, by default 3 replicas of
`messenger:consume rabbit` and 1 of `messenger:consume doctrine`, each with `--time-limit=3600`):

```bash
make up-workers
make scale-workers-rabbit 10   # change the number of RabbitMQ workers (0 stops all of them)
make ps-workers
make logs-workers
make restart-workers
make down-workers
```

Optional ELK stack (Kibana at `http://127.0.0.1:5601`):

```bash
make up-logs
make ps-logs
make clear-logs   # deletes the docker-logs-* log indices (Elasticsearch must be running)
make down-logs
```

## HTTP API

`POST /api/v1/account/{accountId}/account-entry` adds an entry to an account. All fields
are required, extra fields are rejected:

```json
{
    "side": "credit",
    "amount": {"value": 1250, "currency": "PLN"},
    "effectiveAt": "2026-10-01T12:00:00+02:00"
}
```

- `side`: `credit` or `debit`, `amount.value`: integer > 0 in grosze,
  `amount.currency`: `PLN`,
- `effectiveAt`: `DateTimeInterface::ATOM` format, i.e. **with a time zone** (`+02:00`
  or `Z`); a date without a time zone is rejected.

The controller checks that the account exists and dispatches the command asynchronously to `rabbit`;
the entry is saved by a worker. Responses (JSON, built by `ExceptionListener`):

| Status | When                                                      | Body                                                            |
|--------|-----------------------------------------------------------|-----------------------------------------------------------------|
| `202`  | command accepted                                          | `{"id": "<uuid of the new entry>"}`                             |
| `400`  | invalid payload                                           | `{"type": "request-validation", "status", "message", "violations": [{"propertyPath", "message"}]}` |
| `404`  | `accountId` is not a UUID v4                              | `{"type": "url-parameters-validation", ..., "violations": [...]}` |
| `404`  | account does not exist                                    | `{"type": "not-found", "status", "message"}`                    |
| `422`  | domain error (`DomainException`)                          | `{"type": "domain-validation", "status", "message"}`            |
| `405`  | method other than `POST`                                  |                                                                 |

Every response has an `X-Correlation-Id` header.

## Console commands

```bash
bin/console app:account:create <name> <description>
bin/console app:account:add-credit <accountId> <amount> [--effective-at=...]
bin/console app:account:add-debit  <accountId> <amount> [--effective-at=...]
```

`create` executes the command synchronously: on success the account is already in the database, and an error
(e.g. an account name that is already taken) ends the command with a non-zero exit code. `add-credit` and `add-debit`
dispatch the command asynchronously to `rabbit`: the entry is saved by a worker (with retries on
optimistic locking conflicts), and only the worker checks whether the account exists. Domain events
always go asynchronously to `rabbit`. Amounts are given in grosze (minor units),
`--effective-at` accepts any format understood by `DateTimeImmutable`, including
an offset (default: now).

### Stress test

Two scripts run on the host (they require workers: `make up-workers`):

- `helpers/stresstest-account.sh <accountId>` runs in the `php` container, one after another, N
  `add-credit` / `add-debit` commands for a single account, with a random side, amount and `--effective-at`, and prints
  a summary (`ok`, `failed`, requested credit/debit totals, time). It can be run on its own.
- `helpers/stresstest.sh` purges the RabbitMQ queues (`messages` and `delay_*`), resets the dev database
  (`composer dev:reset`), and then runs `stresstest-account.sh` for every account in the database, at most
  `--parallel` accounts at a time, and adds up the results. Ctrl+C stops all loops after the current command.

This makes it possible to check write consistency under concurrent processing by many workers: once the
queue is drained (`bin/console messenger:stats`), the totals in `account_entry` should equal the `credit` / `debit`
fields in `account`.

```bash
helpers/stresstest.sh                                              # 50 accounts × 200 commands, 10 accounts at a time
helpers/stresstest.sh -n 1000 -p 5 -d 30                           # 1000 commands per account, 5 accounts at a time, 30% debit
helpers/stresstest.sh --no-purge --no-reset                        # without purging the queues and the database
helpers/stresstest-account.sh 00000000-0000-4000-8000-000000000001 -n 50   # single account
helpers/stresstest.sh --help                                       # all options
```

## Logging

Monolog writes JSON to the container's stderr, from where (with `make up-logs`) Filebeat forwards
the logs through Logstash to Elasticsearch (`docker-logs-*` indices). Everything from
level `info` upwards goes to ES, from all channels, from HTTP processes and workers. In `dev` there is also
`var/log/dev.log` (`debug`). Workers read the configuration at startup, so after changing it
they must be restarted (`make restart-workers`).

- Every entry has `extra.service` (`APP_SERVICE_NAME`) and `extra.correlationId`.
- `HttpLoggingListener` (`http` channel) logs `HttpRequest` (method, URI, headers, payload, IP)
  and `HttpResponse` (status, payload, route, controller, exception, `durationMs`, `memoryPeakBytes`),
  and `HttpRequestAborted` (`critical`) when the process ends without a response. Sensitive headers
  and payload keys (passwords, tokens, `iban`, `cardNumber`, ...) are masked, the payload is truncated
  to `HTTP_LOG_MAX_PAYLOAD_BYTES`. The `/_profiler` and `/_wdt` paths are skipped.
- `ExceptionListener` logs handled exceptions: 400/422 as `warning`, 404 as `info`,
  the rest (500 response) as `critical`.

### Correlation id

- HTTP: the id from the `X-Correlation-Id` header, if it is a valid UUID; otherwise a new UUID v7.
  The id is returned in the response header.
- CLI: every command run gets a new id (`ConsoleCorrelationIdListener`), except for
  `messenger:consume` and `messenger:failed:retry`.
- Messenger: `CorrelationIdMiddleware` adds a `CorrelationIdStamp` with the current id
  to the outgoing message, and on receipt (worker, `sync` transport) sets the id from the message. Commands
  and events triggered by a single HTTP request or a single CLI command therefore share the same id, also
  after a retry and in the `failed` transport.

In Kibana: `app.extra.service: symfony8 and app.extra.correlationId: "<uuid>"`.

## Tests and code quality

```bash
composer test           # test:sa + test:phpunit
composer test:sa        # php-cs-fixer check + phpcs + phpstan
composer test:phpunit   # tests only (paratest)
composer test:reset     # recreate the test database
composer cs-fix         # PHP-CS-Fixer fixes

# from the host:
make cs / make cs-fix   # PHP-CS-Fixer (dry-run / fixes)
make phpcs              # PHP_CodeSniffer (line length limit only)
make phpstan
```

The PHPUnit configuration is in the versioned `phpunit.dist.xml`. Local overrides
can be kept in `phpunit.xml` (ignored by git, takes precedence).

Tests are divided into:

- `tests/Unit` – value objects, entities, Doctrine types, `RetryStrategy`, `DefaultDelayMiddleware`,
  HTTP request validation, `ExceptionListener`, correlation id listeners,
- `tests/Integration` – handlers, listeners, repositories, bus implementations, `CorrelationIdMiddleware`
  and message serialization on a real `*_test` database, with rollback via DAMA,
- `tests/Functional` – console commands (`CommandTester`) and the HTTP endpoint (`WebTestCase`) with assertions
  on dispatched messages, HTTP logging and correlation id in the CLI,
- `tests/Support` – helpers, including `DispatchedMessagesAssertionTrait` for checking
  what was dispatched on a given bus and to which transport.

## Configuration

Default variable values are in `.env` (and `.env.dev`, `.env.test`):

- `DATABASE_URL` – PostgreSQL in the `postgres` container,
- `MESSENGER_RABBIT_DSN` – RabbitMQ in the `rabbitmq` container,
- `MESSENGER_DOCTRINE_DSN` – database-backed queue,
- `APP_SERVICE_NAME` – service name in logs (`extra.service`, default `symfony8`),
- `HTTP_LOG_MAX_PAYLOAD_BYTES` – limit of the logged HTTP payload (default 16 KB).

Local overrides and secrets should be kept in `.env.local` (outside the repository)
or in the Symfony secrets vault.
