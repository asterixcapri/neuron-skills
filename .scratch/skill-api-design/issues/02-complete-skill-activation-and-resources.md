# 02: Attivare skill complete e rendere accessibili le risorse

**What to build:** Quando l'agent attiva una skill, riceve il documento completo e la sua posizione, così può seguire le istruzioni e usare riferimenti, script e asset con i propri strumenti. Le risorse vengono caricate quando servono; la libreria non include un esecutore e non concede autorizzazioni implicite.

**Blocked by:** 01 — Caricare skill conformi allo standard con diagnostica.

**Status:** ready-for-agent

Riferimento: [spec approvata](../spec.md). Questo ticket usa il caricamento e la validazione consegnati da 01 e completa il comportamento di attivazione su una singola sorgente.

- [ ] Il Tool `skill` restituisce il documento `SKILL.md` completo, preservando frontmatter originale, Markdown e campi opzionali o di estensione. La posizione viene aggiunta senza alterare il contenuto del documento.
- [ ] Il catalogo iniziale rimane limitato a nome e descrizione: contenuto completo e informazioni aggiuntive arrivano solo all'attivazione.
- [ ] `SkillStorageInterface` espone `location(string $skill): ?string`, come posizione di base della skill utilizzabile dagli strumenti dell'agent. L'adapter filesystem restituisce la directory canonica della skill e mantiene il controllo degli accessi ai file della skill.
- [ ] La posizione viene risolta usando l'identificatore della sorgente, anche quando il nome dichiarato nel frontmatter differisce dalla directory.
- [ ] Uno storage senza posizione accessibile può restituire `null`: le letture tramite i Tool delle skill continuano a funzionare, senza inventare un percorso né dichiarare accessibilità o eseguibilità tramite altri strumenti.
- [ ] Le posizioni non locali fornite da adapter personalizzati non vengono interpretate automaticamente come percorsi filesystem. Provisioning remoto e accesso tramite gli strumenti dell'agent restano responsabilità dell'integrazione ospitante, senza download impliciti.
- [ ] Le istruzioni del Toolkit spiegano come risolvere i riferimenti rispetto alla posizione della skill e richiedono di leggere solo le risorse necessarie. Viene rimossa l'indicazione di caricare indiscriminatamente ogni file referenziato.
- [ ] L'esecuzione di uno script può usare il file nella directory della skill e i suoi asset vicini attraverso un Tool dell'agent: non viene proposta come equivalente universale l'esecuzione del solo testo dello script. La libreria non esegue codice.
- [ ] Gli asset binari restano accessibili agli strumenti dell'agent tramite la posizione quando l'integrazione lo consente; non vengono decodificati come testo né caricati preventivamente nel contesto.
- [ ] `allowed-tools` e gli altri metadati vengono preservati e resi visibili con il documento, senza abilitare Tool o modificare permessi. La gestione delle autorizzazioni resta all'agent.
- [ ] File mancanti, risorse fuori dalla skill e altri fallimenti attesi restano messaggi leggibili dal modello; gli errori imprevisti propagano. Le istruzioni non valide al momento della lettura continuano a rispettare la politica del parser.
- [ ] I test coprono documento completo, posizione filesystem, posizione non locale, posizione assente, riferimenti relativi, assenza di letture anticipate e mancata esecuzione di script. Un ciclo Tool Neuron verifica ciò che riceve il modello; uno scenario con script e asset vicini verifica che la posizione fornita sia utilizzabile dagli strumenti ospitanti.
- [ ] README ed esempio eseguibile mostrano l'attivazione completa e l'uso delle risorse, spiegando le responsabilità dell'agent. I controlli appropriati alla modifica passano.

Fonti: [specifica Agent Skills](https://agentskills.io/specification), [attivazione delle skill](https://agentskills.io/client-implementation/adding-skills-support#step-4-activate-skills), [ricerca sulla gestione degli errori](../research/pi-error-handling.md).
