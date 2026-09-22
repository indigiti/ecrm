import './style.css';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import QRCode from 'qrcode';

const app = document.querySelector('#app');
const state = { view: 'dashboard', map: null, searchTimer: null, financeTab: 'receipts', inventoryTab: 'stock' };
const activeViews = ['dashboard','customers','leads','followups','map','products','inventory','quotations','invoices','payments'];
const nav = [
  ['dashboard','Dashboard'],['customers','Customers'],['leads','Leads'],['followups','Follow-ups'],['map','Map'],
  ['products','Products'],['inventory','Inventory'],['workshop','Workshop'],['quotations','Quotations'],
  ['invoices','Invoices'],['payments','Payments'],['reports','Reports'],['settings','Settings']
];

function esc(value) {
  return String(value == null ? '' : value).replace(/[&<>"']/g, function (m) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
  });
}

function money(value) {
  return new Intl.NumberFormat('en-IN',{style:'currency',currency:'INR',maximumFractionDigits:0}).format(Number(value || 0));
}

function moneyPaise(value) {
  return new Intl.NumberFormat('en-IN',{style:'currency',currency:'INR',minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value || 0) / 100);
}

async function api(path, options) {
  const config = Object.assign({headers:{'Content-Type':'application/json','Accept':'application/json'}}, options || {});
  if (config.body && typeof config.body !== 'string') config.body = JSON.stringify(config.body);
  const response = await fetch('./api/' + path, config);
  const payload = await response.json().catch(function(){ return {error:'Invalid server response'}; });
  if (!response.ok) throw new Error(payload.error || 'Request failed');
  return payload;
}

function shell() {
  let navHtml = '';
  nav.forEach(function(item) {
    const key = item[0], label = item[1];
    navHtml += '<button data-view="' + key + '" class="' + (key === state.view ? 'active' : '') + '"><span>' + label + '</span>' + (activeViews.includes(key) ? '' : '<em>Soon</em>') + '</button>';
  });

  app.innerHTML =
    '<div class="shell">' +
      '<aside class="sidebar">' +
        '<div class="brand"><span class="logo">e</span><div><b>eCRM</b><small>Business workspace</small></div></div>' +
        '<nav>' + navHtml + '</nav>' +
        '<div class="side-foot"><span class="status-dot"></span> DigiOps release ready</div>' +
      '</aside>' +
      '<section class="app">' +
        '<header class="topbar">' +
          '<div class="mobile-brand"><span class="logo">e</span><b>eCRM</b></div>' +
          '<div class="search-wrap"><input id="global-search" autocomplete="off" placeholder="Search customers, contacts, GST, leads…"><kbd>⌘ K</kbd><div id="search-results" class="search-results hidden"></div></div>' +
          '<div class="top-actions"><button class="soft" id="scan-qr">Scan QR</button><button class="primary" id="new-customer">+ New</button></div>' +
        '</header>' +
        '<main id="workspace"></main>' +
        '<nav class="mobile-nav"><button data-view="dashboard">Home</button><button data-view="customers">Customers</button><button data-view="map">Map</button><button data-view="leads">Leads</button><button data-view="followups">Activity</button></nav>' +
      '</section>' +
    '</div><div id="modal-root"></div><div id="toast-root"></div>';

  document.querySelectorAll('[data-view]').forEach(function(btn) {
    if (btn.dataset.view === state.view) btn.classList.add('active');
    btn.addEventListener('click', function(){ state.view = btn.dataset.view; shell(); });
  });
  document.querySelector('#new-customer').addEventListener('click', customerModal);
  document.querySelector('#scan-qr').addEventListener('click', qrScanModal);

  const search = document.querySelector('#global-search');
  search.addEventListener('input', function(){
    clearTimeout(state.searchTimer);
    state.searchTimer = setTimeout(function(){ searchGlobal(search.value); }, 160);
  });
  window.onkeydown = function(e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      search.focus();
    }
  };
  renderView();
}

async function searchGlobal(query) {
  const box = document.querySelector('#search-results');
  if (!box) return;
  if (query.trim().length < 2) { box.classList.add('hidden'); box.innerHTML = ''; return; }
  try {
    const payload = await api('search?q=' + encodeURIComponent(query.trim()));
    if (!payload.data.length) {
      box.innerHTML = '<div class="search-empty">No matching records</div>';
    } else {
      box.innerHTML = payload.data.map(function(row){
        return '<button class="search-item" data-type="' + esc(row.type) + '" data-id="' + esc(row.id) + '"><b>' + esc(row.label) + '</b><span>' + esc(row.type) + (row.meta && row.meta.number ? ' · ' + esc(row.meta.number) : '') + '</span></button>';
      }).join('');
    }
    box.classList.remove('hidden');
    box.querySelectorAll('.search-item').forEach(function(el){
      el.addEventListener('click', function(){
        box.classList.add('hidden');
        if (el.dataset.type === 'customer') openCustomer(el.dataset.id);
        if (el.dataset.type === 'lead') { state.view = 'leads'; shell(); }
        if (el.dataset.type === 'product') { state.view = 'products'; shell(); setTimeout(function(){ openProduct(el.dataset.id); },0); }
        if (el.dataset.type === 'location') { state.view = 'inventory'; state.inventoryTab = 'locations'; shell(); }
        if (el.dataset.type === 'product_unit') { state.view = 'inventory'; shell(); setTimeout(function(){ openUnit(el.dataset.id); },0); }
        if (el.dataset.type === 'quotation') { state.view = 'quotations'; shell(); }
        if (el.dataset.type === 'invoice') { state.view = 'invoices'; shell(); }
        if (el.dataset.type === 'payment') { state.view = 'payments'; shell(); setTimeout(function(){ openPayment(el.dataset.id); },0); }
        if (el.dataset.type === 'payment_batch') { state.view = 'payments'; state.financeTab = 'batches'; shell(); }
      });
    });
  } catch (e) {
    box.innerHTML = '<div class="search-empty">' + esc(e.message) + '</div>';
    box.classList.remove('hidden');
  }
}

async function renderView() {
  const w = document.querySelector('#workspace');
  w.innerHTML = '<div class="loading">Loading…</div>';
  try {
    if (state.view === 'dashboard') return dashboard(w);
    if (state.view === 'customers') return customers(w);
    if (state.view === 'leads') return leads(w);
    if (state.view === 'followups') return followups(w);
    if (state.view === 'map') return mapView(w);
    if (state.view === 'products') return products(w);
    if (state.view === 'inventory') return inventoryWorkspace(w);
    if (state.view === 'quotations') return quotations(w);
    if (state.view === 'invoices') return invoices(w);
    if (state.view === 'payments') return paymentsWorkspace(w);
    return comingSoon(w);
  } catch (e) {
    w.innerHTML = errorCard(e.message);
  }
}

async function dashboard(w) {
  const d = await api('dashboard');
  w.innerHTML =
    '<section class="page-head"><div><p class="eyebrow">WORKSPACE</p><h1>Business at a glance</h1><p>Fast operational view of customers, leads and work that needs attention.</p></div><button class="primary" id="dash-customer">+ Customer</button></section>' +
    '<section class="metrics">' +
      metric('Customers', d.customers, 'Active master records') +
      metric('Open leads', d.leads, 'Sales pipeline') +
      metric('Follow-ups', d.open_followups, (d.overdue_followups || 0) + ' overdue · ' + (d.due_today || 0) + ' due today') +
      metric('Mapped sites', d.mapped_addresses, 'Resolved locations') +
      metric('Quotes waiting', d.quotes_awaiting || 0, 'Awaiting decision') +
      metric('Issued invoices', d.issued_invoices || 0, 'Active receivables') +
      metric('Collected', moneyPaise(d.collected_paise || 0), 'Posted receipts') +
      metric('Outstanding', moneyPaise(d.outstanding_paise || 0), moneyPaise(d.overdue_paise || 0) + ' overdue') +
    '</section>' +
    '<section class="dash-grid">' +
      '<article class="panel attention"><div><p class="eyebrow">NEEDS ATTENTION</p><h2>' + (d.open_followups ? d.open_followups + ' open follow-up' + (d.open_followups === 1 ? '' : 's') : 'You’re all caught up') + '</h2><p>Tasks and follow-ups stay visible until completed.</p></div><button class="chip" id="open-followups">Open queue</button></article>' +
      '<article class="panel"><p class="eyebrow">QUICK ACTIONS</p><div class="quick"><button id="quick-customer">+ Customer</button><button id="quick-quote">+ Quotation</button><button id="quick-invoice">+ Invoice</button><button id="quick-payment">+ Payment</button></div></article>' +
      '<article class="panel wide"><div class="row"><div><p class="eyebrow">RECENT ACTIVITY</p><h2>Business timeline</h2></div></div>' + timeline(d.recent_activity) + '</article>' +
    '</section>';

  document.querySelector('#dash-customer').onclick = customerModal;
  document.querySelector('#quick-customer').onclick = customerModal;
  document.querySelector('#quick-quote').onclick = function(){ salesDocumentModal('quotation'); };
  document.querySelector('#quick-invoice').onclick = function(){ salesDocumentModal('invoice'); };
  document.querySelector('#quick-payment').onclick = function(){ paymentModal(); };
  document.querySelector('#open-followups').onclick = function(){ state.view = 'followups'; shell(); };
}

function metric(label, value, note) {
  return '<article><span>' + esc(label) + '</span><strong>' + esc(value) + '</strong><small>' + esc(note) + '</small></article>';
}

async function customers(w) {
  const payload = await api('customers');
  const rows = payload.data;
  let body = '<div class="empty-state"><b>No customers yet</b><span>Create the first customer to start the CRM.</span></div>';
  if (rows.length) {
    body = rows.map(function(c){
      return '<button class="table-row" data-id="' + esc(c.id) + '"><span><b>' + esc(c.name) + '</b><small>' + esc(c.number) + '</small></span><span>' + esc(c.contact_person || '—') + '<small>' + esc(c.mobile || c.email || '') + '</small></span><span>' + esc(c.gstin || '—') + '</span><span><i class="pill">' + esc(c.status) + '</i></span></button>';
    }).join('');
  }
  w.innerHTML =
    '<section class="page-head"><div><p class="eyebrow">CRM</p><h1>Customers</h1><p>' + rows.length + ' customer' + (rows.length === 1 ? '' : 's') + ' in the workspace.</p></div><button class="primary" id="add-customer">+ Customer</button></section>' +
    '<article class="table-card"><div class="table-head"><span>Customer</span><span>Contact</span><span>GSTIN</span><span>Status</span></div><div class="table-body">' + body + '</div></article>';
  document.querySelector('#add-customer').onclick = customerModal;
  w.querySelectorAll('.table-row').forEach(function(row){ row.onclick = function(){ openCustomer(row.dataset.id); }; });
}

async function openCustomer(id) {
  state.view = 'customers';
  const w = document.querySelector('#workspace');
  w.innerHTML = '<div class="loading">Loading customer…</div>';
  try {
    const payload = await api('customers/' + id + '/overview');
    const data = payload.data, c = data.customer;
    w.innerHTML =
      '<button class="back" id="back-customers">← Customers</button>' +
      '<section class="record-head"><div><p class="eyebrow">' + esc(c.number) + ' · ' + esc(c.status) + '</p><h1>' + esc(c.name) + '</h1><p>' + esc(c.category || 'Customer') + (c.gstin ? ' · GSTIN ' + esc(c.gstin) : '') + '</p></div><div class="record-actions"><button class="soft" id="customer-statement">Statement</button><button class="soft" id="edit-customer">Edit</button><button class="soft" id="add-contact">+ Contact</button><button class="soft" id="add-address">+ Address</button><button class="primary" id="add-followup-customer">+ Follow-up</button></div></section>' +
      '<section class="customer-grid">' +
        '<article class="panel"><p class="eyebrow">CUSTOMER</p><dl><dt>Contact</dt><dd>' + esc(c.contact_person || '—') + '</dd><dt>Mobile</dt><dd>' + esc(c.mobile || '—') + '</dd><dt>Email</dt><dd>' + esc(c.email || '—') + '</dd><dt>GSTIN</dt><dd>' + esc(c.gstin || '—') + '</dd></dl></article>' +
        '<article class="panel"><p class="eyebrow">UP NEXT</p>' + nextActivity(data.activities) + '</article>' +
        '<article class="panel"><p class="eyebrow">CONTACTS</p>' + contactCards(data.contacts) + '</article>' +
        '<article class="panel span2"><p class="eyebrow">ADDRESSES</p>' + addressCards(data.addresses) + '</article>' +
        '<article class="panel"><p class="eyebrow">ACTIVITY</p>' + timeline(data.activities) + '</article>' +
        '<article class="panel span2"><p class="eyebrow">RECENT BUSINESS</p>' + customerBusiness(data.quotations || [], data.invoices || []) + '</article>' +
        '<article class="panel"><p class="eyebrow">RECEIVABLES</p>' + customerReceivables(data.receivables) + '</article>' +
        '<article class="panel span2"><p class="eyebrow">PAYMENTS</p>' + customerPayments(data.payments || []) + '</article>' +
      '</section>';
    document.querySelector('#back-customers').onclick = function(){ customers(w); };
    document.querySelector('#customer-statement').onclick = function(){ openCustomerStatement(id); };
    document.querySelector('#edit-customer').onclick = function(){ customerEditModal(c); };
    document.querySelector('#add-contact').onclick = function(){ contactModal(id); };
    document.querySelector('#add-address').onclick = function(){ addressModal(id); };
    document.querySelector('#add-followup-customer').onclick = function(){ followupModal(id); };
    w.querySelectorAll('[data-edit-contact]').forEach(function(btn){ btn.onclick = function(){ var record = data.contacts.find(function(x){ return x.id === btn.dataset.editContact; }); if (record) contactEditModal(record); }; });
    w.querySelectorAll('[data-edit-address]').forEach(function(btn){ btn.onclick = function(){ var record = data.addresses.find(function(x){ return x.id === btn.dataset.editAddress; }); if (record) addressEditModal(record); }; });
    w.querySelectorAll('[data-payment]').forEach(function(btn){ btn.onclick = function(){ openPayment(btn.dataset.payment); }; });
  } catch (e) {
    w.innerHTML = errorCard(e.message);
  }
}

