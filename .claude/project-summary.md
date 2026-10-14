# Podsumowanie projektu `symfony8` (kontekst dla Claude)

> Stan na 2026-10-14 (commit `cdfe057` + niezacommitowane zmiany, gałąź `master`). Szczegóły uruchomienia są w `README.md`,
> zasady pisania kodu w `AGENTS.md`. Raporty review (skill `project-review`) trafiają do `.claude/review/`
> (pierwszy: `2026-10-12_23_48_31.md`).

## Czym jest projekt

Konta księgowe z zapisami Winien (debit) / Ma (credit). Styl DDD + CQRS na Symfony Messenger.
Obsługa przez komendy konsolowe i jeden endpoint REST (`POST /api/v1/account/{accountId}/account-entry`).
Zmiany stanu z API idą asynchronicznie przez RabbitMQ i workery (CLI dispatchuje synchronicznie).

## Stos

- PHP `>=8.4` (kontener: PHP 8.5), Symfony **8.1**, Doctrine ORM 3 + Migrations, PostgreSQL 18.
- Messenger: transporty `rabbit` (AMQP), `doctrine`, `failed` (Doctrine), `sync`.
- Brak Twig, Security, API Platform, Form. Jest Serializer (JSON dla Messengera), Validator (walidacja requestów HTTP
  przez constraint `Collection`), Clock, Uid.
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
│   └── Presentation/
│       ├── Cli/                  # AccountCommands, AccountEntryCommands, StressTestForAccountUpdate
│       └── Http/                 # PostAccountEntryController, Request/PostAddAccountEntryRequest
└── Shared/
    ├── Application/Command.php   # interfejs-marker komend (routing → rabbit)
    ├── Application/Bus/          # CommandBus::dispatch(Command, MessageOption = NullMessageOption),
    │                             # EventBus::publish(list<DomainEvent>, MessageOption = NullMessageOption),
    │                             # MessageOption (interfejs), MessageMode (enum ASYNC/SYNC),
    │                             # NullMessageOption (null object → ASYNC), MessageModeOption::sync()/async()
    ├── Domain/                   # AggregateRoot (pullDomainEvents), DomainEvent (marker),
    │                             # ValueObject/Amount, Exception/DomainException
    ├── Presentation/             # RequestValidation (interfejs obiektów requestu: validate())
    └── Infrastructure/
        ├── Http/                 # EventListener/ExceptionListener (wyjątki → JSON),
        │                         # Exception/RequestValidationException (400),
        │                         # Exception/UrlParametersValidationException (404)
        └── Messenger/            # Bus/: MessengerCommandBus / MessengerEventBus, MessageOptionStamps
                                  # (MessageOption → stampy), EventBus/Middleware/DefaultDelayMiddleware (event.bus, 2 s);
                                  # Transport/RetryStrategy
```

Mapowanie Doctrine: atrybuty, tylko katalog `src/Account/Domain/Entity` (alias `Domain`).

## Kluczowe decyzje modelu

- `Account`: id (UUID), unikalne `name`, `description`, `created_at`, sumy `credit` / `debit` (typ `amount`, getter
  `credit(): AccountCredit` / `debit(): AccountDebit`) oraz `version` (`#[ORM\Version]`, optimistic locking; decyzja
  z 2026-10-13, odwraca wcześniejszą „agregat nie zna sum”). Zdarzenia nadal niosą tylko kwotę wpisu.
  `DoctrineAccountRepository` to zwykłe `find()` / `findOneBy()`.
  Konstruktor (przez `Account::create()`) rejestruje zdarzenie `AccountCreated`.
- `Account::recordCredit()` / `recordDebit()` tworzą `AccountEntry` (trzymany w `newAccountEntries`) i zwiększają sumę `credit` / `debit`
  konta i rejestrują `AccountCreditUpdated` / `AccountDebitUpdated` (tylko kwota wpisu, bez sumy konta).
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

## API HTTP

`POST /api/v1/account/{accountId}/account-entry` (`PostAccountEntryController::add`, bez uwierzytelniania).
Body JSON (wszystkie pola wymagane, dodatkowe pola odrzucane):

```json
{"side": "credit"|"debit", "amount": {"value": <int > 0, grosze>, "currency": "PLN"}, "effectiveAt": "2026-10-01T12:00:00+02:00"}
```

- `effectiveAt` w formacie `DateTimeInterface::ATOM`, czyli **ze strefą czasową** (`+02:00` lub `Z`); data bez strefy
  (`2026-10-01 12:00:00`, `2026-10-01T12:00:00`) jest odrzucana. Strefa przechodzi do komendy bez zmian.
