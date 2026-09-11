# 02: Rivalutare user-invocable quando serve un’integrazione utente

Status: needs-triage

Riferimento: [proposta](../spec.md).

## Priorità proposta

Rimandato: il toolkit non gestisce menu o slash command dell’applicazione.

In Claude Code, `user-invocable: false` nasconde la skill dai comandi utente,
ma non impedisce al modello di invocarla. Non equivale a
`disable-model-invocation: true`.

Rivalutare quando un’applicazione richiede un catalogo per menu o comandi.
In quel momento definire quali informazioni esporre all’host e l’interazione
tra i due flag. Nel frattempo conservare il campo senza attribuirgli effetti
che il toolkit non implementa.
