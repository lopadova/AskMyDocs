# Regola: Storage Stream

Per file grandi:

- preferire stream e chunk invece di caricare tutto in memoria
- validare tipo, dimensione e destinazione prima della copia
- evitare concatenazioni di file o export interi in RAM se non necessario
- pulire file temporanei e artifact intermedi
