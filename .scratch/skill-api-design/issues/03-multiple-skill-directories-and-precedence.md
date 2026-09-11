# 03: Usare più directory con precedenza deterministica

**What to build:** Lo sviluppatore configura più storage nello stesso Toolkit e l'agent vede un unico catalogo. Le sorgenti hanno la precedenza indicata dal loro ordine di configurazione; istruzioni, posizione e risorse provengono sempre dalla sorgente selezionata. Le collisioni sono spiegate nella diagnostica.

**Blocked by:** 01 — Caricare skill conformi allo standard con diagnostica; 02 — Attivare skill complete e rendere accessibili le risorse.

**Status:** resolved

Riferimento: [spec approvata](../spec.md). La dipendenza da 02 permette di verificare anche che la posizione della skill e l'accesso ai suoi asset rispettino la selezione della sorgente.

- [x] `SkillToolkit` accetta più istanze di `SkillStorageInterface` nell'ordine di precedenza desiderato, mantenendo valido l'utilizzo con un solo storage. Ogni adapter filesystem continua a gestire una propria directory radice.
- [x] Il Repository interno costruisce un catalogo unico e i due Tool condividono la stessa selezione delle skill. L'unione non richiede un'altra classe pubblica.
- [x] Su un nome dichiarato duplicato prevale il primo storage configurato; all'interno di ciascuno storage prevale il primo candidato utilizzabile in ordine alfabetico degli identificatori. Riordinare gli storage cambia la precedenza in modo prevedibile.
- [x] La diagnostica segnala le skill oscurate con messaggi che permettono di distinguere le sorgenti coinvolte. Non viene effettuata alcuna stampa automatica.
- [x] Un documento illeggibile o inutilizzabile in una sorgente prioritaria non impedisce di usare una skill valida con lo stesso nome in una sorgente successiva. Una skill caricabile con avvertimenti conserva invece la propria precedenza.
- [x] Gli identificatori sono associati allo storage di origine. Directory con lo stesso nome in radici diverse, così come nomi dichiarati diversi dalla directory, non causano letture incrociate.
- [x] Il documento completo, la posizione della skill e tutte le risorse provengono dalla medesima sorgente vincente. Se una risorsa manca in quella sorgente, si restituisce l'errore senza recuperarla silenziosamente da una skill oscurata.
- [x] Tutti i metadati vengono scoperti per costruire il catalogo, ma le risorse non vengono caricate preventivamente. Le letture successive rispettano il comportamento concordato di lettura su richiesta e il catalogo della sessione.
- [x] Più sorgenti vuote o prive di skill utilizzabili non producono Tool o istruzioni vuote. La composizione funziona anche con uno storage personalizzato senza posizione accessibile.
- [x] I test verificano unione di nomi distinti, inversione della precedenza, collisioni entro e tra sorgenti, fallback dopo documenti inutilizzabili, posizione e risorse della sorgente vincente, risorse mancanti e diagnostica. Un ciclo Tool Neuron esercita l'uso completo da più directory.
- [x] README ed esempio eseguibile mostrano più directory, spiegano che il primo storage prevale e illustrano come anteporre le skill del progetto a quelle dell'utente. Documentano anche come consultare la diagnostica.
- [x] I controlli Composer, PHPUnit e PHPStan passano sull'insieme delle modifiche, preservando PHP 8.1. La verifica finale della spec distingue i comportamenti implementati dalle capacità lasciate esplicitamente all'agent ospitante.

Fonti: [gestione delle collisioni](https://agentskills.io/client-implementation/adding-skills-support#handling-name-collisions), [audit di conformità](../research/agent-skills-conformance.md).


## Answer

Implemented variadic toolkit/repository storage configuration. Each selected name
retains its storage instance, string identifier and source ordinal. Source order
and alphabetical candidates determine precedence; shadowing diagnostics identify
both sources. Lazy instruction, location and resource reads stay with the winner,
including when a shadowed source has a resource absent from the winner.

`tests/MultipleSkillStoragesTest.php` covers cross-root identifiers, reversal,
invalid/unreadable fallback, warned winners, alphabetical collisions, empty sources,
null locations, lazy reads/session catalog and a real Neuron tool loop using both
roots. README and the runnable example show project-before-user configuration and
explicit diagnostic inspection.

Validation: `composer validate --strict`, `composer check` (95 tests, 271 assertions;
PHPStan clean), `php examples/basic.php`, and `git diff --check` pass. Composer
resolves dependencies for PHP 8.1 through its platform setting; local execution
uses PHP 8.5.8. No syntax newer than PHP 8.1 was introduced.

The library implements discovery, validation diagnostics, complete-document
activation, source precedence and location/text resource access. Host script
execution, binary access, remote provisioning and authorization remain explicitly
with the agent integration; the example supplies its own permitted script tool.
