# Convenzioni di sviluppo RPG Servizi Multimediali

Raccolta delle direttive che seguiamo nello sviluppo dei nostri software.
Il file nasce dal progetto `www.rpgmultimedia.it` ed è stato poi confrontato con
`www.maleco-officina.it` e `www.tillomeditalia.com`: è la base del documento comune
a tutti i progetti (e delle skill di `rpg-claude-plugin`).

I **nomi specifici di un progetto** (prefisso delle tabelle, valore dei globali, porte, database)
non stanno qui: stanno nel `CLAUDE.md` del progetto, che rimanda a questo file.

## Come leggere i tag

Ogni sezione è marcata così, per capire cosa migrare nel file comune e cosa resta locale:

- `[C]` = **comune**: vale per tutti i progetti RPG, va nel file condiviso.
- `[P: progetto]` = **specifico di quel progetto** (percorsi, prefissi, nomi cartelle, versioni),
  resta nel CLAUDE.md locale. Il nome del progetto è sempre indicato.
- `[C?]` = **da verificare**: visto su almeno due progetti ma non ancora confermato su tutti.

---

## 1. Stack e ambiente

**[P: rpgmultimedia]** Symfony 7.4 (PHP >= 8.2), Doctrine ORM 2, Twig, Bootstrap 5.
**[P: maleco-officina]** Symfony 7.4 (PHP >= 8.2), Doctrine ORM 3 con DBAL 3, Twig, Bootstrap 5.
**[P: tillomeditalia]** Symfony 7.4 (PHP >= 8.2), Doctrine ORM 3 con DBAL 4, Twig, tema Canvas su Bootstrap 5.

Ambiente locale Laragon su Windows, database MySQL / MariaDB.

**[C]** Regole di base:

- Symfony **7.4** come riferimento: usare attributi PHP (`#[Route]`, `#[ORM\Column]`), mai annotazioni docblock.
- Database sempre MySQL / MariaDB: query e migration possono usare sintassi MariaDB (`IF NOT EXISTS`, ecc.).
- Niente mapping ORM in XML o YAML per le nuove entity: solo attributi PHP.
- Doctrine ORM **3** sui progetti nuovi. Sui progetti ancora su ORM 2 va tenuto d'occhio il codice che si rompe
  al passaggio: il punto preciso è `QueryBuilder::setParameters()` (vedi paragrafo 5).
- La validazione invece resta in YAML (vedi paragrafo 6), non in attributi.

**[C]** Librerie ricorrenti, da usare queste e non altre equivalenti:

| Serve | Libreria |
| --- | --- |
| paginazione delle liste | `knplabs/knp-paginator-bundle` |
| generazione PDF | `mpdf/mpdf` |
| export e import Excel | `phpoffice/phpspreadsheet` |
| codici a barre e QR | `picqer/php-barcode-generator`, `endroid/qr-code-bundle` |
| migration del database | `doctrine/doctrine-migrations-bundle` |

---

## 2. Lingua: quando inglese e quando italiano

**[C]** Regola generale: **il codice è in inglese, l'interfaccia è in italiano**.

| Elemento | Lingua | Esempio |
| --- | --- | --- |
| Classi, entity, proprietà, metodi | inglese | `EmployeeAbsence`, `numOfHours`, `getNumOfHoursLabel()` |
| Colonne e tabelle del database | inglese, `snake_case` | `rpg_employee_absence`, `illness_code` |
| Chiavi degli array e dei JSON salvati | inglese | `jsonPrev`, `jsonCons`, `totalWeight` |
| Nomi delle route | inglese, `snake_case` | `admin_supplier_new`, `user_waste_disposal_list` |
| Nomi di file e cartelle dei template | inglese, `snake_case` | `supplier_order/`, `product_request/` |
| Path (URL) delle route | **italiano** | `/amministratore/fornitori/nuovo`, `/utente/smaltimento` |
| Testi a video, label, messaggi di errore, flash | italiano | "Fornitore inserito correttamente!" |
| Commenti nel codice | italiano | `// Ore di assenza: decimale, così da poter inserire frazioni di ora` |

**[C]** Unica eccezione ai nomi in inglese: i **campi che arrivano da API o tracciati esterni**
(fatturazione elettronica, F24, gestionali di terze parti, webservice dei fornitori).
In quel caso si mantiene il nome originale del tracciato, così il mapping resta leggibile e verificabile,
con un commento in italiano che ne spiega la provenienza.

**[C]** Le cartelle dei template usano `snake_case`, non `kebab-case`: `waste_disposal/`, non `waste-disposal/`.
Dove convivono le due forme si uniforma quando si mette mano a quell'area.

---

## 3. Struttura del codice

**[C]** Struttura standard sotto `src/`:

```
src/
  Controller/
    <Area>/    controller dell'area riservata          (Admin/, Staff/, User/)
    ...        controller pubblici e di servizio       (DefaultController, ScriptController,
                                                        MigrationController, UtilitiesController)
  Entity/       una classe per entity, nome singolare
  Form/         un form per azione (XxxNewType, XxxEditType)
    Custom/     tipi di campo riutilizzabili (CustomTextType, CustomDateType, ...)
    Model/      oggetti non mappati usati da un form (import, cambio password)
  Repository/   un repository per entity (XxxRepository)
  Security/     authenticator, voter, gestore degli accessi negati
  Service/      logica applicativa riutilizzabile (XxxService)
  Twig/Extension/ filtri e funzioni Twig custom
  Validator/    constraint custom
  Command/      comandi da console
```

