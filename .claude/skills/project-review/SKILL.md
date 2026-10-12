---
name: project-review
description: Pełny code review projektu symfony8 (architektura, DDD/CQRS, Messenger, Doctrine, testy) zapisany jako raport Markdown w .claude/review/<YYYY-MM-DD_HH_MM_SS>.md w stałym formacie. Używaj, gdy użytkownik prosi o "code review projektu", "review architektury", "przegląd DDD" lub wywoła /project-review. Opcjonalny argument zawęża zakres (np. ścieżka, warstwa, "messenger").
---

# Code review projektu (raport w `.claude/review/`)

Celem jest **przegląd całego projektu** (nie tylko diffu), ze szczególnym naciskiem na architekturę
i DDD, zakończony raportem w stałym formacie. Odpowiadasz i piszesz raport **po polsku**.
Nie zmieniasz kodu w ramach review — tylko czytasz, uruchamiasz narzędzia i piszesz raport.

Argument skilla (jeśli podany) zawęża zakres, np. `src/Account/Domain`, `messenger`, `testy`.
Bez argumentu: cały `src/`, `tests/`, `config/`, `migrations/`, pliki docker/Makefile.

## Krok 1. Kontekst

1. Przeczytaj `AGENTS.md`, `.claude/project-summary.md`, `README.md`, `composer.json`.
2. Znajdź **poprzedni raport**: najnowszy plik w `.claude/review/` (`ls -1 .claude/review/*.md | sort | tail -1`).
   Jeśli istnieje, przeczytaj go — w nowym raporcie musi powstać sekcja „Status uwag z poprzedniego review”.
   Jeśli nie istnieje, użyj listy „Znane problemy” z `.claude/project-summary.md`.
3. Ustal nazwę pliku raportu: `date +%Y-%m-%d_%H_%M_%S` → `.claude/review/<wynik>.md`.
   Jeśli użytkownik podał datę/godzinę wprost, użyj jej.

## Krok 2. Narzędzia (zawsze w Dockerze)

Na hoście nie ma PHP. Wszystko przez `docker compose exec -T php ...` (bez `-it` — sesja nie jest interaktywna).
Jeśli kontenery nie działają: `make up`. Uruchom i zanotuj wynik każdego polecenia:

| Polecenie | Po co |
|---|---|
| `vendor/bin/php-cs-fixer check` | styl `@Symfony` |
| `composer test:sa` | analiza statyczna |
| `composer test:phpunit` | testy |
| `bin/console lint:container` | poprawność DI |
| `bin/console debug:messenger` | które handlery na których busach |
| `bin/console debug:autowiring MessageBusInterface` | wiring busów |
| `bin/console doctrine:migrations:status` | stan migracji |

Czerwony build (cs/phpstan/testy) to zawsze ustalenie 🔴.

## Krok 3. Przegląd kodu

Przeczytaj **cały** kod w zakresie (nie próbki). Lista kontrolna:

**Architektura / warstwy**
- Kierunek zależności: Domain ← Application ← Infrastructure/Presentation. Czy Domain importuje Symfony/Doctrine
  (atrybuty ORM, `Clock::get()`, `Uid`)? Oceń, czy to świadomy kompromis, czy wyciek.
- Czy repozytoria robią tylko persystencję (bez publikacji zdarzeń, bez logiki)?
- Czy logika/reguły biznesowe nie siedzą w CLI/handlerach zamiast w domenie?
- Granice bounded contextów, co jest w `Shared` i czy powinno tam być.

**DDD**
- Agregaty: czy chronią niezmienniki, czy są anemiczne (tylko gettery + fabryka)? Czy zmiany idą *przez* korzeń?
- Value Objects: niemutowalne (`readonly`), `final`, walidacja w konstruktorze, równość, overflow.
- Zdarzenia domenowe: kto je emituje, czy w ogóle są emitowane, co niosą, czy listenery coś robią.
- Spójność języka (nazwy klas/pól/kolumn/komend).
- Współbieżność: wersjonowanie/locki tam, gdzie są niezmienniki (AGENTS.md: `symfony/lock`).

**CQRS / Messenger**
- `#[AsMessageHandler(bus: ...)]` — handler przypięty do właściwego busa.
- Routing, transakcje (`doctrine_transaction`), publikacja zdarzeń po commicie / outbox.
- Klasyfikacja błędów: deterministyczne błędy walidacji → `UnrecoverableMessageHandlingException`, nie retry.
- Kontrakt wiadomości (serializacja JSON): prymitywy vs VO, kompatybilność wsteczna.

**Doctrine / baza**
- Klucze obce, indeksy pod realne zapytania, typy kolumn (strefy czasowe!), migracje (nigdy `schema:update`).
- Koszt zapytań na ścieżce zapisu (np. liczenie sum tylko po to, by sprawdzić istnienie).

**Testy i jakość**
- Czy każda funkcja ma test „tak, jak woła ją klient” (CLI → `CommandTester`, handler → wywołanie przez bus).
- Czy `.claude/project-summary.md` zgadza się z kodem (rozjazdy zgłaszaj).
- Testy powinny obejmować wszystkie happy-pathy oraz najistotniejsze sad-pathy.
- Testy warstwy aplikacyjnej powinny być testami integracyjnymi / funkcjonalnymi (extends KernelTestCase od symfony). 
- testy warstwy domenowej powinny być zawsze niezależnymi / jednostkowymi (extends TestCase)
- testy kontrolerów powinny testami integracyjnymi / funkcjonalnymi (extends WebTestCase od symfony)