function contactCards(rows) {
  if (!rows.length) return '<div class="empty-small">No contacts yet.</div>';
  return '<div class="mini-list">' + rows.map(function(r){
    return '<div class="mini-row"><div><b>' + esc(r.name) + '</b><span>' + esc(r.designation || r.mobile || r.email || '') + '</span></div><button class="soft mini-edit" data-edit-contact="' + esc(r.id) + '">Edit</button></div>';
  }).join('') + '</div>';
}

function addressCards(rows) {
  if (!rows.length) return '<div class="empty-small">No addresses yet.</div>';
  return '<div class="address-grid">' + rows.map(function(a){
    return '<div class="address-card"><div><span class="pill">' + esc(a.type) + '</span><b>' + esc(a.label) + '</b></div><p>' + esc(a.address) + (a.area ? ', ' + esc(a.area) : '') + (a.city ? ', ' + esc(a.city) : '') + (a.pin ? ' ' + esc(a.pin) : '') + '</p><div class="address-foot"><small>' + (a.geocode_status === 'resolved' ? '● Map ready' : '○ Geocode pending') + '</small><button class="soft mini-edit" data-edit-address="' + esc(a.id) + '">Edit</button></div></div>';
  }).join('') + '</div>';
}

function customerBusiness(quotes, invoices) {
  if (!quotes.length && !invoices.length) return '<div class="empty-small">No quotations or invoices yet.</div>';
  const rows = [];
  quotes.slice(0,4).forEach(function(q){ rows.push({kind:'Quotation',number:q.number,status:q.status,total:q.totals && q.totals.grand_total_paise,at:q.created_at}); });
  invoices.slice(0,4).forEach(function(i){ rows.push({kind:'Invoice',number:i.number,status:i.status,total:i.totals && i.totals.grand_total_paise,at:i.created_at}); });
  rows.sort(function(a,b){ return String(b.at || '').localeCompare(String(a.at || '')); });
  return '<div class="business-list">' + rows.slice(0,6).map(function(r){
    return '<div><span><b>' + esc(r.number) + '</b><small>' + esc(r.kind) + ' · ' + esc(r.status) + '</small></span><strong>' + moneyPaise(r.total) + '</strong></div>';
  }).join('') + '</div>';
}

function customerReceivables(receivables) {
  if (!receivables) return '<div class="empty-small">No receivable summary.</div>';
  const buckets = receivables.buckets || {};
  const overdue = ['1_30','31_60','61_90','90_plus'].reduce(function(sum,key){
    return sum + Number(buckets[key] ? buckets[key].amount_paise || 0 : 0);
  }, 0);
  return '<div class="finance-summary"><strong>' + moneyPaise(receivables.total_outstanding_paise || 0) + '</strong><span>Outstanding</span><small>' + moneyPaise(overdue) + ' overdue</small></div>';
}

function customerPayments(rows) {
  if (!rows.length) return '<div class="empty-small">No payments received yet.</div>';
  return '<div class="business-list">' + rows.slice(0,6).map(function(p){
    return '<button class="business-button" data-payment="' + esc(p.id) + '"><span><b>' + esc(p.number) + '</b><small>' + esc(String(p.method || '').replace('_',' ').toUpperCase()) + (p.reference ? ' · ' + esc(p.reference) : '') + '</small></span><strong>' + moneyPaise(p.amount_paise) + '</strong></button>';
  }).join('') + '</div>';
}

async function openCustomerStatement(customerId) {
  state.view = 'customers';
  const w = document.querySelector('#workspace');
  w.innerHTML = '<div class="loading">Loading statement…</div>';
  try {
    const payload = await api('customers/' + customerId + '/statement');
    const data = payload.data;
    const rows = data.entries.length ? data.entries.map(function(entry){
      return '<div class="statement-row"><span><b>' + esc(entry.reference) + '</b><small>' + esc(entry.description) + '<br>' + esc(entry.at ? new Date(entry.at).toLocaleDateString() : '') + '</small></span><span>' + (entry.debit_paise ? moneyPaise(entry.debit_paise) : '—') + '</span><span>' + (entry.credit_paise ? moneyPaise(entry.credit_paise) : '—') + '</span><strong>' + moneyPaise(entry.balance_paise) + '</strong></div>';
    }).join('') : '<div class="empty-state"><b>No statement entries</b><span>Issued invoices and posted payments will appear here.</span></div>';

    w.innerHTML =
      '<button class="back" id="back-customer-statement">← Customer</button>' +
      '<section class="record-head"><div><p class="eyebrow">' + esc(data.customer.number) + '</p><h1>' + esc(data.customer.name) + '</h1><p>Customer statement as of ' + esc(data.as_of) + '</p></div><div class="record-actions"><button class="soft" id="print-statement">Print / PDF</button><button class="primary" id="statement-payment">+ Payment</button></div></section>' +
      '<section class="metrics finance-metrics">' +
        metric('Statement balance', moneyPaise(data.statement_balance_paise), 'Invoices less posted receipts') +
        metric('Invoice outstanding', moneyPaise(data.invoice_outstanding_paise), 'Allocation-derived') +
        metric('Unallocated credit', moneyPaise(data.unallocated_credit_paise), 'Available customer credit') +
      '</section>' +
      '<article class="table-card statement-card"><div class="statement-head"><span>Reference</span><span>Debit</span><span>Credit</span><span>Balance</span></div>' + rows + '</article>';

    document.querySelector('#back-customer-statement').onclick = function(){ openCustomer(customerId); };
    document.querySelector('#print-statement').onclick = function(){ window.print(); };
    document.querySelector('#statement-payment').onclick = function(){ paymentModal(customerId); };
  } catch(e) { w.innerHTML = errorCard(e.message); }
}

function nextActivity(rows) {
  const open = rows.filter(function(a){ return a.status === 'open'; }).sort(function(a,b){ return String(a.due_at || '').localeCompare(String(b.due_at || '')); })[0];
  if (!open) return '<div class="empty-small">Nothing scheduled.</div>';
  return '<div class="next"><b>' + esc(open.subject) + '</b><span>' + esc(open.type.replace('_',' ')) + '</span><small>' + (open.due_at ? esc(new Date(open.due_at).toLocaleString()) : 'No due date') + '</small></div>';
}

async function leads(w) {
  const payload = await api('leads');
  const data = payload.data, stages = payload.stages;
  let board = '';
  stages.forEach(function(stage){
    const items = data.filter(function(l){ return l.stage === stage; });
    let cards = '<div class="lane-empty">No leads</div>';
    if (items.length) {
      cards = items.map(function(l){
        let options = '';
        stages.forEach(function(s){ options += '<option value="' + esc(s) + '"' + (s === l.stage ? ' selected' : '') + '>' + esc(s.replace('_',' ')) + '</option>'; });
        return '<article class="lead-card"><b>' + esc(l.name) + '</b><span>' + esc(l.company || l.number) + '</span>' + (l.value ? '<strong>' + money(l.value) + '</strong>' : '') + '<select data-lead="' + esc(l.id) + '">' + options + '</select><button class="soft" data-edit-lead="' + esc(l.id) + '">Edit details</button>' + ((!l.customer_id && l.stage !== 'lost') ? '<button class="soft convert-lead" data-convert="' + esc(l.id) + '">Convert to customer</button>' : '') + '</article>';
      }).join('');
    }
    board += '<section class="lane"><div class="lane-head"><b>' + esc(stage.replace('_',' ')) + '</b><span>' + items.length + '</span></div>' + cards + '</section>';
  });
  w.innerHTML = '<section class="page-head"><div><p class="eyebrow">PIPELINE</p><h1>Leads</h1><p>Move opportunities through the sales process without losing their history.</p></div><button class="primary" id="add-lead">+ Lead</button></section><div class="pipeline">' + board + '</div>';
  document.querySelector('#add-lead').onclick = leadModal;
  w.querySelectorAll('select[data-lead]').forEach(function(select){
    select.onchange = async function(){
      try {
        await api('leads/' + select.dataset.lead + '/stage',{method:'PATCH',body:{stage:select.value}});
        toast('Lead stage updated');
        leads(w);
      } catch (e) { toast(e.message,true); }
    };
  });
  w.querySelectorAll('[data-edit-lead]').forEach(function(btn){
    btn.onclick = function(){ var record = data.find(function(x){ return x.id === btn.dataset.editLead; }); if (record) leadEditModal(record); };
  });
  w.querySelectorAll('[data-convert]').forEach(function(btn){
    btn.onclick = async function(){
      btn.disabled = true;
      try {
        const payload = await api('leads/' + btn.dataset.convert + '/convert',{method:'POST',body:{}});
        toast('Lead converted to ' + payload.data.customer.number);
        state.view = 'customers';
        shell();
        setTimeout(function(){ openCustomer(payload.data.customer.id); }, 0);
      } catch (e) { btn.disabled = false; toast(e.message,true); }
    };
  });
}

async function followups(w) {
  const payload = await api('activities/attention');
  const buckets = payload.data;
  const total = buckets.overdue.length + buckets.today.length + buckets.upcoming.length + buckets.unscheduled.length;

  function bucket(title, rows, tone) {
    if (!rows.length) return '';
    return '<section class="attention-section"><div class="attention-title"><b>' + esc(title) + '</b><span>' + rows.length + '</span></div><div class="task-list">' + rows.map(function(a){
      return '<div class="task ' + tone + '"><div><span class="pill">' + esc(a.type.replace('_',' ')) + '</span><b>' + esc(a.subject) + '</b><small>' + (a.due_at ? esc(new Date(a.due_at).toLocaleString()) : 'No due date') + '</small></div><button class="soft" data-complete="' + esc(a.id) + '">Complete</button></div>';
    }).join('') + '</div></section>';
  }

  let content = '<div class="empty-state"><b>No open follow-ups</b><span>New tasks and follow-ups will appear here.</span></div>';
  if (total) {
    content =
      bucket('Overdue', buckets.overdue, 'is-overdue') +
      bucket('Due today', buckets.today, 'is-today') +
      bucket('Upcoming', buckets.upcoming, '') +
      bucket('Unscheduled', buckets.unscheduled, '');
  }

  w.innerHTML = '<section class="page-head"><div><p class="eyebrow">MY WORK</p><h1>Follow-ups</h1><p>' + total + ' open item' + (total === 1 ? '' : 's') + '. Overdue work is surfaced first.</p></div><button class="primary" id="add-followup">+ Follow-up</button></section><article class="panel">' + content + '</article>';
  document.querySelector('#add-followup').onclick = function(){ followupModal(); };
  w.querySelectorAll('[data-complete]').forEach(function(btn){
    btn.onclick = async function(){
      try { await api('activities/' + btn.dataset.complete + '/complete',{method:'PATCH'}); toast('Follow-up completed'); followups(w); }
      catch (e) { toast(e.message,true); }
    };
  });
}

