# Propozycja: logowanie żądań i odpowiedzi HTTP (`HttpRequest` / `HttpResponse`)

> 2026-10-14, stan kodu: commit `cdfe057` + niezacommitowane zmiany. Tylko propozycja, bez zmian w kodzie.

> **Status (2026-10-14): wariant B zaimplementowany** (`src/Shared/Infrastructure/Http/Logging/HttpLoggingListener.php`
> i sąsiednie, `src/Shared/Infrastructure/Logging/`). Decyzje: `HttpRequest`/`HttpResponse` zawsze `info`;
> limity i maskowanie jak niżej; `X-Correlation-Id` z nagłówka **przyjmowany**, jeśli to poprawny UUID (inaczej nowy
> UUID v7), do tego `extra.service` (`APP_SERVICE_NAME`); `HttpRequestAborted` zrobiony; Messenger (stamp,
> worker dziedziczy `correlationId` z wiadomości, nie tworzy nowego) to osobny krok. Infrastruktury (nginx
> `$request_body`, szablon ES, Logstash) nie zmieniano.

## Cel

- Każde żądanie HTTP jest logowane w całości jako `HttpRequest`, a każda odpowiedź jako `HttpResponse`.
- Obie wiadomości łączy `correlationId` (UUID nadawany na początku żądania).
- Ten sam `correlationId` mają wszystkie inne logi z danego żądania, np. z `ExceptionListener` czy Doctrine. W Kibanie
  jedno zapytanie `app.extra.correlationId: "<uuid>"` pokazuje wtedy całą historię żądania.

## Decyzja: dwie wiadomości, nie jedna

| Wariant | Zalety | Wady |
|---|---|---|
| **A. Jedna wiadomość** na `kernel.terminate` (request + response razem) | jeden dokument w ES, prosto w Kibanie | gdy proces padnie (OOM, `max_execution_time`, segfault, kill workera FPM), **nie ma żadnego śladu żądania**; ślad znika dokładnie wtedy, gdy jest najbardziej potrzebny |
| **B. Dwie wiadomości** (`HttpRequest` na `kernel.request`, `HttpResponse` po wysłaniu odpowiedzi) | `HttpRequest` jest zapisany, zanim wykona się kod aplikacji; **`HttpRequest` bez pary `HttpResponse` sam oznacza, że proces padł** | dwa dokumenty do połączenia po `correlationId` |

**Rekomendacja: wariant B.** Wada (łączenie po `correlationId`) jest tania, a „osierocony” `HttpRequest` to
najlepszy dostępny sygnał nagłej awarii. Bez niego nie wiadomo, że żądanie w ogóle przyszło: nginx zaloguje
`502`/`504`, ale nie będzie wiedział, co się działo w PHP.

## Przepływ

```
kernel.request  (priorytet 512, przed ValidateRequestListener 256 i RouterListener 32)
  └─ HttpLoggingSubscriber::onRequest()
       ├─ correlationId = UuidV7 → CorrelationIdStorage + atrybut requestu `_correlation_id`
       └─ log info "HttpRequest" (kanał `http`)

   … routing, kontroler, ewentualnie kernel.exception (ExceptionListener loguje błąd, ma correlationId w extra) …

kernel.response (priorytet -2048, po SessionListener -1000, czyli nagłówki są już ostateczne)
  └─ HttpLoggingSubscriber::onResponse()
       └─ dodaje nagłówek `X-Correlation-Id: <uuid>` do odpowiedzi

   … Response::send(), przy PHP-FPM potem fastcgi_finish_request(): klient ma już odpowiedź …

kernel.terminate
  └─ HttpLoggingSubscriber::onTerminate()
       └─ log info "HttpResponse" (kanał `http`)
```

### Dlaczego takie punkty zaczepienia

- **`HttpRequest` na `kernel.request` z priorytetem 512, czyli przed routerem.** `RouterListener` (priorytet 32) rzuca
  `NotFoundHttpException` lub `MethodNotAllowedHttpException` jeszcze w `kernel.request`. Listener z niższym
  priorytetem w ogóle by się nie wykonał, więc żądania 404 i 405 nie miałyby `HttpRequest`. Z tego samego powodu
  priorytet jest wyższy niż `ValidateRequestListener` (256), który rzuca przy sprzecznych nagłówkach proxy.
  Koszt: w `HttpRequest` nie ma jeszcze route ani kontrolera, dlatego trafiają one do `HttpResponse`.