**[C]** Convenzioni sui nomi:

- Controller: uno per area funzionale, non uno per ogni entity minore.
  **Il nome inizia sempre con il nome dell'area** (`AdminSupplierController`, `StaffAbsenceController`,
  `UserPerformanceController`), anche quando l'area è una sola: serve a capire a colpo d'occhio,
  da uno stack trace o da un log, dove sta la classe.
- Service: nome che finisce in `Service`, metodi statici quando non serve stato (`FilterService::supplierFilter()`).
- Ogni entity **con query proprie** ha il suo repository. Le entity di sola relazione (tabelle di join,
  righe di dettaglio lette sempre dal padre) possono non averlo.
- Le aree corrispondono ai ruoli: `Admin/` amministratore, `Staff/` operatori interni, `User/` utenti della
  piattaforma. Un progetto può averne una sola.

---

## 4. Entity

**[C]** Regole:

- Tabella dichiarata sempre in modo esplicito con il prefisso del progetto:
  `#[ORM\Table(name:"rpg_employee_absence")]`.
  Il prefisso è uno solo per progetto ed è dichiarato nel suo `CLAUDE.md`.
- Nome della colonna sempre esplicito, in `snake_case`: `#[ORM\Column(name:"illness_code", ...)]`.
  Non affidarsi alla derivazione automatica di Doctrine.
- Chiave primaria `id` di tipo `bigint`, `strategy:"AUTO"`.
- Relazioni dichiarate con `targetEntity` in forma di stringa FQCN e `JoinColumn` con `name` esplicito.
- Getter e setter fluent (`setXxx(): self` oppure `: static`, che ritorna `$this`).
- Tipi PHP dichiarati su getter e setter (`?string`, `?\DateTimeInterface`, `?float`).
- Importi e quantità decimali: colonna `decimal` con `precision` e `scale`, e cast a `float` nel getter,
  così il resto del codice non lavora mai con stringhe numeriche.