async function mapView(w) {
  const payload = await api('map/addresses');
  const data = payload.data;
  let list = '<div class="empty-small">Add latitude/longitude to a customer address to show it here.</div>';
  if (data.length) {
    list = data.map(function(a){ return '<button data-lat="' + esc(a.latitude) + '" data-lon="' + esc(a.longitude) + '"><b>' + esc(a.label) + '</b><span>' + esc(a.city || a.address) + '</span></button>'; }).join('');
  }
  w.innerHTML = '<section class="page-head"><div><p class="eyebrow">CUSTOMER MAP</p><h1>Locations</h1><p>' + data.length + ' resolved address' + (data.length === 1 ? '' : 'es') + '. Pending geocodes are kept off the map until resolved.</p></div></section><div class="map-layout"><div id="crm-map"></div><aside class="map-list">' + list + '</aside></div>';
  if (state.map) { try { state.map.remove(); } catch (e) {} state.map = null; }
  const map = L.map('crm-map',{zoomControl:true}).setView([20.5937,78.9629],5);
  state.map = map;
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map);
  const bounds = [];
  data.forEach(function(a){
    const point = [Number(a.latitude),Number(a.longitude)];
    bounds.push(point);
    L.circleMarker(point,{radius:8,weight:2,fillOpacity:.85}).addTo(map).bindPopup('<b>' + esc(a.label) + '</b><br>' + esc(a.address) + '<br>' + esc(a.city));
  });
  if (bounds.length) map.fitBounds(bounds,{padding:[30,30],maxZoom:13});
  w.querySelectorAll('.map-list button').forEach(function(btn){ btn.onclick = function(){ map.flyTo([Number(btn.dataset.lat),Number(btn.dataset.lon)],14); }; });
}


async function products(w) {
  const payload = await api('products');
  const rows = payload.data;
  const body = rows.length ? rows.map(function(p){
    return '<button class="table-row product-row" data-product="' + esc(p.id) + '"><span><b>' + esc(p.name) + '</b><small>' + esc(p.code) + (p.sku ? ' · ' + esc(p.sku) : '') + '</small></span><span>' + esc(p.brand || p.category || '—') + '<small>' + esc(p.model || p.hsn_sac || '') + '</small></span><span>' + moneyPaise(p.selling_price_paise) + '<small>GST ' + ((p.gst_bps || 0) / 100) + '%</small></span><span><i class="pill">' + esc(p.status) + '</i></span></button>';
  }).join('') : '<div class="empty-state"><b>No products yet</b><span>Create Product Master records before building quotations.</span></div>';

  w.innerHTML =
    '<section class="page-head"><div><p class="eyebrow">PRODUCTS</p><h1>Product Master</h1><p>Commercial master data used by quotations, invoices and later inventory movements.</p></div><button class="primary" id="add-product">+ Product</button></section>' +
    '<article class="table-card"><div class="table-head"><span>Product</span><span>Classification</span><span>Selling price</span><span>Status</span></div><div class="table-body">' + body + '</div></article>';

  document.querySelector('#add-product').onclick = function(){ productModal(); };
  w.querySelectorAll('[data-product]').forEach(function(btn){
    btn.onclick = function(){
      const record = rows.find(function(row){ return row.id === btn.dataset.product; });
      if (record) openProduct(record.id);
    };
  });
}

function productModal(product) {
  product = product || null;
  const title = product ? 'Edit product' : 'New product';
  const price = function(field){ return product ? ((product[field] || 0) / 100) : ''; };
  const gst = product ? ((product.gst_bps || 0) / 100) : '';
  modal(title,
    '<label class="full">Product name<input required name="name" value="' + esc(product ? product.name : '') + '"></label>' +
    '<label>SKU<input name="sku" value="' + esc(product ? product.sku : '') + '"></label><label>Brand<input name="brand" value="' + esc(product ? product.brand : '') + '"></label>' +
    '<label>Category<input name="category" value="' + esc(product ? product.category : '') + '"></label><label>Model<input name="model" value="' + esc(product ? product.model : '') + '"></label>' +
    '<label>Unit<input name="unit" value="' + esc(product ? product.unit : 'Nos') + '"></label><label>HSN / SAC<input name="hsn_sac" value="' + esc(product ? product.hsn_sac : '') + '"></label>' +
    '<label>GST %<input name="gst_percent" type="number" min="0" max="100" step="0.01" value="' + esc(gst) + '"></label><label>Selling price<input name="selling_price" type="number" min="0" step="0.01" value="' + esc(price('selling_price_paise')) + '"></label>' +
    '<label>Purchase price<input name="purchase_price" type="number" min="0" step="0.01" value="' + esc(price('purchase_price_paise')) + '"></label><label>MRP<input name="mrp" type="number" min="0" step="0.01" value="' + esc(price('mrp_paise')) + '"></label>' +
    '<label>Min stock<input name="min_stock" type="number" min="0" step="0.001" value="' + esc(product ? product.min_stock : 0) + '"></label><label>Reorder level<input name="reorder_level" type="number" min="0" step="0.001" value="' + esc(product ? product.reorder_level : 0) + '"></label>' +
    '<label>Serial tracking<select name="serial_tracking"><option value="0">No</option><option value="1"' + (product && product.serial_tracking ? ' selected' : '') + '>Yes</option></select></label>' +
    '<label>Status<select name="status"><option value="active"' + (!product || product.status === 'active' ? ' selected' : '') + '>Active</option><option value="inactive"' + (product && product.status === 'inactive' ? ' selected' : '') + '>Inactive</option><option value="archived"' + (product && product.status === 'archived' ? ' selected' : '') + '>Archived</option></select></label>' +
    '<label class="full">Description<textarea name="description">' + esc(product ? product.description : '') + '</textarea></label>',
    function(payload){
      if (!product) delete payload.status;
      return api(product ? 'products/' + product.id : 'products', {method:product ? 'PATCH' : 'POST', body:payload});
    }
  );
}


async function openProduct(id) {
  state.view = 'products';
  const w = document.querySelector('#workspace');
  w.innerHTML = '<div class="loading">Loading product…</div>';

  try {
    const payload = await api('products/' + id + '/overview');
    const data = payload.data, p = data.product, stock = data.stock;
    const locations = stock.locations.length ? stock.locations.map(function(row){
      return '<button class="stock-location" data-location-stock="' + esc(row.location_id) + '"><span><b>' + esc(row.location_name) + '</b><small>' + esc(row.location_code) + '</small></span><strong>' + esc(row.quantity) + ' ' + esc(p.unit || '') + '</strong></button>';
    }).join('') : '<div class="empty-small">No stock received yet.</div>';

    const units = data.units.length ? data.units.slice(0,10).map(function(unit){
      return '<button class="unit-row" data-unit="' + esc(unit.id) + '"><span><b>' + esc(unit.serial_no) + '</b><small>' + esc(unit.batch_no || 'No batch') + '</small></span><i class="pill">' + esc(unit.lifecycle_status) + '</i></button>';
    }).join('') : '<div class="empty-small">No serialized units.</div>';

    const movements = data.movements.length ? data.movements.slice(0,10).map(function(m){
      return '<div class="movement-mini"><span><b>' + esc(String(m.type || '').replaceAll('_',' ')) + '</b><small>#' + esc(m.ledger_seq || '') + ' · ' + esc(m.created_at ? new Date(m.created_at).toLocaleString() : '') + '</small></span><strong>' + esc(m.quantity) + ' ' + esc(p.unit || '') + '</strong></div>';
    }).join('') : '<div class="empty-small">No movement history.</div>';

    w.innerHTML =
      '<button class="back" id="back-products">← Products</button>' +
      '<section class="record-head"><div><p class="eyebrow">' + esc(p.code) + (p.sku ? ' · ' + esc(p.sku) : '') + '</p><h1>' + esc(p.name) + '</h1><p>' + esc(p.brand || p.category || 'Product') + (p.model ? ' · ' + esc(p.model) : '') + '</p></div><div class="record-actions"><button class="soft" id="edit-product">Edit</button>' + (p.serial_tracking ? '<button class="soft" id="add-unit">+ Serial Unit</button>' : '') + '<button class="primary" id="product-movement">+ Stock Movement</button></div></section>' +
      '<section class="metrics inventory-metrics">' +
        metric('Current stock', stock.total_quantity + ' ' + (p.unit || ''), stock.locations.length + ' location(s)') +
        metric('Selling price', moneyPaise(p.selling_price_paise), 'GST ' + ((p.gst_bps || 0) / 100) + '%') +
        metric('Serialized units', data.units.length, p.serial_tracking ? 'Unit-level tracking enabled' : 'Quantity tracked') +
      '</section>' +
      '<section class="dash-grid inventory-detail-grid"><article class="panel"><p class="eyebrow">STOCK BY LOCATION</p><div class="stock-location-list">' + locations + '</div></article><article class="panel"><p class="eyebrow">SERIALIZED UNITS</p><div class="unit-list">' + units + '</div></article><article class="panel wide"><p class="eyebrow">RECENT MOVEMENTS</p><div class="movement-list">' + movements + '</div></article></section>';

    document.querySelector('#back-products').onclick = function(){ products(w); };
    document.querySelector('#edit-product').onclick = function(){ productModal(p); };
    document.querySelector('#product-movement').onclick = function(){ movementModal(p.id); };
    const addUnit = document.querySelector('#add-unit');
    if (addUnit) addUnit.onclick = function(){ productUnitModal(p.id); };
    w.querySelectorAll('[data-unit]').forEach(function(btn){ btn.onclick = function(){ openUnit(btn.dataset.unit); }; });
    w.querySelectorAll('[data-location-stock]').forEach(function(btn){
      btn.onclick = function(){ state.view = 'inventory'; state.inventoryTab = 'locations'; shell(); };
    });
  } catch(e) { w.innerHTML = errorCard(e.message); }
}

