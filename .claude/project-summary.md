# Podsumowanie projektu `symfony8` (kontekst dla Claude)

> Stan na 2026-10-13 (commit `59a9890` + niezacommitowane zmiany, gałąź `master`). Szczegóły uruchomienia są w `README.md`,
> zasady pisania kodu w `AGENTS.md`. Raporty review (skill `project-review`) trafiają do `.claude/review/`
> (pierwszy: `2026-10-12_23_48_31.md`).

## Czym jest projekt

Konta księgowe z zapisami Winien (debit) / Ma (credit). Styl DDD + CQRS na Symfony Messenger.
**Brak interfejsu HTTP**: obsługa wyłącznie przez komendy konsolowe. Zmiany stanu idą
asynchronicznie przez RabbitMQ i workery.

## Stos

- PHP `>=8.4` (kontener: PHP 8.5), Symfony **8.1**, Doctrine ORM 3 + Migrations, PostgreSQL 18.
- Messenger: transporty `rabbit` (AMQP), `doctrine`, `failed` (Doctrine), `sync`.
- Brak Twig, Security, Validatora, API Platform, Form. Jest Serializer (JSON dla Messengera), Clock, Uid.
- Dev: PHPUnit 13 + Paratest, DAMA Doctrine Test Bundle, PHPStan (`level: max`), PHP-CS-Fixer (`@Symfony`),
  PHP_CodeSniffer (tylko limit długości linii), maker-bundle, doctrine-fixtures-bundle.

## Środowisko (ważne)

- **Na hoście nie ma PHP**: wszystko uruchamiaj w kontenerze: `docker compose exec php <cmd>`
  (lub `make sh`). Nazwy hostów: `postgres`, `rabbitmq` (kontener `hostmanager` dopisuje `/etc/hosts`).
- `make up` / `make down`: nginx, php, postgres, rabbitmq.
- `make up-workers`, `make scale-workers-rabbit N`, `make logs-workers`: workery (`docker-compose.workers.yml`,
  domyślnie 3× `messenger:consume rabbit` + 1× `doctrine`).
- `make up-logs`: opcjonalny ELK (Filebeat → Logstash → Elasticsearch → Kibana :5601).
- Composer scripts: `composer test` (= `test:sa`: cs check + phpcs + phpstan, potem `test:phpunit`: paratest),
  `db:reset`, `test:reset`, `dev:reset` (z fixtures), `cs-fix`. Konfiguracja PHPUnit: wersjonowany `phpunit.dist.xml`
  (`phpunit.xml` jest ignorowany, służy do lokalnych nadpisań).

## Struktura kodu

```
src/
├── Account/                      # bounded context
│   ├── Domain/
│   │   ├── Entity/               # Account (AggregateRoot), AccountEntry
│   │   ├── ValueObject/          # AccountId, AccountEntryId, AccountName, AccountDescription,
│   │   │                         # AccountCredit, AccountDebit, AccountEntrySide (enum DEBIT/CREDIT)
│   │   ├── Event/                # AccountCreated, AccountCreditUpdated, AccountDebitUpdated
│   │   ├── Dto/, Exception/, Repository/ (interfejsy)
│   ├── Application/
│   │   ├── Command/              # CreateAccountCommand, AddAccountEntryCreditCommand, AddAccountEntryDebitCommand
│   │   ├── Handler/              # #[AsMessageHandler(bus: 'command.bus')] dla komend
│   │   └── Listener/             # #[AsMessageHandler(bus: 'event.bus')] dla zdarzeń (AccountCreatedListener,
│   │                             # AccountCreditAddedListener, AccountDebitAddedListener)
│   ├── Infrastructure/
│   │   ├── Doctrine/Repository/  # DoctrineAccountRepository, DoctrineAccountEntryRepository
│   │   ├── Doctrine/Types/       # typy DBAL: account_id, account_entry_id, account_name,
│   │   │                         # account_description, amount (side to natywny Types::ENUM)
│   │   └── DataFixtures/
│   └── Presentation/Cli/         # AccountCommands, AccountEntryCommands, StressTestForAccountUpdate
└── Shared/
    ├── Application/Command.php   # interfejs-marker komend (routing → rabbit)
    ├── Application/Bus/          # CommandBus::dispatch(Command, MessageOption = NullMessageOption),
    │                             # EventBus::publish(list<DomainEvent>, MessageOption = NullMessageOption),
    │                             # MessageOption (interfejs), MessageMode (enum ASYNC/SYNC),
    │                             # NullMessageOption (null object → ASYNC), MessageModeOption::sync()/async()
    ├── Domain/                   # AggregateRoot (pullDomainEvents), DomainEvent (marker),
    │                             # ValueObject/Amount, Exception/DomainException
    └── Infrastructure/Messenger/ # MessengerCommandBus / MessengerEventBus, MessageOptionStamps (MessageOption → stampy),
                                  # DefaultDelayMiddleware (event.bus, 2 s), RetryStrategy
```