## Krok 4. Weryfikacja ustaleń

Każde ustalenie musi mieć dowód: `plik:linia` i — jeśli się da — potwierdzenie uruchomieniem
(`docker compose exec -T php php -r '...'`, polecenie konsoli, test). Ustalenie, którego nie udało się
potwierdzić, oznacz **(niepotwierdzone)** albo je pomiń. Nie zgłaszaj rzeczy, które tylko „mogłyby” być problemem
bez konkretnego scenariusza.

## Krok 5. Raport — format (obowiązkowy)

Poziomy ważności:

| Poziom | Znaczenie |
|---|---|
| 🔴 Krytyczny | czerwony build, utrata/uszkodzenie danych, błąd poprawności na głównej ścieżce |
| 🟠 Wysoki | błąd architektoniczny lub DDD z realnym ryzykiem; błąd, który ujawni się przy następnej funkcji |
| 🟡 Średni | dług techniczny z konkretnym kosztem (wydajność, niespójność, brak testów) |
| 🔵 Niski | porządki, nazewnictwo, drobna niespójność |

Kategorie (jedna na ustalenie): `Architektura`, `DDD`, `CQRS/Messenger`, `Doctrine/DB`, `Poprawność`,
`Testy`, `Konfiguracja`, `Styl`.

Identyfikatory: `K-n` (krytyczne), `W-n` (wysokie), `S-n` (średnie), `N-n` (niskie), numeracja od 1 w każdym raporcie.

Szablon (zachowaj kolejność i nagłówki):

````markdown
# Code review — symfony8 — <YYYY-MM-DD HH:MM>

| | |
|---|---|
| Zakres | <cały projekt / zawężenie> |
| Wersja kodu | <commit lub "brak repozytorium git"> |
| Poprzednie review | <ścieżka lub "brak"> |
| Autor | Claude (<model>) |

## TL;DR

<3–5 zdań: ogólna ocena, najważniejsze ryzyko, co zrobić najpierw.>

| 🔴 Krytyczne | 🟠 Wysokie | 🟡 Średnie | 🔵 Niskie |
|:-:|:-:|:-:|:-:|
| n | n | n | n |

## Wyniki narzędzi

| Narzędzie | Wynik | Uwagi |
|---|---|---|
| php-cs-fixer | ✅ / ❌ | ... |
| ... | | |

## Ocena obszarów

| Obszar | Ocena (1–5) | Komentarz |
|---|:-:|---|
| Warstwy i zależności | | |
| Model domenowy (DDD) | | |
| Zdarzenia domenowe | | |
| CQRS / Messenger | | |
| Persystencja (Doctrine/DB) | | |
| Testy | | |
| Narzędzia i konfiguracja | | |

## Mocne strony

- <konkretnie, z odwołaniem do plików>

## Ustalenia

### 🔴 Krytyczne

#### K-1. <Krótki tytuł>

- **Kategoria:** <kategoria>
- **Lokalizacja:** `ścieżka/Plik.php:linia` (+ kolejne)
- **Problem:** <co jest nie tak, konkretnie>
- **Dowód:** <wynik polecenia / scenariusz krok po kroku>
- **Dlaczego to ważne:** <skutek>
- **Rekomendacja:** <co zrobić; krótki fragment kodu, jeśli pomaga>

### 🟠 Wysokie
...
### 🟡 Średnie
...
### 🔵 Niskie
...

(Pusta sekcja → „Brak.”)

## Architektura i DDD — omówienie

<Narracja: jak wygląda przepływ (CLI → bus → handler → domena → repozytorium → zdarzenia), gdzie są granice,
co jest anemiczne, jak powinien wyglądać docelowy model. Może zawierać diagram w bloku ```text```.>

## Plan naprawczy

| # | Krok | Ustalenia | Wysiłek (S/M/L) |
|---|---|---|:-:|
| 1 | ... | K-1 | S |

## Status uwag z poprzedniego review

| Uwaga | Status | Komentarz |
|---|---|---|
| ... | ✅ naprawione / ⏳ otwarte / ➖ nieaktualne | |

## Rozjazdy z dokumentacją

- <miejsca, gdzie `.claude/project-summary.md`/README nie zgadza się z kodem; „Brak.” jeśli nie ma>
````

Zasady formatu:
- Ścieżki zawsze w backtickach, z numerem linii.
- Rekomendacje konkretne (co, gdzie, jakim mechanizmem Symfony), zgodne z `AGENTS.md`.
- Fragmenty kodu krótkie (do ~15 linii), tylko gdy wyjaśniają rekomendację.
- Ustalenia w sekcji posortowane od najważniejszego.

## Krok 6. Odpowiedź w czacie

Po zapisaniu pliku wypisz w czacie tylko: ścieżkę raportu, tabelę liczności ustaleń, wynik narzędzi
w jednym zdaniu i 3 najważniejsze ustalenia (po jednym zdaniu). Nie wklejaj całego raportu.
