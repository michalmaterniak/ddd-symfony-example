# symfony8

Aplikacja w Symfony 8.1 (PHP 8.5 w kontenerze, wymagane `>=8.4`) modelująca konta
księgowe z zapisami po stronie Winien (debit) i Ma (credit). Projekt jest zbudowany
w stylu DDD/CQRS: zmiany stanu idą przez szynę komend Symfony Messenger i są
przetwarzane asynchronicznie przez workery konsumujące RabbitMQ.

## Stos technologiczny

| Obszar         | Technologia                                                    |
|----------------|----------------------------------------------------------------|
| Framework      | Symfony 8.1 (Console, Messenger, Serializer, Clock, Uid)       |
| Persystencja   | Doctrine ORM 3 + Doctrine Migrations, PostgreSQL 18            |
| Kolejki        | Symfony Messenger: RabbitMQ (AMQP) i transport Doctrine        |
| Środowisko     | Docker Compose: nginx, php-fpm 8.5, postgres, rabbitmq         |
| Logi (opcja)   | Filebeat → Logstash → Elasticsearch → Kibana 9.5               |
| Jakość         | PHPUnit 13 + Paratest, PHPStan (`level: max`), PHP-CS-Fixer, PHP_CodeSniffer, DAMA Doctrine Test Bundle |

Aplikacja nie ma interfejsu HTTP; obsługa odbywa się przez komendy konsolowe.

## Architektura

Kod jest podzielony na moduły (bounded contexts) i warstwy:

```
src/
├── Account/
│   ├── Domain/          # encje, value objecty, zdarzenia, interfejsy repozytoriów
│   ├── Application/     # komendy, handlery komend, listenery zdarzeń
│   ├── Infrastructure/  # repozytoria Doctrine, typy Doctrine, fixtures
│   └── Presentation/    # komendy konsolowe (CLI)
└── Shared/              # AggregateRoot, DomainEvent, Amount, middleware i retry dla Messengera
```

### Model domeny

- **`Account`** (aggregate root) – identyfikator (UUID), unikalna nazwa, opis, data
  utworzenia. Agregat nie zna sum `credit`/`debit` (nie ma ich ani w tabeli, ani w encji,
  ani w zdarzeniach); saldo, gdy będzie potrzebne, ma być liczone w warstwie aplikacji
  z zapisów.
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

- **`command.bus`** (domyślna) – middleware `doctrine_ping_connection`
  i `doctrine_transaction`; komendy (`App\Shared\Application\Command`) trafiają
  do transportu `rabbit`. Handlery komend są przypięte tylko do tej szyny
  (`#[AsMessageHandler(bus: 'command.bus')]`).
- **`event.bus`** – zdarzenia domenowe (`App\Shared\Domain\DomainEvent`) również
  idą do `rabbit`, z domyślnym opóźnieniem 2 s (`DefaultDelayMiddleware`); zdarzenia
  bez handlerów i bez routingu nie powodują błędu. Listenery są przypięte tylko
  do tej szyny (`bus: 'event.bus'`).
- **`query.bus`** – zarezerwowana pod zapytania (na razie nieużywana).

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
make down-logs
```

## Komendy konsolowe

```bash
bin/console app:account:create <name> <description>
bin/console app:account:add-credit <accountId> <amount> [--effective-at=...]
bin/console app:account:add-debit  <accountId> <amount> [--effective-at=...]
bin/console app:account:stress-test <accountId> <amount> [--iterations=1000] [--debit-ratio=50]
```

`create`, `add-credit` i `add-debit` wykonują komendę synchronicznie: po sukcesie
zmiana jest już w bazie, a błąd (np. zajęta nazwa konta, nieistniejące konto) kończy
komendę z kodem różnym od zera. Zdarzenia domenowe nadal idą asynchronicznie do `rabbit`.
Kwoty podaje się w jednostkach groszowych, `--effective-at` przyjmuje dowolny format
zrozumiały dla `DateTimeImmutable`, łącznie z offsetem (domyślnie: teraz).

`stress-test` zostaje asynchroniczny: wysyła wiele losowo pomieszanych komend
credit/debit, których suma netto równa się podanej kwocie, do przetworzenia przez
workery `rabbit`. Pozwala to sprawdzić spójność zapisów przy współbieżnym
przetwarzaniu przez wiele workerów.

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
- `tests/Integration` – handlery, listenery, repozytoria i implementacje szyn na prawdziwej bazie `*_test`,
  z rollbackiem przez DAMA,
- `tests/Functional` – komendy konsolowe (`CommandTester`) z asercjami na wysłane komunikaty,
- `tests/Support` – helpery, m.in. `DispatchedMessagesAssertionTrait` do sprawdzania,
  co i do którego transportu trafiło na danej szynie.

## Konfiguracja

Domyślne wartości zmiennych są w `.env` (oraz `.env.dev`, `.env.test`):

- `DATABASE_URL` – PostgreSQL w kontenerze `postgres`,
- `MESSENGER_RABBIT_DSN` – RabbitMQ w kontenerze `rabbitmq`,
- `MESSENGER_DOCTRINE_DSN` – kolejka w bazie danych.

Lokalne nadpisania i sekrety należy trzymać w `.env.local` (poza repozytorium)
lub w Symfony secrets vault.