- I metodi di **presentazione** (formattazione per l'utente) stanno sull'entity con il suffisso `Label`:
  `getNumOfHoursLabel()` restituisce `1,5` invece di `1.50`. Il template chiama il Label, non formatta da sé.
- Metodi di comodo per i calcoli accanto al dato grezzo (`getNumOfMinutes()`), così la logica non si sparpaglia.
- I controlli di ammissibilità stanno sull'entity con il prefisso `getCan`: `getCanDelete()` risponde
  guardando le relazioni collegate, e il template lo usa per mostrare o nascondere l'icona.
- `__toString()` sulle entity che vengono stampate nei titoli o usate in una select.

---

## 5. Repository e query

**[C]** Regole:

- Il repository estende `ServiceEntityRepository`.
- Metodi `add(Entity $e, bool $flush = true)` e `remove(...)` per il ciclo di vita base.
- Le query di lista filtrata si chiamano `queryXxxFiltered($filters)` e **restituiscono la Query**,
  non il risultato: la paginazione la fa il paginator nel controller.
  Quando il repository serve una sola lista si accetta anche `queryAllFiltered($filters)`.
  Il prefisso `query` dice che torna la Query e non il risultato: dove si trova ancora la forma vecchia
  `findXxxFiltered()` si rinomina quando si mette mano a quel repository.
- L'ordinamento di default fa parte della query, non del template. L'ordinamento scelto dall'utente
  arriva dai filtri (`sortField`, `sortOrder`) e viene applicato in coda al DQL.
- I filtri si costruiscono con il pattern `WHERE 1=1` più le condizioni aggiunte solo se il filtro è valorizzato,
  accumulando i parametri in un array dedicato (`$params`), **non** riusando l'array dei filtri della request:
  contiene anche voci che non sono parametri della query (intervalli di date grezzi, ordinamento, pagina).
- Niente concatenazione di valori nel DQL: sempre parametri nominati.
- **`setParameters()` e ORM 3** (verificato sul codice di `doctrine/orm` installato, settembre 2026):

  | Oggetto | ORM 2 | ORM 3 |
  | --- | --- | --- |
  | `Query` / `AbstractQuery` da `createQuery($dql)` | array associativo ammesso | **array associativo ammesso**, implementazione identica |
  | `QueryBuilder` | array associativo ammesso (scorciatoia di compatibilità) | **solo `ArrayCollection`**: la firma è `setParameters(ArrayCollection $parameters)` |

  Quindi la regola non è "su ORM 3 `setParameters()` non va più": è che **su un QueryBuilder l'array
  associativo non è più accettato** e va usata una `setParameter()` per filtro:

  ```php
  foreach($params as $key => $value)
      $qb->setParameter($key, $value);
  ```

  Con `createQuery($dql)->setParameters($params)` non serve. Non c'entra la versione di DBAL:
  il comportamento è lo stesso con DBAL 3 e DBAL 4.

---

## 6. Form e validazione

**[C]** Form:

- **Un tipo per entity** (`SupplierType`), riusato da inserimento e modifica finché i campi coincidono.
  Quando la modifica mostra campi diversi si aggiunge un secondo tipo (`SupplierEditType`), non un ramo
  dentro al primo: la regola vera è che **dentro al form non ci sono `if` sull'azione**.
  Un tipo per azione fin da subito (`SupplierNewType` + `SupplierEditType`) resta corretto,
  ed è la forma da preferire quando si sa già che le due azioni divergeranno.
- Campi ricorrenti tramite tipi custom in `src/Form/Custom/` (`CustomTextType`, `CustomTextareaType`,
  `CustomDateType`, `CustomDateNullableType`, `CustomSearchType`): concentrano attributi, widget e classi CSS.
- Campi non mappati sull'entity: sempre `'mapped' => false` (modalità, conferme, campi di supporto).
- `EntityType` con `query_builder` esplicito per l'ordinamento e `choice_label` sul campo leggibile.
- I form che non stanno su un'entity lavorano su un oggetto in `src/Form/Model/` (import, cambio password),
  non su array sciolti.

**[C]** Validazione:

- Le regole stanno in **YAML** dentro `config/validator/<Entity>.yml`, una per entity. Mai constraint in attributi.
  Estensione `.yml`, non `.yaml`: vale per i soli file di validazione, i file di configurazione di Symfony
  restano `.yaml` come li genera Flex. Dove convivono le due forme si uniforma quando si tocca quel file.
- Si usano sempre i **gruppi di validazione**, in `snake_case`: `<entity>` quando il tipo è uno solo
  (`employee_absence`), `<entity>_<azione>` quando i tipi sono separati o quando serve una variante
  (`employee_absence_new`, `employee_absence_edit`, `employee_absence_period`).
- Il form dichiara il gruppo in `configureOptions()`; se il gruppo dipende da un campo,
  `validation_groups` diventa una closure che legge il form.
- I messaggi di errore sono in **italiano**, brevi e diretti ("Campo obbligatorio").
- La validazione dei dati sta nel validator, non nel controller. Il controller gestisce solo gli errori
  che dipendono da logiche esterne (aggiunti con `FormError`).

---

## 7. Controller

**[C]** Struttura:

- Prefisso di route a livello di classe, con il path in italiano: `#[Route('/amministratore/fornitori')]`.
- Controllo dei permessi a livello di classe con `#[IsGranted(...)]` (vedi paragrafo 14).
  Se una singola azione richiede di più, si aggiunge l'attributo anche sul metodo.
- Le azioni che modificano dati sono `methods:['POST']`.

**[C]** Nome dei metodi: **il metodo riprende il nome del controller**, seguito dall'azione.
Serve a capire a colpo d'occhio, leggendo uno stack trace o un log, da quale controller arriva la chiamata:

| Controller | Metodo | Route |
| --- | --- | --- |
| `AdminController` | `adminIndex()` | `admin_index` |
| `AdminSupplierController` | `adminSupplierNew()` | `admin_supplier_new` |
| `AdminSupplierController` | `adminSuppliers()` | `admin_suppliers` |
| `StaffAbsenceController` | `staffAbsenceEdit()` | `staff_absence_edit` |
| `UserWasteDisposalController` | `userWasteDisposalList()` | `user_waste_disposal_list` |

Il nome della route è sempre lo `snake_case` del nome del metodo: se uno dei due cambia, cambiano entrambi.
Metodi generici (`list()`, `new()`, `exportExcel()`) sono da evitare: in un log dicono l'azione ma non l'area.

**[C]** Le cinque azioni standard di ogni entità gestita:

| Azione | Route | Template |
| --- | --- | --- |
| elenco filtrato e paginato | `admin_suppliers` (`/lista`) | `list.html.twig` |
| inserimento | `admin_supplier_new` (`/nuovo`) | `new.html.twig` |
| modifica | `admin_supplier_edit` (`/modifica/{id}`) | `edit.html.twig` |
| scheda di dettaglio | `admin_supplier_sheet` (`/scheda/{id}`) | `sheet.html.twig` |
| eliminazione | `admin_supplier_delete` (POST) | (redirect) |

**[C]** Dopo il salvataggio si usa sempre il pattern post / redirect / get con un flash message.
Chiavi dei flash, sempre queste tre:

- `notice_success` per le operazioni andate a buon fine,
- `notice_warning` per gli avvisi,
- `notice_danger` per gli errori.

**[C]** Dove serve, il salvataggio da `new` supporta il parametro `saveAndSheet`: se vale `sheet` si va alla
scheda appena creata, altrimenti si torna alla lista. Non è obbligatorio: si aggiunge sulle entity che si
compilano a tappe (quelle con una scheda ricca), non su quelle che si inseriscono e basta.

**[C]** Le azioni chiamate via AJAX restituiscono JSON con almeno `{'success': true|false}`,
più i dati aggiornati che servono al front end.

**[C]** Filtri: la costruzione dei filtri sta in `FilterService`, un metodo statico per lista
(`FilterService::supplierFilter($request)`), che li legge dalla request e li conserva in sessione,
così tornando su una lista si ritrovano gli stessi criteri. La route `remove_filter` li azzera.

**[C]** Filtri per intervallo di date: in sessione e nel form viaggiano come **una sola stringa**
`'gg/mm/AAAA - gg/mm/AAAA'` (widget daterangepicker). È il controller a spezzarla in `dateStart` e `dateEnd`
(`DateTime::createFromFormat('d/m/Y', ...)`, con orari `00:00:00` e `23:59:59`) prima di passarla al repository.

---

## 8. Template Twig

**[C]** Gerarchia:

```
base.html.twig                  head, css, js, footer, flash message
  admin/index.html.twig         area amministratore
  staff/index.html.twig         area staff
  user/index.html.twig          area utente
```

Ogni template dell'area riservata estende il layout della sua area, mai direttamente `base.html.twig`.
Il layout dell'area contiene il menu, il titolo di pagina, il breadcrumb e i due sotto menu.

**[C]** Blocchi da valorizzare sempre: `title`, il titolo di pagina dell'area, il breadcrumb,
`sub_menu_left` e `sub_menu_right`. I nomi dei blocchi sono in `snake_case`
(`role_page_title_inner`, `role_page_title_breadcrumb`, `role_content`, `sub_menu_left`, `sub_menu_right`).

**[C]** Divisione dei due sotto menu, sempre questa:

- `sub_menu_left`: **navigazione**, cioè ritorno alla scrivania, ritorno alla lista, passi del breadcrumb;
- `sub_menu_right`: **azioni**, cioè Aggiungi, Esporta, cambio di stato, integrazioni.

**[C]** I quattro template standard per entity:

- `list.html.twig`: tabella con la riga dei filtri dentro al `<thead>` (form `GET`), intestazioni con classi
  `bg_pr color_w`, colonna azioni in fondo, paginazione con `knp_pagination_render()`, modali di conferma in coda.
- `new.html.twig` e `edit.html.twig`: solo il form.
- `sheet.html.twig`: **la scheda dell'entity**, cioè la pagina che raccoglie tutto quello che le ruota intorno.

**[C]** Nella riga dei filtri **non si mettono placeholder**: i campi restano vuoti, senza "Cerca...".
L'intestazione della colonna dice già cosa si sta filtrando, e il placeholder sporca una riga già fitta.

**[C]** Regola sulla scheda: quando un'entity ha dati collegati (documenti, ordini, fatture, attività, storico),
non si creano pagine separate per ogni sezione, ma si usa `sheet.html.twig` con i **tab Bootstrap**:

- il primo tab è sempre l'anagrafica, con il link di modifica in alto a destra del titolo;
- ogni tab successivo è una relazione dell'entity (listino, ordini, fatture, materiali, ...);
- i tab hanno la classe `tab_session`, così il tab attivo resta selezionato dopo un salvataggio,
  ed è il controller a impostarlo in sessione (`$session->set('tab_supplier', 'anagraphic')`);
- i tab condizionali si mostrano con un `{% if %}` sul dato (esempio: le autofatture solo per i fornitori esteri);
- le sotto entity hanno la loro cartella sotto quella del padre (`templates/admin/customer/domain/sheet.html.twig`)
  e possono avere a loro volta una scheda.

**[C]** Componenti riutilizzabili in `templates/components/`: modali, messaggi (`div_info_msg`,
`div_warning_msg`, `div_danger_msg`, `tr_info_msg`), blocchi JS in `components/js/`, schede e link
(`page_link`, `divider`, `preloader`, `loading`).
Prima di scrivere un pezzo di markup, controllare se il componente esiste già.

**[C]** Le icone si prendono da una variabile globale di Twig che si chiama sempre **`PATH_GRAPHICS`**,
in maiuscolo come tutte le globali (il valore, datato o no, è nel `CLAUDE.md` del progetto):
`{{ asset (PATH_GRAPHICS ~ '/icons/edit.png') }}`, con la classe `icon_action` più il colore
(`icon_amber`, `icon_red`, `icon_green`). Il percorso è definito una volta sola, mai scritto a mano nel template.

**[C]** Menu e scrivania mostrano **le stesse voci nello stesso ordine**: se si aggiunge una sezione al menu,
la si aggiunge anche fra le `page_link` della scrivania, nella stessa posizione.
Le **configurazioni stanno sempre in fondo**, sia nel menu sia nella scrivania, dopo ogni altra voce.

---

## 9. Modali, messaggi e testi di aiuto

**[C]** Per conferme e avvisi prima di un'azione **non si usano `window.alert()` né `window.confirm()`**:
si usa un modale del progetto, come tutti gli altri messaggi dell'applicazione.
I dialoghi nativi del browser hanno un aspetto estraneo all'interfaccia, non permettono testo formattato
e sono l'unico punto in cui l'utente vede una grafica diversa.

**[C]** Componenti da usare:

- conferme di eliminazione e di azione: `components/modals/confirm.html.twig`, passando un `type` proprio,
  l'`action` della rotta e un `modalBody` con gli input nascosti e un `<p>` vuoto con un id.
  Il componente genera da sé la funzione di apertura `{{ type }}ConfirmModal()` e racchiude tutto in un
  `<form method="post">`: per far partire l'azione senza mostrare il modale basta
  `document.querySelector('#{{ type }}ConfirmModal form').submit()`;
- form dentro al modale: `components/modals/form.html.twig`;
- dettagli di sola lettura: `details`, scelte multiple: `three_option`, avanzamento: `progress`.

Il nome del file **non ripete `modal_`**: stanno già dentro `components/modals/`.
Dove si trova ancora la forma vecchia (`modal_confirm.html.twig`) si rinomina quando si tocca quell'area.

Il messaggio si riempie da JavaScript con `innerHTML`, così si possono usare `<strong>` e `<br>`.

**[C]** Le azioni distruttive passano **sempre** da un modale di conferma e da una rotta in POST.

**[C]** Le **spiegazioni dei campi** non si scrivono come testo grigio sotto al campo: si mettono a database
e si richiamano con un'icona informativa accanto all'etichetta, che apre il pannello di aiuto.

```twig
<a href="javascript:divHelp('<slug>')" data-bs-toggle="tooltip" title="Info <argomento>">
    <img class="icon_info" src="{{ asset (PATH_GRAPHICS ~ '/icons/info.png') }}"/>
</a>
```

L'interfaccia resta compatta e il testo diventa modificabile a database senza toccare i template.
Se lo slug non esiste il pannello semplicemente non si apre, senza errori.
Il testo si inserisce con una **migration** (paragrafo 12), così arriva anche in produzione.
**[P: maleco-officina]** tabella `m_o_help`, funzione `divHelp()` definita in `templates/base.html.twig`.

Restano come testo grigio solo le informazioni di stato, non le spiegazioni:
per esempio "Generato il 08/09/2026 10:13" sotto un documento.

---

## 10. CSS, JavaScript e testi dell'interfaccia

**[C]** CSS:

- Il versionamento degli asset va gestito con le **funzioni asset di Symfony**, non a mano.
  Il problema da risolvere è sempre lo stesso (la cache del browser che serve il CSS vecchio dopo un rilascio),
  ma le cartelle datate e i `?v=48` scritti nel template sono da considerare una soluzione superata:
  vanno mantenuti dove già esistono, non replicati sui progetti nuovi.
  - **Da preferire**: AssetMapper (`symfony/asset-mapper`), che versiona in automatico con l'hash del contenuto,
    quindi il file cambia URL solo quando cambia davvero.
  - **In alternativa**, su progetti che non usano AssetMapper: `framework.assets.version` più `version_format`,
    così il numero di versione si alza in un punto solo di configurazione invece che in ogni `<link>`.
  - In entrambi i casi il template scrive solo `{{ asset('css/...') }}`: nessuna query string a mano,
    nessuna data nel percorso.
- **[P: rpgmultimedia]** cartelle datate `css/personal_AAAA_MM_GG/` e `images/graphics_AAAA_MM_GG/` con `?v=` manuali.
  **[P: maleco-officina]** stesse cartelle datate (`css/personal_2026_05_15/`, `images/graphics_2025_11_27`
  esposta come globale `PATH_GRAPHICS`), con AssetMapper installato ma non ancora usato per questi fogli.
  In entrambi i casi sono legacy, da convertire quando si mette mano a quell'area.
- I template caricano la versione **non minificata** dei CSS personalizzati, così restano modificabili.
- Si usano le classi utility del progetto invece degli stili inline: colori (`color_r` rosso, `color_g` / `color_gr` verde,
  `color_w` bianco, `bg_pr` sfondo primario), spaziature (`m_b_30p`, `p_lr_30p`), allineamenti (`txt_a_c`, `f_right`),
  larghezze di colonna (`td_w_200p`).
- Il CSS specifico di una pagina va nel blocco `page_stylesheets` di quella pagina, non nel foglio globale.

**[C]** Testi dell'interfaccia:

- Si dà sempre del **tu** al cliente e all'utente, mai del Lei o del voi.
- Non si usano i trattini lunghi (em dash): in italiano non esistono, si usano due punti, virgole o parentesi.
- Per sottolineare un link si usa il tag `<u>` dentro all'`<a>`, non il CSS (viene sovrascritto dal tema).
- Nei `nav-link` della navbar: o solo testo o solo icona, mai testo in grassetto.

**[C]** Pagina degli aggiornamenti (il changelog che vede l'utente, `updates.html.twig`):

- si scrive in **linguaggio utente**, non tecnico: niente nomi di tabelle, colonne, classi, entity, framework,
  metodi o classi CSS, niente gergo ("null", "DOM", "fix", "merge", "alert nativo").
  Si parla di finestra, tendina, elenco, "risolto un errore", "ora è opzionale";
- si descrive il **comportamento percepito**, non l'implementazione;
- dentro ogni blocco di data, i titoli in `<strong>` stanno in **ordine alfabetico**.

---

## 11. Export Excel e PDF

**[C]** Excel:

- si genera con PhpSpreadsheet (writer `Xlsx`) restituito da una `StreamedResponse`;
- il foglio **rispecchia esattamente la tabella a video**: stesse colonne, stesso ordine, stesse intestazioni,
  stessi allineamenti. Se cambia la lista, cambia anche l'export;
- stili standard, dichiarati una volta in cima al metodo: `$thStyle` (intestazione, grassetto bianco su sfondo
  primario), `$titleStyle`, `$totalRowStyle` (grassetto su grigio chiaro), `$cellBorderStyle`;
- i numeri (pesi, quantità, importi) sono allineati a **destra** e formattati con `NumberFormat` `#,##0.00`;
- riga dei **totali in fondo**, con `$totalRowStyle` e l'etichetta nella colonna che precede il numero;
- nome del file in italiano, descrittivo, con la data: `'Smaltimento Rifiuti ' . date('d-m-Y') . '.xlsx'`;
- l'export rispetta i filtri attivi sulla lista, non esporta tutto.

**[C]** PDF: si generano con **mPDF**, partendo da un template Twig dedicato sotto `templates/<area>/print/`.
La logica di composizione sta in un service (`PdfService`), non nel controller.
Un solo generatore per progetto: dove si trova ancora Dompdf va convertito quando si mette mano ai PDF.

---

## 12. Migration del database

**[C]** Ogni modifica al database è **una sola migration Doctrine** in `migrations/`
(`VersionAAAAMMGGHHMMSS.php`), con SQL esplicito in `up()` e `down()`.
Mai vuota, mai con il solo commento auto generato.
Vale per **tutto**: schema, permessi, impostazioni, testi di aiuto, migrazioni di dati.

**[C]** Niente file SQL paralleli in `sql/` e niente SQL passato a mano all'utente da lanciare:
la doppia scrittura è stata abbandonata perché i due file finivano per divergere.
In produzione le migration si applicano dal browser con il **MigrationController**
della skill migration di `rpg-claude-plugin`, che elenca le migration presenti, segna quelle già applicate
e le esegue una alla volta ("Esegui", "Marca", "Esegui prossima pendente").
Su un progetto nuovo si parte installando quella skill, non riscrivendo il controller.

**[C]** Regole sul file PHP:

- ogni Version deve poter essere eseguita **da sola**: le migration di soli dati, che l'utente può voler saltare,
  stanno in una Version a parte;
- docblock in testa che spiega **perché** serve la modifica, e `getDescription()` con una riga in italiano;
- `down()` annulla esattamente `up()`;
- niente guardia di piattaforma (`abortIf` su `AbstractMySQLPlatform`): il database è MySQL / MariaDB
  per convenzione (paragrafo 1), la guardia non scatta mai e riempie di righe ogni Version;
- SQL **idempotente** dove la piattaforma lo consente (`IF NOT EXISTS` / `IF EXISTS` su MariaDB),
  così una migration rilanciata per sbaglio non lascia il database a metà.
  Per gli insert di permessi, impostazioni e testi:

  ```sql
  INSERT INTO ... SELECT ... WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM ... WHERE slug = '...') x)
  ```
- attenzione agli apostrofi nelle stringhe SQL dei testi italiani: vanno raddoppiati o evitati;
- una migration per modifica logica: meglio due migration piccole che una che tocca cinque tabelle scollegate;
- le migration di soli dati (testi di aiuto, ripuliture di etichette) fanno il lavoro **in PHP dentro `up()`**
  quando la logica va riusata dal codice applicativo, invece di replicarla in SQL con funzioni che cambiano
  fra MySQL e MariaDB (`REGEXP_REPLACE`); se non sono reversibili, `down()` chiama
  `throwIrreversibleMigrationException()`.

Il riferimento per lo stile sono le Version di `www.tillomeditalia.com`.

**[C]** In locale le migration pendenti si eseguono senza chiedere conferma
(`php bin/console doctrine:migrations:migrate --no-interaction`); in produzione le lancia l'utente dal browser.

**[P: maleco-officina]** La cartella `sql/` conserva le nove migration scritte a mano prima del 3 settembre 2026:
restano come storico, sono già applicate, non si convertono in Version e non se ne aggiungono di nuove.

---

## 13. File caricati e immagini

**[C]** Quando in un progetto compare un caricamento di immagini, **non si scrive la struttura da zero**:
si usa la skill `gestione-immagini` di `rpg-claude-plugin`, che genera già cartella privata, service,
controller protetto, funzione Twig e lightbox, più il ritaglio e il ridimensionamento.
Vale sia per il primo upload del progetto sia quando si aggiunge un'immagine a un'entity esistente.

**[C]** Regole che la skill applica, e che restano il riferimento anche sul codice legacy:

- Le cartelle di upload stanno **fuori dalla document root** (parametro `private_path`), non sotto `public/`.
  Sui progetti storici i file stanno ancora sotto `public/`, raggiungibili per link diretto: è un debito da
  sanare, non una variante ammessa.
- I file vengono serviti da un controller dedicato che verifica i permessi (`SecureFileController`),
  mai tramite link diretto.
- Il salvataggio passa da un service dedicato (`MediaService`), che si occupa del nome file,
  del ritaglio, della versione scalata e della miniatura (`thumb_<nomefile>`).
  I PDF vengono salvati così come sono.
- Il percorso su disco non è indovinabile: si genera con un hash salvato sull'entity (`directoryPath`).

---

## 14. Sicurezza e permessi

**[C]** Regole:

- Ruoli applicativi con gerarchia in `security.yaml`, controllo a livello di classe del controller.
- Firewall separati per gli endpoint automatici: `^/script` (cron, manutenzione, migration) e `^/utility`,
  protetti da http basic con credenziali dedicate, così non passano dal login utente.
- Password degli utenti con `bcrypt` e `migrate_from` verso gli algoritmi vecchi quando si migra un progetto storico.
- I dati sensibili salvati a database (credenziali dei clienti, token) passano da `EncryptionService`;
  in Twig si leggono con il filtro `decrypt`.
- Le azioni distruttive passano sempre da un modale di conferma e da una rotta in POST (paragrafo 9).

**[C]** Quando i ruoli non bastano (utenti dello stesso ruolo che devono vedere sezioni diverse), si aggiunge un
livello di **permessi a database**, gestito da un voter, su tre piani:

| Piano | Dove si controlla | Come |
| --- | --- | --- |
| categoria (visibilità della sezione) | classe del controller, voce di menu | `is_granted('view_category', 'waste_disposal')`, `getCanViewPermissionCategory('waste_disposal')` |
| voce (singola pagina o azione) | metodo del controller, card della scrivania | `is_granted('view', 'waste_disposal_list')`, `canViewPermission('waste_disposal_export')` |
| modifica | metodo del controller | `is_granted('edit', 'performance_delete')` |

Sul controller si usa `#[IsGranted(new Expression("..."))]`, combinando ruolo, stato dell'utente e permesso:

```php
#[IsGranted(new Expression("is_granted('ROLE_USER') and is_granted('active', user) and is_granted('view_category', 'waste_disposal')"))]
```

Nel template si nasconde il link con lo stesso permesso che protegge la rotta: mai solo l'uno o solo l'altro.
I permessi nuovi si inseriscono con una migration idempotente (paragrafo 12).

---

## 15. Test e dati di prova

**[C]** Regole:

- I test girano su PHPUnit con la configurazione in `phpunit.xml.dist`.
- **Mai svuotare le tabelle del database di sviluppo**: un test rimuove solo le righe che ha creato lui.
- I test non devono dipendere da dati preesistenti: se servono, li crea il test e poi li cancella.

**[C]** Il database di sviluppo contiene anche dati veri dell'utente, quindi le verifiche manuali
non devono poterli danneggiare. In ordine di importanza:

1. **Snapshot prima di iniziare**, è la salvaguardia che rende l'errore recuperabile:

       mkdir -p var/db-snapshots
       mysqldump -u root --single-transaction --no-tablespaces <database> <tabelle coinvolte> \
         > var/db-snapshots/<data>-<tema>.sql

   `var/` è già in `.gitignore`, quindi gli snapshot non finiscono nei commit.
   Ripristino con `mysql -u root <database> < <file>`.
2. **Creare e rimuovere i dati di prova passando dall'applicazione**, con le sue stesse azioni
   (le rotte di eliminazione), non con SQL: così valgono i servizi, i permessi e le regole sulle relazioni.
3. **Se serve inserire dati con SQL, marcarli**: ogni record di prova porta un marcatore riconoscibile
   (per esempio `notes` che inizia con `CLAUDE-TEST`), e la pulizia filtra **solo** su quel marcatore,
   mai su intervalli di id ricostruiti a memoria.
4. **Non toccare mai i record dell'utente**, nemmeno per rimetterli a posto: se una prova li ha agganciati,
   va segnalato invece di "sistemare" con un `UPDATE`.
5. **Contare prima e dopo** le righe delle tabelle coinvolte: se il conto non torna, dirlo subito.

Il motivo è concreto: l'8 settembre 2026, ripulendo dati di prova con `DELETE` diretti su tabelle condivise,
sono state cancellate righe vere dell'utente, e non è stato possibile risalire a quale istruzione avesse
sbagliato bersaglio. Il problema non è stata una singola query, ma l'aver usato SQL diretto.

---

## 16. Ambiente locale e verifica delle modifiche

**[C]** Il **nome a dominio del progetto punta alla produzione**: non va mai usato per provare le modifiche,
nemmeno per un controllo veloce. Si prova sempre su un indirizzo locale.

**[C]** Se Laragon non ha un vhost per il progetto, si avvia il server PHP dalla radice:

    php -S 127.0.0.1:<porta> -t public public/index.php

Il `DATABASE_URL` in `.env` punta al MySQL locale, quindi i dati sono quelli di sviluppo.
**[P: maleco-officina]** porta 8123, database `maleco_officina_db`.

**[C?]** Con la protezione CSRF **stateless same-origin** (`config/packages/csrf.yaml`) il token nel form è il
segnaposto letterale `csrf-token` e la validazione passa se la richiesta porta un header `Origin` che combacia
con l'host. Quindi da curl:

    curl -X POST http://127.0.0.1:<porta>/<login> \
      -H "Origin: http://127.0.0.1:<porta>" \
      -d "email=..." -d "password=..." -d "_csrf_token=csrf-token"

Lo stesso vale per ogni POST successiva, e i form Symfony vogliono anche `<nomeform>[_token]=csrf-token`.

**[C]** Per ispezionare i PDF generati si usa `pdftotext -table`, **non** `-layout`:
con `-layout` le celle numeriche delle tabelle mPDF risultano su righe sfalsate e sembra un difetto
di impaginazione che non c'è.

**[P: maleco-officina]** Un utente creato a mano per le prove ha bisogno anche della riga di profilo
(`m_o_user_profile`, senza la quale `User::__toString()` va in errore) e di quella delle impostazioni
(`m_o_user_settings`, letta dal tutorial).

---

## 17. Modo di lavorare con l'assistente

**[C]** **Mai fare il commit prima che l'utente abbia provato le modifiche e lo abbia chiesto esplicitamente.**
Finite le modifiche ci si ferma e si aspetta: il commit si crea solo su richiesta ("fai il commit", "committa").
Serve a non portare in cronologia modifiche non verificate.

**[C]** Il lavoro di una funzionalità nuova sta sul suo branch; i bugfix non collegati vanno sul branch principale,
così la funzionalità resta isolata fino a quando non è approvata.

**[C]** Le convenzioni che l'utente detta durante il lavoro vanno annotate, non riscoperte ogni volta:
questo documento è il posto giusto per quelle che valgono per tutti i progetti.

---

## 18. Checklist: aggiungere una nuova entity gestita

**[C]** Ordine di lavoro consigliato:

1. `src/Entity/Xxx.php` con tabella prefissata, colonne esplicite, getter e setter fluent,
   eventuali `getXxxLabel()` e `getCanDelete()`.
2. `src/Repository/XxxRepository.php` con `add`, `remove`, `queryXxxFiltered`.
3. Migration Doctrine in `migrations/`, da applicare in produzione con il MigrationController della skill.
4. `config/validator/Xxx.yaml` con i gruppi `xxx_new` e `xxx_edit`.
5. `src/Form/XxxNewType.php` e `XxxEditType.php`, con i tipi custom.
6. Metodo di filtro in `FilterService`.
7. Controller: `list`, `new`, `edit`, `sheet`, `delete`, con nomi di metodo prefissati dall'area,
   permessi, flash e redirect.
8. Template: `list.html.twig`, `new.html.twig`, `edit.html.twig`, `sheet.html.twig` (a tab se ha relazioni),
   senza placeholder nei filtri, con i modali di conferma in coda.
9. Permessi a database (categoria, voce, modifica) inseriti con la migration, se il progetto li usa.
10. Voce di menu nel layout dell'area **e** card corrispondente nella scrivania, nella stessa posizione.
11. Export Excel della lista, se la lista serve anche fuori dalla piattaforma.
12. Voce nella pagina degli aggiornamenti, in linguaggio utente.
13. Verifica finale: filtri che restano in sessione, paginazione, conferma sull'eliminazione, permessi.

---

## 19. Nota per la versione comune

Quando questo file verrà unito a quelli degli altri progetti:

- le sezioni `[C]` sono il documento condiviso;
- le sezioni `[P: progetto]` restano nel `CLAUDE.md` di ciascun progetto, che rimanda al documento condiviso;
- le sezioni `[C?]` vanno confermate su un terzo progetto.

Progetti confrontati finora: `www.rpgmultimedia.it`, `www.maleco-officina.it`, `www.tillomeditalia.com`.
Da confrontare: `www.boldiniautonoleggio.it`, `www.diademaimmobiliare.it`.

Stato delle skill di `rpg-claude-plugin` rispetto a questo documento:

| Argomento | Paragrafo | Skill |
| --- | --- | --- |
| Migration e MigrationController | 12 | già nel kit |
| Caricamento immagini, ritaglio e ridimensionamento | 13 | già nel kit (`gestione-immagini`) |
| Notifiche push del browser | | già nel kit (`symfony-web-push`) |
| Nuova entity end to end | 18 | da scrivere |
| Scheda a tab (`sheet.html.twig`) | 8 | da scrivere |
| Form più validazione a gruppi | 6 | da scrivere |
| Lista filtrata, `FilterService` e paginazione | 5 e 7 | da scrivere |
| Permessi a database e voter | 14 | da scrivere |
| Export Excel che rispecchia la lista | 11 | da scrivere |

---

## 20. Candidati da confrontare con gli ultimi due progetti

Convenzioni viste su `www.tillomeditalia.com` che non sono ancora nel documento. Restano qui, fuori dai
paragrafi numerati, finché non le si confronta con `www.boldiniautonoleggio.it` e `www.diademaimmobiliare.it`:
se reggono diventano `[C]` dentro al paragrafo di competenza, altrimenti si buttano.

- **Componenti Twig non modali**: `templates/components/twig/` per i pezzi di pagina riutilizzabili
  (tabelle, badge, blocchi di scheda), accanto a `components/js/` e `components/modals/` (paragrafo 8).
- **Oggetti delle email come parametri**: le righe di oggetto stanno in `services.yaml` come `object_<cosa>`,
  non come stringhe sparse nei controller.
- **Prefisso `rpg_` per le classi CSS custom**, cioè quelle che non vengono da Bootstrap o dal tema.
  Non si usa mai il nome del cliente come prefisso: così i copia incolla fra progetti restano sicuri.
- **Blocchi `page_stylesheets` e `page_javascripts`** per CSS e JS della singola pagina (paragrafo 10).
- **Filtri, due dettagli che evitano gli errori ricorrenti** (paragrafo 7): i filtri stanno in sessione sotto
  `<route>_filters` (e la pagina sotto `<route>_page`); i filtri a selezione multipla inviano sempre un
  marcatore `<campo>Set`, così una selezione svuotata azzera il filtro invece di far ripescare dalla sessione
  il valore precedente.
- **Tracciamento d'uso**: un subscriber registra le visite e un ascoltatore delegato i click sui pulsanti,
  con una pagina di riepilogo. Le etichette dei pulsanti vanno ripulite dei conteggi fra parentesi
  ("Allarmi (293)"), altrimenti lo stesso pulsante finisce su una riga diversa a ogni click.
  Candidato a skill di `rpg-claude-plugin`.
- **Modalità manutenzione**: un listener più i parametri `maintenance_mode` e `maintenance_allowed_ips`.
- **Template `.xlsx` di partenza** in `templates/xlsx/` per gli export che devono rispettare un tracciato
  esterno, invece di costruire il foglio da zero.
- **Area "sito pubblico"**: Iubenda (autoblocking in `<head>`, banner prima di `</body>`), GA4 che non carica
  in `app.debug`, `<head>` SEO completo (lang, robots, description, canonical, blocco `meta`),
  `robots.txt` e `sitemap.xml`, pulsanti fissi (WhatsApp, gotoTop, Iubenda) senza sovrapposizioni.
  Oggi sta nei `CLAUDE.md`: se vale anche per boldini e diadema merita un paragrafo suo, marcato come
  valido per i soli siti pubblici e non per i gestionali.
