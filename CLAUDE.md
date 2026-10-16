# Instrumento

Gestionale per agenzie e studi (pratiche, clienti, documenti, notai). Le regole comuni a tutti i
progetti RPG stanno in **`CONVENZIONI-SVILUPPO.md`**: questo file contiene solo i valori specifici
di Instrumento e le eccezioni motivate.

## Stack e ambiente

- Symfony 7.4, Doctrine ORM 3 con DBAL 4, Twig, tema Canvas su Bootstrap 5, MariaDB (Laragon).
- **Multi-tenant master/slave**: il master (`instrumento_master_db`) contiene clienti (`Company`), licenze,
  utenti amministratore e notaio, catalogo geografico; ogni agenzia ha il suo database
  (`Company::$dbName`, es. `instrumento_ag001`) su cui la connessione `slave` viene ripuntata a runtime
  (`App\Bundle\DynamicConnection`, `CompanyService::pointSlaveToDbName()`).
- Entity in `src/Entity/Master/` e `src/Entity/Slave/`, repository e validator con la stessa divisione
  (`config/validator/Master/`, `config/validator/Slave/`, `config/validator/Model/`).
- Prova in locale: `php -S 127.0.0.1:8137 -t public public/index.php` (porta 8137). Il dominio punta alla produzione.
- Utenti di prova (marcati `CLAUDE-TEST` nel nome): `claude-test-admin@instrumento.local` (admin) e
  `notaio@ebasm.it` (notaio, è l'e-mail a cui sono assegnate le pratiche di prova di AG001).
  Area agenzia: si entra solo con un utente dello staff dalla pagina `/accedi/AG001` (l'impersonazione
  da admin non esiste più) e serve una licenza valida.

## Valori del progetto

- Prefisso tabelle: **`eb_m_`** sul master, **`eb_s_`** sugli slave (storico: il progetto nasce da ebasm).
- Globale Twig `PATH_GRAPHICS` = `images/graphics/` (parametro `path_graphics`). Loghi in `images/graphics/logos/`
  (`wordmark-scuro.svg` per l'header pubblico, `wordmark-bianco.svg` per l'area riservata).
- Aree e prefissi di rotta: `admin_` (`/amministratore`), `agency_` (`/agenzia`), `notary_` (`/notaio`),
  `script_` (`/script`, http basic `ROLE_SCRIPT`, password in `SCRIPT_PASSWORD`).
- File caricati fuori dalla webroot: `var/uploads/logos/{dbName}/` e `var/uploads/practices/{dbName}/`,
  serviti da controller con controllo dei permessi.

## Migration (CONVENZIONI 12)

- Due cartelle: `migrations/master/` (namespace `DoctrineMigrations\Master`) e `migrations/slave/`
  (`DoctrineMigrations\Slave`, una copia per agenzia). Con due namespace **mai** `latest` / `next`:
  sempre una versione esplicita, `--em=master` o `--em=slave`.
- In produzione: `/script/migration` (`ScriptMigrationController`, dalla skill symfony-migration-controller),
  che per lo slave esegue la migration su ogni agenzia che non l'ha ancora.
- Le migration dello slave devono essere idempotenti (guardie sullo schema): le agenzie non sono tutte allo stesso punto.
- Un'agenzia nuova nasce con lo schema corrente e tutte le migration slave già registrate
  (`CompanyService::provisionDatabase()`).
- `app:sync-slave-schema` (schema:update su tutti gli slave) resta solo come strumento di sviluppo.

## Eccezioni motivate alle convenzioni

- **Rotte dei controller pubblici e di sicurezza** (`DefaultController`, `SecurityController`, `SeoController`):
  restano i nomi storici (`homepage`, `login`, `logout`, `login_check`, `robots_txt`, `sitemap_xml`, ...)
  perché li usano `security.yaml`, i redirect del firewall e la SEO. La regola "rotta = snake_case del metodo"
  vale per le aree riservate e per i controller di servizio.
- **`remove_filter`** (in `SessionController`): nome fissato dalle convenzioni (paragrafo 7), accetta solo le liste
  elencate in `SessionController::FILTERED_LISTS`.
- **Ordinamento delle liste**: lo applicano i repository dai filtri (`sortField` / `sortOrder`); KnpPaginator è
  configurato per ignorare i suoi parametri di ordinamento (`config/packages/knp_paginator.yaml`).
- **Scrivanie senza `page_link`** (CONVENZIONI 8): le scrivanie di agenzia e notaio non ripetono le sezioni del
  menu come card, mostrano solo i riquadri di lavoro (pratiche contrassegnate, spazio, avvisi); alle sezioni si
  arriva dal menu laterale. Il componente `page_link` è stato tolto.
- **Form nei modali**: schema di tillomeditalia, la pagina che mostra il modale gestisce il form (`createNamed`),
  la modifica porta l'id in query (`?edit=ID`). Il picker città/CAP condiviso invia `city_id` / `zip_id` fuori
  dal form Symfony e li risolve il controller dopo `isValid()`.

## Comandi utili

- `app:create-user`, `app:create-agency`: utenti master e nuove agenzie.
- `app:notify-licenses` (cron giornaliero): avvisi di scadenza licenza e sospensione delle demo scadute.
- `app:sync-permissions`: allinea il catalogo dei permessi degli slave.