async function inventoryWorkspace(w) {
  const tab = state.inventoryTab || 'stock';
  const tabs = [['stock','Stock'],['locations','Locations'],['movements','Movements'],['units','Serialized Units']];

  w.innerHTML =
    '<section class="page-head"><div><p class="eyebrow">OPERATIONS</p><h1>Inventory</h1><p>Stock is derived from an append-only movement ledger across hierarchical locations.</p></div><div class="record-actions"><button class="soft" id="inventory-location">+ Location</button><button class="primary" id="inventory-movement">+ Movement</button></div></section>' +
    '<div class="finance-tabs inventory-tabs">' + tabs.map(function(item){ return '<button data-inventory-tab="' + item[0] + '" class="' + (item[0] === tab ? 'active' : '') + '">' + item[1] + '</button>'; }).join('') + '</div>' +
    '<div id="inventory-content"><div class="loading">Loading inventory…</div></div>';

  w.querySelectorAll('[data-inventory-tab]').forEach(function(btn){
    btn.onclick = function(){ state.inventoryTab = btn.dataset.inventoryTab; inventoryWorkspace(w); };
  });
  document.querySelector('#inventory-location').onclick = function(){ locationModal(); };
  document.querySelector('#inventory-movement').onclick = function(){ movementModal(); };
  const content = document.querySelector('#inventory-content');

  try {
    if (tab === 'stock') {
      const payload = await api('inventory/stock');
      const rows = payload.data;
      const body = rows.length ? rows.map(function(row){
        return '<button class="inventory-stock-row" data-product-stock="' + esc(row.product_id) + '"><span><b>' + esc(row.product_name) + '</b><small>' + esc(row.product_code) + '</small></span><strong>' + esc(row.total_quantity) + '</strong><span>' + row.locations.length + ' location(s)</span></button>';
      }).join('') : '<div class="empty-state"><b>No products in stock</b><span>Receive stock into a location to start the inventory ledger.</span></div>';

      content.innerHTML = '<article class="table-card inventory-card"><div class="inventory-stock-head"><span>Product</span><span>Quantity</span><span>Locations</span></div>' + body + '</article>';
      content.querySelectorAll('[data-product-stock]').forEach(function(btn){ btn.onclick = function(){ openProduct(btn.dataset.productStock); }; });
      return;
    }

    if (tab === 'locations') {
      const payload = await api('locations/tree');
      const flat = flattenLocations(payload.data);
      const body = flat.length ? flat.map(function(row){
        return '<button class="location-row" data-location="' + esc(row.id) + '" style="--depth:' + row.depth + '"><span class="location-indent"></span><span><b>' + esc(row.name) + '</b><small>' + esc(row.code) + ' · ' + esc(String(row.type || '').replaceAll('_',' ')) + '</small></span><span><i class="pill">' + esc(row.status) + '</i></span></button>';
      }).join('') : '<div class="empty-state"><b>No locations yet</b><span>Create a warehouse, showroom, rack, workshop bay or other stock location.</span></div>';

      content.innerHTML = '<article class="panel location-panel"><div class="row"><div><p class="eyebrow">LOCATION TREE</p><h2>' + flat.length + ' locations</h2></div></div><div class="location-tree">' + body + '</div></article>';
      content.querySelectorAll('[data-location]').forEach(function(btn){ btn.onclick = function(){ openLocation(btn.dataset.location); }; });
      return;
    }

    if (tab === 'movements') {
      const results = await Promise.all([api('inventory/movements'), api('products'), api('locations')]);
      const rows = results[0].data, products = results[1].data, locations = results[2].data;
      const productMap = Object.fromEntries(products.map(function(p){ return [p.id,p]; }));
      const locationMap = Object.fromEntries(locations.map(function(l){ return [l.id,l]; }));

      const body = rows.length ? rows.map(function(m){
        const p = productMap[m.product_id] || {};
        const from = m.from_location_id ? (locationMap[m.from_location_id] || {}).name || 'Unknown' : 'External';
        const to = m.to_location_id ? (locationMap[m.to_location_id] || {}).name || 'Unknown' : 'External';
        return '<div class="movement-row"><span><b>#' + esc(m.ledger_seq || '') + ' · ' + esc(String(m.type || '').replaceAll('_',' ')) + '</b><small>' + esc(m.created_at ? new Date(m.created_at).toLocaleString() : '') + '</small></span><span><b>' + esc(p.name || m.product_id) + '</b><small>' + esc(from) + ' → ' + esc(to) + '</small></span><strong>' + esc(m.quantity) + ' ' + esc(p.unit || '') + '</strong>' + (m.type !== 'reversal' ? '<button class="soft" data-reverse-movement="' + esc(m.id) + '">Reverse</button>' : '<span><i class="pill">reversal</i></span>') + '</div>';
      }).join('') : '<div class="empty-state"><b>No stock movements</b><span>Every receive, transfer, sale, issue and correction will appear here.</span></div>';

      content.innerHTML = '<article class="panel"><p class="eyebrow">MOVEMENT LEDGER</p><div class="movement-ledger">' + body + '</div></article>';
      content.querySelectorAll('[data-reverse-movement]').forEach(function(btn){ btn.onclick = function(){ reverseMovementModal(btn.dataset.reverseMovement); }; });
      return;
    }

    if (tab === 'units') {
      const results = await Promise.all([api('product-units'), api('products')]);
      const units = results[0].data, products = results[1].data;
      const productMap = Object.fromEntries(products.map(function(p){ return [p.id,p]; }));
      const body = units.length ? units.map(function(unit){
        const p = productMap[unit.product_id] || {};
        return '<button class="serialized-row" data-unit="' + esc(unit.id) + '"><span><b>' + esc(unit.serial_no) + '</b><small>' + esc(unit.batch_no || 'No batch') + '</small></span><span><b>' + esc(p.name || 'Unknown product') + '</b><small>' + esc(p.code || '') + '</small></span><i class="pill">' + esc(unit.lifecycle_status) + '</i></button>';
      }).join('') : '<div class="empty-state"><b>No serialized units</b><span>Add serial units from a serialized Product Master record.</span></div>';

      content.innerHTML = '<article class="table-card serialized-card"><div class="serialized-head"><span>Serial</span><span>Product</span><span>Status</span></div>' + body + '</article>';
      content.querySelectorAll('[data-unit]').forEach(function(btn){ btn.onclick = function(){ openUnit(btn.dataset.unit); }; });
    }
  } catch(e) { content.innerHTML = errorCard(e.message); }
}

function flattenLocations(nodes, depth) {
  depth = depth || 0;
  let rows = [];
  (nodes || []).forEach(function(node){
    rows.push(Object.assign({}, node, {depth:depth}));
    rows = rows.concat(flattenLocations(node.children || [], depth + 1));
  });
  return rows;
}

async function openLocation(id) {
  state.view = 'inventory';
  state.inventoryTab = 'locations';
  const w = document.querySelector('#workspace');
  w.innerHTML = '<div class="loading">Loading location…</div>';
  try {
    const payload = await api('locations/' + id + '/stock');
    const data = payload.data, loc = data.location;
    const stock = data.stock.length ? data.stock.map(function(row){
      return '<button class="stock-location" data-product-stock="' + esc(row.product_id) + '"><span><b>' + esc(row.product_name) + '</b><small>' + esc(row.product_code) + '</small></span><strong>' + esc(row.quantity) + '</strong></button>';
    }).join('') : '<div class="empty-small">No stock at this location.</div>';

    w.innerHTML =
      '<button class="back" id="back-locations">← Locations</button>' +
      '<section class="record-head"><div><p class="eyebrow">' + esc(loc.code) + ' · ' + esc(String(loc.type || '').replaceAll('_',' ')) + '</p><h1>' + esc(loc.name) + '</h1><p>' + esc(loc.address || 'No address') + '</p></div><div class="record-actions"><button class="soft" id="edit-location">Edit</button><button class="primary" id="location-movement">+ Movement</button></div></section>' +
      '<section class="dash-grid"><article class="panel wide"><p class="eyebrow">STOCK AT LOCATION</p><div class="stock-location-list">' + stock + '</div></article></section>';

    document.querySelector('#back-locations').onclick = function(){ inventoryWorkspace(w); };
    document.querySelector('#edit-location').onclick = function(){ locationModal(loc); };
    document.querySelector('#location-movement').onclick = function(){ movementModal(null,null,loc.id); };
    w.querySelectorAll('[data-product-stock]').forEach(function(btn){ btn.onclick = function(){ openProduct(btn.dataset.productStock); }; });
  } catch(e) { w.innerHTML = errorCard(e.message); }
}

async function locationModal(location) {
  location = location || null;
  try {
    const payload = await api('locations');
    const rows = payload.data.filter(function(r){ return !location || r.id !== location.id; });
    const parentOptions = '<option value="">No parent</option>' + rows.map(function(r){
      return '<option value="' + esc(r.id) + '"' + (location && location.parent_id === r.id ? ' selected' : '') + '>' + esc(r.name) + ' · ' + esc(r.code) + '</option>';
    }).join('');
    const types = payload.types.map(function(type){
      return '<option value="' + esc(type) + '"' + (location && location.type === type ? ' selected' : '') + '>' + esc(type.replaceAll('_',' ')) + '</option>';
    }).join('');

    modal(location ? 'Edit location' : 'New location',
      '<label class="full">Location name<input required name="name" value="' + esc(location ? location.name : '') + '"></label>' +
      '<label>Type<select required name="type">' + types + '</select></label><label>Parent<select name="parent_id">' + parentOptions + '</select></label>' +
      '<label class="full">Address<input name="address" value="' + esc(location ? location.address || '' : '') + '"></label>' +
      '<label>Latitude<input name="latitude" type="number" step="any" value="' + esc(location && location.latitude != null ? location.latitude : '') + '"></label><label>Longitude<input name="longitude" type="number" step="any" value="' + esc(location && location.longitude != null ? location.longitude : '') + '"></label>' +
      '<label>Contact name<input name="contact_name" value="' + esc(location ? location.contact_name || '' : '') + '"></label><label>Contact mobile<input name="contact_mobile" value="' + esc(location ? location.contact_mobile || '' : '') + '"></label>' +
      (location ? '<label>Status<select name="status"><option value="active"' + (location.status === 'active' ? ' selected' : '') + '>Active</option><option value="inactive"' + (location.status === 'inactive' ? ' selected' : '') + '>Inactive</option><option value="archived"' + (location.status === 'archived' ? ' selected' : '') + '>Archived</option></select></label>' : ''),
      async function(payload){
        const result = await api(location ? 'locations/' + location.id : 'locations',{method:location ? 'PATCH' : 'POST',body:payload});
        return function(){ openLocation(result.data.id); };
      }
    );
  } catch(e) { toast(e.message,true); }
}

async function movementModal(productId, unitId, locationId) {
  productId = productId || '';
  unitId = unitId || '';
  locationId = locationId || '';
  try {
    const results = await Promise.all([api('products'), api('locations'), api('product-units')]);
    const products = results[0].data.filter(function(p){ return p.status === 'active'; });
    const locations = results[1].data.filter(function(l){ return l.status === 'active'; });
    const units = results[2].data.filter(function(u){ return u.lifecycle_status === 'active'; });

    const productOptions = products.map(function(p){ return '<option value="' + esc(p.id) + '"' + (p.id === productId ? ' selected' : '') + '>' + esc(p.name) + ' · ' + esc(p.code) + '</option>'; }).join('');
    const locationOptions = '<option value="">External / none</option>' + locations.map(function(l){ return '<option value="' + esc(l.id) + '"' + (l.id === locationId ? ' selected' : '') + '>' + esc(l.name) + ' · ' + esc(l.code) + '</option>'; }).join('');
    const unitOptions = '<option value="">Not serialized</option>' + units.map(function(u){ return '<option value="' + esc(u.id) + '"' + (u.id === unitId ? ' selected' : '') + '>' + esc(u.serial_no) + '</option>'; }).join('');

    modal('Stock movement',
      '<label class="full">Product<select required name="product_id"><option value="">Select product</option>' + productOptions + '</select></label>' +
      '<label>Type<select required name="type"><option value="receive">Receive</option><option value="transfer">Transfer</option><option value="issue">Issue</option><option value="return">Return</option><option value="sale">Sale</option><option value="customer_return">Customer Return</option><option value="workshop_in">Workshop In</option><option value="workshop_out">Workshop Out</option><option value="adjustment">Adjustment</option><option value="damaged">Damaged</option><option value="lost">Lost</option><option value="scrap">Scrap</option></select></label>' +
      '<label>Quantity<input required name="quantity" type="number" min="0.001" step="0.001" value="1"></label>' +
      '<label>Source location<select name="from_location_id">' + locationOptions + '</select></label><label>Destination location<select name="to_location_id">' + locationOptions + '</select></label>' +
      '<label>Serialized unit<select name="product_unit_id">' + unitOptions + '</select></label><label>Adjustment direction<select name="adjustment_direction"><option value="">Not adjustment</option><option value="increase">Increase</option><option value="decrease">Decrease</option></select></label>' +
      '<label>Reference type<input name="reference_type" placeholder="invoice / workshop job / PO"></label><label>Reference ID<input name="reference_id"></label>' +
      '<label class="full">Notes<textarea name="notes"></textarea></label>',
      async function(payload){
        await api('inventory/movements',{method:'POST',body:payload});
        return function(){
          if (unitId) openUnit(unitId);
          else if (productId) openProduct(productId);
          else { state.view='inventory'; state.inventoryTab='movements'; shell(); }
        };
      }
    );
  } catch(e) { toast(e.message,true); }
}

function reverseMovementModal(id) {
  modal('Reverse stock movement',
    '<label class="full">Reason<textarea required name="reason" placeholder="The original movement stays in history; this creates the opposite movement."></textarea></label>',
    async function(payload){
      await api('inventory/movements/' + id + '/reverse',{method:'POST',body:payload});
      return function(){ state.view='inventory'; state.inventoryTab='movements'; shell(); };
    }
  );
}

async function productUnitModal(productId) {
  productId = productId || '';
  try {
    const products = (await api('products')).data.filter(function(p){ return p.status === 'active' && p.serial_tracking; });
    if (!products.length) { toast('Enable serial tracking on a Product Master record first', true); return; }
    const options = products.map(function(p){ return '<option value="' + esc(p.id) + '"' + (p.id === productId ? ' selected' : '') + '>' + esc(p.name) + ' · ' + esc(p.code) + '</option>'; }).join('');

    modal('New serialized unit',
      '<label class="full">Product<select required name="product_id">' + options + '</select></label><label>Serial number<input required name="serial_no"></label><label>Batch number<input name="batch_no"></label><label class="full">Notes<textarea name="notes"></textarea></label>',
      async function(payload){
        const result = await api('product-units',{method:'POST',body:payload});
        return function(){ openUnit(result.data.id); };
      }
    );
  } catch(e) { toast(e.message,true); }
}

