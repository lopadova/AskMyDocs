/// <reference types="vite/client" />

interface ImportMetaEnv {
  /**
   * Backend base URL override, read at build time. Defaults to the production
   * deployment https://askmydocs.surfacesrl.com. Point it at a local backend
   * (e.g. https://askmydocs.test) for development, or — required on a physical
   * iPhone, where `.test` and localhost are unreachable — at the host machine's
   * LAN address, e.g. VITE_API_BASE=http://192.168.1.50:8000.
   */
  readonly VITE_API_BASE?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