Mapowanie Doctrine: atrybuty, tylko katalog `src/Account/Domain/Entity` (alias `Domain`).

## Kluczowe decyzje modelu

- `Account`: id (UUID), unikalne `name`, `description`, `created_at`. **Agregat nie zna sum `credit`/`debit`**
  (decyzja z 2026-10-13): nie ma ich w tabeli, encji ani zdarzeniach. Saldo, gdy będzie potrzebne, ma być pobierane
  w warstwie aplikacji i przekazywane w DTO. `DoctrineAccountRepository` to zwykłe `find()` / `findOneBy()`.
  Konstruktor (przez `Account::create()`) rejestruje zdarzenie `AccountCreated`.
- `Account::recordCredit()` / `recordDebit()` tworzą `AccountEntry` (trzymany w `newAccountEntries`) i rejestrują
  `AccountCreditUpdated` / `AccountDebitUpdated` (tylko kwota wpisu, bez sumy konta).
  Handler pobiera wpisy przez `getNewAccountEntries()`, zapisuje je `AccountEntryRepository::new()` i publikuje
  zdarzenia przez `EventBus::publish($account->pullDomainEvents())`. Brak konta →
  `UnrecoverableMessageHandlingException`.
- `AccountEntry`: osobna encja (nie kolekcja w agregacie, indeks na `account_id`) z `account_id`, `amount`
  (typ `amount`), `side` (`AccountEntrySide`), `effective_at`, `created_at`. Gettery `credit()` / `debit()`
  rzucają wyjątek dla wpisu z przeciwnej strony.
- Daty (`account.created_at`, `account_entry.effective_at` / `created_at`): `Types::DATETIMETZ_IMMUTABLE`
  (`TIMESTAMPTZ`). Cały schemat tworzy jedna migracja `Version20261012221713`.
- Kwoty: nieujemne liczby całkowite w groszach (`Shared\Domain\ValueObject\Amount`); `AccountCredit` i `AccountDebit`
  dziedziczą po `Amount`. Komendy niosą `AccountCredit` / `AccountDebit`, encja wpisu trzyma `Amount`.
- Czas: `Symfony\Component\Clock\Clock::get()->now()`.

## Messenger (`config/packages/messenger.yaml`)

- `command.bus` (domyślny): middleware `doctrine_ping_connection`, `doctrine_transaction`.
- `event.bus`: `allow_no_handlers`, `allow_no_senders`, `DefaultDelayMiddleware` (DelayStamp 2 s dla nowych wiadomości).
- `query.bus`: zarezerwowany, nieużywany.
- Routing: `App\Shared\Application\Command` → `rabbit`, `App\Shared\Domain\DomainEvent` → `rabbit`.
- `RetryStrategy`: opóźnienia 1/2/3/4 s, maks. 4 próby; `OptimisticLockException` ponawiany bez limitu
  (przeszukuje łańcuch wyjątków, w tym `HandlerFailedException`).
- `failure_transport: failed`. W `when@test` transporty `rabbit`/`doctrine` → `in-memory://` (`sync` zostaje `sync://`).
- `MessageOption` (port aplikacji): `ASYNC` (domyślnie, `NullMessageOption`) = routing + `DispatchAfterCurrentBusStamp`;
  `SYNC` = `TransportNamesStamp(['sync'])` bez `DispatchAfterCurrentBusStamp` (handler od razu, w bieżącej transakcji,
  wyjątek `HandlerFailedException` trafia do wywołującego).

