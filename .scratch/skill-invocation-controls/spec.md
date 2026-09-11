# Controllo dell’invocazione delle skill

Stato: proposta da valutare, registrata su richiesta dell’utente. Implementazione non avviata.

Il toolkit conserva i campi aggiuntivi del frontmatter, ma non applica
`disable-model-invocation` e `user-invocable`: tutte le skill utilizzabili sono
attualmente esposte al modello. Questi campi sono estensioni dei coding agent,
non requisiti dello standard Agent Skills consultato durante la discussione.

## Direzione proposta

- [01 — disable-model-invocation](issues/01-disable-model-invocation.md): valutare
  un supporto limitato ma completo, con esclusione dall’invocazione autonoma e
  un percorso di attivazione esplicita gestito dall’applicazione.
- [02 — user-invocable](issues/02-user-invocable.md): rimandare finché esiste
  un’esigenza concreta di integrazione con menu o comandi utente.

Non estendere automaticamente il lavoro ad altre convenzioni dei coding agent.
Prima dell’implementazione, definire il contratto con l’applicazione ospitante.

## Fonti

- [Agent Skills: frontmatter standard](https://agentskills.io/specification#frontmatter)
- [Claude Code: controllo dell’invocazione](https://code.claude.com/docs/en/skills#control-who-invokes-a-skill)
