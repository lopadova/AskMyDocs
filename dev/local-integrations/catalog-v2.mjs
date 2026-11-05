/** Curated, versioned links to the checked-in gold email fixtures. */
export const DATASET_REVISION = 'case-study-linked-v2';

const email = (mailboxKey, subject, reference) => ({ mailboxKey, subject, reference });

const CATALOG = {
  'rotta-logistics': {
    customers: [
      { id: 'RO-CARUSO', name: 'Laura Caruso', role: 'Coordinamento Filiali', orderIds: [], shipmentIds: ['SPD-51230'], emailEvidence: [email('rotta-logistics-2', 'Reclamo consegna a indirizzo errato', 'Laura Caruso')] },
      { id: 'RO-LONGO', name: 'Veronica Longo', role: 'cliente business', orderIds: ['PO-5582'], shipmentIds: ['RL-2024-1120'], emailEvidence: [email('rotta-logistics-1', 'Conferma spedizione ordine urgente di oggi', 'Veronica Longo')] },
      { id: 'RO-ROMANO', name: 'Francesca Romano', role: 'cliente business', orderIds: ['88512'], shipmentIds: [], emailEvidence: [email('rotta-logistics-2', 'Reclamo ritardo consegna - ordine 88512', 'Francesca Romano')] },
    ],
    products: [
      { id: 'RO-LAMPO-24H', name: 'Consegna Lampo 24h', kind: 'shipping-service', orderIds: ['ORD-4471', 'PO-5582', '88512'], emailEvidence: [email('rotta-logistics-1', 'Conferma spedizione multipla ordine #ORD-4471', 'Consegna Lampo 24h')] },
    ],
    orders: [
      { id: 'ORD-4471', observedAt: '2024-02-02', status: 'shipped', customerId: null, productIds: ['RO-LAMPO-24H'], shipmentIds: ['RL-2024-1280', 'RL-2024-1281'], emailEvidence: [email('rotta-logistics-1', 'Conferma spedizione multipla ordine #ORD-4471', 'ORD-4471')] },
      { id: 'PO-5582', observedAt: '2024-08-01', status: 'accepted_for_dispatch', customerId: 'RO-LONGO', productIds: ['RO-LAMPO-24H'], shipmentIds: ['RL-2024-1120'], emailEvidence: [email('rotta-logistics-1', 'Re: Conferma spedizione ordine urgente di oggi', 'PO-5582')] },
      { id: '88512', observedAt: '2024-09-17', status: 'delivery_delay_reported', customerId: 'RO-ROMANO', productIds: ['RO-LAMPO-24H'], shipmentIds: [], emailEvidence: [email('rotta-logistics-2', 'Reclamo ritardo consegna - ordine 88512', '88512')] },
    ],
    shipments: [
      { id: 'SPD-51230', status: 'misdelivered_reported', orderId: null, customerId: 'RO-CARUSO', productIds: [], origin: 'Bergamo', intendedDestination: 'Catania', reportedDestination: 'Messina', emailEvidence: [email('rotta-logistics-2', 'Reclamo consegna a indirizzo errato', 'SPD-51230')] },
      { id: 'RL-2024-1280', trackingCode: 'RL-TRACK-8810', status: 'shipped', orderId: 'ORD-4471', customerId: null, productIds: ['RO-LAMPO-24H'], emailEvidence: [email('rotta-logistics-1', 'Conferma spedizione multipla ordine #ORD-4471', 'RL-2024-1280')] },
      { id: 'RL-2024-1281', trackingCode: 'RL-TRACK-8811', status: 'shipped', orderId: 'ORD-4471', customerId: null, productIds: ['RO-LAMPO-24H'], emailEvidence: [email('rotta-logistics-1', 'Conferma spedizione multipla ordine #ORD-4471', 'RL-2024-1281')] },
      { id: 'RL-2024-1120', trackingCode: 'RL-TRACK-9355', status: 'scheduled_for_dispatch', orderId: 'PO-5582', customerId: 'RO-LONGO', productIds: ['RO-LAMPO-24H'], emailEvidence: [email('rotta-logistics-1', 'Re: Conferma spedizione ordine urgente di oggi', 'RL-2024-1120')] },
    ],
    claims: [
      { id: 'RO-SPD-51230-REQUEST', status: 'requested_not_confirmed', shipmentId: 'SPD-51230', orderId: null, summary: 'La mittente chiede di aprire un reclamo e recuperare la merce; nessuna apertura è confermata da questa email.', emailEvidence: [email('rotta-logistics-2', 'Reclamo consegna a indirizzo errato', 'SPD-51230')] },
      { id: 'RO-88512-DELAY', status: 'opened_reference_unknown', shipmentId: null, orderId: '88512', summary: 'Francesca Romano dichiara di aver aperto un reclamo sul portale; il numero pratica non è indicato.', emailEvidence: [email('rotta-logistics-2', 'Reclamo ritardo consegna - ordine 88512', '88512')] },
    ],
    inventory: [],
  },
  'prometeo-antincendio': {
    customers: [
      { id: 'PR-LAURA-BIANCHI', name: 'Laura Bianchi', role: 'referente cliente', orderIds: [], shipmentIds: [], emailEvidence: [email('prometeo-antincendio-1', 'Scadenza CPI edificio B-MI-07: come procediamo?', 'Laura Bianchi')] },
      { id: 'PR-LOGOS', name: 'Logos SpA', role: 'cliente business', orderIds: ['PRV-2024-0457'], shipmentIds: [], emailEvidence: [email('prometeo-antincendio-2', 'Conferma ordine forniture per cantiere Logos SpA', 'Logos SpA')] },
    ],
    products: [
      { id: 'PR-CO2-5KG', name: 'Estintore CO2 da 5 kg', kind: 'supply', orderIds: ['ORD-2024-188'], emailEvidence: [email('prometeo-antincendio-2', 'Notifica conferma ordine n. ORD-2024-188', 'estintori a CO2 da 5 kg')] },
      { id: 'PR-PORTE-REI90', name: 'Porte tagliafuoco REI 90', kind: 'supply-and-installation', orderIds: ['PR-2024-217'], emailEvidence: [email('prometeo-antincendio-1', 'Preventivo accettato - fornitura 8 porte tagliafuoco', 'porte tagliafuoco REI 90')] },
      { id: 'PR-ESTINTORI-LOGOS', name: 'Estintori e cartellonistica Logos SpA', kind: 'supply-and-installation', orderIds: ['PRV-2024-0457'], emailEvidence: [email('prometeo-antincendio-2', 'Re: Conferma ordine forniture per cantiere Logos SpA', 'estintori e cartellonistica')] },
      { id: 'PR-POLVERE-250', name: 'Agente estinguente in polvere', kind: 'supply', orderIds: ['ORD-2024-3471'], emailEvidence: [email('prometeo-antincendio-1', 'Conferma fornitura ricariche estinguenti e ricambi sprinkler', '250 kg')] },
      { id: 'PR-MANOMETRI', name: 'Manometri di ricambio', kind: 'supply', orderIds: ['ORD-2024-3471'], emailEvidence: [email('prometeo-antincendio-1', 'Conferma fornitura ricariche estinguenti e ricambi sprinkler', '60 manometri')] },
      { id: 'PR-UGELLI', name: 'Ugelli sprinkler', kind: 'supply', orderIds: ['ORD-2024-3471'], emailEvidence: [email('prometeo-antincendio-1', 'Conferma fornitura ricariche estinguenti e ricambi sprinkler', '24 ugelli')] },
    ],
    orders: [
      { id: 'ORD-2024-188', observedAt: '2024-02-16', status: 'confirmed', customerId: null, productIds: ['PR-CO2-5KG'], shipmentIds: [], quantity: 8, emailEvidence: [email('prometeo-antincendio-2', 'Notifica conferma ordine n. ORD-2024-188', 'ORD-2024-188')] },
      { id: 'PRV-2024-0457', identifierKind: 'accepted_quote_reference', observedAt: '2024-07-29', status: 'confirmed_installation_scheduled', customerId: 'PR-LOGOS', productIds: ['PR-ESTINTORI-LOGOS'], shipmentIds: [], emailEvidence: [email('prometeo-antincendio-2', 'Re: Conferma ordine forniture per cantiere Logos SpA', 'PRV-2024-0457')] },
      { id: 'PR-2024-217', identifierKind: 'accepted_quote_reference', observedAt: '2024-09-23', status: 'accepted_quote', customerId: null, productIds: ['PR-PORTE-REI90'], shipmentIds: [], quantity: 8, emailEvidence: [email('prometeo-antincendio-1', 'Preventivo accettato - fornitura 8 porte tagliafuoco', 'PR-2024-217')] },
      { id: 'ORD-2024-3471', observedAt: '2024-11-12', status: 'confirmed', customerId: null, supplier: 'Sicura Forniture S.p.A.', productIds: ['PR-POLVERE-250', 'PR-MANOMETRI', 'PR-UGELLI'], shipmentIds: [], expectedDeliveryDate: '2024-11-20', emailEvidence: [email('prometeo-antincendio-1', 'Conferma fornitura ricariche estinguenti e ricambi sprinkler', 'ORD-2024-3471')] },
    ],
    shipments: [],
    claims: [
      { id: 'RC-2024-014', status: 'received', shipmentId: null, orderId: null, summary: 'Segnalazione su ritardo evasione di un ordine di estintori; l’email non indica il numero ordine.', emailEvidence: [email('prometeo-antincendio-2', 'Ricevuta automatica reclamo cliente n. RC-2024-014', 'RC-2024-014')] },
      { id: 'PR-LAMBRATE-DELAY', status: 'reported', shipmentId: null, orderId: null, summary: 'Giorgio Pellegrini segnala il mancato intervento di manutenzione estintori a Lambrate; nessuna pratica numerata è citata.', emailEvidence: [email('prometeo-antincendio-1', 'Reclamo ritardo intervento estintori cantiere Lambrate', 'Lambrate')] },
      { id: 'PR-DOOR-CLOSURE', status: 'reported', shipmentId: null, orderId: null, summary: 'Francesca Lombardi segnala due porte tagliafuoco con chiusura automatica difettosa; il messaggio non le lega al preventivo PR-2024-217.', emailEvidence: [email('prometeo-antincendio-1', 'Reclamo qualità installazione porte tagliafuoco', 'Francesca Lombardi')] },
    ],
    inventory: [
      { id: 'PR-INBOUND-3471', productId: 'PR-POLVERE-250', orderId: 'ORD-2024-3471', availableQuantity: null, incomingQuantity: 250, unit: 'kg', status: 'expected_not_received', emailEvidence: [email('prometeo-antincendio-1', 'Conferma fornitura ricariche estinguenti e ricambi sprinkler', '250 kg')] },
    ],
  },
  'passolibero-calzature': {
    customers: [
      { id: 'PL-CHIARA-MORETTI', name: 'Chiara Moretti', role: 'cliente', orderIds: ['CLB-6390'], shipmentIds: [], emailEvidence: [email('passolibero-calzature-1', 'Ordine #CLB-6390 arrivato con scatola danneggiata', 'Chiara Moretti')] },
      { id: 'PL-SILVIA-GRECO', name: 'Silvia Greco', role: 'cliente', orderIds: ['CLB-5801'], shipmentIds: [], emailEvidence: [email('passolibero-calzature-1', 'Reclamo - pacco arrivato danneggiato ordine #CLB-5801', 'Silvia Greco')] },
    ],
    products: [
      { id: 'PL-BREZZA-PELLAME', name: 'Pellame conciato al vegetale per modello Brezza', kind: 'material', orderIds: ['FRN-2024-241'], emailEvidence: [email('passolibero-calzature-2', 'Nuovo ordine fornitore per riassortimento Brezza', 'pellame conciato al vegetale')] },
      { id: 'PL-BREZZA', name: 'Sandali Brezza', kind: 'footwear', orderIds: ['CLB-5701', 'CLB-5890'], emailEvidence: [email('passolibero-calzature-1', 'Conferma ordine #CLB-5701 - sandali Brezza', 'Brezza')] },
      { id: 'PL-ELEGANZA', name: 'Scarpe Eleganza', kind: 'footwear', orderIds: ['CLB-5810', 'CLB-5602', 'CLB-5801'], emailEvidence: [email('passolibero-calzature-1', 'Conferma ordine #CLB-5810 - modello Eleganza', 'Eleganza')] },
      { id: 'PL-ELEGANZA-PELLAME', name: 'Pellame per modello Eleganza', kind: 'material', orderIds: ['FRN-2024-258'], emailEvidence: [email('passolibero-calzature-2', 'Nuovo ordine fornitore FRN-2024-258 a ConceriaToscana', 'Pellame conciato al vegetale')] },
      { id: 'PL-AERO-PELLAME', name: 'Pellame per modello Aero', kind: 'material', orderIds: ['FRN-2024-220'], emailEvidence: [email('passolibero-calzature-2', 'Conferma ordine fornitore FRN-2024-220 a ConceriaToscana', 'Pellame conciato al vegetale')] },
    ],
    orders: [
      { id: 'FRN-2024-241', observedAt: '2024-01-23', status: 'received_and_quality_approved', customerId: null, supplier: 'ConceriaToscana', productIds: ['PL-BREZZA-PELLAME'], shipmentIds: ['PL-FRN-2024-241'], emailEvidence: [email('passolibero-calzature-2', 'Nuovo ordine fornitore per riassortimento Brezza', 'FRN-2024-241')] },
      { id: 'CLB-6390', observedAt: '2024-07-15', status: 'delivered_with_packaging_issue', customerId: 'PL-CHIARA-MORETTI', productIds: [], shipmentIds: [], emailEvidence: [email('passolibero-calzature-1', 'Ordine #CLB-6390 arrivato con scatola danneggiata', 'CLB-6390')] },
      { id: 'CLB-5701', observedAt: '2024-01-29', status: 'confirmed', customerId: null, productIds: ['PL-BREZZA'], shipmentIds: [], emailEvidence: [email('passolibero-calzature-1', 'Conferma ordine #CLB-5701 - sandali Brezza', 'CLB-5701')] },
      { id: 'CLB-5810', observedAt: '2024-03-08', status: 'confirmed', customerId: null, productIds: ['PL-ELEGANZA'], shipmentIds: [], emailEvidence: [email('passolibero-calzature-1', 'Conferma ordine #CLB-5810 - modello Eleganza', 'CLB-5810')] },
      { id: 'CLB-5890', observedAt: '2024-03-20', status: 'confirmed', customerId: null, productIds: ['PL-BREZZA'], shipmentIds: [], emailEvidence: [email('passolibero-calzature-1', 'Conferma ordine #CLB-5890 - sandali Brezza primavera', 'CLB-5890')] },
      { id: 'CLB-5602', observedAt: '2024-10-21', status: 'confirmed', customerId: null, productIds: ['PL-ELEGANZA'], shipmentIds: [], emailEvidence: [email('passolibero-calzature-1', 'Conferma ordine #CLB-5602 - modello Eleganza', 'CLB-5602')] },
      { id: 'CLB-5801', observedAt: '2024-12-10', status: 'delivered_damaged_reported', customerId: 'PL-SILVIA-GRECO', productIds: ['PL-ELEGANZA'], shipmentIds: [], emailEvidence: [email('passolibero-calzature-1', 'Reclamo - pacco arrivato danneggiato ordine #CLB-5801', 'CLB-5801')] },
      { id: 'FRN-2024-220', observedAt: '2024-01-08', status: 'sent_to_supplier', customerId: null, supplier: 'ConceriaToscana', productIds: ['PL-AERO-PELLAME'], shipmentIds: [], emailEvidence: [email('passolibero-calzature-2', 'Conferma ordine fornitore FRN-2024-220 a ConceriaToscana', 'FRN-2024-220')] },
      { id: 'FRN-2024-258', observedAt: '2024-02-22', status: 'sent_to_supplier', customerId: null, supplier: 'ConceriaToscana', productIds: ['PL-ELEGANZA-PELLAME'], shipmentIds: [], emailEvidence: [email('passolibero-calzature-2', 'Nuovo ordine fornitore FRN-2024-258 a ConceriaToscana', 'FRN-2024-258')] },
    ],
    shipments: [
      { id: 'PL-FRN-2024-241', status: 'shipped', orderId: 'FRN-2024-241', customerId: null, productIds: ['PL-BREZZA-PELLAME'], parcels: 8, trackingCode: null, emailEvidence: [email('passolibero-calzature-2', 'Conferma spedizione ConceriaToscana ordine FRN-2024-241', 'FRN-2024-241')] },
    ],
    claims: [
      { id: 'PL-CLB-6390-PACKAGING', status: 'reported', shipmentId: null, orderId: 'CLB-6390', summary: 'Chiara Moretti segnala la scatola danneggiata; nessun codice pratica è presente nell’email.', emailEvidence: [email('passolibero-calzature-1', 'Ordine #CLB-6390 arrivato con scatola danneggiata', 'CLB-6390')] },
      { id: 'PL-CLB-5801-DAMAGE', status: 'replacement_requested', shipmentId: null, orderId: 'CLB-5801', summary: 'Silvia Greco chiede la sostituzione delle scarpe Eleganza danneggiate; nessuna sostituzione è confermata nel messaggio.', emailEvidence: [email('passolibero-calzature-1', 'Reclamo - pacco arrivato danneggiato ordine #CLB-5801', 'CLB-5801')] },
    ],
    inventory: [
      { id: 'PL-BREZZA-241-STOCK', productId: 'PL-BREZZA-PELLAME', orderId: 'FRN-2024-241', availableQuantity: null, incomingQuantity: 0, plannedProductionQuantity: 40, unit: 'paia', status: 'material_quality_approved', emailEvidence: [email('passolibero-calzature-2', 'Esito QC lotto riassortimento FRN-2024-241', '40 paia')] },
      { id: 'PL-BREZZA-STOCK-20240214', productId: 'PL-BREZZA', orderId: null, availableQuantity: 52, incomingQuantity: 0, unit: 'paia', status: 'stock_snapshot_2024-02-14', emailEvidence: [email('passolibero-calzature-2', 'Aggiornamento giacenze dopo riassortimento Brezza', '52 paia')] },
    ],
  },
};

