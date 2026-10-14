# symfony8

Aplikacja w Symfony 8.1 (PHP 8.5 w kontenerze, wymagane `>=8.4`) modelująca konta
księgowe z zapisami po stronie Winien (debit) i Ma (credit). Projekt jest zbudowany
w stylu DDD/CQRS: zmiany stanu idą przez szynę komend Symfony Messenger i (poza
zakładaniem konta z CLI) są przetwarzane asynchronicznie przez workery konsumujące RabbitMQ.

## Stos technologiczny

| Obszar         | Technologia                                                    |
|----------------|----------------------------------------------------------------|
| Framework      | Symfony 8.1 (Console, Messenger, Serializer, Validator, Monolog, Clock, Uid) |
| Persystencja   | Doctrine ORM 3 + Doctrine Migrations, PostgreSQL 18            |
| Kolejki        | Symfony Messenger: RabbitMQ (AMQP) i transport Doctrine        |
| Środowisko     | Docker Compose: nginx, php-fpm 8.5, postgres, rabbitmq         |
| Logi (opcja)   | Filebeat → Logstash → Elasticsearch → Kibana 9.5               |
| Jakość         | PHPUnit 13 + Paratest, PHPStan (`level: max`), PHP-CS-Fixer, PHP_CodeSniffer, DAMA Doctrine Test Bundle |

Obsługa odbywa się przez komendy konsolowe i jeden endpoint REST
(`POST /api/v1/account/{accountId}/account-entry`, bez uwierzytelniania).

## Architektura

Kod jest podzielony na moduły (bounded contexts) i warstwy:

```
src/
├── Account/
│   ├── Domain/          # encje, value objecty, zdarzenia, interfejsy repozytoriów
│   ├── Application/     # komendy, handlery komend, listenery zdarzeń
│   ├── Infrastructure/  # repozytoria Doctrine, typy Doctrine, fixtures
│   └── Presentation/    # komendy konsolowe (Cli) i kontroler HTTP (Http)
└── Shared/              # AggregateRoot, DomainEvent, Amount, porty szyn, middleware i retry dla Messengera,
                         # obsługa wyjątków HTTP, logowanie i correlation id
```

### Model domeny

- **`Account`** (aggregate root) – identyfikator (UUID), unikalna nazwa, opis, data
  utworzenia, sumy `credit` / `debit` oraz `version` (`#[ORM\Version]`, optimistic locking).
  `recordCredit()` / `recordDebit()` tworzą nowy `AccountEntry`, zwiększają odpowiednią sumę
  i rejestrują zdarzenie `AccountCreditUpdated` / `AccountDebitUpdated` (z kwotą wpisu, bez sumy
  konta). Równoległe zapisy na tym samym koncie kończą się `OptimisticLockException`, który
  jest ponawiany.
- **`AccountEntry`** – pojedynczy zapis na koncie (osobna encja, nie kolekcja
  w agregacie): konto, kwota, strona (`DEBIT`/`CREDIT`), data zastosowania
  (`effective_at`) i data utworzenia (`created_at`). Daty są zapisywane jako
  `TIMESTAMPTZ` (`Types::DATETIMETZ_IMMUTABLE`), więc offset podany przy wpisie
  nie ginie.
- **Value objecty** – `AccountId`, `AccountEntryId`, `AccountName`, `AccountDescription`,
  `AccountCredit`, `AccountDebit`, enum `AccountEntrySide` oraz współdzielony `Amount`
  (`Shared/Domain`, nieujemna liczba całkowita w jednostkach groszowych).
  Identyfikatory, nazwa, opis i kwota mają własne typy Doctrine
  (`Infrastructure/Doctrine/Types`), a strona zapisu jest natywnym enumem.

### Messenger

Skonfigurowane są trzy szyny (`config/packages/messenger.yaml`):

- **`command.bus`** (domyślna) – middleware `CorrelationIdMiddleware`, `doctrine_ping_connection`
  i `doctrine_transaction`; komendy (`App\Shared\Application\Command`) trafiają
  do transportu `rabbit`. Handlery komend są przypięte tylko do tej szyny
  (`#[AsMessageHandler(bus: 'command.bus')]`).
