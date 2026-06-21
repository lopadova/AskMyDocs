/// <reference types="vite/client" />

interface ImportMetaEnv {
  /**
   * Backend base URL override, read at build time. Defaults to
   * https://askmydocs.test. Required on a physical iPhone, where `.test` and
   * localhost are unreachable — point it at the host machine's LAN address,
   * e.g. VITE_API_BASE=http://192.168.1.50:8000.
   */
  readonly VITE_API_BASE?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