async function openUnit(id) {
  state.view = 'inventory';
  state.inventoryTab = 'units';
  const w = document.querySelector('#workspace');
  w.innerHTML = '<div class="loading">Loading serialized unit…</div>';

  try {
    const payload = await api('product-units/' + id);
    const data = payload.data, unit = data.unit;
    const product = (await api('products/' + unit.product_id)).data;
    const qrValue = 'ecrm:unit:' + unit.qr_token;
    const qrUrl = await QRCode.toDataURL(qrValue,{width:280,margin:1,errorCorrectionLevel:'M'});

    const history = data.history.length ? data.history.map(function(m){
      return '<div class="movement-mini"><span><b>#' + esc(m.ledger_seq || '') + ' · ' + esc(String(m.type || '').replaceAll('_',' ')) + '</b><small>' + esc(m.created_at ? new Date(m.created_at).toLocaleString() : '') + '</small></span><strong>' + esc(m.quantity) + '</strong></div>';
    }).join('') : '<div class="empty-small">No movement history. Receive the unit into stock first.</div>';

    w.innerHTML =
      '<button class="back" id="back-units">← Serialized Units</button>' +
      '<section class="record-head"><div><p class="eyebrow">' + esc(product.code) + ' · SERIALIZED UNIT</p><h1>' + esc(unit.serial_no) + '</h1><p>' + esc(product.name) + (unit.batch_no ? ' · Batch ' + esc(unit.batch_no) : '') + '</p></div><div class="record-actions"><button class="soft" id="print-unit-qr">Print QR</button><button class="primary" id="move-unit">+ Movement</button></div></section>' +
      '<section class="dash-grid unit-detail-grid"><article class="panel qr-panel"><p class="eyebrow">UNIT QR</p><img class="qr-image" src="' + esc(qrUrl) + '" alt="QR for ' + esc(unit.serial_no) + '"><b>' + esc(unit.serial_no) + '</b><small>' + esc(unit.qr_token) + '</small></article><article class="panel"><p class="eyebrow">CURRENT STATE</p><dl><dt>Location</dt><dd>' + esc(data.location ? data.location.name : 'Outside stock / not received') + '</dd><dt>Status</dt><dd>' + esc(data.operational_status) + '</dd><dt>Lifecycle</dt><dd>' + esc(unit.lifecycle_status) + '</dd></dl>' + (unit.lifecycle_status === 'active' && !data.location_id ? '<button class="soft" id="retire-unit">Retire unit</button>' : '') + '</article><article class="panel wide"><p class="eyebrow">UNIT MOVEMENT HISTORY</p><div class="movement-list">' + history + '</div></article></section>';

    document.querySelector('#back-units').onclick = function(){ inventoryWorkspace(w); };
    document.querySelector('#print-unit-qr').onclick = function(){ window.print(); };
    document.querySelector('#move-unit').onclick = function(){ movementModal(unit.product_id,unit.id,data.location_id || ''); };
    const retire = document.querySelector('#retire-unit');
    if (retire) retire.onclick = function(){ retireUnitModal(unit); };
  } catch(e) { w.innerHTML = errorCard(e.message); }
}

function retireUnitModal(unit) {
  modal('Retire serialized unit',
    '<label class="full">Reason<textarea required name="reason"></textarea></label>',
    async function(payload){
      await api('product-units/' + unit.id + '/retire',{method:'POST',body:payload});
      return function(){ openUnit(unit.id); };
    }
  );
}

function normalizeQrToken(value) {
  let raw = String(value || '').trim();
  if (raw.toLowerCase().startsWith('ecrm:unit:')) raw = raw.slice(10);
  return raw;
}

async function resolveQr(value) {
  const token = normalizeQrToken(value);
  if (!token) throw new Error('QR token is empty');
  const payload = await api('qr/unit/' + encodeURIComponent(token));
  document.querySelector('#modal-root').innerHTML = '';
  openUnit(payload.data.unit.id);
}

function qrScanModal() {
  const root = document.querySelector('#modal-root');
  let stream = null;
  let scanning = false;

  function close() {
    scanning = false;
    if (stream) stream.getTracks().forEach(function(track){ track.stop(); });
    root.innerHTML = '';
  }

  root.innerHTML =
    '<div class="modal-backdrop"><div class="modal qr-scan-modal"><div class="modal-head"><div><p class="eyebrow">INVENTORY</p><h2>Scan unit QR</h2></div><button type="button" class="icon-btn" id="close-qr-scan">×</button></div><video id="qr-video" playsinline muted class="qr-video hidden"></video><form id="qr-form" class="qr-manual"><label>QR token or code<input autofocus name="token" placeholder="ecrm:unit:…"></label><button class="primary" type="submit">Open Unit</button></form><div class="modal-actions"><button type="button" class="soft" id="qr-camera">Use Camera</button></div></div></div>';

  document.querySelector('#close-qr-scan').onclick = close;
  document.querySelector('#qr-form').onsubmit = async function(e){
    e.preventDefault();
    try { await resolveQr(new FormData(e.currentTarget).get('token')); }
    catch(err) { toast(err.message,true); }
  };

  document.querySelector('#qr-camera').onclick = async function(){
    if (!('BarcodeDetector' in window) || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      toast('Camera QR detection is not supported in this browser. Enter the QR code manually.', true);
      return;
    }

    try {
      const detector = new BarcodeDetector({formats:['qr_code']});
      stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});
      const video = document.querySelector('#qr-video');
      video.srcObject = stream;
      video.classList.remove('hidden');
      await video.play();
      scanning = true;

      const scan = async function(){
        if (!scanning) return;
        try {
          const codes = await detector.detect(video);
          if (codes.length && codes[0].rawValue) {
            scanning = false;
            await resolveQr(codes[0].rawValue);
            if (stream) stream.getTracks().forEach(function(track){ track.stop(); });
            return;
          }
        } catch(e) {}
        requestAnimationFrame(scan);
      };
      scan();
    } catch(err) { toast('Camera could not start: ' + err.message,true); }
  };
}

async function quotations(w) {
  const payload = await api('quotations');
  const rows = payload.data;
  let body = '<div class="empty-state"><b>No quotations yet</b><span>Create a quotation from Product Master items or custom lines.</span></div>';
  if (rows.length) {
    body = rows.map(function(q){
      return '<button class="sales-row" data-open-quote="' + esc(q.id) + '"><span><b>' + esc(q.number) + '</b><small>v' + esc(q.version) + '</small></span><span><b>' + esc(q.customer_snapshot && q.customer_snapshot.name) + '</b><small>' + esc(q.customer_snapshot && q.customer_snapshot.mobile) + '</small></span><span><b>' + moneyPaise(q.totals && q.totals.grand_total_paise) + '</b><small>' + (q.items ? q.items.length : 0) + ' line(s)</small></span><span><i class="pill">' + esc(q.status) + '</i></span></button>';
    }).join('');
  }

  w.innerHTML =
    '<section class="page-head"><div><p class="eyebrow">SALES</p><h1>Quotations</h1><p>Commercial offers with exact tax snapshots, approval states and invoice conversion.</p></div><button class="primary" id="new-quotation">+ Quotation</button></section>' +
    '<article class="table-card sales-card"><div class="sales-head"><span>Quotation</span><span>Customer</span><span>Total</span><span>Status</span></div>' + body + '</article>';

  document.querySelector('#new-quotation').onclick = function(){ salesDocumentModal('quotation'); };
  w.querySelectorAll('[data-open-quote]').forEach(function(btn){ btn.onclick = function(){ openQuotation(btn.dataset.openQuote); }; });
}

async function openQuotation(id) {
  state.view = 'quotations';
  const w = document.querySelector('#workspace');
  w.innerHTML = '<div class="loading">Loading quotation…</div>';
  try {
    const payload = await api('quotations/' + id);
    const q = payload.data;
    const actions = quotationActions(q);
    w.innerHTML =
      '<button class="back" id="back-quotes">← Quotations</button>' +
      '<section class="record-head"><div><p class="eyebrow">' + esc(q.number) + ' · VERSION ' + esc(q.version) + '</p><h1>' + esc(q.customer_snapshot.name) + '</h1><p>' + esc(q.status) + (q.valid_until ? ' · valid until ' + esc(q.valid_until) : '') + '</p></div><div class="record-actions">' + actions + '</div></section>' +
      salesDocumentDetail(q, 'Quotation') +
      '<section class="dash-grid"><article class="panel"><p class="eyebrow">PAYMENT TERMS</p><p class="document-note">' + esc(q.payment_terms || '—') + '</p></article><article class="panel"><p class="eyebrow">DELIVERY / NOTES</p><p class="document-note">' + esc(q.delivery_terms || q.notes || '—') + '</p></article></section>';

    document.querySelector('#back-quotes').onclick = function(){ quotations(w); };
    bindQuotationActions(q, w);
  } catch(e) { w.innerHTML = errorCard(e.message); }
}

function quotationActions(q) {
  let html = '<button class="soft" id="print-current">Print / PDF</button>';
  if (['draft','sent','viewed','revised'].includes(q.status)) html += '<button class="soft" id="edit-quotation">Edit</button>';
  if (q.status === 'draft' || q.status === 'revised') html += '<button class="soft" data-qstatus="sent">Mark Sent</button>';
  if (q.status === 'sent') html += '<button class="soft" data-qstatus="viewed">Mark Viewed</button>';
  if (['draft','sent','viewed','revised'].includes(q.status)) html += '<button class="primary" data-qstatus="approved">Approve</button>';
  if (['draft','sent','viewed','revised'].includes(q.status)) html += '<button class="soft" data-qstatus="rejected">Reject</button>';
  if (q.status === 'approved') html += '<button class="primary" id="quote-to-invoice">Create Invoice</button>';
  return html;
}

function bindQuotationActions(q, w) {
  const printButton = document.querySelector('#print-current');
  if (printButton) printButton.onclick = function(){ window.print(); };
  const editButton = document.querySelector('#edit-quotation');
  if (editButton) editButton.onclick = function(){ salesDocumentModal('quotation', q); };
  w.querySelectorAll('[data-qstatus]').forEach(function(btn){
    btn.onclick = async function(){
      try {
        await api('quotations/' + q.id + '/status',{method:'PATCH',body:{status:btn.dataset.qstatus}});
        toast('Quotation status updated');
        openQuotation(q.id);
      } catch(e) { toast(e.message,true); }
    };
  });
  const invoice = document.querySelector('#quote-to-invoice');
  if (invoice) invoice.onclick = async function(){
    try {
      const result = await api('quotations/' + q.id + '/invoice',{method:'POST',body:{}});
      toast('Invoice ' + result.data.number + ' created');
      openInvoice(result.data.id);
    } catch(e) { toast(e.message,true); }
  };
}

async function invoices(w) {
  const payload = await api('invoices');
  const rows = payload.data;
  let body = '<div class="empty-state"><b>No invoices yet</b><span>Create a standalone invoice or convert an approved quotation.</span></div>';
  if (rows.length) {
    body = rows.map(function(inv){
      return '<button class="sales-row" data-open-invoice="' + esc(inv.id) + '"><span><b>' + esc(inv.number) + '</b><small>' + (inv.quotation_id ? 'From quotation' : 'Standalone') + '</small></span><span><b>' + esc(inv.customer_snapshot && inv.customer_snapshot.name) + '</b><small>' + esc(inv.due_date || 'No due date') + '</small></span><span><b>' + moneyPaise(inv.totals && inv.totals.grand_total_paise) + '</b><small>' + (inv.items ? inv.items.length : 0) + ' line(s)</small></span><span><i class="pill">' + esc(inv.status) + '</i></span></button>';
    }).join('');
  }

  w.innerHTML =
    '<section class="page-head"><div><p class="eyebrow">SALES</p><h1>Invoices</h1><p>Standalone or quotation-derived invoices with immutable issued snapshots.</p></div><button class="primary" id="new-invoice">+ Invoice</button></section>' +
    '<article class="table-card sales-card"><div class="sales-head"><span>Invoice</span><span>Customer</span><span>Total</span><span>Status</span></div>' + body + '</article>';

  document.querySelector('#new-invoice').onclick = function(){ salesDocumentModal('invoice'); };
  w.querySelectorAll('[data-open-invoice]').forEach(function(btn){ btn.onclick = function(){ openInvoice(btn.dataset.openInvoice); }; });
}

