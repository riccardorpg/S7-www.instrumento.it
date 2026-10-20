# Instrumento

Gestionale per agenzie e studi (pratiche, clienti, documenti, notai). Le regole comuni a tutti i
progetti RPG stanno in **`CONVENZIONI-SVILUPPO.md`**: questo file contiene solo i valori specifici
di Instrumento e le eccezioni motivate.

## Stack e ambiente

- Symfony 7.4, Doctrine ORM 3 con DBAL 4, Twig, tema Canvas su Bootstrap 5, MariaDB (Laragon).
- **Database unico** (`instrumento_db`): amministrazione, notai e tutte le agenzie. I dati di un'agenzia
  (clienti, pratiche, tipi documento, contrassegni, tag) portano la FK `company_id`
  (`CompanyOwnedInterface` + `CompanyOwnedTrait`); righe documentali, allegati e avvisi stanno sotto la pratica.
- **Isolamento**: il filtro Doctrine `company` (`App\Doctrine\Filter\CompanyFilter`), acceso da
  `CompanyFilterListener` per ogni richiesta di uno staff di agenzia, limita ogni query (anche `find()` e join)
  alla sua agenzia. `CompanyOwnedListener` assegna l'agenzia ai nuovi record e rifiuta il salvataggio in un'altra.
  Le entity figlie della pratica le proteggono i controller risalendo alla pratica. Comandi console e area
  notaio/admin girano a filtro spento: lì le query vanno limitate a mano (`p.company`, `p.notary`).
- **Utenti**: una sola tabella `eb_user`, un account = un'e-mail. `ROLE_ADMIN`, `ROLE_NOTARY`, `ROLE_USER`
  (staff di agenzia, con `company`, ruolo di staff e permessi di sezione). Login unico su `/accedi`;
  `UserChecker` blocca utenti disattivi e staff di agenzie sospese o senza licenza valida.
- **Pratica**: `company` (agenzia) e `notary` (utente ROLE_NOTARY). Codice generato con il prefisso dell'agenzia,
  `AG001-0001/2026` (`PracticeRepository::nextNumber()`), unico su tutta la piattaforma. Il notaio vede le pratiche a lui assegnate
  di tutte le agenzie attive, senza selezione dell'agenzia.
- **Staff del notaio**: collaboratori `ROLE_NOTARY_STAFF` (`User::$collaborators`, tabella `eb_notary_staff`; uno
  può stare nello staff di più notai). Il notaio li crea o li aggiunge cercandoli per e-mail (`NotaryStaffController`)
  e assegna loro le sue pratiche (`Practice::$notaryStaff`). Il collaboratore lavora nell'area notaio come il notaio,
  solo sulle pratiche assegnate e finché è nello staff: la regola sta in `PracticeRepository::notaryScope()` e
  `Practice::isVisibleInNotaryArea()`.
- Entity in `src/Entity/`, repository in `src/Repository/`, validator in `config/validator/`
  (`config/validator/Model/` per i modelli dei form).
- Prova in locale: `php -S 127.0.0.1:8137 -t public` (porta 8137; senza `public/index.php` come router, altrimenti
  anche CSS e JS passano da Symfony). Il dominio punta alla produzione.
- Utenti di prova (marcati `CLAUDE-TEST` nel nome): `claude-test-admin@instrumento.local` (admin),
  `notaio@ebasm.it` (notaio, con la pratica di prova P-0009/2026 di AG001) e
  `claude-test-staff@instrumento.local` (staff amministratore di AG001). Tutti entrano da `/accedi`;
  per lo staff serve una licenza valida dell'agenzia (l'impersonazione da admin non esiste).

## Valori del progetto

- Prefisso tabelle: **`eb_`** (storico: il progetto nasce da ebasm).
- Globale Twig `PATH_GRAPHICS` = `images/graphics/` (parametro `path_graphics`). Loghi in `images/graphics/logos/`
  (`wordmark-scuro.svg` per l'header pubblico, `wordmark-bianco.svg` per l'area riservata).
- Aree e prefissi di rotta: `admin_` (`/amministratore`), `agency_` (`/agenzia`), `notary_` (`/notaio`),
  `script_` (`/script`, http basic `ROLE_SCRIPT`, password in `SCRIPT_PASSWORD`).
- File caricati fuori dalla webroot: `var/uploads/logos/{companyId}/` e `var/uploads/practices/{companyId}/{practiceId}/`,
  serviti da controller con controllo dei permessi.

## Migration (CONVENZIONI 12)

- Una cartella: `migrations/` (namespace `DoctrineMigrations`). La prima, `Version20261020100000`, è lo schema
  iniziale del database unico (le vecchie migration master/slave sono nella storia git).
- In produzione: `/script/migration` (`ScriptMigrationController`, dalla skill symfony-migration-controller).
- `app:merge-legacy-databases` (una tantum) porta nel database unico i dati della vecchia architettura
  master/slave: si ferma se trova e-mail doppie, rimappa gli id, copia i file.

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

- `app:create-user`, `app:create-agency`: amministratori/notai e nuove agenzie (con il primo utente).
- `app:notify-licenses` (cron giornaliero): avvisi di scadenza licenza e sospensione delle demo scadute.
- `app:sync-permissions`: allinea il catalogo dei permessi dello staff.