export const COMPANY_KEYS = Object.freeze(Object.keys(CATALOG));
export const MCP_ENTITY_TYPES = Object.freeze(['shipments', 'orders', 'products', 'customers']);

function company(companyKey) {
  return CATALOG[companyKey] ?? null;
}

function copy(value) {
  return structuredClone(value);
}

export function searchEntities(companyKey, type, query = '', { fromDate, toDate, name, limit = 20 } = {}) {
  const data = company(companyKey);
  if (!data || !MCP_ENTITY_TYPES.includes(type)) return null;
  const needle = query.trim().toLocaleLowerCase('it-IT');
  const nameNeedle = name?.trim().toLocaleLowerCase('it-IT');
  const records = data[type].filter((record) =>
    (!needle || JSON.stringify(record).toLocaleLowerCase('it-IT').includes(needle))
    && (!nameNeedle || [record.name, record.supplier, data.customers.find((customer) => customer.id === record.customerId)?.name]
      .filter(Boolean).some((value) => value.toLocaleLowerCase('it-IT').includes(nameNeedle)))
    && (!fromDate || (record.observedAt && record.observedAt >= fromDate))
    && (!toDate || (record.observedAt && record.observedAt <= toDate)));
  if (type === 'orders') records.sort((a, b) => b.observedAt.localeCompare(a.observedAt) || a.id.localeCompare(b.id));
  return copy({ companyKey, entityType: type, query, dateField: 'observedAt', records: records.slice(0, limit), total: records.length });
}

