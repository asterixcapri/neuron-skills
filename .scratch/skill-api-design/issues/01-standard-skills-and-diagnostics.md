# 01: Caricare skill conformi allo standard con diagnostica

**What to build:** Configurando una singola sorgente, lo sviluppatore può far scoprire e caricare all'agent skill con frontmatter YAML conforme ad Agent Skills. Le irregolarità recuperabili producono avvisi; i documenti inutilizzabili vengono esclusi con un motivo consultabile dal Toolkit. Parsing e validazione restano interni e il normale utilizzo non richiede configurazione della diagnostica.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

Riferimento: [spec approvata](../spec.md). Il lavoro riguarda le modifiche ancora da implementare; la separazione iniziale tra Toolkit, Repository interno e Tool è già presente.

- [ ] La scoperta utilizza `skills()` e l'argomento `$skill` nello storage, aggiornando tutti i consumatori e gli adapter di test. Le interfacce conservano il suffisso `Interface`.
- [ ] Un parser interno dedicato separa frontmatter e Markdown, usa `symfony/yaml` e applica le regole Agent Skills. Il Repository coordina scoperta, catalogo e letture senza tornare a essere un'interfaccia pubblica.
- [ ] La dipendenza YAML e le altre dipendenze necessarie mantengono il supporto a PHP 8.1. Le forme YAML previste per i documenti delle skill, incluse stringhe quotate, blocchi multilinea, commenti e mappe, vengono interpretate correttamente.
- [ ] I campi obbligatori e quelli opzionali `license`, `compatibility`, `metadata` e `allowed-tools` vengono interpretati e validati secondo la specifica. I nomi Unicode e i limiti in caratteri non vengono trattati come soli caratteri ASCII o conteggi di byte.
- [ ] Validità e possibilità di caricamento sono distinte: nomi diversi dalla directory o troppo lunghi producono avvisi ma restano caricabili; descrizioni mancanti o vuote, YAML non interpretabile e identità o descrizioni di tipo inutilizzabile producono esclusione e diagnostica. La politica per gli altri campi malformati viene esplicitata e verificata, mantenendo caricabili i documenti utilizzabili.
- [ ] Le raccomandazioni per gli autori, come la lunghezza suggerita del corpo Markdown, non diventano vincoli che escludono skill valide. Eventuali estensioni dei metadati vengono conservate senza attribuire loro comportamenti non concordati.
- [ ] Il Repository conserva separatamente nome dichiarato e identificatore nello storage: un nome diverso dalla directory non impedisce di leggere istruzioni e risorse dalla sorgente corretta.
- [ ] Nella singola sorgente, le collisioni tra nomi dichiarati sono risolte scegliendo il primo candidato utilizzabile in ordine alfabetico degli identificatori. I candidati oscurati sono segnalati e quelli inutilizzabili non riservano il nome.
- [ ] `SkillToolkit::diagnostics()` restituisce gli avvisi raccolti dal Repository come elenco di elementi con `skill` e `message`, oppure un elenco vuoto. Non stampa automaticamente, non invia gli avvisi al modello e non richiede logger, callback o nuove classi pubbliche.
- [ ] Il catalogo iniziale contiene solo nomi e descrizioni. Un catalogo vuoto non aggiunge Tool o istruzioni all'agent. Il contratto già approvato sugli errori attesi e imprevisti continua a funzionare.
- [ ] I test verificano documenti validi, campi opzionali, Unicode, violazioni tollerate, esclusioni e diagnostica, includendo caricamento tramite Toolkit e ciclo Tool di un agent Neuron. Il confronto con la specifica identifica e risolve eventuali lacune del sottoinsieme YAML di Symfony, senza considerare la sola dipendenza una prova di conformità.
- [ ] README e documentazione della politica di validazione descrivono il comportamento consegnato. I controlli Composer, PHPUnit e PHPStan appropriati alla modifica passano.

Fonti: [specifica Agent Skills](https://agentskills.io/specification), [guida per i client](https://agentskills.io/client-implementation/adding-skills-support), [audit di conformità](../research/agent-skills-conformance.md).