- **`HttpResponse` na `kernel.terminate`.** Przy PHP-FPM Symfony woła `fastcgi_finish_request()` przed
  `kernel.terminate`, więc logowanie nie opóźnia odpowiedzi dla klienta, a `durationMs` obejmuje całe żądanie.
  Odpowiedzi z błędem (nasz `ExceptionListener`, 405 z frameworka) też przechodzą przez `kernel.response` i
  `kernel.terminate`, więc mają swój `HttpResponse`.
  - Alternatywa: logowanie od razu na `kernel.response` z priorytetem -2048. Jest to bliżej momentu powstania
    odpowiedzi, ale wydłuża czas odpowiedzi o samo logowanie. Pod względem odporności na awarie różnica jest
    pomijalna: oba miejsca działają w tym samym procesie.
- **Tylko main request** (`$event->isMainRequest()`), bez sub-requestów.

## Komponenty (proponowane miejsca w `src/`)

```
src/Shared/Infrastructure/
├── Logging/
│   ├── CorrelationIdStorage.php          # trzyma bieżący correlationId; implements ResetInterface
│   └── CorrelationIdProcessor.php        # #[AsMonologProcessor]: dopisuje extra.correlationId do KAŻDEGO logu
└── Http/Logging/
    ├── HttpLoggingSubscriber.php         # kernel.request / kernel.exception / kernel.response / kernel.terminate
    │                                     # #[WithMonologChannel('http')] na loggerze
    ├── HttpMessageNormalizer.php         # Request/Response → tablica kontekstu (nagłówki, payload, limity)
    └── SensitiveDataMasker.php           # maskowanie nagłówków i pól payloadu
```

Wszystko na atrybutach i autowiringu, zgodnie z `AGENTS.md`: `#[AsEventListener]` (lub `EventSubscriberInterface`),
`#[AsMonologProcessor]`, `#[WithMonologChannel]`, `#[Autowire('%env(...)%')]` dla limitów. Bez YAML-owych definicji
serwisów. W `config/packages/monolog.yaml` zmienia się tylko konfiguracja handlerów (patrz niżej).

- **`CorrelationIdStorage`** jest osobnym serwisem, a nie tylko atrybutem requestu. Dzięki temu processor działa także
  poza HTTP (worker Messengera, patrz „Rozszerzenia”). `ResetInterface` czyści wartość między żądaniami.
- **`correlationId` to `UuidV7`** (`symfony/uid` jest już w projekcie). Jest uporządkowany w czasie, więc dobrze się
  sortuje i łatwo go wyszukać w ES.
- **Nagłówek przychodzący** (`X-Correlation-Id` / `X-Request-Id` od klienta lub proxy): **nie** jest używany jako nasz
  identyfikator, bo klient mógłby podszyć się pod cudzy ślad. Trafia jako osobne pole `clientCorrelationId`.
  Jeśli kiedyś pojawi się zaufane proxy lub gateway, można przyjmować jego identyfikator (tylko poprawny UUID).

## Zawartość wiadomości

### `HttpRequest` (poziom `info`, kanał `http`)

```json
{
  "message": "HttpRequest",
  "channel": "http",
  "level_name": "INFO",
  "context": {
    "correlationId": "01929a3b-…",
    "clientCorrelationId": null,
    "method": "POST",
    "scheme": "http",
    "host": "web",
    "port": 80,
    "path": "/api/v1/account/9fc6…/account-entry",
    "queryString": null,
    "uri": "http://web/api/v1/account/9fc6…/account-entry",
    "protocolVersion": "HTTP/1.1",
    "clientIp": "172.19.0.3",
    "clientIps": ["172.19.0.3"],
    "headers": {"content-type": ["application/json"], "user-agent": ["curl/8.14.1"], "authorization": ["***"]},
    "contentType": "application/json",
    "contentLength": 12,
    "payload": "{\"side\":\"x\"}",
    "payloadTruncated": false,
    "files": [{"field": "doc", "name": "a.pdf", "size": 1234, "mimeType": "application/pdf"}],
    "startedAt": "2026-10-14T19:45:56.259688+02:00"
  },
  "extra": {"correlationId": "01929a3b-…"}
}
```

### `HttpResponse` (poziom `info`; `warning` dla 4xx, `error` dla 5xx, do ustalenia)