## Komendy CLI

```
bin/console app:account:create <name> <description>
bin/console app:account:add-credit <accountId> <amount> [--effective-at=...]
bin/console app:account:add-debit  <accountId> <amount> [--effective-at=...]
bin/console app:account:stress-test <accountId> <amount> [--iterations=1000] [--debit-ratio=50]
```

`create`, `add-credit`, `add-debit` dispatchują z `MessageModeOption::sync()` (błąd → wyjątek, kod ≠ 0; zdarzenia nadal
async). `stress-test` zostaje asynchroniczny: wysyła losowo pomieszane credit/debit o sumie netto = `amount`
do workerów `rabbit`.

## Testy

- `tests/Unit` (VO, encje, typy Doctrine, RetryStrategy, DefaultDelayMiddleware),
- `tests/Integration` (handlery, listenery, repozytoria, implementacje busów, serializacja komunikatów
  `MessageSerializationTest`; prawdziwa baza `*_test`, rollback przez DAMA),
- `tests/Functional` (komendy CLI przez `CommandTester`; `create`/`add-*` sprawdzają transport `sync` i stan bazy).
- Dispatch w trybie `SYNC` jest widoczny w `getDispatchedMessages()` dwa razy (wywołanie + ponowny dispatch przez
  transport `sync`), oba z transportem `sync`.
- `tests/Support/Messenger`: `DispatchedMessagesAssertionTrait` (dla testów `KernelTestCase`) z
  `self::assertDispatchedMessages([new DispatchedMessage($msg, 'rabbit')], 'event.bus')` oraz
  `self::getDispatchedMessages('command.bus')` (zwraca `list<DispatchedMessage>`), `getSentMessages('<transport>')`
  i `resetDispatchedMessages('<bus>')`. Działa dzięki
  dekoratorom `TraceableMessageBus` (`test.messenger.traced.<bus>`) z `when@test` w `config/services.yaml`;
  transport jest brany ze `SentStamp`, a `null` oznacza wiadomość bez routingu.

## Znane problemy / otwarte tematy (zweryfikowane w kodzie 2026-10-13)

Pełna lista z identyfikatorami: `.claude/review/2026-10-12_23_48_31.md`. Naprawione 2026-10-13: W-1, W-2, W-3, W-4,
W-5 (CLI synchroniczne), S-1.

- Zdarzenia publikują handlery (repozytorium tego nie robi). `MessengerEventBus` dodaje
  `DispatchAfterCurrentBusStamp`, więc w workerze zdarzenia wychodzą po commicie `doctrine_transaction`
  (bez outboxa: awaria między commitem a wysyłką gubi zdarzenie).
- Brak blokad i wersjonowania: gałąź `OptimisticLockException` w `RetryStrategy` w praktyce nie ma czego łapać.
- Nazwy listenerów nie pasują do zdarzeń (`AccountCreditAddedListener` obsługuje `AccountCreditUpdated`).
- Duplikat nazwy konta w trybie async (worker) nadal jest ponawiany 4× jak błąd przejściowy (brak sprawdzenia
  w handlerze ani walidacji `AccountName`); w CLI (sync) kończy się błędem.
- `AccountDebitAddedListener` to pusty TODO, `AccountCreditAddedListener` tylko loguje brak konta.
- `AddAccountCreditHandler` i `AddAccountDebitHandler` różnią się tylko stroną (credit/debit), czyli duplikacja.
- Fixtures leżą w `src/` (Infrastructure/DataFixtures), a `fakerphp/faker` jest w `require` zamiast `require-dev`.

## Konwencje pracy

- Odpowiadaj po polsku (użytkownik pisze po polsku; README i review też są po polsku).
- Trzymaj się `AGENTS.md`: atrybuty zamiast YAML, autowiring, `composer require` + Flex zamiast ręcznego
  wiringu, migracje przez `make:migration` (nigdy `schema:update`), każda funkcja z testem.
- Przed zakończeniem zadania uruchom w kontenerze `composer test` (cs + phpstan max + testy).
