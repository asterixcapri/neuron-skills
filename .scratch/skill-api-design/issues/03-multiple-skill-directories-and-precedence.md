# 03: Usare più directory con precedenza deterministica

**What to build:** Lo sviluppatore configura più storage nello stesso Toolkit e l'agent vede un unico catalogo. Le sorgenti hanno la precedenza indicata dal loro ordine di configurazione; istruzioni, posizione e risorse provengono sempre dalla sorgente selezionata. Le collisioni sono spiegate nella diagnostica.

**Blocked by:** 01 — Caricare skill conformi allo standard con diagnostica; 02 — Attivare skill complete e rendere accessibili le risorse.

**Status:** ready-for-agent

Riferimento: [spec approvata](../spec.md). La dipendenza da 02 permette di verificare anche che la posizione della skill e l'accesso ai suoi asset rispettino la selezione della sorgente.

- [ ] `SkillToolkit` accetta più istanze di `SkillStorageInterface` nell'ordine di precedenza desiderato, mantenendo valido l'utilizzo con un solo storage. Ogni adapter filesystem continua a gestire una propria directory radice.
- [ ] Il Repository interno costruisce un catalogo unico e i due Tool condividono la stessa selezione delle skill. L'unione non richiede un'altra classe pubblica.
- [ ] Su un nome dichiarato duplicato prevale il primo storage configurato; all'interno di ciascuno storage prevale il primo candidato utilizzabile in ordine alfabetico degli identificatori. Riordinare gli storage cambia la precedenza in modo prevedibile.
- [ ] La diagnostica segnala le skill oscurate con messaggi che permettono di distinguere le sorgenti coinvolte. Non viene effettuata alcuna stampa automatica.
- [ ] Un documento illeggibile o inutilizzabile in una sorgente prioritaria non impedisce di usare una skill valida con lo stesso nome in una sorgente successiva. Una skill caricabile con avvertimenti conserva invece la propria precedenza.
- [ ] Gli identificatori sono associati allo storage di origine. Directory con lo stesso nome in radici diverse, così come nomi dichiarati diversi dalla directory, non causano letture incrociate.
- [ ] Il documento completo, la posizione della skill e tutte le risorse provengono dalla medesima sorgente vincente. Se una risorsa manca in quella sorgente, si restituisce l'errore senza recuperarla silenziosamente da una skill oscurata.
- [ ] Tutti i metadati vengono scoperti per costruire il catalogo, ma le risorse non vengono caricate preventivamente. Le letture successive rispettano il comportamento concordato di lettura su richiesta e il catalogo della sessione.
- [ ] Più sorgenti vuote o prive di skill utilizzabili non producono Tool o istruzioni vuote. La composizione funziona anche con uno storage personalizzato senza posizione accessibile.
- [ ] I test verificano unione di nomi distinti, inversione della precedenza, collisioni entro e tra sorgenti, fallback dopo documenti inutilizzabili, posizione e risorse della sorgente vincente, risorse mancanti e diagnostica. Un ciclo Tool Neuron esercita l'uso completo da più directory.
- [ ] README ed esempio eseguibile mostrano più directory, spiegano che il primo storage prevale e illustrano come anteporre le skill del progetto a quelle dell'utente. Documentano anche come consultare la diagnostica.
- [ ] I controlli Composer, PHPUnit e PHPStan passano sull'insieme delle modifiche, preservando PHP 8.1. La verifica finale della spec distingue i comportamenti implementati dalle capacità lasciate esplicitamente all'agent ospitante.

Fonti: [gestione delle collisioni](https://agentskills.io/client-implementation/adding-skills-support#handling-name-collisions), [audit di conformità](../research/agent-skills-conformance.md).