- `PostAddAccountEntryRequest` nie używa `#[MapRequestPayload]`: to serwis autowirowany jako argument akcji,
  który w konstruktorze czyta request z `RequestStack` i waliduje go Validatorem (najpierw parametry URL, potem
  payload). Działa przy PHP-FPM (nowy kontener na request); przy FrankenPHP/worker mode trzeba to przemyśleć.
- Kontroler sprawdza istnienie konta (`AccountRepository::findById`), potem dispatchuje
  `AddAccountEntryCreditCommand` / `AddAccountEntryDebitCommand` **asynchronicznie** (`rabbit`).
- Odpowiedzi (JSON budowany przez `ExceptionListener`, nasłuchuje `kernel.exception` dla całej aplikacji):
  - `202` `{"id": "<uuid nowego wpisu>"}`,
  - `400` `{"type": "request-validation", "status", "message", "violations": [{"propertyPath": "amount.value", "message"}]}`,
  - `404` `{"type": "url-parameters-validation", ..., "violations": [...]}` dla `accountId`, który nie jest UUID v4,
  - `404` `{"type": "not-found", "status", "message"}` dla nieistniejącego konta (i innych `NotFoundHttpException`),
  - `422` `{"type": "domain-validation", ...}` dla `DomainException` (także opakowanego w `HandlerFailedException`),
  - `405` dla innych metod niż `POST`.

## Logowanie

- Monolog → stderr kontenera (JSON) → Filebeat → Logstash → Elasticsearch (`docker-logs-*`, `app.context` /
  `app.extra` typu `flattened`). Zasada: **wszystko od `info` w górę, ze wszystkich kanałów** (HTTP i workery) idzie do
  ES. `dev`: handler `stderr` (`info`, JSON) + `main` do pliku `var/log/dev.log` (`debug`, lokalnie);
  `prod`: tylko `main` na stderr (`info`, JSON), bez `fingers_crossed`; `test`: plik + `test` w pamięci.
  Workery czytają konfigurację przy starcie, więc po zmianie trzeba je zrestartować.
- Każdy wpis ma `extra.service` (`APP_SERVICE_NAME`, domyślnie `symfony8`, `ServiceNameProcessor`) oraz, w trakcie
  żądania HTTP, `extra.correlationId` (`CorrelationIdProcessor` + `CorrelationIdStorage`). W Kibanie:
  `app.extra.service: symfony8 and app.extra.correlationId: "<uuid>"`.
- `HttpLoggingListener` (`Shared/Infrastructure/Http/Logging`, kanał `http`, zawsze `info`):
  - `HttpRequest` na `kernel.request` (priorytet 512, przed routerem, więc 404/405 też są logowane): metoda, URI, query,
    nagłówki, payload, pliki (tylko metadane), IP. `correlationId` brany z nagłówka `X-Correlation-Id`, jeśli to
    poprawny UUID; inaczej nowy UUID v7 (zły nagłówek → `invalidClientCorrelationId`);
  - `X-Correlation-Id` w każdej odpowiedzi (`kernel.response`, priorytet -2048);
  - `HttpResponse` na `kernel.terminate`: status, nagłówki, payload, route, parametry, kontroler, klasa wyjątku
    (zapamiętana na `kernel.exception` z priorytetem 1024), `durationMs`, `memoryPeakBytes`;
  - `HttpRequestAborted` (`critical`) z `register_shutdown_function()`, gdy proces skończył się bez `HttpResponse`
    (z rezerwą pamięci na OOM).
  - Pomijane ścieżki: `/_profiler`, `/_wdt`. Maskowanie (`SensitiveDataMasker`): nagłówki `Authorization`, `Cookie`,
    `Set-Cookie`, `X-Api-Key` itp. oraz klucze payloadu/query z `password`, `secret`, `token`, `apikey`… i `iban`,
    `cardNumber`, `cvv`, `cvc`, `pin`. Payload obcinany do `HTTP_LOG_MAX_PAYLOAD_BYTES` (16 KB) z `payloadTruncated`.
- `ExceptionListener` loguje obsłużone wyjątki sam (zatrzymuje propagację, więc `ErrorListener` Symfony ich nie
  loguje): 400/422 `warning`, 404 `info`, reszta → odpowiedź 500 „Internal error.” i `critical`.
- Propagacja `correlationId` do Messengera (stamp, worker bez generowania nowego id) to osobny, jeszcze niezrobiony krok.
- Testy: handler `monolog.handler.test` (`when@test`, kanały `http`, `app`); `HttpLoggingTest` (funkcjonalny).

