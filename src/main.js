import './style.css';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const app = document.querySelector('#app');
const state = { view: 'dashboard', map: null, searchTimer: null };
const activeViews = ['dashboard','customers','leads','followups','map'];
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
  document.querySelector('#scan-qr').addEventListener('click', function(){ toast('QR scanning is reserved for the inventory phase.'); });

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
    '</section>' +
    '<section class="dash-grid">' +
      '<article class="panel attention"><div><p class="eyebrow">NEEDS ATTENTION</p><h2>' + (d.open_followups ? d.open_followups + ' open follow-up' + (d.open_followups === 1 ? '' : 's') : 'You’re all caught up') + '</h2><p>Tasks and follow-ups stay visible until completed.</p></div><button class="chip" id="open-followups">Open queue</button></article>' +
      '<article class="panel"><p class="eyebrow">QUICK ACTIONS</p><div class="quick"><button id="quick-customer">+ Customer</button><button id="quick-lead">+ Lead</button><button id="quick-followup">+ Follow-up</button><button id="quick-map">Open Map</button></div></article>' +
      '<article class="panel wide"><div class="row"><div><p class="eyebrow">RECENT ACTIVITY</p><h2>Business timeline</h2></div></div>' + timeline(d.recent_activity) + '</article>' +
    '</section>';

  document.querySelector('#dash-customer').onclick = customerModal;
  document.querySelector('#quick-customer').onclick = customerModal;
  document.querySelector('#quick-lead').onclick = leadModal;
  document.querySelector('#quick-followup').onclick = function(){ followupModal(); };
  document.querySelector('#open-followups').onclick = function(){ state.view = 'followups'; shell(); };
  document.querySelector('#quick-map').onclick = function(){ state.view = 'map'; shell(); };
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
      '<section class="record-head"><div><p class="eyebrow">' + esc(c.number) + ' · ' + esc(c.status) + '</p><h1>' + esc(c.name) + '</h1><p>' + esc(c.category || 'Customer') + (c.gstin ? ' · GSTIN ' + esc(c.gstin) : '') + '</p></div><div class="record-actions"><button class="soft" id="edit-customer">Edit</button><button class="soft" id="add-contact">+ Contact</button><button class="soft" id="add-address">+ Address</button><button class="primary" id="add-followup-customer">+ Follow-up</button></div></section>' +
      '<section class="customer-grid">' +
        '<article class="panel"><p class="eyebrow">CUSTOMER</p><dl><dt>Contact</dt><dd>' + esc(c.contact_person || '—') + '</dd><dt>Mobile</dt><dd>' + esc(c.mobile || '—') + '</dd><dt>Email</dt><dd>' + esc(c.email || '—') + '</dd><dt>GSTIN</dt><dd>' + esc(c.gstin || '—') + '</dd></dl></article>' +
        '<article class="panel"><p class="eyebrow">UP NEXT</p>' + nextActivity(data.activities) + '</article>' +
        '<article class="panel"><p class="eyebrow">CONTACTS</p>' + contactCards(data.contacts) + '</article>' +
        '<article class="panel span2"><p class="eyebrow">ADDRESSES</p>' + addressCards(data.addresses) + '</article>' +
        '<article class="panel"><p class="eyebrow">ACTIVITY</p>' + timeline(data.activities) + '</article>' +
      '</section>';
    document.querySelector('#back-customers').onclick = function(){ customers(w); };
    document.querySelector('#edit-customer').onclick = function(){ customerEditModal(c); };
    document.querySelector('#add-contact').onclick = function(){ contactModal(id); };
    document.querySelector('#add-address').onclick = function(){ addressModal(id); };
    document.querySelector('#add-followup-customer').onclick = function(){ followupModal(id); };
  } catch (e) {
    w.innerHTML = errorCard(e.message);
  }
}

function contactCards(rows) {
  if (!rows.length) return '<div class="empty-small">No contacts yet.</div>';
  return '<div class="mini-list">' + rows.map(function(r){
    return '<div><b>' + esc(r.name) + '</b><span>' + esc(r.designation || r.mobile || r.email || '') + '</span></div>';
  }).join('') + '</div>';
}

function addressCards(rows) {
  if (!rows.length) return '<div class="empty-small">No addresses yet.</div>';
  return '<div class="address-grid">' + rows.map(function(a){
    return '<div class="address-card"><div><span class="pill">' + esc(a.type) + '</span><b>' + esc(a.label) + '</b></div><p>' + esc(a.address) + (a.area ? ', ' + esc(a.area) : '') + (a.city ? ', ' + esc(a.city) : '') + (a.pin ? ' ' + esc(a.pin) : '') + '</p><small>' + (a.geocode_status === 'resolved' ? '● Map ready' : '○ Geocode pending') + '</small></div>';
  }).join('') + '</div>';
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
        return '<article class="lead-card"><b>' + esc(l.name) + '</b><span>' + esc(l.company || l.number) + '</span>' + (l.value ? '<strong>' + money(l.value) + '</strong>' : '') + '<select data-lead="' + esc(l.id) + '">' + options + '</select>' + ((!l.customer_id && l.stage !== 'lost') ? '<button class="soft convert-lead" data-convert="' + esc(l.id) + '">Convert to customer</button>' : '') + '</article>';
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
      await submit(payload);
      root.innerHTML = '';
      toast('Saved');
      renderView();
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
      setTimeout(function(){ openCustomer(customer.id); }, 0);
    }
  );
}

function contactModal(customerId) {
  modal('Add contact',
    '<label class="full">Name<input required name="name" autofocus></label><label>Designation<input name="designation"></label><label>Mobile<input name="mobile"></label><label>Email<input name="email" type="email"></label><label>WhatsApp<input name="whatsapp"></label>',
    function(p){ return api('customers/' + customerId + '/contacts',{method:'POST',body:p}); }
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
