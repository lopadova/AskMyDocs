/**
 * Deterministic fixture catalogue for local connector development.
 *
 * This file is deliberately independent from Laravel, databases, Gmail and any
 * third-party service. The future local-environment Artisan command may use the
 * identifiers below to seed documents, IMAP messages and connector settings, but
 * neither fixture server reads application state at runtime.
 */

export const DATASET_REVISION = 'case-study-connectors-v1';

const COMPANIES = [
  {
    key: 'rotta-logistics',
    name: 'Rotta Sicura Logistics',
    tenantId: 'rotta-logistics',
    projectKey: 'rotta-logistics',
    users: [
      { email: 'rotta@case-study.local', role: 'viewer' },
      { email: 'rotta.admin@case-study.local', role: 'admin' },
      { email: 'rotta.super@case-study.local', role: 'super-admin' },
    ],
    mailboxes: ['rotta-logistics-1', 'rotta-logistics-2'],
    documents: [
      { id: 'DDT-2024-1182', title: 'DDT 2024/1182 — Hub Torino → Roma' },
      { id: 'SLA-W03-2024', title: 'Riepilogo SLA settimana 03/2024' },
    ],
    records: [
      {
        id: 'shipment-RL-2024-0815',
        kind: 'shipment',
        title: 'Spedizione Milano → Roma',
        reference: 'RL-2024-0815',
        status: 'delivered',
        updatedAt: '2024-01-10T12:48:00Z',
        summary: 'Tre colli consegnati entro il servizio Consegna Lampo 24h.',
        related: {
          emailReferences: ['RL-TRACK-8842'],
          documentIds: ['DDT-2024-1182'],
        },
        fields: {
          trackingCode: 'RL-TRACK-8842',
          carrier: 'VeloxCorriere',
          originHub: 'HUB-MI-07',
          destinationHub: 'HUB-RM-05',
          parcels: 3,
          totalWeightKg: 41.5,
        },
      },
      {
        id: 'shipment-RL-2024-9015',
        kind: 'shipment',
        title: 'Spedizione internazionale trattenuta in dogana',
        reference: 'RL-2024-9015',
        status: 'on_hold',
        updatedAt: '2024-01-17T09:20:00Z',
        summary: 'Manca il codice HS nella fattura proforma di due articoli.',
        related: {
          emailReferences: ['RL-TRACK-9015'],
          documentIds: ['DDT-2024-1182'],
        },
        fields: {
          trackingCode: 'RL-TRACK-9015',
          location: 'Dogana di Malpensa',
          requiredAction: 'Integrare la fattura proforma entro 48 ore',
        },
      },
    ],
  },
  {
    key: 'prometeo-antincendio',
    name: 'Prometeo Sicurezza Antincendio',
    tenantId: 'prometeo-antincendio',
    projectKey: 'prometeo-antincendio',
    users: [
      { email: 'prometeo@case-study.local', role: 'viewer' },
      { email: 'prometeo.admin@case-study.local', role: 'admin' },
      { email: 'prometeo.super@case-study.local', role: 'super-admin' },
    ],
    mailboxes: ['prometeo-antincendio-1', 'prometeo-antincendio-2'],
    documents: [
      { id: 'CPI-ACME-2025', title: 'Certificato prevenzione incendi — Acme S.r.l.' },
      { id: 'VERBALE-ESTINTORI-2024-10', title: 'Verbale verifica quinquennale estintori' },
    ],
    records: [
      {
        id: 'inspection-CPI-ACME-2025',
        kind: 'fire-safety-inspection',
        title: 'Rinnovo CPI Acme S.r.l.',
        reference: 'CPI-ACME-2025',
        status: 'scheduled',
        updatedAt: '2024-10-14T08:30:00Z',
        summary: 'Sopralluogo e raccolta documentale programmati prima della scadenza.',
        related: {
          emailReferences: ['CPI-ACME-2025'],
          documentIds: ['CPI-ACME-2025'],
        },
        fields: {
          customer: 'Acme S.r.l.',
          nextInspectionDate: '2025-01-15',
          facility: 'Via delle Industrie 18, Milano',
        },
      },
      {
        id: 'service-ORD-2024-3471',
        kind: 'supply-order',
        title: 'Fornitura agente estinguente e ricambi sprinkler',
        reference: 'ORD-2024-3471',
        status: 'confirmed',
        updatedAt: '2024-11-08T11:00:00Z',
        summary: 'Fornitura in consegna al magazzino entro il 20 novembre.',
        related: {
          emailReferences: ['ORD-2024-3471'],
          documentIds: ['VERBALE-ESTINTORI-2024-10'],
        },
        fields: {
          supplier: 'Sicura Forniture S.p.A.',
          expectedDeliveryDate: '2024-11-20',
          items: ['250 kg polvere estinguente', '60 manometri', '24 ugelli sprinkler'],
        },
      },
    ],
  },
  {
    key: 'passolibero-calzature',
    name: 'PassoLibero Calzature',
    tenantId: 'passolibero-calzature',
    projectKey: 'passolibero-calzature',
    users: [
      { email: 'passolibero@case-study.local', role: 'viewer' },
      { email: 'passolibero.admin@case-study.local', role: 'admin' },
      { email: 'passolibero.super@case-study.local', role: 'super-admin' },
    ],
    mailboxes: ['passolibero-calzature-1', 'passolibero-calzature-2'],
    documents: [
      { id: 'PO-FRN-2024-241', title: 'Ordine fornitore FRN-2024-241 — ConceriaToscana' },
      { id: 'QC-BE-2024-0067', title: 'Esito controllo qualità bolla BE-2024-0067' },
    ],
    records: [
      {
        id: 'purchase-order-FRN-2024-241',
        kind: 'purchase-order',
        title: 'Riassortimento modello Brezza',
        reference: 'FRN-2024-241',
        status: 'received',
        updatedAt: '2024-02-09T14:10:00Z',
        summary: 'Pellame ricevuto, controllato e liberato per la produzione.',
        related: {
          emailReferences: ['FRN-2024-241', 'BE-2024-0067'],
          documentIds: ['PO-FRN-2024-241', 'QC-BE-2024-0067'],
        },
        fields: {
          supplier: 'ConceriaToscana',
          product: 'Pellame conciato al vegetale per 40 paia',
          productLine: 'ClubPasso Brezza',
          receivedQuantity: 40,
        },
      },
      {
        id: 'return-RMA-7801',
        kind: 'return',
        title: 'Sostituzione ClubPasso Aero taglia 44',
        reference: 'RMA-7801',
        status: 'replacement_authorized',
        updatedAt: '2024-02-12T10:15:00Z',
        summary: 'Difetto di produzione confermato; sostituzione in consegna.',
        related: {
          emailReferences: ['RMA-7801', 'GLS-PL-99845'],
          documentIds: ['QC-BE-2024-0067'],
        },
        fields: {
          product: 'ClubPasso Aero',
          size: 44,
          trackingCode: 'GLS-PL-99845',
        },
      },
    ],
  },
];

