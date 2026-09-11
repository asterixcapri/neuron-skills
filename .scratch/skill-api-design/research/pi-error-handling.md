# Pi: gestione degli errori delle skill

Ricerca del 2026-09-11 sul codice ufficiale di Pi, commit `d12cd92e45e308d4af000554292165ef1984253b`. L'indirizzo storico `badlogic/pi-mono` reindirizza a `earendil-works/pi`; tutti i riferimenti al codice qui sotto sono fissati al commit ispezionato. Scopo: chiarire Q2, cioè quale livello distingue gli errori dai contenuti e li comunica al modello.

## Risultati verificati

### Scoperta e validazione

Il caricatore restituisce skill e diagnostica separatamente. Un errore di lettura del documento produce una diagnostica `warning` e nessuna skill; un errore di parsing produce lo stesso esito per un documento dichiarato `SKILL.md`. Altri file Markdown non validi possono essere ignorati senza diagnostica. [skills.ts: lettura e parsing](https://github.com/earendil-works/pi/blob/d12cd92e45e308d4af000554292165ef1984253b/packages/coding-agent/src/core/skills.ts#L278-L307).

Gli errori di validazione del nome e della descrizione diventano warning. Pi continua a caricare la skill con questi warning, salvo descrizione mancante o vuota: in quel caso esclude la skill. Non è quindi una politica universale di eccezioni o di rifiuto di ogni violazione. [skills.ts: validazione](https://github.com/earendil-works/pi/blob/d12cd92e45e308d4af000554292165ef1984253b/packages/coding-agent/src/core/skills.ts#L313-L344).

Le diagnostiche restano accessibili separatamente attraverso `resourceLoader.getSkills()`. L'interfaccia interattiva le formatta e mostra quando `showDiagnostics` è abilitato. Non vengono inserite come contenuto delle istruzioni della skill. [resource-loader.ts](https://github.com/earendil-works/pi/blob/d12cd92e45e308d4af000554292165ef1984253b/packages/coding-agent/src/core/resource-loader.ts#L308-L310); [visualizzazione diagnostiche](https://github.com/earendil-works/pi/blob/d12cd92e45e308d4af000554292165ef1984253b/packages/coding-agent/src/modes/interactive/interactive-mode.ts#L1802-L1809).

### Lettura richiesta dal modello

Il catalogo nel prompt contiene nome, descrizione e percorso; invita il modello a usare il tool generico `read` per caricare il documento, oppure `bash` quando configurato come strumento di lettura. Il percorso ordinario esaminato qui è quello con `read`, non un ipotetico tool specializzato per le skill. [formatSkillsForPrompt](https://github.com/earendil-works/pi/blob/d12cd92e45e308d4af000554292165ef1984253b/packages/coding-agent/src/core/skills.ts#L355-L380).

Il tool `read` verifica l'accessibilità del file e, se l'operazione fallisce, rigetta la Promise con l'errore. Non converte quel fallimento in una normale stringa di successo. [read: accesso](https://github.com/earendil-works/pi/blob/d12cd92e45e308d4af000554292165ef1984253b/packages/coding-agent/src/core/tools/read.ts#L98-L104); [read: propagazione errore](https://github.com/earendil-works/pi/blob/d12cd92e45e308d4af000554292165ef1984253b/packages/coding-agent/src/core/tools/read.ts#L180-L185).

È **il ciclo di esecuzione dei tool nell'agent runtime** a intercettare l'errore, estrarne il messaggio testuale e impostare `isError: true`. Il risultato diventa un messaggio `toolResult` con contenuto testuale e flag d'errore; viene aggiunto al contesto della conversazione. La conversione in testo avviene dunque sopra il tool, senza perdere la distinzione strutturata tra esito positivo ed errore nel messaggio interno. [catch dell’esecuzione](https://github.com/earendil-works/pi/blob/d12cd92e45e308d4af000554292165ef1984253b/packages/agent/src/agent-loop.ts#L677-L718); [costruzione risultato](https://github.com/earendil-works/pi/blob/d12cd92e45e308d4af000554292165ef1984253b/packages/agent/src/agent-loop.ts#L767-L797); [aggiunta al contesto](https://github.com/earendil-works/pi/blob/d12cd92e45e308d4af000554292165ef1984253b/packages/agent/src/agent-loop.ts#L230-L240).

### Comando esplicito `/skill:nome`

Questo segue un percorso diverso. La sessione cerca la skill e legge direttamente il file per espandere il messaggio utente. Una skill sconosciuta lascia invariato il testo. Se la lettura fallisce, emette un errore `skill_expansion` tramite l'extension runner e restituisce il testo originale: non crea un `toolResult` con `isError`. [_expandSkillCommand](https://github.com/earendil-works/pi/blob/d12cd92e45e308d4af000554292165ef1984253b/packages/coding-agent/src/core/agent-session.ts#L1357-L1385).

## Interpretazione per neuron-skills

Queste sono indicazioni progettuali ricavate dal confronto, non prescrizioni dello standard né decisioni già approvate:

- Separare la diagnostica di scoperta dalle istruzioni caricate è coerente con Pi. Il caricatore può restituire un risultato strutturato senza dover lanciare per ogni skill invalida.
- Per una lettura richiesta dal modello, mantenere distinguibile il fallimento fino al confine che costruisce il risultato del tool evita di confondere errore e contenuto.
- Pi non dimostra che la conversione debba stare nel Repository o nel singolo Tool: la colloca nel runtime comune. Per Neuron AI bisogna verificare il comportamento effettivo del suo runtime prima di scegliere tra eccezioni lasciate propagare e conversione esplicita nei nostri Tool. Copiare soltanto il `throw`/`reject` di Pi non riproduce necessariamente la stessa esperienza per il modello.
- Questi riscontri non determinano il nome della classe interna né richiedono da soli nuove classi pubbliche.

Nessuna modifica all'implementazione effettuata in questa ricerca.