async function openInvoice(id) {
  state.view = 'invoices';
  const w = document.querySelector('#workspace');
  w.innerHTML = '<div class="loading">Loading invoice…</div>';
  try {
    const results = await Promise.all([api('invoices/' + id), api('invoices/' + id + '/receivable')]);
    const inv = results[0].data;
    const receivable = results[1].data;
    let actions = '<button class="soft" id="print-current">Print / PDF</button>';
    if (['issued','partially_paid'].includes(inv.status) && receivable.outstanding_paise > 0) actions += '<button class="primary" id="invoice-payment">+ Payment</button>';
    if (inv.status === 'draft') actions += '<button class="soft" id="edit-invoice">Edit</button><button class="primary" id="issue-invoice">Issue Invoice</button>';
    if (['draft','issued'].includes(inv.status)) actions += '<button class="soft" id="void-invoice">Void</button>';

    w.innerHTML =
      '<button class="back" id="back-invoices">← Invoices</button>' +
      '<section class="record-head"><div><p class="eyebrow">' + esc(inv.number) + '</p><h1>' + esc(inv.customer_snapshot.name) + '</h1><p>' + esc(inv.status) + (inv.due_date ? ' · due ' + esc(inv.due_date) : '') + '</p></div><div class="record-actions">' + actions + '</div></section>' +
      salesDocumentDetail(inv, 'Invoice') +
      '<section class="metrics finance-metrics invoice-finance"><article><span>Allocated</span><strong>' + moneyPaise(receivable.allocated_paise) + '</strong><small>Applied receipts</small></article><article><span>Outstanding</span><strong>' + moneyPaise(receivable.outstanding_paise) + '</strong><small>' + esc(ageingLabel(receivable.ageing_bucket)) + '</small></article></section>' +
      '<section class="dash-grid"><article class="panel"><p class="eyebrow">TERMS</p><p class="document-note">' + esc(inv.terms || '—') + '</p></article><article class="panel"><p class="eyebrow">SOURCE</p><p class="document-note">' + (inv.quotation_id ? 'Approved quotation linked' : 'Standalone invoice') + '</p></article></section>';

    document.querySelector('#back-invoices').onclick = function(){ invoices(w); };
    const printButton = document.querySelector('#print-current');
    if (printButton) printButton.onclick = function(){ window.print(); };
    const editButton = document.querySelector('#edit-invoice');
    if (editButton) editButton.onclick = function(){ salesDocumentModal('invoice', inv); };
    const invoicePayment = document.querySelector('#invoice-payment');
    if (invoicePayment) invoicePayment.onclick = function(){ paymentModal(inv.customer_id); };
    const issue = document.querySelector('#issue-invoice');
    if (issue) issue.onclick = async function(){
      try { await api('invoices/' + inv.id + '/issue',{method:'POST'}); toast('Invoice issued and locked'); openInvoice(inv.id); }
      catch(e) { toast(e.message,true); }
    };
    const voidButton = document.querySelector('#void-invoice');
    if (voidButton) voidButton.onclick = function(){ voidInvoiceModal(inv); };
  } catch(e) { w.innerHTML = errorCard(e.message); }
}

function salesDocumentDetail(record, kind) {
  const lines = (record.items || []).map(function(line){
    return '<div class="doc-line"><span><b>' + esc(line.description) + '</b><small>' + esc(line.hsn_sac || '') + (line.unit ? ' · ' + esc(line.unit) : '') + '</small></span><span>' + esc(line.quantity) + '</span><span>' + moneyPaise(line.rate_paise) + '</span><span>' + ((line.discount_bps || 0) / 100) + '%</span><span>' + ((line.tax_bps || 0) / 100) + '%</span><strong>' + moneyPaise(line.line_total_paise) + '</strong></div>';
  }).join('');

  const t = record.totals || {};
  return '<article class="panel document-panel"><div class="document-customer"><div><p class="eyebrow">' + esc(kind.toUpperCase()) + ' TO</p><h2>' + esc(record.customer_snapshot.name) + '</h2><p>' + esc(record.customer_snapshot.gstin || '') + '</p></div><div><p class="eyebrow">ADDRESS</p><p>' + esc(record.address_snapshot ? [record.address_snapshot.address,record.address_snapshot.city,record.address_snapshot.state,record.address_snapshot.pin].filter(Boolean).join(', ') : 'No address selected') + '</p></div></div><div class="doc-head"><span>Item</span><span>Qty</span><span>Rate</span><span>Disc.</span><span>Tax</span><span>Total</span></div><div class="doc-lines">' + lines + '</div><div class="doc-totals"><span>Taxable <b>' + moneyPaise(t.taxable_paise) + '</b></span><span>Tax <b>' + moneyPaise(t.tax_paise) + '</b></span><span>Round off <b>' + moneyPaise(t.round_off_paise) + '</b></span><strong>Grand Total ' + moneyPaise(t.grand_total_paise) + '</strong></div></article>';
}

function voidInvoiceModal(invoice) {
  modal('Void invoice',
    '<label class="full">Reason<textarea required name="reason" placeholder="Reason is recorded in the audit ledger"></textarea></label>',
    async function(payload){
      await api('invoices/' + invoice.id + '/void',{method:'POST',body:payload});
      return function(){ openInvoice(invoice.id); };
    }
  );
}

async function salesDocumentModal(kind, record) {
  record = record || null;
  const root = document.querySelector('#modal-root');

  try {
    const results = await Promise.all([api('customers'), api('products')]);
    const customers = results[0].data;
    const products = results[1].data.filter(function(p){ return p.status === 'active'; });
    if (!customers.length) { toast('Create a customer before creating sales documents', true); return; }

    const customerOptions = customers.map(function(c){
      return '<option value="' + esc(c.id) + '"' + (record && record.customer_id === c.id ? ' selected' : '') + '>' + esc(c.name) + ' · ' + esc(c.number) + '</option>';
    }).join('');
    const productOptions = '<option value="">Custom item</option>' + products.map(function(p){
      return '<option value="' + esc(p.id) + '">' + esc(p.name) + (p.sku ? ' · ' + esc(p.sku) : '') + '</option>';
    }).join('');

    const title = (record ? 'Edit ' : 'New ') + (kind === 'quotation' ? 'quotation' : 'invoice');
    const taxMode = record ? record.tax_mode : 'intra_state';

    root.innerHTML =
      '<div class="modal-backdrop"><form class="modal sales-modal"><div class="modal-head"><div><p class="eyebrow">SALES</p><h2>' + title + '</h2></div><button type="button" class="icon-btn" data-close>×</button></div>' +
      '<div class="form-grid"><label>Customer<select id="sales-customer" required name="customer_id"><option value="">Select customer</option>' + customerOptions + '</select></label><label>Address<select id="sales-address" name="address_id"><option value="">No address</option></select></label><label>Tax mode<select name="tax_mode"><option value="intra_state"' + (taxMode === 'intra_state' ? ' selected' : '') + '>CGST + SGST</option><option value="inter_state"' + (taxMode === 'inter_state' ? ' selected' : '') + '>IGST</option></select></label>' +
      (kind === 'quotation'
        ? '<label>Valid until<input type="date" name="valid_until" value="' + esc(record ? record.valid_until || '' : '') + '"></label><label class="full">Payment terms<input name="payment_terms" value="' + esc(record ? record.payment_terms || '' : '') + '" placeholder="e.g. 50% advance"></label><label class="full">Delivery terms<input name="delivery_terms" value="' + esc(record ? record.delivery_terms || '' : '') + '"></label>'
        : '<label>Due date<input type="date" name="due_date" value="' + esc(record ? record.due_date || '' : '') + '"></label><label class="full">Terms<input name="terms" value="' + esc(record ? record.terms || '' : '') + '" placeholder="Payment terms"></label>') +
      '</div><div class="sales-lines-head"><p class="eyebrow">LINE ITEMS</p><button type="button" class="soft" id="add-sales-line">+ Line</button></div><div id="sales-lines"></div><label class="sales-note">Notes<textarea name="notes">' + esc(record ? record.notes || '' : '') + '</textarea></label><div class="modal-actions"><button type="button" class="soft" data-close>Cancel</button><button class="primary" type="submit">Save ' + (kind === 'quotation' ? 'Quotation' : 'Invoice') + '</button></div></form></div>';

    root.querySelectorAll('[data-close]').forEach(function(btn){ btn.onclick = function(){ root.innerHTML = ''; }; });
    const lines = root.querySelector('#sales-lines');

    function addLine(item) {
      item = item || {};
      const row = document.createElement('div');
      row.className = 'sales-line-edit';
      row.innerHTML =
        '<select class="line-product">' + productOptions + '</select>' +
        '<input class="line-description" required placeholder="Description" value="' + esc(item.description || '') + '">' +
        '<input class="line-qty" type="number" required min="0.001" step="0.001" value="' + esc(item.quantity || 1) + '" placeholder="Qty">' +
        '<input class="line-rate" type="number" required min="0" step="0.01" value="' + esc(item.rate_paise != null ? (item.rate_paise / 100).toFixed(2) : '') + '" placeholder="Rate">' +
        '<input class="line-discount" type="number" min="0" max="100" step="0.01" value="' + esc(item.discount_bps != null ? item.discount_bps / 100 : 0) + '" placeholder="Disc %">' +
        '<input class="line-tax" type="number" min="0" max="100" step="0.01" value="' + esc(item.tax_bps != null ? item.tax_bps / 100 : 0) + '" placeholder="Tax %">' +
        '<input class="line-unit" value="' + esc(item.unit || 'Nos') + '" placeholder="Unit">' +
        '<input class="line-hsn" value="' + esc(item.hsn_sac || '') + '" placeholder="HSN/SAC">' +
        '<button type="button" class="icon-btn remove-line">×</button>';

      lines.appendChild(row);
      if (item.product_id) row.querySelector('.line-product').value = item.product_id;

      row.querySelector('.remove-line').onclick = function(){ if (lines.children.length > 1) row.remove(); };
      row.querySelector('.line-product').onchange = function(e){
        const product = products.find(function(p){ return p.id === e.target.value; });
        if (!product) return;
        row.querySelector('.line-description').value = product.name || '';
        row.querySelector('.line-rate').value = ((product.selling_price_paise || 0) / 100).toFixed(2);
        row.querySelector('.line-tax').value = ((product.gst_bps || 0) / 100).toFixed(2);
        row.querySelector('.line-unit').value = product.unit || 'Nos';
        row.querySelector('.line-hsn').value = product.hsn_sac || '';
      };
    }

    if (record && record.items && record.items.length) record.items.forEach(addLine);
    else addLine();

    root.querySelector('#add-sales-line').onclick = function(){ addLine(); };

    async function loadAddresses(customerId) {
      const addressSelect = root.querySelector('#sales-address');
      addressSelect.innerHTML = '<option value="">No address</option>';
      if (!customerId) return;
      const detail = await api('customers/' + customerId + '/overview');
      detail.data.addresses.forEach(function(a){
        const option = document.createElement('option');
        option.value = a.id;
        option.textContent = (a.label || a.type) + ' · ' + (a.city || a.address);
        if (record && record.address_id === a.id) option.selected = true;
        addressSelect.appendChild(option);
      });
    }

    root.querySelector('#sales-customer').onchange = async function(e){
      try { await loadAddresses(e.target.value); } catch(err) { toast(err.message,true); }
    };
    if (record && record.customer_id) await loadAddresses(record.customer_id);

    root.querySelector('form').onsubmit = async function(e){
      e.preventDefault();
      const form = new FormData(e.currentTarget);
      const payload = Object.fromEntries(form.entries());
      payload.items = Array.from(lines.querySelectorAll('.sales-line-edit')).map(function(row){
        return {
          product_id: row.querySelector('.line-product').value || null,
          description: row.querySelector('.line-description').value,
          quantity: row.querySelector('.line-qty').value,
          rate: row.querySelector('.line-rate').value,
          discount_percent: row.querySelector('.line-discount').value,
          tax_percent: row.querySelector('.line-tax').value,
          unit: row.querySelector('.line-unit').value,
          hsn_sac: row.querySelector('.line-hsn').value
        };
      });

      const endpoint = kind === 'quotation' ? 'quotations' : 'invoices';
      try {
        const result = await api(record ? endpoint + '/' + record.id : endpoint, {
          method: record ? 'PATCH' : 'POST',
          body: payload
        });
        root.innerHTML = '';
        toast((kind === 'quotation' ? 'Quotation ' : 'Invoice ') + result.data.number + (record ? ' updated' : ' created'));
        if (kind === 'quotation') openQuotation(result.data.id); else openInvoice(result.data.id);
      } catch(err) { toast(err.message,true); }
    };
  } catch(e) { toast(e.message,true); }
}

