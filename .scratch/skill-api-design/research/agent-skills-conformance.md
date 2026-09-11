# Conformità Agent Skills

Audit del 2026-09-11. Nessuna modifica all'implementazione. Il requisito approvato
è supportare integralmente lo standard; le scelte di prodotto ancora aperte non
riducono questo requisito.

Fonti primarie: [specifica](https://agentskills.io/specification),
[guida client](https://agentskills.io/client-implementation/adding-skills-support),
implementazione di riferimento al commit
[`69ef37e9424c0a7ea9dd2293b559e43ec8176379`](https://github.com/agentskills/agentskills/tree/69ef37e9424c0a7ea9dd2293b559e43ec8176379/skills-ref).
La guida client distingue esplicitamente percorsi alternativi: non obbliga un
client a supportare ogni scenario. La conformità del formato e le funzionalità
del runtime vanno verificate separatamente.

## Formato: vincoli e lacune

I vincoli di questa tabella derivano dalla [specifica](https://agentskills.io/specification#frontmatter);
le osservazioni riguardano `src/Internal/SkillRepository.php` e gli Storage.

| Area | Vincolo | Implementazione e intervento |
| --- | --- | --- |
| Documento | `SKILL.md`, YAML iniziale e corpo Markdown | Nome file corretto. `parseManifest()` estrae righe, senza interpretare YAML: introdurre parsing reale e validazione della mappa. |
| Nome | 1–64 caratteri, alfanumerici Unicode minuscoli e trattini; niente trattini iniziali, finali o consecutivi; corrispondenza directory | Regex ASCII: rifiuta nomi Unicode. `strlen` misura byte. Risolvere Unicode e conteggio caratteri. |
| Descrizione | Stringa non vuota, massimo 1024 caratteri | Il limite Unicode esiste. Quote/commenti non interpretati; `description: |` produce letteralmente `|`, ignorando il blocco. Verificare tipi e stringhe composte da spazi. |
| `compatibility` | Opzionale, stringa di 1–500 caratteri | Ignorato, anche se invalido. Validare e conservare. |
| `metadata` | Opzionale, mappa stringa → stringa | Ignorato. Validare e conservare; niente conversioni implicite non deliberate. |
| `license` | Opzionale, nome o riferimento alla licenza | Ignorato. Conservare il valore; non richiede interpretazione legale. |
| `allowed-tools` | Opzionale, stringa con strumenti separati da spazi; sperimentale | Ignorato. Riconoscere/conservare il campo; il runtime deve definire l'eventuale effetto sulle autorizzazioni. |
| Corpo | Markdown senza restrizioni di formato | Viene restituito dopo `trim`; comportamento compatibile con la guida client. |
| Risorse | File/cartelle aggiuntivi consentiti; riferimenti relativi alla radice | Percorsi relativi presenti. Lo storage rifiuta binari: limite di fruizione, non motivo per dichiarare invalida la skill. |

Non trasformare consigli per gli autori (500 righe, circa 5000 token, riferimenti
poco annidati) in vincoli di validazione.

## Ambiguità: specifica e codice di riferimento

Il [validatore ufficiale](https://github.com/agentskills/agentskills/blob/69ef37e9424c0a7ea9dd2293b559e43ec8176379/skills-ref/src/skills_ref/validator.py)
usa normalizzazione NFKC, confronto lowercase e `isalnum()` Unicode: chiarisce
che il testo sui nomi non va ridotto al solo ASCII. Non copiarlo come oracolo
assoluto: accetta `compatibility` vuota e non verifica tutti i campi opzionali.
Rifiuta inoltre campi sconosciuti, mentre la specifica non esplicita una regola
generale sulle estensioni.

Il [parser ufficiale](https://github.com/agentskills/agentskills/blob/69ef37e9424c0a7ea9dd2293b559e43ec8176379/skills-ref/src/skills_ref/parser.py)
usa StrictYAML e accetta anche `skill.md`; la guida prescrive la ricerca di
`SKILL.md` esatto. Queste tolleranze non sono nuovi obblighi del formato. Le
divergenze richiedono fixture esplicite, con fonte della regola scelta.

## Comportamento del client

Confronto con la [guida client](https://agentskills.io/client-implementation/adding-skills-support).

| Area | Stato e azione proposta |
| --- | --- |
| Progressive disclosure | Catalogo iniziale e caricamento delle istruzioni su richiesta presenti. La frase `Always load every referenced file` nelle guidelines forza letture indiscriminate: sostituire con caricamento quando necessario. |
| Catalogo vuoto e enum | Già gestiti: niente tool senza skill; nomi vincolati nello schema. |
| Campi opzionali all'attivazione | La guida ammette corpo solo oppure file intero. Oggi sono persi: conservarli internamente; decidere quali informazioni servono al modello, soprattutto `compatibility`. |
| Diagnostica | Documenti invalidi e letture fallite saltati silenziosamente. Aggiungere un canale diagnostico configurabile; distinguere validazione stretta dalla tolleranza consigliata dalla guida. |
| Risorse e percorsi | Il Tool fornisce testo con nome e percorso relativo. Manca un meccanismo per materializzare risorse remote/binari o fornire una directory utilizzabile da altri strumenti. Un elenco risorse è un miglioramento facoltativo. |
| Script | Leggibili come testo; la guideline suggerisce di eseguire il contenuto con un altro Tool. Questo non preserva file vicini, working directory e asset. Il supporto reale dipende dall'ambiente di esecuzione scelto. |
| Scoperta | Una radice configurata; niente merge progetto/utente o precedenze. La specifica non impone percorsi di installazione. Multi-root e deduplicazione sono capacità di prodotto ulteriori. |
| Fiducia e permessi | La scelta esplicita dello storage non equivale a un sistema di fiducia del progetto. Se necessario, integrarsi con le autorizzazioni dell'host; `allowed-tools` non dovrebbe creare strumenti o ampliare privilegi da solo. |
| Contesto | Nessuna protezione esplicita dalla compattazione. `TrackByInputs` non dimostra che istruzioni eliminate possano essere ricaricate: verificare il runtime Neuron prima di dichiarare supportato il ciclo completo. |
| Attivazione esplicita | Il prompt “Use caveman skill…” delega ancora la chiamata al modello. Slash command, autocomplete, filtri e delega a subagent sono integrazioni dell'host, non campi obbligatori del formato. |

## Prossime decisioni minime

Non chiedere nuovamente se supportare YAML completo e campi standard: è già
approvato. Il confine architetturale da discutere è se questo package debba
anche rendere utilizzabili script e asset mediante strumenti dell'host, con un
contratto per accesso/materializzazione, oppure includere un esecutore proprio.
Consiglio integrazione con strumenti dell'host, coerente con Storage pubblici e
Repository interno; non promettere esecuzione universale.

Separatamente: preferire validazione stretta con diagnostica oppure caricamento
tollerante delle skill non conformi? Supportare tutte le skill valide non obbliga
a rifiutare tutte quelle invalide. La guida propone tolleranza, lo standard
definisce invece i vincoli: registrare la scelta, senza confondere i due piani.

Parsing e validazione del documento giustificano un modulo interno dedicato.
Il Repository può continuare a coordinare scoperta e accesso. La rinomina
`packages()` → `skills()` resta una decisione terminologica, non un requisito
prescritto dal formato.