## Testy

- `tests/Unit` (VO, encje, typy Doctrine, RetryStrategy, DefaultDelayMiddleware, `PostAddAccountEntryRequest`,
  `ExceptionListener`, wyjątki HTTP),
- `tests/Integration` (handlery, listenery, repozytoria, implementacje busów, serializacja komunikatów
  `MessageSerializationTest`; prawdziwa baza `*_test`, rollback przez DAMA),
- `tests/Functional` (komendy CLI przez `CommandTester`; `create`/`add-*` sprawdzają transport `sync` i stan bazy;
  endpoint HTTP przez `WebTestCase` w `AccountEntryControllerTest`: konto zakładane w `setUp()`, komendy sprawdzane
  `getSentMessages('rabbit')`, błędy przez pełne ciało JSON odpowiedzi).
- Dispatch w trybie `SYNC` jest widoczny w `getDispatchedMessages()` dwa razy (wywołanie + ponowny dispatch przez
  transport `sync`), oba z transportem `sync`.
- `tests/Support/Messenger`: `DispatchedMessagesAssertionTrait` (dla testów `KernelTestCase`) z
  `self::assertDispatchedMessages([new DispatchedMessage($msg, 'rabbit')], 'event.bus')` oraz
  `self::getDispatchedMessages('command.bus')` (zwraca `list<DispatchedMessage>`), `getSentMessages('<transport>')`
  i `resetDispatchedMessages('<bus>')`. Działa dzięki
  dekoratorom `TraceableMessageBus` (`test.messenger.traced.<bus>`) z `when@test` w `config/services.yaml`;
  transport jest brany ze `SentStamp`, a `null` oznacza wiadomość bez routingu.

## Znane problemy / otwarte tematy (zweryfikowane w kodzie 2026-10-14)

Pełna lista z identyfikatorami: `.claude/review/2026-10-12_23_48_31.md`. Naprawione 2026-10-13: W-1, W-2, W-3, W-4,
W-5 (CLI synchroniczne), S-1.

- Zdarzenia publikują handlery (repozytorium tego nie robi). `MessengerEventBus` dodaje
  `DispatchAfterCurrentBusStamp`, więc w workerze zdarzenia wychodzą po commicie `doctrine_transaction`
  (bez outboxa: awaria między commitem a wysyłką gubi zdarzenie).
- Równoległe zapisy na tym samym koncie kończą się `OptimisticLockException` przy flushu; `RetryStrategy` ponawia je
  bez limitu (co 1 s), a `doctrine_ping_connection` resetuje zamknięty EntityManager przed kolejną wiadomością.
- Nazwy listenerów nie pasują do zdarzeń (`AccountCreditAddedListener` obsługuje `AccountCreditUpdated`).
- **Błąd:** `AccountDebitAddedListener::__invoke()` przyjmuje `AccountCreditUpdated` (zamiast `AccountDebitUpdated`),
  więc reaguje na zdarzenia credit i loguje je jako debit; na `AccountDebitUpdated` nie reaguje nikt. Brak testu.
- Duplikat nazwy konta w trybie async (worker) nadal jest ponawiany 4× jak błąd przejściowy (brak sprawdzenia
  w handlerze ani walidacji `AccountName`); w CLI (sync) kończy się błędem.
- Listenery credit/debit tylko logują (`critical` przy braku konta, `info` w pozostałych przypadkach).
- `AddAccountCreditHandler` i `AddAccountDebitHandler` różnią się tylko stroną (credit/debit), czyli duplikacja.
- Endpoint HTTP sprawdza istnienie konta przed dispatchem, ale konto może zniknąć przed obsługą w workerze
  (wtedy `UnrecoverableMessageHandlingException`, klient ma już `202`).
- Fixtures leżą w `src/` (Infrastructure/DataFixtures), a `fakerphp/faker` jest w `require` zamiast `require-dev`.

## Konwencje pracy

- Odpowiadaj po polsku (użytkownik pisze po polsku; README i review też są po polsku).
- Trzymaj się `AGENTS.md`: atrybuty zamiast YAML, autowiring, `composer require` + Flex zamiast ręcznego
  wiringu, migracje przez `make:migration` (nigdy `schema:update`), każda funkcja z testem.
- Styl: `yoda_style` także dla `<`/`>` (`0 < $x`), `ordered_class_elements` bez wydzielania metod statycznych
  (data providery zostają przy testach); `composer cs-fix` poprawia to automatycznie.
- Przed zakończeniem zadania uruchom w kontenerze `composer test` (cs + phpstan max + testy).