export function recentOrders(companyKey, filters = {}) {
  const result = searchEntities(companyKey, 'orders', '', filters);
  if (!result) return null;
  return { companyKey, dateField: 'observedAt', total: result.total,
    orders: result.records.map(({ id, observedAt, status, customerId, supplier }) => ({ id, observedAt, status, customerId, supplier })) };
}

export function claimsForCompany(companyKey, { shipmentId, orderId } = {}) {
  const data = company(companyKey);
  if (!data) return null;
  return copy({ companyKey, claims: data.claims.filter((claim) =>
    (!shipmentId || claim.shipmentId === shipmentId) && (!orderId || claim.orderId === orderId)) });
}

export function findClaim(companyKey, claimId) {
  const data = company(companyKey);
  const claim = data?.claims.find((candidate) => candidate.id === claimId);
  return claim ? copy({ companyKey, claim }) : null;
}

export function inventoryForCompany(companyKey, { productId } = {}) {
  const data = company(companyKey);
  if (!data) return null;
  return copy({ companyKey, inventory: data.inventory.filter((entry) => !productId || entry.productId === productId) });
}

export function findInventory(companyKey, inventoryId) {
  const data = company(companyKey);
  const entry = data?.inventory.find((candidate) => candidate.id === inventoryId);
  return entry ? copy({ companyKey, inventory: entry }) : null;
}

export function catalogForValidation() {
  return copy(CATALOG);
}