```json
{
  "message": "HttpResponse",
  "context": {
    "correlationId": "01929a3b-…",
    "method": "POST",
    "path": "/api/v1/account/9fc6…/account-entry",
    "route": "api_v1_account_entry_add",
    "routeParameters": {"accountId": "9fc6…"},
    "controller": "App\\Account\\Presentation\\Http\\PostAccountEntryController::add",
    "status": 400,
    "statusText": "Bad Request",
    "headers": {"content-type": ["application/json"], "x-correlation-id": ["01929a3b-…"]},
    "contentType": "application/json",
    "contentLength": 231,
    "payload": "{\"type\":\"request-validation\",…}",
    "payloadTruncated": false,
    "responseType": "JsonResponse",
    "exception": "App\\Shared\\Infrastructure\\Http\\Exception\\RequestValidationException",
    "durationMs": 23.4,
    "memoryPeakBytes": 8388608
  }
}
```

- `method` i `path` są powtórzone w `HttpResponse` celowo: sam dokument odpowiedzi da się wtedy czytać w Kibanie bez
  łączenia z `HttpRequest`.
- `exception`: subscriber nasłuchuje też `kernel.exception` (z wysokim priorytetem, **przed** `ExceptionListener`,
  który zatrzymuje propagację) i tylko zapamiętuje klasę wyjątku w atrybucie requestu. Pełny wyjątek ze stack trace
  loguje już `ExceptionListener`, połączony tym samym `correlationId`.
- `durationMs` liczone od `$request->server->get('REQUEST_TIME_FLOAT')`.
- Odpowiedzi strumieniowane (`StreamedResponse`, `BinaryFileResponse`): bez `payload`, tylko `responseType` i nagłówki.
- Pliki (`multipart/form-data`): tylko metadane, nigdy zawartość.

## Bezpieczeństwo i rozmiar

- **Maskowanie nagłówków:** `Authorization`, `Cookie`, `Set-Cookie`, `Proxy-Authorization`, `X-Api-Key`, `X-Auth-Token`
  → `***`. Lista w jednym miejscu (`SensitiveDataMasker`).
- **Maskowanie pól payloadu (JSON):** rekursywnie klucze typu `password`, `token`, `secret`, `iban`, `cardNumber`.
  Jeśli payload nie jest poprawnym JSON-em, logujemy surowy, obcięty tekst.
- **Limit payloadu:** np. 16 KB (`%env(int:HTTP_LOG_MAX_PAYLOAD)%`) i flaga `payloadTruncated`. To ważne z dwóch powodów:
  - `app.context` jest w ES typu `flattened` (`.docker/logstash/templates/docker-logs.json`). Każda wartość liścia to
    jeden term keyword, a term dłuższy niż 32 766 bajtów powoduje **odrzucenie całego dokumentu**. Warto przy okazji
    dodać `"ignore_above"` do mapowania `context`;
  - Docker dzieli linie logów dłuższe niż 16 KB. Filebeat (parser `container`) skleja je z powrotem, ale bardzo duże
    linie to niepotrzebny koszt.
- **Wykluczone ścieżki:** `/_profiler`, `/_wdt` (dev) i ewentualne healthchecki. Lista konfigurowalna.
- **Duplikat w nginx:** access log nginx (`json_combined`) już zapisuje `$request_body`. Po wdrożeniu warto to pole
  usunąć, bo omija maskowanie i daje dwie kopie tych samych danych w ES.

## Monolog: osobny kanał `http`

W `prod` handler `main` to `fingers_crossed` z `action_level: error`. Logi `info` z udanych żądań zostałyby w buforze
i **nigdy nie trafiłyby do ES**. Dlatego:

- **Osobny kanał `http`** (deklaracja w `monolog.channels`, logger wstrzykiwany przez `#[WithMonologChannel('http')]`).
- **W `prod` osobny handler** `http`: `type: stream`, `path: php://stderr`, `formatter: monolog.formatter.json`,
  `level: info`, `channels: [http]`. W `main` trzeba dodać `"!http"`, żeby wpisy nie wychodziły dwa razy.
- **W `dev`** obecny handler `stderr` (level `info`, JSON) już przepuszcza kanał `http`. Ewentualnie można go wyłączyć
  z handlera `console`.
