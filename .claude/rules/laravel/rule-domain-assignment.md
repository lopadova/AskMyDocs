# Regola: Assegnazione al Domain Corretto

Prima di creare un nuovo file chiedersi:

- di quale bounded context fa davvero parte?
- chi e' il proprietario del dato e della regola?
- il codice verra' riusato da piu' moduli?

Se una classe orchestri piu' domini, mettila in un layer applicativo chiaramente dichiarato, non in un dominio arbitrario.
