(() => {
  'use strict';
  const c = window.AISA_CONFIG;
  if (!c || document.getElementById('aisa-host')) return;
  const host = document.createElement('div'); host.id = 'aisa-host'; document.body.append(host);
  const root = host.attachShadow({ mode: 'open' });
  const style = document.createElement('link'); style.rel = 'stylesheet'; style.href = c.stylesheet; root.append(style);
  const el = (tag, text, cls) => { const n = document.createElement(tag); if (text) n.textContent = text; if (cls) n.className = cls; return n; };
  const safeUrl = value => { try { const u = new URL(value, location.origin); return ['https:', 'http:', 'mailto:'].includes(u.protocol) ? u.href : ''; } catch { return ''; } };
  const link = (text, url) => { const a = el('a', text); a.href = safeUrl(url); a.target = '_blank'; a.rel = 'noopener noreferrer'; return a; };
  const shell = el('div', '', 'shell ' + (c.position === 'left' ? 'left' : 'right'));
  shell.style.setProperty('--accent', /^#[0-9a-f]{6}$/i.test(c.color) ? c.color : '#635bff');
  shell.style.setProperty('--radius', Math.min(32, Math.max(0, Number(c.radius) || 0)) + 'px');
  const launch = el('button', '✦ Support', 'launch'); launch.type = 'button'; launch.setAttribute('aria-expanded', 'false'); launch.setAttribute('aria-controls', 'aisa-panel');
  const panel = el('section', '', 'panel'); panel.id = 'aisa-panel'; panel.hidden = true; panel.setAttribute('aria-label', c.title);
  const header = el('header'); const heading = el('div'); heading.append(el('strong', c.title), el('small', 'AI assistant · Support options'));
  const close = el('button', '×', 'close'); close.type = 'button'; close.setAttribute('aria-label', 'Close support'); header.append(heading, close);
  const log = el('div', '', 'messages'); log.setAttribute('role', 'log'); log.setAttribute('aria-live', 'polite');
  const trimLog = () => { while (log.children.length > 40) log.children[0].remove(); };
  const bubble = (text, who = 'agent') => { const n = el('div', text, 'bubble ' + who); log.append(n); trimLog(); log.scrollTop = log.scrollHeight; return n; };
  const actions = el('nav', '', 'actions'); actions.setAttribute('aria-label', 'Support actions');
  const purchases = el('button', 'My purchases'); const payment = el('button', 'Payment options'); const human = el('button', 'Talk to a person'); const clear = el('button', 'Clear chat');
  const memory = el('button', 'Remember context: off'); memory.setAttribute('aria-pressed', 'false');
  memory.title = 'Approved public topics only. No transcript, orders, download links or credentials.';
  [purchases, payment, human, clear].forEach(b => { b.type = 'button'; actions.append(b); });
  const tickets = el('button', 'My support tickets'); tickets.type = 'button';
  if (Number(c.customer_tickets)) actions.append(tickets);
  if (Number(c.memory) && window.crypto?.getRandomValues) { memory.type = 'button'; actions.append(memory); }
  const form = el('form', '', 'composer'); const input = el('textarea'); input.rows = 2; input.maxLength = 1500; input.required = true; input.placeholder = 'How can we help you?'; input.setAttribute('aria-label', 'Your message');
  const send = el('button', 'Send'); send.type = 'submit'; form.append(input, send);
  const footer = el('small', 'AI can make mistakes. Avoid passwords and card details. Memory is off.', 'disclaimer');
  panel.append(header, log, actions, form, footer); shell.append(panel, launch); root.append(shell); bubble(c.welcome);
  const newSession = () => { if (!window.crypto?.getRandomValues) return ''; const bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes); return Array.from(bytes, x => x.toString(16).padStart(2, '0')).join(''); };
  let session = newSession(), generation = 0, remembering = false, expires = 0, viewExpires = 0, blocked = false, ticketCard = null, ticketViewExpires = 0;
  const controllers = new Set();
  const memoryLabel = () => { memory.textContent = 'Remember context: ' + (remembering ? 'on' : 'off'); memory.setAttribute('aria-pressed', String(remembering)); footer.textContent = remembering ? 'Public context only · 4 turns · expires within 15 minutes. No transcript is saved.' : 'AI can make mistakes. Avoid passwords and card details. Memory is off.'; };
  function fresh(message = c.welcome) {
    generation++; controllers.forEach(controller => controller.abort()); controllers.clear(); session = newSession(); expires = 0; viewExpires = 0;
    [...log.children].forEach(n => n.remove()); ticketCard = null; ticketViewExpires = 0; bubble(message); input.value = ''; input.disabled = false; send.disabled = false; purchases.disabled = false; human.disabled = false; tickets.disabled = false; panel.removeAttribute('aria-busy'); memoryLabel();
  }
  function identityError(error) {
    if (![401, 403, 409].includes(error.status)) return false;
    blocked = true; remembering = false; fresh('Your sign-in or support session changed. Reload to continue safely.');
    actions.hidden = true; form.hidden = true; log.hidden = false;
    const reload = el('button', 'Reload page'); reload.type = 'button'; reload.addEventListener('click', () => location.reload()); bubble('A fresh page prevents sharing history between accounts.').append(reload); return true;
  }
  async function request(path, body) {
    const controller = new AbortController(); controllers.add(controller); const timer = setTimeout(() => controller.abort(), 45000);
    try {
      const response = await fetch(c.api + path, { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: { 'Content-Type': 'application/json', ...(c.nonce ? { 'X-WP-Nonce': c.nonce } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
      const result = await response.json();
      if (!response.ok) { const error = new Error(result.message || 'Support is unavailable.'); error.status = response.status; error.code = result.code; throw error; }
      return result;
    } catch (error) { if (error.name === 'AbortError') throw new Error('The request timed out. Please try again or contact support.'); throw error; }
    finally { clearTimeout(timer); controllers.delete(controller); }
  }
  async function ensure() {
    if (blocked) return null;
    if (ticketViewExpires && Date.now() >= ticketViewExpires) { ticketCard?.remove(); ticketCard = null; ticketViewExpires = 0; bubble('Ticket view expired. Refresh your support tickets to continue.'); }
    if ((expires && Date.now() >= expires) || (viewExpires && Date.now() >= viewExpires)) fresh('Support context expired. Start a fresh conversation.');
    const epoch = generation;
    try {
      const result = await request('context', { scope: c.scope, session, remember: remembering });
      if (epoch !== generation) return null;
      if (expires && (!result.expires || result.expired)) fresh('Public context expired. Start a fresh conversation.');
      if (!result.memory_enabled && remembering) { remembering = false; fresh('Context memory is unavailable.'); }
      if (!viewExpires) viewExpires = Date.now() + 900000;
      return generation;
    } catch (error) { if (epoch !== generation) return null; if (!identityError(error)) bubble(error.message); return null; }
  }
  async function open() {
    panel.hidden = false; launch.setAttribute('aria-expanded', 'true'); log.hidden = true; actions.hidden = true; form.hidden = true;
    const epoch = await ensure(); if (epoch !== null && !panel.hidden) { log.hidden = false; actions.hidden = false; form.hidden = false; input.focus(); }
    else { log.hidden = false; if (!blocked) { const retry = el('button', 'Try again'); retry.type = 'button'; retry.addEventListener('click', open); bubble('Check your connection and retry.').append(retry); } }
  }
  const shut = () => { panel.hidden = true; launch.setAttribute('aria-expanded', 'false'); launch.focus(); };
  launch.addEventListener('click', () => panel.hidden ? open() : shut()); close.addEventListener('click', shut);
  root.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) shut(); });
  form.addEventListener('submit', async e => {
    e.preventDefault(); const message = input.value.trim(); if (!message || send.disabled || blocked) return;
    send.disabled = true; let epoch = await ensure(); if (epoch === null) { send.disabled = false; return; }
    send.disabled = true; input.disabled = true; input.value = ''; if (!viewExpires) viewExpires = Date.now() + 900000; bubble(message, 'customer'); const waiting = bubble('Finding an answer…'); panel.setAttribute('aria-busy', 'true');
    try {
      const result = await request('chat', { message, scope: c.scope, session, remember: remembering });
      if (epoch !== generation) return;
      if (await ensure() !== epoch) { if (epoch === generation) waiting.remove(); return; }
      if (result.memory?.expired) { fresh('Previous public context expired.'); epoch = generation; bubble(message, 'customer'); }
      else waiting.remove();
      bubble(result.reply);
      (result.products || []).forEach(p => { const card = el('div', '', 'card'); card.append(el('strong', p.name), el('p', p.price), el('p', p.description), link(p.available ? 'View product & checkout →' : 'View product →', p.url)); log.append(card); });
      (result.sources || []).filter(s => s.url).forEach(s => { const row = el('div', '', 'source'); row.append(link(s.title, s.url)); log.append(row); });
      trimLog();
      if (remembering && result.memory?.saved) expires = result.memory.expires * 1000;
      else if (remembering) bubble('This turn was answered but its public context was not saved.');
    } catch (error) { if (epoch === generation && !identityError(error)) waiting.textContent = error.message; }
    finally { if (epoch === generation) { send.disabled = false; input.disabled = false; panel.removeAttribute('aria-busy'); input.focus(); log.scrollTop = log.scrollHeight; } }
  });
  input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); } });
  purchases.addEventListener('click', async () => {
    const epoch = await ensure(); if (epoch === null) return;
    if (!c.loggedIn) { bubble('Please sign in to securely view your purchases and downloads.').append(link('Sign in →', c.login)); return; }
    purchases.disabled = true; if (!viewExpires) viewExpires = Date.now() + 900000;
    try {
      const [orders, downloads] = await Promise.all([request('orders'), request('downloads')]);
      if (epoch !== generation || await ensure() !== epoch) return;
      if (!orders.orders.length && !downloads.downloads.length) bubble('No purchases or available downloads found for your account. Contact support if you purchased as a guest.');
      orders.orders.forEach(o => { const n = bubble('Order #' + o.number + ' · ' + o.status + '\n' + o.items.map(i => i.name + ' × ' + i.quantity).join('\n')); n.append(link('View order →', o.url)); });
      downloads.downloads.forEach(d => bubble(d.name).append(link('Download →', d.url)));
    } catch (error) { if (epoch === generation && !identityError(error)) bubble(error.message); } finally { if (epoch === generation) purchases.disabled = false; }
  });
  payment.addEventListener('click', async () => {
    if (await ensure() === null) return; const n = bubble(c.payment_text || 'Choose a product above to pay through our secure store checkout.');
    if (c.qr_url && safeUrl(c.qr_url)) { const img = el('img'); img.src = safeUrl(c.qr_url); img.alt = 'Store payment QR code'; img.width = 180; img.height = 180; n.append(img); }
    n.append(el('small', 'Payment is confirmed by the store, not by this chat.'));
  });
  human.addEventListener('click', async () => {
    const epoch = await ensure(); if (epoch === null) return; const n = bubble('Contact our team using the options below.');
    if (c.handoff_url) n.append(link('Open human support →', c.handoff_url)); if (c.support_email) n.append(link('Email support →', 'mailto:' + c.support_email));
    if (!c.handoff_url && !c.support_email) n.append(el('small', 'Support contact is awaiting configuration.'));
    human.disabled = true;
    try { const result = await request('handoff', {}); if (epoch === generation) n.append(el('small', result.message)); } catch (error) { if (epoch === generation && !identityError(error)) n.append(el('small', error.message)); } finally { if (epoch === generation) human.disabled = false; }
  });
  const ticketTopics = c.ticket_topics || { general: 'General support', catalog: 'Product information', knowledge: 'Knowledge review', orders: 'Order access review', downloads: 'Download access review' };
  const ticketStatuses = { open: 'Open', in_review: 'In review', resolved: 'Resolved · review status only' };
  const ticketIdentityError = error => ([401, 403].includes(error.status) || error.code === 'identity_changed') && identityError(error);
  function ticketRow(ticket) {
    if (!ticket || !Number.isSafeInteger(ticket.id) || ticket.id < 1 || !Object.prototype.hasOwnProperty.call(ticketTopics, ticket.topic) || !Object.prototype.hasOwnProperty.call(ticketStatuses, ticket.status) || !['created', 'updated', 'expires'].every(key => Number.isSafeInteger(ticket[key]) && ticket[key] > 0 && ticket[key] < 8640000000000) || ticket.expires !== ticket.created + 604800) throw new Error('Ticket status is unavailable. Refresh or use support contact options.');
    const row = el('div', '', 'ticket-row');
    row.append(el('strong', '#' + ticket.id + ' · ' + ticketTopics[ticket.topic]), el('p', ticketStatuses[ticket.status]), el('small', 'Expires ' + new Date(ticket.expires * 1000).toISOString().slice(0, 16).replace('T', ' ') + ' UTC'));
    return row;
  }
  tickets.addEventListener('click', async () => {
    if (tickets.disabled || blocked) return; tickets.disabled = true;
    const epoch = await ensure(); if (epoch === null) { tickets.disabled = false; return; }
    if (!c.loggedIn) { bubble('Sign in to submit a structured support request or view your own ticket status.').append(link('Sign in →', c.login)); tickets.disabled = false; return; }
    ticketCard?.remove(); ticketCard = null;
    try {
      const data = await request('tickets/status', { scope: c.scope });
      if (epoch !== generation || await ensure() !== epoch) return;
      if (!Array.isArray(data.tickets) || data.tickets.length > 3) throw new Error('Ticket status is unavailable. Please refresh.');
      const current = data.tickets.filter(ticket => { ticketRow(ticket); return ticket.expires * 1000 > Date.now(); });
      const rows = current.map(ticketRow); ticketViewExpires = current.length ? Math.min(...current.map(ticket => ticket.expires * 1000)) : 0;
      const card = el('div', '', 'card ticket-box'); ticketCard = card; card.setAttribute('role', 'region'); card.setAttribute('aria-label', 'Account-linked support tickets');
      const list = el('div', '', 'ticket-list'); list.append(...rows); if (!rows.length) list.append(el('p', 'No retained support tickets for your account.'));
      const refresh = el('button', 'Refresh ticket status'); refresh.type = 'button'; refresh.addEventListener('click', () => tickets.click());
      card.append(el('h3', 'My support tickets'), list, refresh, el('p', 'Submit only a topic for human review. Kept with your account for up to 7 days. Up to 3 retained tickets and 5 new submissions per 24 hours. No chat text, contact details, order IDs or download links are included. No notification or guaranteed reply.', 'ticket-help'));
      const requestId = newSession();
      if (!requestId) card.append(el('p', 'This browser cannot create a safe request ID. Use the support contact options.'));
      else {
        const box = el('form', '', 'ticket-form');
        const topicLabel = el('label'); topicLabel.append(el('span', 'Support topic')); const topic = el('select'); topic.required = true;
        Object.entries(ticketTopics).forEach(([key, text]) => { const option = el('option', text); option.value = key; topic.append(option); }); topic.value = 'general'; topicLabel.append(topic);
        const consentLabel = el('label', '', 'ticket-consent'); const consent = el('input'); consent.type = 'checkbox'; consent.required = true;
        consentLabel.append(consent, el('span', 'I explicitly submit this topic linked to my signed-in account for human review.'));
        const submit = el('button', 'Submit support request'); submit.type = 'submit'; const resultText = el('p', '', 'ticket-result'); resultText.setAttribute('role', 'status'); resultText.setAttribute('aria-live', 'polite');
        box.append(topicLabel, consentLabel, submit, resultText); card.append(box);
        box.addEventListener('submit', async e => {
          e.preventDefault(); if (submit.disabled || blocked || !consent.checked) return;
          submit.disabled = true; tickets.disabled = true; refresh.disabled = true; const submitEpoch = await ensure(); if (submitEpoch === null) { submit.disabled = false; tickets.disabled = false; refresh.disabled = false; return; }
          topic.disabled = true; consent.disabled = true; resultText.textContent = 'Saving your structured request…'; box.setAttribute('aria-busy', 'true');
          try {
            const result = await request('tickets/submit', { scope: c.scope, topic: topic.value, confirm: true, request_id: requestId });
            if (submitEpoch !== generation || await ensure() !== submitEpoch) return;
            const savedRow = ticketRow(result.ticket); [...list.children].forEach(n => n.remove()); list.append(savedRow); ticketViewExpires = result.ticket.expires * 1000;
            box.remove(); card.append(el('p', (result.duplicate ? 'This request was already saved. ' : 'Request saved for human review. ') + 'Refresh status to see all your tickets. No customer message or notification was sent.', 'ticket-result'));
          } catch (error) { if (submitEpoch === generation && !ticketIdentityError(error)) resultText.textContent = error.message + ' Submission may have been saved. Refresh status before retrying; this form reuses the same request ID.'; }
          finally { if (submitEpoch === generation) { submit.disabled = false; tickets.disabled = false; refresh.disabled = false; topic.disabled = false; consent.disabled = false; box.removeAttribute('aria-busy'); } }
        });
      }
      log.append(card); trimLog(); card.scrollIntoView?.({ block: 'nearest' });
    } catch (error) { if (epoch === generation && !ticketIdentityError(error)) bubble(error.message); }
    finally { if (epoch === generation) tickets.disabled = false; }
  });
  async function clearChat(turnOff = false) {
    const old = session, needed = remembering || expires > 0; if (turnOff) remembering = false; fresh('Chat cleared. A new support session has started.');
    if (Number(c.customer_tickets)) bubble('Clearing this chat does not delete account-linked tickets. They expire within 7 days. A request already submitted may still finish saving.');
    if (needed && old) { try { await request('memory_reset', { scope: c.scope, session: old }); } catch (error) { if (!identityError(error)) bubble('Cleared in this tab. Previous public references will expire within 15 minutes.'); } }
  }
  clear.addEventListener('click', () => clearChat());
  memory.addEventListener('click', async () => { if (remembering) { await clearChat(true); return; } remembering = true; fresh('Public context memory is on for this tab only. No transcript is saved.'); await ensure(); });
  if (Number(c.learning)) {
    const suggest = el('button', 'Suggest an improvement'); suggest.type = 'button'; actions.append(suggest);
    suggest.addEventListener('click', async () => {
      if (await ensure() === null) return; const box = el('form', '', 'suggestion'); const text = el('textarea'); text.required = true; text.minLength = 10; text.maxLength = 700; text.placeholder = 'Public help information only — no personal details'; text.setAttribute('aria-label', 'Knowledge suggestion');
      const submit = el('button', 'Submit for review'); submit.type = 'submit'; box.append(el('p', 'Admin review required. Do not include personal information.'), text, submit); log.append(box); trimLog();
      box.addEventListener('submit', async e => { e.preventDefault(); const epoch = await ensure(); if (epoch === null) return; submit.disabled = true; try { const result = await request('suggest', { text: text.value }); if (epoch === generation) { box.remove(); bubble(result.message); } } catch (error) { if (epoch === generation && !identityError(error)) { bubble(error.message); submit.disabled = false; } } });
    });
  }
  const recheck = () => { if (!panel.hidden) { log.hidden = true; actions.hidden = true; form.hidden = true; return open(); } };
  window.addEventListener?.('focus', recheck); window.addEventListener?.('pageshow', recheck);
  document.addEventListener?.('visibilitychange', () => { if (!document.hidden) recheck(); });
  window.setInterval?.(() => { if (!panel.hidden && !document.hidden) ensure(); }, 30000);
})();
