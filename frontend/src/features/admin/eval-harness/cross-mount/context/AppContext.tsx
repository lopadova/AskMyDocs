/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * context/AppContext.tsx`. Verbatim copy modulo relative-path imports.
 */
import { createContext, type ReactNode, useContext } from 'react';
import { createApiClient, type EvalHarnessApiClient } from '../services/evalHarnessApi';
import type { AppBootstrapConfig } from '../utils/bootstrap';

type AppCtx = {
  apiBase: string;
  config: AppBootstrapConfig;
  client: EvalHarnessApiClient;
  createClient: () => EvalHarnessApiClient;
};

const EvalUiContext = createContext<AppCtx | null>(null);

export const AppContextProvider = ({
  apiBase,
  config,
  children,
}: {
  apiBase: string;
  config: AppBootstrapConfig;
  children: ReactNode;
}) => {
  const client = createApiClient(apiBase, config.tenant_header);
  const createClient = () => client;

  return (
    <EvalUiContext.Provider value={{ apiBase, config, client, createClient }}>
      {children}
    </EvalUiContext.Provider>
  );
};

export const useAppContext = () => {
  const ctx = useContext(EvalUiContext);
  if (!ctx) {
    throw new Error('EvalHarness app context not found');
  }
  return ctx;
};
