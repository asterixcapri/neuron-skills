# 01: Valutare il supporto a disable-model-invocation

Status: needs-triage

Riferimento: [proposta](../spec.md).

## Motivazione

Skill come deploy o release possono dichiarare `disable-model-invocation: true`
per richiedere un’attivazione esplicita dell’utente. Oggi il toolkit conserva il
campo ma espone comunque la skill al modello.

## Comportamento proposto

- Leggere il flag durante la scoperta.
- Escludere le skill con valore `true` dal catalogo destinato al modello e
  dall’accesso autonomo tramite i tool; nasconderne solo la descrizione non basta.
- Fornire all’applicazione un percorso per attivarle su richiesta esplicita
  dell’utente. La skill deve restare utilizzabile tramite questo percorso.
- Lasciare invariato il comportamento quando il flag è assente o `false`.
- Documentare il supporto come estensione. Non usarlo per concedere permessi
  di esecuzione: quelli restano responsabilità dell’host.

## Da decidere prima dell’implementazione

- Interfaccia per l’attivazione esplicita e modo in cui l’host ne attesta l’origine;
  un parametro scelto dal modello non deve aggirare il vincolo.
- Trattamento dei valori non booleani e relativa diagnostica.
- Accesso alle risorse prima e dopo l’attivazione esplicita.

## Verifica prevista

Testare catalogo, chiamata diretta ai tool, attivazione esplicita e successivo
uso delle risorse, includendo un ciclo Neuron reale con provider deterministico.