export const COMPANY_KEYS = Object.freeze(COMPANIES.map((company) => company.key));

function clone(value) {
  return structuredClone(value);
}

function summary(company) {
  return {
    key: company.key,
    name: company.name,
    tenantId: company.tenantId,
    projectKey: company.projectKey,
  };
}

export function listCompanies() {
  return clone(COMPANIES.map(summary));
}

export function findCompany(companyKey) {
  return COMPANIES.find((company) => company.key === companyKey) ?? null;
}

export function companyContext(companyKey) {
  const company = findCompany(companyKey);

  if (company === null) {
    return null;
  }

  return clone({
    company: summary(company),
    identities: {
      users: company.users,
      mailboxes: company.mailboxes,
      documents: company.documents,
    },
  });
}

export function recordsForCompany(companyKey, filters = {}) {
  const company = findCompany(companyKey);

  if (company === null) {
    return null;
  }

  const records = company.records.filter((record) => {
    if (filters.kind && record.kind !== filters.kind) {
      return false;
    }

    return !filters.status || record.status === filters.status;
  });

  return clone({ company: summary(company), records });
}

export function findRecord(companyKey, recordId) {
  const company = findCompany(companyKey);

  if (company === null) {
    return null;
  }

  const record = company.records.find((candidate) => candidate.id === recordId);

  return record ? clone({ company: summary(company), record }) : null;
}

export function searchRecords(companyKey, query) {
  const response = recordsForCompany(companyKey);

  if (response === null) {
    return null;
  }

  const normalizedQuery = query.trim().toLocaleLowerCase('it-IT');

  if (normalizedQuery === '') {
    return response;
  }

  return {
    ...response,
    records: response.records.filter((record) =>
      JSON.stringify(record).toLocaleLowerCase('it-IT').includes(normalizedQuery),
    ),
  };
}