async function paymentsWorkspace(w) {
  const tab = state.financeTab || 'receipts';
  const tabs = [
    ['receipts','Receipts'],
    ['outstanding','Outstanding'],
    ['ageing','Ageing'],
    ['batches','Batches']
  ];

  w.innerHTML =
    '<section class="page-head"><div><p class="eyebrow">FINANCE</p><h1>Payments & Receivables</h1><p>Independent receipts, invoice allocations, outstanding balances and ageing.</p></div><div class="record-actions"><button class="soft" id="new-batch">+ Batch</button><button class="primary" id="new-payment">+ Payment</button></div></section>' +
    '<div class="finance-tabs">' + tabs.map(function(item){ return '<button data-finance-tab="' + item[0] + '" class="' + (item[0] === tab ? 'active' : '') + '">' + item[1] + '</button>'; }).join('') + '</div>' +
    '<div id="finance-content"><div class="loading">Loading finance…</div></div>';

  w.querySelectorAll('[data-finance-tab]').forEach(function(btn){
    btn.onclick = function(){ state.financeTab = btn.dataset.financeTab; paymentsWorkspace(w); };
  });
  document.querySelector('#new-payment').onclick = function(){ paymentModal(); };
  document.querySelector('#new-batch').onclick = function(){ paymentBatchModal(); };

  const content = document.querySelector('#finance-content');

  try {
    if (tab === 'receipts') {
      const results = await Promise.all([api('payments'), api('receivables/totals')]);
      const payments = results[0].data;
      const totals = results[1].data;
      const body = payments.length ? payments.map(function(p){
        return '<button class="finance-row" data-open-payment="' + esc(p.id) + '"><span><b>' + esc(p.number) + '</b><small>' + esc(p.received_at ? new Date(p.received_at).toLocaleDateString() : '') + '</small></span><span><b>' + esc(paymentMethodLabel(p.method)) + '</b><small>' + esc(p.reference || 'No reference') + '</small></span><span><b>' + moneyPaise(p.amount_paise) + '</b><small>' + esc(p.status) + '</small></span></button>';
      }).join('') : '<div class="empty-state"><b>No receipts yet</b><span>Record the first payment without mutating invoice history.</span></div>';

      content.innerHTML =
        '<section class="metrics finance-metrics">' +
          metric('Collected', moneyPaise(totals.collected_paise), 'Posted receipts') +
          metric('Outstanding', moneyPaise(totals.outstanding_paise), 'Invoice allocation balance') +
          metric('Overdue', moneyPaise(totals.overdue_paise), 'Past due') +
          metric('Unallocated credit', moneyPaise(totals.unallocated_credit_paise), 'Available to allocate') +
        '</section>' +
        '<article class="table-card finance-card"><div class="finance-head"><span>Receipt</span><span>Method / Reference</span><span>Amount / Status</span></div>' + body + '</article>';

      content.querySelectorAll('[data-open-payment]').forEach(function(btn){ btn.onclick = function(){ openPayment(btn.dataset.openPayment); }; });
      return;
    }

    if (tab === 'outstanding') {
      const payload = await api('receivables/outstanding');
      const rows = payload.data;
      const body = rows.length ? rows.map(function(row){
        return '<button class="finance-row four" data-finance-invoice="' + esc(row.invoice_id) + '"><span><b>' + esc(row.number) + '</b><small>' + esc(row.customer_name || '') + '</small></span><span><b>' + moneyPaise(row.outstanding_paise) + '</b><small>of ' + moneyPaise(row.total_paise) + '</small></span><span><b>' + esc(row.due_date || 'No due date') + '</b><small>' + esc(ageingLabel(row.ageing_bucket)) + '</small></span><span><i class="pill">' + esc(row.status) + '</i></span></button>';
      }).join('') : '<div class="empty-state"><b>No outstanding invoices</b><span>Issued invoices with unpaid balances will appear here.</span></div>';

      content.innerHTML = '<article class="table-card finance-card"><div class="finance-head four"><span>Invoice / Customer</span><span>Outstanding</span><span>Due / Ageing</span><span>Status</span></div>' + body + '</article>';
      content.querySelectorAll('[data-finance-invoice]').forEach(function(btn){ btn.onclick = function(){ openInvoice(btn.dataset.financeInvoice); }; });
      return;
    }

    if (tab === 'ageing') {
      const payload = await api('receivables/ageing');
      const data = payload.data;
      const keys = ['current','1_30','31_60','61_90','90_plus'];
      content.innerHTML =
        '<section class="metrics finance-metrics">' + keys.map(function(key){
          const bucket = data.buckets[key];
          return metric(bucket.label, moneyPaise(bucket.amount_paise), bucket.count + ' invoice' + (bucket.count === 1 ? '' : 's'));
        }).join('') + '</section>' +
        '<article class="panel ageing-panel"><div class="row"><div><p class="eyebrow">AGEING DETAIL</p><h2>' + moneyPaise(data.total_outstanding_paise) + ' outstanding</h2></div><span class="pill">As of ' + esc(data.as_of) + '</span></div>' +
        keys.map(function(key){
          const bucket = data.buckets[key];
          if (!bucket.invoices.length) return '';
          return '<section class="ageing-group"><div class="attention-title"><b>' + esc(bucket.label) + '</b><span>' + bucket.count + '</span></div>' + bucket.invoices.map(function(row){
            return '<button data-finance-invoice="' + esc(row.invoice_id) + '"><span><b>' + esc(row.number) + '</b><small>' + esc(row.customer_name || '') + '</small></span><strong>' + moneyPaise(row.outstanding_paise) + '</strong></button>';
          }).join('') + '</section>';
        }).join('') + '</article>';

      content.querySelectorAll('[data-finance-invoice]').forEach(function(btn){ btn.onclick = function(){ openInvoice(btn.dataset.financeInvoice); }; });
      return;
    }

    if (tab === 'batches') {
      const payload = await api('payment-batches');
      const rows = payload.data;
      const body = rows.length ? rows.map(function(batch){
        return '<div class="batch-row"><span><b>' + esc(batch.number) + '</b><small>' + esc(batch.title) + ' · ' + esc(batch.batch_date) + '</small></span><span><i class="pill">' + esc(batch.status) + '</i></span>' + (batch.status === 'open' ? '<button class="soft" data-close-batch="' + esc(batch.id) + '">Close</button>' : '<span></span>') + '</div>';
      }).join('') : '<div class="empty-state"><b>No payment batches</b><span>Batches can group bank or counter receipts without changing individual payment identity.</span></div>';

      content.innerHTML = '<article class="panel"><p class="eyebrow">PAYMENT BATCHES</p><div class="batch-list">' + body + '</div></article>';
      content.querySelectorAll('[data-close-batch]').forEach(function(btn){
        btn.onclick = async function(){
          try { await api('payment-batches/' + btn.dataset.closeBatch + '/close',{method:'POST'}); toast('Batch closed'); paymentsWorkspace(w); }
          catch(e) { toast(e.message,true); }
        };
      });
    }
  } catch(e) {
    content.innerHTML = errorCard(e.message);
  }
}

async function openPayment(id) {
  state.view = 'payments';
  const w = document.querySelector('#workspace');
  w.innerHTML = '<div class="loading">Loading receipt…</div>';

  try {
    const detailPayload = await api('payments/' + id);
    const detail = detailPayload.data;
    const payment = detail.payment;
    const results = await Promise.all([
      api('receivables/outstanding?customer_id=' + encodeURIComponent(payment.customer_id)),
      api('customers/' + payment.customer_id + '/overview')
    ]);
    const outstanding = results[0].data;
    const invoices = results[1].data.invoices || [];
    const invoiceMap = {};
    invoices.forEach(function(inv){ invoiceMap[inv.id] = inv; });

    const reversed = new Set(detail.allocations.filter(function(a){ return a.type === 'reversal' && a.reversal_of; }).map(function(a){ return a.reversal_of; }));
    const ledger = detail.allocations.length ? detail.allocations.map(function(a){
      const inv = invoiceMap[a.invoice_id];
      const reversible = a.type === 'allocation' && !reversed.has(a.id);
      return '<div class="allocation-row ' + (a.type === 'reversal' ? 'reversal' : '') + '"><span><b>' + esc(inv ? inv.number : a.invoice_id) + '</b><small>' + esc(a.type) + (a.reason ? ' · ' + esc(a.reason) : '') + '</small></span><strong>' + moneyPaise(a.amount_paise) + '</strong>' + (reversible ? '<button class="soft" data-reverse-allocation="' + esc(a.id) + '">Reverse</button>' : '<span></span>') + '</div>';
    }).join('') : '<div class="empty-small">No allocations yet.</div>';

    const allocatable = outstanding.length && detail.available_paise > 0 ? outstanding.map(function(row){
      return '<div class="allocation-target"><span><b>' + esc(row.number) + '</b><small>' + esc(row.due_date || 'No due date') + ' · ' + esc(ageingLabel(row.ageing_bucket)) + '</small></span><strong>' + moneyPaise(row.outstanding_paise) + '</strong><button class="soft" data-allocate-invoice="' + esc(row.invoice_id) + '">Allocate</button></div>';
    }).join('') : '<div class="empty-small">No eligible outstanding invoices or no unallocated balance.</div>';

    w.innerHTML =
      '<button class="back" id="back-payments">← Payments</button>' +
      '<section class="record-head"><div><p class="eyebrow">' + esc(payment.number) + '</p><h1>' + moneyPaise(payment.amount_paise) + '</h1><p>' + esc(paymentMethodLabel(payment.method)) + (payment.reference ? ' · ' + esc(payment.reference) : '') + ' · ' + esc(payment.status) + '</p></div><div class="record-actions"><button class="soft" id="print-receipt">Print Receipt</button>' + (payment.status === 'posted' && detail.allocated_paise === 0 ? '<button class="soft" id="void-payment">Void</button>' : '') + '</div></section>' +
      '<section class="metrics finance-metrics">' +
        metric('Receipt amount', moneyPaise(payment.amount_paise), 'Posted transaction') +
        metric('Allocated', moneyPaise(detail.allocated_paise), 'Across invoices') +
        metric('Available', moneyPaise(detail.available_paise), 'Unallocated credit') +
      '</section>' +
      '<section class="dash-grid finance-detail-grid"><article class="panel"><p class="eyebrow">ALLOCATION LEDGER</p><div class="allocation-list">' + ledger + '</div></article><article class="panel"><p class="eyebrow">ALLOCATE TO INVOICES</p><div class="allocation-list">' + allocatable + '</div></article></section>';

    document.querySelector('#back-payments').onclick = function(){ state.financeTab = 'receipts'; paymentsWorkspace(w); };
    document.querySelector('#print-receipt').onclick = function(){ window.print(); };
    const voidButton = document.querySelector('#void-payment');
    if (voidButton) voidButton.onclick = function(){ paymentVoidModal(payment); };

    w.querySelectorAll('[data-allocate-invoice]').forEach(function(btn){
      btn.onclick = function(){
        const row = outstanding.find(function(x){ return x.invoice_id === btn.dataset.allocateInvoice; });
        if (row) allocationModal(payment, detail.available_paise, row);
      };
    });
    w.querySelectorAll('[data-reverse-allocation]').forEach(function(btn){
      btn.onclick = function(){ reverseAllocationModal(payment.id, btn.dataset.reverseAllocation); };
    });
  } catch(e) {
    w.innerHTML = errorCard(e.message);
  }
}

