# Regola: Chain PageSpeed dopo test frontend

Quando un run Playwright riguarda visual regression, perf budget o release gate:

- se il run passa, proporre una review prestazionale
- se il run fallisce per metriche prestazionali, aprire direttamente una review PageSpeed/Core Web Vitals