- **`CorrelationIdProcessor`** dopisuje `extra.correlationId` do każdego rekordu we wszystkich kanałach.
  Logstash rozpakowuje JSON do `app`, więc w Kibanie pole to `app.extra.correlationId` (flattened, czyli wyszukiwanie
  po dokładnej wartości działa). Opcjonalnie w `logstash.conf` można skopiować je do pola najwyższego poziomu
  `correlation_id`, żeby wygodnie filtrować i budować wizualizacje.

## Nagła awaria (OOM, timeout)

- **Bazowo:** `HttpRequest` bez `HttpResponse` w ES (zapytanie typu „correlationId z `HttpRequest`, dla którego nie ma
  `HttpResponse`” albo alert w Kibanie) oznacza, że proces nie dokończył żądania. Do tego log nginx z `502`/`504`
  i ten sam `request_uri` w tym samym czasie.
- **Opcjonalnie (do decyzji):** w `onRequest()` rejestrujemy `register_shutdown_function()`. W funkcji zamykającej
  sprawdzamy, czy `HttpResponse` został zalogowany, a jeśli nie, logujemy `HttpRequestAborted` z `correlationId` i
  `error_get_last()`. Przy OOM potrzebna jest rezerwa pamięci (np. `str_repeat` zwalniany w funkcji zamykającej, tak
  jak robi to `Symfony\Component\ErrorHandler`). Przy kill -9 nic się nie wykona, więc „osierocony” `HttpRequest`
  zostaje ostatecznym sygnałem.

## Rozszerzenia (osobny krok)

- **Messenger:** `CorrelationIdStamp` dodawany przez middleware na `command.bus` / `event.bus` przy dispatchu (z
  `CorrelationIdStorage`). W workerze ten sam middleware ustawia `CorrelationIdStorage` z odebranego stampu, a po
  obsłużeniu wiadomości go czyści. Logi handlerów i listenerów w workerach dostają ten sam `correlationId` co
  `HttpRequest`, więc w Kibanie widać ścieżkę od HTTP przez komendę do zdarzeń. Stamp musi być serializowalny
  (transport `rabbit` używa serializera JSON).
- **CLI:** komendy konsolowe mogą nadawać własny `correlationId` na `console.command`, z tym samym processorem.
- **Odpowiedź z błędem:** `ExceptionListener` może dodawać `correlationId` do ciała JSON błędu (np. `"correlationId"`
  przy 500), żeby klient mógł podać go przy zgłoszeniu. Nagłówek `X-Correlation-Id` będzie i tak.

## Testy (gdy przyjdzie do implementacji)

- `WebTestCase` + `TestHandler` Monologa (lub handler `test` w `when@test`):
  - jedno żądanie daje dokładnie jeden `HttpRequest` i jeden `HttpResponse` z tym samym `correlationId`, który jest
    też w nagłówku `X-Correlation-Id` odpowiedzi;
  - 400 / 404 (routing) / 405 / 500 mają oba wpisy, a dla 404 z routingu `HttpRequest` istnieje (priorytet przed
    routerem);
  - log z `ExceptionListener` ma `extra.correlationId` równy temu z `HttpRequest`;
  - `Authorization` jest zamaskowany, długi payload jest obcięty z `payloadTruncated: true`.
- Unit: `SensitiveDataMasker`, `HttpMessageNormalizer` (limity, nie-JSON, pliki, `StreamedResponse`).
- Uwaga: `KernelBrowser` nie woła `fastcgi_finish_request()`, ale `kernel.terminate` wywołuje (`$client->request()`
  robi `terminate()`), więc `HttpResponse` jest testowalny.

## Otwarte decyzje

1. Poziom `HttpResponse`: zawsze `info` czy zależnie od statusu (`warning` dla 4xx, `error` dla 5xx)? Przy zależnym
   od statusu trzeba pamiętać, że `error` w kanale `http` nie trafia do `fingers_crossed` (osobny handler), więc nie
   „odpali” bufora `main`. To raczej zaleta.
2. Limit payloadu (propozycja 16 KB) i lista maskowanych pól.
3. Czy przyjmować `X-Correlation-Id` z zewnątrz (propozycja: nie, tylko jako `clientCorrelationId`).
4. Czy robić `HttpRequestAborted` (shutdown function) od razu, czy dopiero gdy pojawi się potrzeba.
5. Czy propagację do Messengera robić razem z tym, czy jako osobny krok (propozycja: osobno).