function paymentMethodLabel(method) {
  return String(method || '').replaceAll('_',' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
}

function ageingLabel(key) {
  return ({current:'Current','1_30':'1–30','31_60':'31–60','61_90':'61–90','90_plus':'90+'})[key] || key || '';
}

async function paymentModal(customerId, batchId) {
  customerId = customerId || '';
  batchId = batchId || '';

  try {
    const results = await Promise.all([api('customers'), api('payment-batches')]);
    const customers = results[0].data;
    const batches = results[1].data.filter(function(b){ return b.status === 'open'; });

    if (!customers.length) { toast('Create a customer before recording a payment', true); return; }

    const customerOptions = customers.map(function(c){ return '<option value="' + esc(c.id) + '"' + (c.id === customerId ? ' selected' : '') + '>' + esc(c.name) + ' · ' + esc(c.number) + '</option>'; }).join('');
    const batchOptions = '<option value="">No batch</option>' + batches.map(function(b){ return '<option value="' + esc(b.id) + '"' + (b.id === batchId ? ' selected' : '') + '>' + esc(b.number) + ' · ' + esc(b.title) + '</option>'; }).join('');

    modal('Record payment',
      '<label class="full">Customer<select required name="customer_id"><option value="">Select customer</option>' + customerOptions + '</select></label>' +
      '<label>Amount<input required name="amount" type="number" min="0.01" step="0.01"></label><label>Method<select required name="method"><option value="cash">Cash</option><option value="upi">UPI</option><option value="neft">NEFT</option><option value="rtgs">RTGS</option><option value="imps">IMPS</option><option value="cheque">Cheque</option><option value="card">Card</option><option value="bank_transfer">Bank Transfer</option><option value="credit_note">Credit Note</option><option value="other">Other</option></select></label>' +
      '<label>Reference / UTR<input name="reference"></label><label>Batch<select name="batch_id">' + batchOptions + '</select></label>' +
      '<label>Bank<input name="bank"></label><label>UPI reference<input name="upi_ref"></label><label>Cheque number<input name="cheque_no"></label><label>Cheque date<input name="cheque_date" type="date"></label>' +
      '<label class="full">Notes<textarea name="notes"></textarea></label>',
      async function(payload){
        payload.method_meta = {
          bank: payload.bank || '',
          upi_ref: payload.upi_ref || '',
          cheque_no: payload.cheque_no || '',
          cheque_date: payload.cheque_date || ''
        };
        delete payload.bank; delete payload.upi_ref; delete payload.cheque_no; delete payload.cheque_date;
        const result = await api('payments',{method:'POST',body:payload});
        return function(){ openPayment(result.data.id); };
      }
    );
  } catch(e) { toast(e.message,true); }
}

async function paymentBatchModal() {
  try {
    const customers = (await api('customers')).data;
    const options = '<option value="">Any customer</option>' + customers.map(function(c){ return '<option value="' + esc(c.id) + '">' + esc(c.name) + '</option>'; }).join('');

    modal('New payment batch',
      '<label class="full">Title<input required name="title" value="Payment Batch"></label><label>Customer<select name="customer_id">' + options + '</select></label><label>Batch date<input name="batch_date" type="date"></label><label class="full">Notes<textarea name="notes"></textarea></label>',
      async function(payload){
        const result = await api('payment-batches',{method:'POST',body:payload});
        state.financeTab = 'batches';
        return function(){ paymentsWorkspace(document.querySelector('#workspace')); };
      }
    );
  } catch(e) { toast(e.message,true); }
}

function allocationModal(payment, availablePaise, invoice) {
  const max = Math.min(Number(availablePaise || 0), Number(invoice.outstanding_paise || 0));
  modal('Allocate payment',
    '<div class="full allocation-callout"><b>' + esc(invoice.number) + '</b><span>Outstanding ' + moneyPaise(invoice.outstanding_paise) + ' · Receipt available ' + moneyPaise(availablePaise) + '</span></div>' +
    '<input type="hidden" name="invoice_id" value="' + esc(invoice.invoice_id) + '"><label class="full">Amount<input required name="amount" type="number" min="0.01" max="' + (max / 100).toFixed(2) + '" step="0.01" value="' + (max / 100).toFixed(2) + '"></label>',
    async function(payload){
      await api('payments/' + payment.id + '/allocate',{method:'POST',body:payload});
      return function(){ openPayment(payment.id); };
    }
  );
}

function reverseAllocationModal(paymentId, allocationId) {
  modal('Reverse allocation',
    '<label class="full">Reason<textarea required name="reason" placeholder="Reason is appended to the Finance ledger"></textarea></label>',
    async function(payload){
      await api('allocations/' + allocationId + '/reverse',{method:'POST',body:payload});
      return function(){ openPayment(paymentId); };
    }
  );
}

function paymentVoidModal(payment) {
  modal('Void payment',
    '<label class="full">Reason<textarea required name="reason" placeholder="Void reason is permanent in the audit ledger"></textarea></label>',
    async function(payload){
      await api('payments/' + payment.id + '/void',{method:'POST',body:payload});
      return function(){ openPayment(payment.id); };
    }
  );
}

function comingSoon(w) {
  const item = nav.find(function(n){ return n[0] === state.view; });
  const label = item ? item[1] : 'Module';
  w.innerHTML = '<section class="page-head"><div><p class="eyebrow">NEXT PHASE</p><h1>' + esc(label) + '</h1><p>This module is reserved in the navigation and will be connected in the planned build sequence.</p></div></section><article class="panel"><div class="empty-state"><b>' + esc(label) + ' engine not activated yet</b><span>CRM Phase 2 remains isolated from future sales, finance and inventory modules.</span></div></article>';
}

function timeline(rows) {
  if (!rows || !rows.length) return '<div class="empty-small">No activity yet.</div>';
  return '<div class="timeline">' + rows.slice(0,8).map(function(a){
    return '<div><span class="timeline-dot"></span><p><b>' + esc(a.subject || a.action || 'Activity') + '</b><small>' + esc(a.type || a.status || '') + '</small></p><time>' + (a.created_at ? esc(new Date(a.created_at).toLocaleDateString()) : '') + '</time></div>';
  }).join('') + '</div>';
}

function modal(title, fields, submit) {
  const root = document.querySelector('#modal-root');
  root.innerHTML = '<div class="modal-backdrop"><form class="modal"><div class="modal-head"><div><p class="eyebrow">eCRM</p><h2>' + esc(title) + '</h2></div><button type="button" class="icon-btn" data-close>×</button></div><div class="form-grid">' + fields + '</div><div class="modal-actions"><button type="button" class="soft" data-close>Cancel</button><button class="primary" type="submit">Save</button></div></form></div>';
  root.querySelectorAll('[data-close]').forEach(function(btn){ btn.onclick = function(){ root.innerHTML = ''; }; });
  root.querySelector('form').onsubmit = async function(e){
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(e.currentTarget).entries());
    try {
      const afterSave = await submit(payload);
      root.innerHTML = '';
      toast('Saved');
      if (typeof afterSave === 'function') afterSave(); else renderView();
    } catch (err) { toast(err.message,true); }
  };
}

function customerModal() {
  modal('New customer',
    '<label class="full">Customer / Business name<input required name="name" autofocus></label>' +
    '<label>Contact person<input name="contact_person"></label><label>Mobile<input name="mobile"></label>' +
    '<label>Email<input name="email" type="email"></label><label>WhatsApp<input name="whatsapp"></label>' +
    '<label>GSTIN<input name="gstin"></label><label>Category<input name="category"></label>',
    function(p){ return api('customers',{method:'POST',body:p}); }
  );
}

function customerEditModal(customer) {
  modal('Edit customer',
    '<label class="full">Customer / Business name<input required name="name" value="' + esc(customer.name) + '"></label>' +
    '<label>Contact person<input name="contact_person" value="' + esc(customer.contact_person || '') + '"></label><label>Mobile<input name="mobile" value="' + esc(customer.mobile || '') + '"></label>' +
    '<label>Email<input name="email" type="email" value="' + esc(customer.email || '') + '"></label><label>WhatsApp<input name="whatsapp" value="' + esc(customer.whatsapp || '') + '"></label>' +
    '<label>GSTIN<input name="gstin" value="' + esc(customer.gstin || '') + '"></label><label>PAN<input name="pan" value="' + esc(customer.pan || '') + '"></label>' +
    '<label>Category<input name="category" value="' + esc(customer.category || '') + '"></label><label>Status<select name="status"><option value="active"' + (customer.status === 'active' ? ' selected' : '') + '>Active</option><option value="inactive"' + (customer.status === 'inactive' ? ' selected' : '') + '>Inactive</option><option value="archived"' + (customer.status === 'archived' ? ' selected' : '') + '>Archived</option></select></label>',
    async function(p){
      await api('customers/' + customer.id,{method:'PATCH',body:p});
      return function(){ openCustomer(customer.id); };
    }
  );
}

function contactModal(customerId) {
  modal('Add contact',
    '<label class="full">Name<input required name="name" autofocus></label><label>Designation<input name="designation"></label><label>Mobile<input name="mobile"></label><label>Email<input name="email" type="email"></label><label>WhatsApp<input name="whatsapp"></label>',
    function(p){ return api('customers/' + customerId + '/contacts',{method:'POST',body:p}); }
  );
}

function contactEditModal(contact) {
  modal('Edit contact',
    '<label class="full">Name<input required name="name" value="' + esc(contact.name) + '"></label><label>Designation<input name="designation" value="' + esc(contact.designation || '') + '"></label><label>Mobile<input name="mobile" value="' + esc(contact.mobile || '') + '"></label><label>Email<input name="email" type="email" value="' + esc(contact.email || '') + '"></label><label>WhatsApp<input name="whatsapp" value="' + esc(contact.whatsapp || '') + '"></label>',
    async function(p){ await api('contacts/' + contact.id,{method:'PATCH',body:p}); return function(){ openCustomer(contact.customer_id); }; }
  );
}

function addressEditModal(address) {
  modal('Edit address',
    '<label>Type<select name="type">' + ['site','billing','shipping','registered','warehouse','office','other'].map(function(t){ return '<option value="' + t + '"' + (address.type === t ? ' selected' : '') + '>' + t + '</option>'; }).join('') + '</select></label><label>Label<input name="label" value="' + esc(address.label || '') + '"></label>' +
    '<label class="full">Address<textarea required name="address">' + esc(address.address || '') + '</textarea></label><label>Area<input name="area" value="' + esc(address.area || '') + '"></label><label>City<input name="city" value="' + esc(address.city || '') + '"></label><label>State<input name="state" value="' + esc(address.state || '') + '"></label><label>PIN<input name="pin" value="' + esc(address.pin || '') + '"></label>',
    async function(p){ await api('addresses/' + address.id,{method:'PATCH',body:p}); return function(){ openCustomer(address.customer_id); }; }
  );
}

function leadEditModal(lead) {
  modal('Edit lead',
    '<label class="full">Lead / Opportunity name<input required name="name" value="' + esc(lead.name) + '"></label><label>Company<input name="company" value="' + esc(lead.company || '') + '"></label><label>Mobile<input name="mobile" value="' + esc(lead.mobile || '') + '"></label><label>Email<input name="email" type="email" value="' + esc(lead.email || '') + '"></label><label>Source<input name="source" value="' + esc(lead.source || '') + '"></label><label>Estimated value<input name="value" type="number" min="0" value="' + esc(lead.value || 0) + '"></label><label class="full">Requirement<textarea name="requirement">' + esc(lead.requirement || '') + '</textarea></label>',
    async function(p){ await api('leads/' + lead.id,{method:'PATCH',body:p}); }
  );
}

function addressModal(customerId) {
  modal('Add address',
    '<label>Type<select name="type"><option>site</option><option>billing</option><option>shipping</option><option>registered</option><option>warehouse</option><option>office</option></select></label><label>Label<input name="label" placeholder="Pune Plant"></label>' +
    '<label class="full">Address<textarea required name="address"></textarea></label><label>Area<input name="area"></label><label>City<input name="city"></label><label>State<input name="state"></label><label>PIN<input name="pin"></label><label>Latitude<input name="latitude" inputmode="decimal"></label><label>Longitude<input name="longitude" inputmode="decimal"></label>',
    function(p){ return api('customers/' + customerId + '/addresses',{method:'POST',body:p}); }
  );
}

function leadModal() {
  modal('New lead',
    '<label class="full">Lead / Opportunity name<input required name="name" autofocus></label><label>Company<input name="company"></label><label>Mobile<input name="mobile"></label><label>Email<input name="email" type="email"></label><label>Source<input name="source"></label><label>Estimated value<input name="value" type="number" min="0"></label><label class="full">Requirement<textarea name="requirement"></textarea></label>',
    function(p){ return api('leads',{method:'POST',body:p}); }
  );
}

async function followupModal(customerId) {
  customerId = customerId || '';
  let customerField = '';
  if (!customerId) {
    let options = '';
    try {
      const payload = await api('customers');
      options = payload.data.map(function(c){ return '<option value="' + esc(c.id) + '">' + esc(c.name) + '</option>'; }).join('');
    } catch (e) {}
    customerField = '<label class="full">Customer<select required name="customer_id"><option value="">Select customer</option>' + options + '</select></label>';
  } else {
    customerField = '<input type="hidden" name="customer_id" value="' + esc(customerId) + '">';
  }

  modal('New follow-up',
    customerField +
    '<label>Type<select name="type"><option value="follow_up">Follow-up</option><option value="task">Task</option><option value="call">Call</option><option value="meeting">Meeting</option></select></label><label>Due<input type="datetime-local" name="due_at"></label><label class="full">Subject<input required name="subject"></label><label class="full">Notes<textarea name="notes"></textarea></label>',
    function(p){ return api('activities',{method:'POST',body:p}); }
  );
}

function toast(message, error) {
  const root = document.querySelector('#toast-root');
  const el = document.createElement('div');
  el.className = 'toast' + (error ? ' error' : '');
  el.textContent = message;
  root.appendChild(el);
  setTimeout(function(){ el.remove(); }, 3200);
}

function errorCard(message) {
  return '<article class="panel error-card"><b>Could not load this workspace</b><span>' + esc(message) + '</span></article>';
}

shell();