- **`event.bus`** – zdarzenia domenowe (`App\Shared\Domain\DomainEvent`) również
  idą do `rabbit`, z domyślnym opóźnieniem 2 s (`DefaultDelayMiddleware`); zdarzenia
  bez handlerów i bez routingu nie powodują błędu. Listenery są przypięte tylko
  do tej szyny (`bus: 'event.bus'`).
- **`query.bus`** – zarezerwowana pod zapytania (na razie nieużywana).

Każda szyna zaczyna się od `CorrelationIdMiddleware`, który przenosi correlation id
między procesami (zob. [Logowanie](#logowanie)).

Aplikacja korzysta z szyn przez własne interfejsy `CommandBus` i `EventBus`
(`Shared/Application/Bus`). Obie metody (`dispatch()`, `publish()`) przyjmują
opcjonalny `MessageOption` z trybem `MessageMode::ASYNC` / `MessageMode::SYNC`:

- domyślnie (`NullMessageOption`, null object) i dla `MessageModeOption::async()`
  komunikat idzie zgodnie z routingiem (`rabbit`) z `DispatchAfterCurrentBusStamp`,
  więc wysłany w trakcie obsługi innego komunikatu wychodzi dopiero po commicie
  transakcji z `doctrine_transaction`,
- `MessageModeOption::sync()` nadpisuje routing transportem `sync` (`TransportNamesStamp`):
  handler wykonuje się od razu, w bieżącym procesie, a jego wyjątek trafia do wywołującego.

Własna `RetryStrategy` ponawia komunikaty z rosnącym opóźnieniem (1–4 s, maks.
4 próby), a `OptimisticLockException` ponawia bez limitu. Komunikaty, które się
nie powiodły, lądują w transporcie `failed` (Doctrine). W środowisku testowym
transporty są zastąpione przez `in-memory://`.

## Uruchomienie

Wymagany Docker z Compose. Na hoście nie trzeba mieć PHP: wszystkie komendy
uruchamia się w kontenerze `php` (`make sh` albo `docker compose exec php <cmd>`).
Kontener `hostmanager` dopisuje nazwy kontenerów (`postgres`, `rabbitmq`, ...)
do `/etc/hosts`.

```bash
make up            # buduje i uruchamia nginx, php, postgres, rabbitmq
make down          # zatrzymuje i usuwa kontenery
make sh            # powłoka w kontenerze php

# w kontenerze:
composer install
composer db:reset          # drop + create + migracje
composer dev:reset         # j.w. dla dev + fixtures
composer test:reset        # j.w. dla bazy testowej
```

Workery Messengera (`docker-compose.workers.yml`, domyślnie 3 repliki
`messenger:consume rabbit` i 1 `messenger:consume doctrine`, każdy z `--time-limit=3600`):

```bash
make up-workers
make scale-workers-rabbit 10   # zmiana liczby workerów RabbitMQ (0 zatrzymuje wszystkie)
make ps-workers
make logs-workers
make restart-workers
make down-workers
```

Opcjonalny stos ELK (Kibana na `http://127.0.0.1:5601`):

```bash
make up-logs
make ps-logs
make clear-logs   # usuwa indeksy logów docker-logs-* (Elasticsearch musi działać)
make down-logs
```

## API HTTP

`POST /api/v1/account/{accountId}/account-entry` dodaje wpis na koncie. Wszystkie pola
są wymagane, dodatkowe pola są odrzucane:

```json
{
    "side": "credit",
    "amount": {"value": 1250, "currency": "PLN"},
    "effectiveAt": "2026-10-01T12:00:00+02:00"
}
```

- `side`: `credit` lub `debit`, `amount.value`: liczba całkowita > 0 w groszach,
  `amount.currency`: `PLN`,
- `effectiveAt`: format `DateTimeInterface::ATOM`, czyli **ze strefą czasową** (`+02:00`
  lub `Z`); data bez strefy jest odrzucana.

Kontroler sprawdza, czy konto istnieje, i wysyła komendę asynchronicznie do `rabbit`;
wpis zapisuje worker. Odpowiedzi (JSON, budowany przez `ExceptionListener`):

| Status | Kiedy                                                     | Ciało                                                           |
|--------|-----------------------------------------------------------|-----------------------------------------------------------------|
| `202`  | komenda przyjęta                                          | `{"id": "<uuid nowego wpisu>"}`                                 |
| `400`  | błędny payload                                            | `{"type": "request-validation", "status", "message", "violations": [{"propertyPath", "message"}]}` |
| `404`  | `accountId` nie jest UUID v4                              | `{"type": "url-parameters-validation", ..., "violations": [...]}` |
| `404`  | konto nie istnieje                                        | `{"type": "not-found", "status", "message"}`                    |
| `422`  | błąd domenowy (`DomainException`)                         | `{"type": "domain-validation", "status", "message"}`            |
| `405`  | metoda inna niż `POST`                                    |                                                                 |

Każda odpowiedź ma nagłówek `X-Correlation-Id`.

## Komendy konsolowe

```bash
bin/console app:account:create <name> <description>
bin/console app:account:add-credit <accountId> <amount> [--effective-at=...]
bin/console app:account:add-debit  <accountId> <amount> [--effective-at=...]
```

`create` wykonuje komendę synchronicznie: po sukcesie konto jest już w bazie, a błąd
(np. zajęta nazwa konta) kończy komendę z kodem różnym od zera. `add-credit` i `add-debit`
wysyłają komendę asynchronicznie do `rabbit`: wpis zapisuje worker (z ponawianiem przy
konfliktach optimistic locking), a istnienie konta sprawdza dopiero on. Zdarzenia domenowe
zawsze idą asynchronicznie do `rabbit`. Kwoty podaje się w jednostkach groszowych,
`--effective-at` przyjmuje dowolny format zrozumiały dla `DateTimeImmutable`, łącznie
z offsetem (domyślnie: teraz).

### Stress test

Dwa skrypty uruchamiane na hoście (wymagają workerów: `make up-workers`):

- `helpers/stresstest-account.sh <accountId>` wykonuje w kontenerze `php`, jedna po drugiej, N komend
  `add-credit` / `add-debit` dla jednego konta, z losową stroną, kwotą i `--effective-at`, i wypisuje
  podsumowanie (`ok`, `failed`, zlecone sumy credit/debit, czas). Można go uruchomić samodzielnie.
- `helpers/stresstest.sh` czyści kolejki RabbitMQ (`messages` i `delay_*`), resetuje bazę dev
  (`composer dev:reset`), a potem uruchamia `stresstest-account.sh` dla każdego konta w bazie, najwyżej
  `--parallel` kont naraz, i sumuje wyniki. Ctrl+C zatrzymuje wszystkie pętle po bieżącej komendzie.

Pozwala to sprawdzić spójność zapisów przy współbieżnym przetwarzaniu przez wiele workerów: po opróżnieniu
kolejki (`bin/console messenger:stats`) sumy w `account_entry` powinny równać się polom `credit` / `debit`
w `account`.

```bash
helpers/stresstest.sh                                              # 50 kont × 200 komend, 10 kont naraz
helpers/stresstest.sh -n 1000 -p 5 -d 30                           # 1000 komend na konto, 5 kont naraz, 30% debit
helpers/stresstest.sh --no-purge --no-reset                        # bez czyszczenia kolejek i bazy
helpers/stresstest-account.sh 00000000-0000-4000-8000-000000000001 -n 50   # jedno konto
helpers/stresstest.sh --help                                       # wszystkie opcje
```

## Logowanie

Monolog pisze JSON na stderr kontenera, skąd (przy `make up-logs`) Filebeat przekazuje
logi przez Logstash do Elasticsearch (indeksy `docker-logs-*`). Do ES trafia wszystko od
poziomu `info` w górę, ze wszystkich kanałów, z procesów HTTP i workerów. W `dev` dodatkowo
`var/log/dev.log` (`debug`). Workery czytają konfigurację przy starcie, więc po jej zmianie
trzeba je zrestartować (`make restart-workers`).

- Każdy wpis ma `extra.service` (`APP_SERVICE_NAME`) i `extra.correlationId`.
- `HttpLoggingListener` (kanał `http`) loguje `HttpRequest` (metoda, URI, nagłówki, payload, IP)
  i `HttpResponse` (status, payload, route, kontroler, wyjątek, `durationMs`, `memoryPeakBytes`),
  a `HttpRequestAborted` (`critical`), gdy proces skończy się bez odpowiedzi. Wrażliwe nagłówki
  i klucze payloadu (hasła, tokeny, `iban`, `cardNumber`, ...) są maskowane, payload jest obcinany
  do `HTTP_LOG_MAX_PAYLOAD_BYTES`. Ścieżki `/_profiler` i `/_wdt` są pomijane.
- `ExceptionListener` loguje obsłużone wyjątki: 400/422 jako `warning`, 404 jako `info`,
  pozostałe (odpowiedź 500) jako `critical`.

### Correlation id

- HTTP: id z nagłówka `X-Correlation-Id`, jeśli to poprawny UUID; w przeciwnym razie nowy UUID v7.
  Id wraca w nagłówku odpowiedzi.
- CLI: każde uruchomienie komendy dostaje nowy id (`ConsoleCorrelationIdListener`), z wyjątkiem
  `messenger:consume` i `messenger:failed:retry`.
- Messenger: `CorrelationIdMiddleware` dokłada do wysyłanej wiadomości `CorrelationIdStamp`
  z bieżącym id, a przy odbiorze (worker, transport `sync`) ustawia id z wiadomości. Komendy
  i zdarzenia wywołane jednym żądaniem HTTP lub jedną komendą CLI mają więc wspólny id, także
  po ponowieniu i w transporcie `failed`.

W Kibanie: `app.extra.service: symfony8 and app.extra.correlationId: "<uuid>"`.

## Testy i jakość kodu

```bash
composer test           # test:sa + test:phpunit
composer test:sa        # php-cs-fixer check + phpcs + phpstan
composer test:phpunit   # same testy (paratest)
composer test:reset     # odtworzenie bazy testowej
composer cs-fix         # poprawki PHP-CS-Fixer

# z hosta:
make cs / make cs-fix   # PHP-CS-Fixer (dry-run / poprawki)
make phpcs              # PHP_CodeSniffer (tylko limit długości linii)
make phpstan
```

Konfiguracja PHPUnit jest w wersjonowanym `phpunit.dist.xml`. Lokalne nadpisania
można trzymać w `phpunit.xml` (ignorowany przez git, ma pierwszeństwo).

Testy są podzielone na:

- `tests/Unit` – value objecty, encje, typy Doctrine, `RetryStrategy`, `DefaultDelayMiddleware`,
  walidacja requestu HTTP, `ExceptionListener`, listenery correlation id,
- `tests/Integration` – handlery, listenery, repozytoria, implementacje szyn, `CorrelationIdMiddleware`
  i serializacja komunikatów na prawdziwej bazie `*_test`, z rollbackiem przez DAMA,
- `tests/Functional` – komendy konsolowe (`CommandTester`) i endpoint HTTP (`WebTestCase`) z asercjami
  na wysłane komunikaty, logowanie HTTP i correlation id w CLI,
- `tests/Support` – helpery, m.in. `DispatchedMessagesAssertionTrait` do sprawdzania,
  co i do którego transportu trafiło na danej szynie.

## Konfiguracja

Domyślne wartości zmiennych są w `.env` (oraz `.env.dev`, `.env.test`):

- `DATABASE_URL` – PostgreSQL w kontenerze `postgres`,
- `MESSENGER_RABBIT_DSN` – RabbitMQ w kontenerze `rabbitmq`,
- `MESSENGER_DOCTRINE_DSN` – kolejka w bazie danych,
- `APP_SERVICE_NAME` – nazwa serwisu w logach (`extra.service`, domyślnie `symfony8`),
- `HTTP_LOG_MAX_PAYLOAD_BYTES` – limit logowanego payloadu HTTP (domyślnie 16 KB).

Lokalne nadpisania i sekrety należy trzymać w `.env.local` (poza repozytorium)
lub w Symfony secrets vault.
