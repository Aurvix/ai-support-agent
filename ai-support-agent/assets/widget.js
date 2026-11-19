(() => {
  'use strict';
  const c = window.AISA_CONFIG;
  if (!c || document.getElementById('aisa-host')) return;
  const host = document.createElement('div'); host.id = 'aisa-host'; document.body.append(host);
  const root = host.attachShadow({ mode: 'open' });
  const style = document.createElement('link'); style.rel = 'stylesheet'; style.href = c.stylesheet; root.append(style);
  const el = (tag, text, cls) => { const n = document.createElement(tag); if (text) n.textContent = text; if (cls) n.className = cls; return n; };
  const safeUrl = value => { try { const u = new URL(value, location.origin); return ['https:', 'http:', 'mailto:'].includes(u.protocol) ? u.href : ''; } catch { return ''; } };
  const accountUrl = value => { try { if (typeof value !== 'string' || !value) return ''; const u = new URL(value, location.origin); return ['https:', 'http:'].includes(u.protocol) && u.origin === location.origin && !u.username && !u.password && !u.hash && !['download_file','order','order_id','order_key','key','token','access_token','email','_wpnonce'].some(k=>u.searchParams.has(k)) ? u.href : ''; } catch { return ''; } };
  const link = (text, url) => { const a = el('a', text); a.href = safeUrl(url); a.target = '_blank'; a.rel = 'noopener noreferrer'; return a; };
  const positions = {'left':['left','bottom'],'right':['right','bottom'],'top-left':['left','top'],'top-center':['center','top'],'top-right':['right','top'],'center-left':['left','center'],'center':['center','center'],'center-right':['right','center'],'bottom-center':['center','bottom']};
  const anchor = Object.prototype.hasOwnProperty.call(positions,c.position) ? positions[c.position] : positions.right;
  const shell = el('div', '', 'shell ' + (anchor[0] === 'left' ? 'left' : 'right'));
  shell.setAttribute('data-aisa-horizontal',anchor[0]); shell.setAttribute('data-aisa-vertical',anchor[1]);
  const uiTheme = ['classic','messenger','ocean','slate','forest','indigo','rose','amber','teal','plum'].includes(c.ui_theme) ? c.ui_theme : 'classic';
  const appearance = c.admin_theme === 'dark' ? 'dark' : 'light';
  shell.setAttribute('data-ui-theme', uiTheme); shell.setAttribute('data-theme', appearance);
  shell.style.setProperty('--accent', /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(c.color) ? c.color : '#635bff');
  shell.style.setProperty('--radius', Math.min(32, Math.max(0, Number(c.radius) || 0)) + 'px');
  const icons = { none:'',spark:'✦',catalog:'▦',bag:'▱',ticket:'▤',chat:'◌',link:'↗',question:'?' };
  const plain = (value, fallback, limit = 120) => typeof value === 'string' && value.trim() && value.length <= limit ? value : fallback;
  const ink = color => { const v = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(color) ? color : '#635bff'; const hex = v.length === 4 ? '#' + [...v.slice(1)].map(x=>x+x).join('') : v; const rgb = [1,3,5].map(i => parseInt(hex.slice(i,i+2),16)/255).map(x => x <= .04045 ? x/12.92 : ((x+.055)/1.055)**2.4); const l = rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722; return (l+.05)/.05 >= 1.05/(l+.05) ? '#000000' : '#ffffff'; };
  shell.style.setProperty('--accent-ink', ink(c.color)); shell.style.setProperty('--header', /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(c.header_color) ? c.header_color : '#f6f5ff'); shell.style.setProperty('--header-ink', ink(c.header_color || '#f6f5ff'));
  // Both surfaces receive the same fixed server palette; reject arbitrary CSS values.
  const paletteKeys = ['--ui-primary-ink','--ui-accent','--ui-focus','--ui-bg','--ui-panel','--ui-text','--ui-muted','--ui-line','--ui-outgoing','--ui-incoming','--ui-hover','--ui-hero','--ui-hero-text','--ui-hero-muted'];
  const palette = {};
  for (const [key,value] of Object.entries(c.theme_palette || {})) if (paletteKeys.includes(key) && typeof value==='string' && /^#[0-9a-f]{6}$/i.test(value)) { palette[key]=value; shell.style.setProperty(key,value); }
  const themeAccent = uiTheme!=='classic' ? (palette['--ui-accent'] || {messenger:appearance==='dark'?'#7edabb':'#176b53',ocean:appearance==='dark'?'#77d0f4':'#12618d'}[uiTheme]) : null;
  if (themeAccent) { shell.style.setProperty('--accent',themeAccent); shell.style.setProperty('--accent-ink',ink(themeAccent)); }
  if (uiTheme !== 'classic' || appearance === 'dark') {
    const themeHeader = palette['--ui-panel'] || (appearance==='dark'?'#242438':'#f4f6fa');
    shell.style.setProperty('--header',themeHeader); shell.style.setProperty('--header-ink',ink(themeHeader));
  }
  if (c.widget_size === 'comfortable') shell.className += ' comfortable';
  const sizeRules={launcher_size:[40,80,48],launcher_icon_size:[16,36,20],panel_width:[300,520,360],panel_height:[360,720,500],chat_font_size:[12,20,13],action_font_size:[11,16,11],composer_height:[40,80,40],bubble_radius:[4,24,12]};
  for(const [key,[min,max,fallback]]of Object.entries(sizeRules)){const value=Number(c[key]);shell.style.setProperty('--'+key.replaceAll('_','-'),(Number.isInteger(value)&&value>=min&&value<=max?value:fallback)+'px');}
  const decorate = (node, label, icon) => { node.textContent = label; if (icons[icon]) { node.textContent = ''; const art = el('span', icons[icon] + ' ', 'action-icon'); art.setAttribute('aria-hidden','true'); node.append(art, el('span',label)); } };
  const launch = el('button', '', 'launch'); decorate(launch, plain(c.launcher_label,'Support',60), c.launcher_icon || 'spark'); launch.setAttribute('data-mobile-style',c.launcher_mobile === 'label'?'label':'icon');if(c.launcher_icon==='none'){launch.textContent='';const art=el('span','✦','action-icon');art.setAttribute('aria-hidden','true');launch.append(art,el('span',plain(c.launcher_label,'Support',60)));}launch.type = 'button'; launch.setAttribute('aria-label', 'Open support chat'); launch.setAttribute('aria-expanded', 'false'); launch.setAttribute('aria-controls', 'aisa-panel');
  const panel = el('section', '', 'panel'); panel.id = 'aisa-panel'; panel.hidden = true; panel.setAttribute('aria-label', c.title);
  const assistantLabel=plain(c.assistant_label,'AI assistant',60), aiAuthor=/\bAI\b/i.test(assistantLabel)?assistantLabel:assistantLabel+' · AI';
  const header = el('header'); const heading = el('div'); heading.append(el('strong', c.title), el('small', aiAuthor+' · Website chat'));
  const profile = el('span', '', 'assistant-profile'); const avatar = el('img'); avatar.alt = ''; avatar.setAttribute('aria-hidden', 'true'); avatar.width = 32; avatar.height = 32;
  try { const sheet = new URL(c.stylesheet, location.origin); const u = new URL(c.avatar || 'assistant.svg', sheet); const media = new URL(c.media_base || sheet.origin, location.origin); if (['http:', 'https:'].includes(u.protocol) && [sheet.origin, media.origin].includes(u.origin) && !u.username && !u.password && !(sheet.protocol === 'https:' && u.protocol !== 'https:')) avatar.src = u.href; } catch {}
  avatar.addEventListener('error', () => { try { const fallback = new URL('assistant.svg', new URL(c.stylesheet,location.origin)).href; if (avatar.src !== fallback) avatar.src = fallback; } catch {} });
  profile.append(avatar, heading);
  const close = el('button', '×', 'close'); close.type = 'button'; close.setAttribute('aria-label', 'Close support'); header.append(profile, close);
  const log = el('div', '', 'messages'); log.setAttribute('role', 'log'); log.setAttribute('aria-live', 'polite');
  const trimLog = () => { while (log.children.length > 40) log.children[0].remove(); };
  const bubble = (text, who = 'agent') => { const n = el('div', text, 'bubble ' + who); log.append(n); trimLog(); log.scrollTop = log.scrollHeight; return n; };
  const messageMeta = status => {
    const meta = el('span', '', 'message-meta');
    // Device time is presentation only, never a server-delivery/read receipt.
    const time = el('span', new Intl.DateTimeFormat(undefined,{hour:'2-digit',minute:'2-digit'}).format(Date.now()), 'message-time');
    time.setAttribute('aria-hidden','true'); meta.append(time);
    const receipt = el('span', status || '', 'message-delivery');
    if (status) { receipt.setAttribute('role','status'); receipt.setAttribute('aria-live','polite'); meta.append(receipt); }
    return {meta,receipt};
  };
  const customerMessage = (text, status = 'Sending…') => {
    const n = bubble('', 'customer'); n.setAttribute('data-chat-message','true');
    const bits = messageMeta(status); n.append(el('span',text,'message-body'),bits.meta); return bits.receipt;
  };
  const receiptStatus = (turn, status) => { if (turn?.epoch === generation && turn.receipt) turn.receipt.textContent = status; };
  const actions = el('nav', '', 'actions'); actions.setAttribute('aria-label', 'Support actions'); actions.setAttribute('tabindex', '0'); actions.setAttribute('aria-description', 'Scroll horizontally for more options. Use Tab to move between support actions.');
  const catalogButton = el('button', 'View Catalog'); catalogButton.type = 'button'; actions.append(catalogButton); catalogButton.hidden = c.commerce === false;
  const purchases = el('button', 'My purchases'); const payment = el('button', 'Payment options'); const human = el('button', 'Talk to a person');
  const memory = el('button', 'Remember context: off'); memory.setAttribute('aria-pressed', 'false');
  memory.title = 'Approved public topics only. No transcript, orders, download links or credentials.';
  [purchases].forEach(b => { b.type = 'button'; actions.append(b); });
  const tickets = el('button', 'My support tickets'); tickets.type = 'button';
  if (Number(c.customer_tickets)) actions.append(tickets);
  // Public memory opt-in is not exposed in this UI; server settings and retained records are untouched.
  const form = el('form', '', 'composer'); const input = el('textarea'); input.rows = 1; input.maxLength = 1500; input.required = true; input.placeholder = plain(c.input_label, 'How can we help you?'); input.setAttribute('aria-label', 'Your message');
  const send = el('button', ''); decorate(send, plain(c.send_label,'Send',60), c.send_icon || 'none'); send.type = 'submit'; send.setAttribute('aria-label', 'Send message'); form.append(input, send);
  const stop = el('button', 'Stop request', 'stop'); stop.type = 'button'; stop.hidden = true; form.append(stop);
  const selection = el('div', '', 'selected-product'); selection.hidden = true; form.append(selection);
  const recovery = el('div', '', 'recovery'); recovery.hidden = true; recovery.setAttribute('role', 'status');
  const contacts = () => {
    const n = el('div', '', 'contact-options');
    if (c.handoff_url && safeUrl(c.handoff_url)) n.append(link('Open human support →', c.handoff_url));
    if (c.support_email && safeUrl('mailto:' + c.support_email)) n.append(link('Email support →', 'mailto:' + c.support_email));
    if (!n.children.length) n.append(el('small', 'Support contact is awaiting configuration. Please use the website contact page.'));
    return n;
  };
  const hideRecovery = () => { recovery.hidden = true; [...recovery.children].forEach(n => n.remove()); };
  const showRecovery = () => {
    hideRecovery(); recovery.hidden = false;
    recovery.append(el('p', 'Support could not verify this session. Previous conversation and account details stay hidden until a successful retry.'), contacts(), el('small', 'These contact links do not send a notification or include your chat.'));
    const retry = el('button', 'Try again'); retry.type = 'button'; retry.addEventListener('click', open); recovery.append(retry); retry.focus();
  };
  purchases.hidden = c.commerce === false;
  const archivePermission = el('label', '', 'archive-permission'); archivePermission.hidden = c.archive_enabled !== true;
  const archiveConsent = el('input'); archiveConsent.type = 'checkbox'; archiveConsent.checked = false; archiveConsent.setAttribute('aria-label', 'Save this chat for admin and AI-assisted review');
  archivePermission.append(archiveConsent, el('span', 'I agree to save this chat for admin and AI-assisted review.'));
  const deskStatus = el('p', '', 'desk-status'); deskStatus.hidden = true; deskStatus.setAttribute('role','status'); deskStatus.setAttribute('aria-live','polite');
  panel.append(header, log, deskStatus, recovery, actions, form, archivePermission); shell.append(panel, launch); root.append(shell); bubble(c.welcome);
  // Fit both the closed launcher and expanded chat without persisting visitor coordinates.
  const positionWidget = () => {
    const view=window.visualViewport, width=view?.width || window.innerWidth, height=view?.height || window.innerHeight;
    if (!Number.isFinite(width) || !Number.isFinite(height) || typeof shell.getBoundingClientRect !== 'function') return;
    const originX=view?.offsetLeft || 0, originY=view?.offsetTop || 0;
    shell.setAttribute('data-aisa-short',height<500?'true':'false');
    const offset=key => Number.isInteger(c[key]) && Math.abs(c[key])<=2000 ? c[key] : (width<=480?10:24);
    shell.style.setProperty('--aisa-viewport-height',height+'px');
    shell.style.setProperty('right','auto'); shell.style.setProperty('bottom','auto');
    const box=shell.getBoundingClientRect(), css=window.getComputedStyle?.(shell);
    const safe=side=>Math.max(10,parseFloat(css?.getPropertyValue('--aisa-safe-'+side))||0);
    let topSafe=safe('top'); const adminBar=document.getElementById('wpadminbar')?.getBoundingClientRect?.();
    if (adminBar && adminBar.top>=0 && adminBar.bottom>0 && adminBar.bottom<height/2) topSafe=Math.max(topSafe,adminBar.bottom-originY+10);
    const x=anchor[0]==='left'?offset('widget_offset_x'):anchor[0]==='right'?width-box.width-offset('widget_offset_x'):(width-box.width)/2+offset('widget_offset_x');
    const y=anchor[1]==='top'?offset('widget_offset_y'):anchor[1]==='bottom'?height-box.height-offset('widget_offset_y'):(height-box.height)/2+offset('widget_offset_y');
    const fit=(value,min,max)=>Math.max(min,Math.min(value,Math.max(min,max)));
    shell.style.setProperty('left',(originX+fit(x,safe('left'),width-box.width-safe('right')))+'px');
    shell.style.setProperty('top',(originY+fit(y,topSafe,height-box.height-safe('bottom')))+'px');
    if(height<500 && !panel.hidden && root.activeElement===input) {
      const field=form.getBoundingClientRect(), viewport=panel.getBoundingClientRect();
      if(field.bottom>viewport.bottom-4)panel.scrollTop+=field.bottom-viewport.bottom+4;
      else if(field.top<viewport.top+4)panel.scrollTop-=viewport.top-field.top+4;
    }
  };
  style.addEventListener('load',positionWidget);
  window.addEventListener?.('resize',positionWidget);
  window.visualViewport?.addEventListener('resize',positionWidget);
  window.visualViewport?.addEventListener('scroll',positionWidget);
  if (typeof window.ResizeObserver==='function') new window.ResizeObserver(positionWidget).observe(shell);
  positionWidget();
  const newSession = () => { if (!window.crypto?.getRandomValues) return ''; const bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes); return Array.from(bytes, x => x.toString(16).padStart(2, '0')).join(''); };
  let session = newSession(), generation = 0, remembering = false, expires = 0, viewExpires = 0, blocked = false, ticketCard = null, ticketViewExpires = 0, ticketIntake = Number(c.customer_tickets) === 1;
  const controllers = new Set();
  let activeTurn = null, selectedProduct = null, catalogCard = null;
  let liveMode = 'ai', liveGeneration = 1, liveCursor = 0, liveSync = null, reviewerAvailable = true;
  let memoryBase = 'Remember context', memoryIcon = 'none';
  const memoryLabel = () => { decorate(memory, memoryBase + ': ' + (remembering ? 'on' : 'off'), memoryIcon); memory.setAttribute('aria-pressed', String(remembering)); };
  function fresh(message = c.welcome) {
    if (activeTurn) { activeTurn.cancelled = true; clearTimeout(activeTurn.revealTimer); activeTurn.revealContinue?.(); activeTurn = null; }
    selectedProduct = null; selection.hidden = true; [...selection.children].forEach(n => n.remove()); catalogCard = null; stop.hidden = true; send.hidden = false; input.style.height = (Number(c.composer_height)>=40&&Number(c.composer_height)<=80?Number(c.composer_height):40)+'px';
    archiveConsent.checked = false; liveMode = 'ai'; liveGeneration = 1; liveCursor = 0; deskStatus.hidden = true; generation++; controllers.forEach(controller => controller.abort()); controllers.clear(); session = newSession(); expires = 0; viewExpires = 0;
    [...log.children].forEach(n => n.remove()); ticketCard = null; ticketViewExpires = 0; bubble(message); input.value = ''; input.disabled = false; send.disabled = false; purchases.disabled = false; catalogButton.disabled = false; human.disabled = false; tickets.disabled = false; panel.removeAttribute('aria-busy'); form.removeAttribute('aria-busy'); memoryLabel();
  }
  function identityError(error) {
    if (typeof error.code === 'string' && error.code.startsWith('conversation_')) return false;
    if (error.status === 409 && ['knowledge_changed','product_unapproved','product_changed'].includes(error.code)) return false;
    if (![401, 403, 409].includes(error.status)) return false;
    blocked = true; remembering = false; hideRecovery(); fresh('Your sign-in or support session changed. Reload to continue safely.');
    actions.hidden = true; form.hidden = true; log.hidden = false;
    const reload = el('button', 'Reload page'); reload.type = 'button'; reload.addEventListener('click', () => location.reload()); bubble('A fresh page prevents sharing history between accounts.').append(reload); return true;
  }
  async function request(path, body, owner) {
    const controller = new AbortController(); if (owner) owner.controller = controller; controllers.add(controller); const timer = setTimeout(() => controller.abort(), 45000);
    try {
      const response = await fetch(c.api + path, { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: { 'Content-Type': 'application/json', ...(c.nonce ? { 'X-WP-Nonce': c.nonce } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
      let result;
      try { result = await response.json(); }
      catch { const error = new Error('Support returned an unreadable response. Please retry or use contact options.'); error.status = response.status; throw error; }
      if (!response.ok) { const error = new Error(typeof result?.message === 'string' ? result.message : 'Support is unavailable.'); error.status = response.status; error.code = result?.code; throw error; }
      if (!result || typeof result !== 'object' || Array.isArray(result)) throw new Error('Support returned an unreadable response. Please retry or use contact options.');
      return result;
    } catch (error) { if (error.name === 'AbortError') throw new Error(owner?.cancelled ? 'AI request stopped in this tab. It may still finish on the server; no automatic retry.' : 'The request timed out. It may still finish on the server; no automatic retry.'); throw error; }
    finally { clearTimeout(timer); controllers.delete(controller); }
  }
  async function syncLive() {
    if (archivePermission.hidden || !archiveConsent.checked || blocked) return;
    if (liveSync) return liveSync;
    const epoch = generation, currentSession = session;
    const task = (async () => {
      const result = await request('conversation_poll', {scope:c.scope,session:currentSession,archive_consent:true,after:liveCursor});
      if (epoch !== generation || currentSession !== session || !archiveConsent.checked) return;
      if (!['ai','human','closed'].includes(result.mode) || !Number.isSafeInteger(result.generation) || result.generation < 1 || !Number.isInteger(result.cursor) || result.cursor < 0 || result.cursor > 40 || !Array.isArray(result.events) || result.events.length > 40 || typeof result.reviewer_available !== 'boolean' || !result.events.every(x=>x && Number.isInteger(x.sequence) && x.sequence > 0 && x.sequence <= 40 && ['customer','ai','human','notice'].includes(x.kind) && typeof x.text === 'string' && x.text.length <= 12000)) throw new Error('Support status is unavailable. Refresh before continuing.');
      liveMode = result.mode; liveGeneration = result.generation; reviewerAvailable = result.reviewer_available;
      const turn = activeTurn;
      if (turn && !turn.human && turn.liveGeneration && (turn.liveGeneration !== liveGeneration || liveMode !== 'ai')) {
        receiptStatus(turn,'Not confirmed'); turn.cancelled = true; turn.controller?.abort(); clearTimeout(turn.revealTimer); turn.revealContinue?.(); turn.answerBubble?.remove();
        if (turn.waiting) turn.waiting.textContent = 'AI reply stopped. A support agent changed this conversation.';
        activeTurn = null; releaseComposer();
      }
      deskStatus.hidden = liveMode === 'ai'; deskStatus.textContent = liveMode === 'closed' ? 'This support conversation is resolved.' : (reviewerAvailable ? 'A support agent has taken over. Your next message goes to them, not AI.' : 'Your support agent is currently unavailable. No message will be sent.');
      for (const event of result.events) if (event.sequence > liveCursor && ['human','notice'].includes(event.kind)) {
        const n=bubble('',event.kind==='human'?'human':'notice');
        if(event.kind==='human'){n.setAttribute('data-chat-message','true');n.append(el('small','Support agent','message-author'),el('span',event.text,'message-body'));}
        else n.textContent=event.text;
      }
      liveCursor = Math.max(liveCursor,result.cursor); trimLog();
      if (!activeTurn) send.disabled = liveMode === 'closed' || !reviewerAvailable;
    })();
    liveSync = task; try { await task; } finally { if (liveSync === task) liveSync = null; }
  }
  archiveConsent.addEventListener('change', () => { if (archiveConsent.checked) { ensure(); } else { deskStatus.hidden = true; } });
  async function ensure() {
    if (blocked) return null;
    if (ticketViewExpires && Date.now() >= ticketViewExpires) { ticketCard?.remove(); ticketCard = null; ticketViewExpires = 0; bubble('Ticket view expired. Refresh your support tickets to continue.'); }
    if ((expires && Date.now() >= expires) || (viewExpires && Date.now() >= viewExpires)) fresh('Support context expired. Start a fresh conversation.');
    const epoch = generation;
    try {
      const result = await request('context', { scope: c.scope, session, remember: remembering });
      if (typeof result.archive_enabled === 'boolean') { archivePermission.hidden = !result.archive_enabled; if (!result.archive_enabled) archiveConsent.checked = false; }
      if (epoch !== generation) return null;
      if (typeof result.customer_tickets === 'boolean') {
        const previous = ticketIntake; ticketIntake = result.customer_tickets; tickets.hidden = !ticketIntake;
        if (!ticketIntake) {
          ticketCard?.remove(); ticketCard = null; ticketViewExpires = 0;
          if (previous) bubble('Account-linked tickets are disabled. Existing requests keep their expiry; a request already submitted may still finish saving. Use the support contact options.');
        }
      }
      if (expires && (!result.expires || result.expired)) fresh('Public context expired. Start a fresh conversation.');
      memory.hidden = !result.memory_enabled;
      if (!result.memory_enabled && remembering) { remembering = false; fresh('Context memory is unavailable.'); }
      if (!viewExpires) viewExpires = Date.now() + 900000;
      await syncLive();
      return generation;
    } catch (error) { if (epoch !== generation) return null; if (!identityError(error)) { log.hidden = true; actions.hidden = true; form.hidden = true; showRecovery(); } return null; }
  }
  async function open() {
    hideRecovery();
    panel.hidden = false; launch.setAttribute('aria-expanded', 'true'); log.hidden = true; actions.hidden = true; form.hidden = true; positionWidget();
    const epoch = await ensure(); if (panel.hidden) return;
    if (epoch !== null) { hideRecovery(); log.hidden = false; actions.hidden = false; form.hidden = false; input.focus(); }
    else if (blocked) log.hidden = false;
  }
  const shut = () => { panel.hidden = true; launch.setAttribute('aria-expanded', 'false'); launch.focus(); positionWidget(); };
  launch.addEventListener('click', () => panel.hidden ? open() : shut()); close.addEventListener('click', shut);
  root.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) shut(); });
  const releaseComposer = () => { send.disabled = liveMode === 'closed' || !reviewerAvailable; send.hidden = false; stop.hidden = true; input.disabled = false; panel.removeAttribute('aria-busy'); if (!panel.hidden && !form.hidden) input.focus(); };
  const pendingStatus = () => { const n = bubble('', 'agent loading'); n.setAttribute('role', 'status'); n.append(el('span', 'AI is preparing an answer…', 'sr-only')); const dots = el('span', '', 'typing-dots'); dots.setAttribute('aria-hidden', 'true'); for (let i = 0; i < 3; i++) dots.append(el('span', '•')); n.append(dots); return n; };
  async function revealAnswer(text, turn) {
    const n = bubble('', 'agent'); n.setAttribute('data-revealing', 'true');
    n.setAttribute('data-chat-message','true'); turn.answerBubble=n;
    const accessible = el('span', text, 'sr-only'), visible = el('span'); visible.setAttribute('aria-hidden', 'true'); n.append(el('small',aiAuthor,'message-author'),accessible, visible,messageMeta().meta);
    const chars = Array.from(text), animate = window.matchMedia && !window.matchMedia('(prefers-reduced-motion: reduce)').matches && !document.hidden;
    if (!animate) { visible.textContent = text; n.removeAttribute('data-revealing'); return; }
    turn.answer = text; turn.answerNode = visible; stop.textContent = 'Show full answer';
    const chunk = Math.max(1, Math.ceil(chars.length / 10));
    for (let i = chunk; i < chars.length; i += chunk) {
      if (turn.cancelled || turn.epoch !== generation) return;
      if (turn.finishReveal || document.hidden) break;
      visible.textContent = chars.slice(0, i).join(''); log.scrollTop = log.scrollHeight;
      await new Promise(resolve => { turn.revealContinue = resolve; turn.revealTimer = setTimeout(resolve, 55); });
    }
    if (!turn.cancelled && turn.epoch === generation) visible.textContent = text;
    n.removeAttribute('data-revealing'); turn.answer = null;
  }
  stop.addEventListener('click', () => {
    const turn = activeTurn; if (!turn) return;
    if (turn.answer) { turn.finishReveal = true; clearTimeout(turn.revealTimer); turn.revealContinue?.(); return; }
    receiptStatus(turn,'Not confirmed'); turn.cancelled = true; turn.controller?.abort(); if (turn.waiting) turn.waiting.textContent = turn.human ? 'Support message stopped in this tab. It may already be saved; refresh before retrying.' : 'AI request stopped in this tab. It may still finish on the server; no automatic retry.';
    activeTurn = null; releaseComposer();
  });
  form.addEventListener('submit', async e => {
    e.preventDefault(); const message = input.value.trim(); if (!message || send.disabled || blocked) return;
    if (liveMode === 'human' && (archivePermission.hidden || !archiveConsent.checked)) { bubble('Saved-chat consent is required to message your support agent. No message was sent.'); return; }
    const turn = { epoch: generation, cancelled: false }; activeTurn = turn; send.disabled = true; send.hidden = true; stop.textContent = 'Stop request'; stop.hidden = false;
    let epoch = await ensure(); if (epoch !== null && turn.cancelled && !turn.waiting && generation !== turn.epoch && !blocked) { turn.cancelled = false; activeTurn = turn; } if (epoch === null || turn.cancelled) { if (activeTurn === turn) { activeTurn = null; releaseComposer(); } return; }
    turn.epoch = epoch; turn.liveGeneration = liveGeneration; turn.human = liveMode === 'human'; if (liveMode === 'closed' || !reviewerAvailable) { activeTurn = null; releaseComposer(); return; } send.disabled = true; input.disabled = true; input.value = ''; input.style.height = (Number(c.composer_height)>=40&&Number(c.composer_height)<=80?Number(c.composer_height):40)+'px'; if (!viewExpires) viewExpires = Date.now() + 900000; turn.receipt=customerMessage(message); const waiting = turn.human ? bubble('Sending to support agent…','notice') : pendingStatus(); turn.waiting = waiting; form.setAttribute('aria-busy', 'true'); if (!panel.hidden) stop.focus();
    try {
      const result = await request(turn.human ? 'conversation_message' : 'chat', turn.human ? {message,scope:c.scope,session,archive_consent:true,request_id:newSession()} : { message, scope: c.scope, session, remember: remembering, ...(!archivePermission.hidden && archiveConsent.checked === true ? { archive_consent: true } : {}), ...(selectedProduct ? { product_id: selectedProduct.id } : {}) }, turn);
      if (epoch !== generation || turn.cancelled) return;
      if (await ensure() !== epoch || turn.cancelled) { if (epoch === generation) waiting.remove(); return; }
      if (turn.human) { if (result.saved !== true) throw new Error('Message could not be confirmed. Refresh before retrying.'); receiptStatus(turn,'Saved for support'); waiting.textContent = 'Message saved for your support agent. No email or external message was sent.'; return; }
      if (typeof result.reply !== 'string' || !result.reply.trim() || result.reply.length > 12000 || (result.products !== undefined && !Array.isArray(result.products)) || (result.sources !== undefined && !Array.isArray(result.sources))) throw new Error('Support returned an incomplete answer. Please retry or use contact options.');
      receiptStatus(turn,'Sent');
      if (result.memory?.expired) { fresh('Previous public context expired.'); epoch = generation; turn.epoch = epoch; activeTurn = turn; turn.cancelled = false; turn.receipt=customerMessage(message,'Sent'); }
      else waiting.remove();
      // The complete response has arrived. Reveal is a brief local visual effect, not token streaming.
      panel.removeAttribute('aria-busy'); await revealAnswer(result.reply, turn);
      if (turn.cancelled || epoch !== generation) return;
      (result.products || []).forEach(p => { const card = el('div', '', 'card'); const title = link(p.name, p.url); title.className = 'product-title'; card.append(title, el('p', p.price), el('p', p.description)); log.append(card); });
      (result.sources || []).filter(s => s.url).forEach(s => { const row = el('div', '', 'source'); row.append(link(s.title, s.url)); log.append(row); }); trimLog();
      if (!archivePermission.hidden && archiveConsent.checked && result.archive_saved === false) bubble('This reply was not saved to the admin inbox.');
      if (remembering && result.memory?.saved) expires = result.memory.expires * 1000;
      else if (remembering) bubble('This turn was answered but its public context was not saved.');
    } catch (error) { if (epoch === generation && !turn.cancelled && !identityError(error)) { receiptStatus(turn,'Not confirmed'); waiting.className = 'bubble agent notice'; waiting.textContent = error.message; waiting.append(contacts()); } }
    finally { if (activeTurn === turn) { activeTurn = null; if (epoch === generation) { releaseComposer(); log.scrollTop = log.scrollHeight; } } }
  });
  const composerBase=Number.isInteger(Number(c.composer_height))&&Number(c.composer_height)>=40&&Number(c.composer_height)<=80?Number(c.composer_height):40;
  input.addEventListener('input', () => { input.style.height = composerBase+'px'; input.style.height = Math.min(Math.max(72,composerBase), Math.max(composerBase, input.scrollHeight || composerBase)) + 'px'; });
  input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); } });
  function chooseProduct(p) {
    selectedProduct = p; [...selection.children].forEach(n => n.remove()); selection.hidden = false;
    const clear = el('button', 'Remove selection'); clear.type = 'button'; clear.setAttribute('aria-label', 'Remove selected product'); clear.addEventListener('click', () => { selectedProduct = null; selection.hidden = true; [...selection.children].forEach(n => n.remove()); input.focus(); });
    selection.append(el('strong', p.name), clear); input.focus();
  }
  async function loadCatalog(page = 1) {
    if (catalogButton.disabled || blocked || c.commerce === false) return;
    catalogButton.disabled = true; const epoch = await ensure(); if (epoch === null) { catalogButton.disabled = false; return; }
    catalogCard?.remove(); const card = el('section', '', 'card catalog-box'); catalogCard = card; card.setAttribute('aria-label', 'Product catalog'); card.setAttribute('aria-busy', 'true');
    const status = el('p', 'Loading public products…', 'catalog-status'); status.setAttribute('role', 'status'); card.append(el('h3', 'View Catalog'), status); log.append(card); trimLog();
    try {
      const result = await request('browse_catalog', { scope: c.scope, page });
      if (epoch !== generation || await ensure() !== epoch || card !== catalogCard) return;
      const validLink = value => { try { const u = new URL(value); return ['http:', 'https:'].includes(u.protocol) && u.origin === location.origin && !u.username && !u.password && !['download_file','order','key','_wpnonce','add-to-cart','wc-ajax','remove_item','undo_item','pay_for_order','redirect_to'].some(k => u.searchParams.has(k)); } catch { return false; } };
      const validImage = image => {
        if (image === null) return true;
        try { const u = new URL(image.url), base = new URL(c.media_base || '/wp-content/uploads/', location.origin); return Number.isSafeInteger(image.id) && image.id > 0 && typeof image.alt === 'string' && Array.from(image.alt).length <= 250 && Number.isInteger(image.width) && Number.isInteger(image.height) && image.width > 0 && image.width <= 4096 && image.height > 0 && image.height <= 4096 && ['http:', 'https:'].includes(u.protocol) && !(base.protocol === 'https:' && u.protocol !== 'https:') && u.origin === base.origin && !u.username && !u.password && u.pathname.startsWith(base.pathname.replace(/\/$/, '') + '/') && !decodeURIComponent(u.pathname).split('/').some(p => p === '..' || p === '.' || p.includes('\\')) && !['key','order','_wpnonce','download_file'].some(k => u.searchParams.has(k)); } catch { return false; }
      };
      const validCommerce = p => { const x = p.commerce; return x && ['simple','select','unavailable'].includes(x.mode) && ['simple','variable','grouped','external','other'].includes(x.type) && ['in_stock','backorder','out_of_stock','unavailable','selection_required'].includes(x.stock) && typeof x.label === 'string' && x.label.length <= 60 && x.quantity === 1 && (x.mode === 'simple' ? p.available && x.type === 'simple' && ['in_stock','backorder'].includes(x.stock) && validLink(x.cart_url) && (x.buy_url === null || validLink(x.buy_url)) : x.cart_url === null && x.buy_url === null); };
      if (result.page !== page || !Array.isArray(result.products) || result.products.length > 12 || !(result.next_page === null || result.next_page === page + 1) || !result.products.every(p => p && Number.isSafeInteger(p.id) && p.id > 0 && typeof p.name === 'string' && Array.from(p.name).length <= 250 && typeof p.price === 'string' && Array.from(p.price).length <= 250 && typeof p.display_price === 'string' && Array.from(p.display_price).length <= 250 && typeof p.description === 'string' && Array.from(p.description).length <= 700 && typeof p.available === 'boolean' && typeof p.ai_ready === 'boolean' && validLink(p.url) && validImage(p.image) && validCommerce(p))) throw new Error('The catalog returned an incomplete page. Please retry.');
      status.textContent = result.products.length ? 'Page ' + page : (page === 1 && result.next_page === null ? 'No public products are available.' : 'No displayable products in this batch. Continue or go back.');
      const track = el('div', '', 'catalog-track'); track.setAttribute('role','list'); track.setAttribute('aria-label','Products on page ' + page); track.setAttribute('tabindex','0'); track.setAttribute('aria-description','Swipe or scroll horizontally. On this row use Left/Right arrows, Home/End, or Tab through product actions.');
      track.addEventListener('keydown', e => { if (e.target !== track || !['ArrowLeft','ArrowRight','Home','End'].includes(e.key)) return; e.preventDefault(); const behavior = 'auto'; if (e.key === 'Home' || e.key === 'End') track.scrollTo?.({left:e.key === 'Home' ? 0 : track.scrollWidth,behavior}); else track.scrollBy?.({left:(e.key === 'ArrowRight' ? 1 : -1)*Math.max(200,track.clientWidth*.85),behavior}); });
      track.addEventListener('focusin', e => { if (e.target !== track) e.target?.scrollIntoView?.({block:'nearest',inline:'nearest'}); }); card.append(track);
      result.products.forEach(p => {
        const item = el('div','','catalog-item'); item.setAttribute('role','listitem'); const row = el('article', '', 'catalog-product'); row.setAttribute('aria-label',p.name); item.append(row);
        const media = link('', p.url); media.className = 'product-image'; media.setAttribute('aria-label', 'Open ' + p.name); const missing = el('span', p.image ? 'Image unavailable' : 'No product image', 'image-empty'); missing.hidden = !!p.image; media.append(missing);
        if (p.image) { const img = el('img'); img.alt = p.image.alt || p.name; img.width = p.image.width; img.height = p.image.height; img.loading = 'lazy'; img.decoding = 'async'; img.referrerPolicy = 'no-referrer'; img.crossOrigin = 'anonymous'; img.setAttribute('data-attachment-id', String(p.image.id)); img.addEventListener('error', () => { img.remove(); missing.hidden = false; }); img.src = p.image.url; media.append(img); }
        const info = el('div', '', 'product-info'), title = link(p.name, p.url); title.className = 'product-title';
        const stock = { in_stock: 'Available', backorder: 'Available on backorder', out_of_stock: 'Out of stock', unavailable: 'Currently unavailable', selection_required: 'Check product options' }[p.commerce.stock];
        info.append(title, el('p', p.display_price || 'Price not listed', 'product-price')); if (['backorder','out_of_stock','unavailable'].includes(p.commerce.stock)) info.append(el('span', stock, 'stock ' + p.commerce.stock));
        const buttons = el('div', '', 'product-buttons');
        if (p.commerce.mode === 'simple') {
          for (const [label, url] of [['Add to cart', p.commerce.cart_url], ['Buy now', p.commerce.buy_url]].filter(([, url]) => url)) {
            const native = el('form', '', 'native-cart'); native.method = 'post'; native.action = url; native.target = '_blank'; native.rel = 'noopener noreferrer';
            for (const [name, value] of [['add-to-cart', p.id], ['quantity', 1], ['aisa_catalog', 1]]) { const field = el('input'); field.type = 'hidden'; field.name = name; field.value = String(value); native.append(field); }
            const submit = el('button', label); submit.type = 'submit'; submit.setAttribute('aria-label', label + ': ' + p.name); native.append(submit); buttons.append(native);
          }
          // Native fixed-quantity forms preserve the existing cart; no redundant visible helper line.
        } else if (p.commerce.mode === 'select') { const options = link(p.commerce.label, p.url); options.className = 'product-options'; buttons.append(options); }
        row.append(media, info, buttons, el('p', p.description, 'catalog-description')); track.append(item);
      });
      const controls = el('div', '', 'catalog-pages'); if (page > 1) { const prev = el('button', 'Previous products'); prev.type = 'button'; prev.addEventListener('click', () => loadCatalog(page - 1)); controls.append(prev); } if (result.next_page !== null) { const next = el('button', 'Next products'); next.type = 'button'; next.addEventListener('click', () => loadCatalog(result.next_page)); controls.append(next); } card.append(controls); if (page > 1) track.focus();
    } catch (error) { if (epoch === generation && card === catalogCard && !identityError(error)) { status.textContent = error.message; const retry = el('button', 'Retry catalog'); retry.type = 'button'; retry.addEventListener('click', () => loadCatalog(page)); card.append(retry); } }
    finally { if (epoch === generation) { catalogButton.disabled = false; card.removeAttribute('aria-busy'); card.scrollIntoView?.({ block: 'nearest' }); } }
  }
  catalogButton.addEventListener('click', () => loadCatalog(1));
  purchases.addEventListener('click', async () => {
    if (purchases.disabled || blocked || c.commerce === false) return;
    purchases.disabled = true; let epoch = generation;
    try {
      const checked = await ensure(); if (checked === null) return; epoch = checked;
      if (!c.loggedIn) { const destination=accountUrl(c.downloads_page); bubble('Please sign in on the website to view your purchases and available downloads.').append(link(destination ? 'My Downloads →' : 'Sign in →', destination || c.login)); return; }
      if (!viewExpires) viewExpires = Date.now() + 900000;
      const results = await Promise.allSettled([request('orders'), request('downloads')]);
      if (epoch !== generation || await ensure() !== epoch) return;
      if (results.some(result => result.status === 'rejected' && identityError(result.reason))) return;
      const orderRows = results[0].status === 'fulfilled' ? results[0].value?.orders : null;
      const downloadRows = results[1].status === 'fulfilled' ? results[1].value?.downloads : null;
      const orders = Array.isArray(orderRows) && orderRows.every(o => o && ['string','number'].includes(typeof o.number) && typeof o.status === 'string' && typeof o.url === 'string' && Array.isArray(o.items) && o.items.every(i => i && typeof i.name === 'string' && ['string','number'].includes(typeof i.quantity))) ? orderRows : null;
      const downloads = Array.isArray(downloadRows) && downloadRows.every(d => d && typeof d.name === 'string' && typeof d.url === 'string') ? downloadRows : null;
      if (!orders) bubble('Orders are temporarily unavailable. Retry My purchases or use support contact options.');
      if (!downloads) bubble('Downloads are temporarily unavailable. Retry My purchases or use support contact options.');
      if (orders && downloads && !orders.length && !downloads.length) bubble('No purchases or available downloads found for your account. Contact support if you purchased as a guest.');
      orders?.forEach(o => { const n = bubble('Order #' + o.number + ' · ' + o.status + '\n' + o.items.map(i => i.name + ' × ' + i.quantity).join('\n')); n.append(link('View order →', o.url)); });
      downloads?.forEach(d => bubble(d.name));
      const destination = accountUrl(results[1].status === 'fulfilled' ? results[1].value?.account_url : '') || accountUrl(c.downloads_page);
      if (destination) bubble('Open your account to view and download your available files.').append(link('My Downloads →', destination));
      else if (downloads) bubble('The account Downloads page is temporarily unavailable. Please open My Account on the website.');
    } catch (error) { if (epoch === generation && !identityError(error)) bubble(error.message); } finally { if (epoch === generation) purchases.disabled = false; }
  });
  payment.addEventListener('click', async () => {
    if (await ensure() === null) return; const n = bubble(c.payment_text || 'Choose a product above to pay through our secure store checkout.');
    if (c.qr_url && safeUrl(c.qr_url)) { const img = el('img'); img.src = safeUrl(c.qr_url); img.alt = 'Store payment QR code'; img.width = 180; img.height = 180; n.append(img); }
    n.append(el('small', 'Payment is confirmed by the store, not by this chat.'));
  });
  human.addEventListener('click', async () => {
    const epoch = await ensure(); if (epoch === null) return; const n = bubble('Contact our team using the options below.');
    n.append(contacts());
    if (!(c.handoff_url && safeUrl(c.handoff_url)) && !(c.support_email && safeUrl('mailto:' + c.support_email))) return;
    human.disabled = true;
    try { const result = await request('handoff', {}); if (epoch === generation) n.append(el('small', result.message)); } catch (error) { if (epoch === generation && !identityError(error)) n.append(el('small', error.message)); } finally { if (epoch === generation) human.disabled = false; }
  });
  const ticketTopics = c.ticket_topics || { general: 'General support', catalog: 'Product information', knowledge: 'Knowledge review', orders: 'Order access review', downloads: 'Download access review' };
  const ticketStatuses = { open: 'Open', in_review: 'In review', resolved: 'Resolved · review status only' };
  const ticketIdentityError = error => ([401, 403].includes(error.status) || error.code === 'identity_changed') && identityError(error);
  function ticketRow(ticket) {
    if (!ticket || !Number.isSafeInteger(ticket.id) || ticket.id < 1 || !Object.prototype.hasOwnProperty.call(ticketTopics, ticket.topic) || !Object.prototype.hasOwnProperty.call(ticketStatuses, ticket.status) || !['created', 'updated', 'expires'].every(key => Number.isSafeInteger(ticket[key]) && ticket[key] > 0 && ticket[key] < 8640000000000) || ticket.expires !== ticket.created + 604800) throw new Error('Ticket status is unavailable. Refresh or use support contact options.');
    const row = el('div', '', 'ticket-row');
    row.append(el('strong', '#' + ticket.id + ' · ' + ticketTopics[ticket.topic]), el('p', ticketStatuses[ticket.status]), el('small', 'Account-linked support request'));
    if (ticket.review_availability !== undefined && !['unassigned', 'assigned', 'unavailable'].includes(ticket.review_availability)) throw new Error('Reviewer availability is unavailable. Please refresh.');
    if (ticket.review_availability === 'unavailable') row.append(el('p', ticket.status === 'resolved' ? 'The reviewer is unavailable. The recorded resolved review status is unchanged; it is not a refund or delivery confirmation.' : 'Review is paused because the assigned reviewer is unavailable. Use support contact options; this request is not automatically reassigned.', 'ticket-help'));
    return row;
  }
  tickets.addEventListener('click', async () => {
    if (tickets.disabled || blocked) return; tickets.disabled = true;
    const epoch = await ensure(); if (epoch === null) { tickets.disabled = false; return; }
    if (!ticketIntake) { tickets.disabled = false; return; }
    if (!c.loggedIn) { bubble('Sign in to submit a structured support request or view your own ticket status.').append(link('Sign in →', c.login)); tickets.disabled = false; return; }
    ticketCard?.remove(); ticketCard = null;
    try {
      const data = await request('tickets/status', { scope: c.scope });
      if (epoch !== generation || await ensure() !== epoch) return;
      if (!Array.isArray(data.tickets) || data.tickets.length > 3) throw new Error('Ticket status is unavailable. Please refresh.');
      const current = data.tickets.filter(ticket => { ticketRow(ticket); return ticket.expires * 1000 > Date.now(); });
      const rows = current.map(ticketRow); ticketViewExpires = current.length ? Math.min(...current.map(ticket => ticket.expires * 1000)) : 0;
      const card = el('div', '', 'card ticket-box'); ticketCard = card; card.setAttribute('role', 'region'); card.setAttribute('aria-label', 'Account-linked support tickets');
      const list = el('div', '', 'ticket-list'); list.append(...rows); if (!rows.length) list.append(el('p', 'No saved support requests for your account.'));
      const refresh = el('button', 'Refresh ticket status'); refresh.type = 'button'; refresh.addEventListener('click', () => tickets.click());
      card.append(el('h3', 'My support tickets'), list, refresh, el('p', 'Submit only a topic for human review. Up to 3 saved requests and 5 submissions per day. No chat text, contact details, order IDs or download links are included. No notification or guaranteed reply.', 'ticket-help'));
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
          submit.disabled = true; tickets.disabled = true; refresh.disabled = true; const submitEpoch = await ensure(); if (submitEpoch === null || !ticketIntake) { submit.disabled = false; tickets.disabled = false; refresh.disabled = false; return; }
          topic.disabled = true; consent.disabled = true; resultText.textContent = 'Saving your structured request…'; box.setAttribute('aria-busy', 'true');
          try {
            const result = await request('tickets/submit', { scope: c.scope, topic: topic.value, confirm: true, request_id: requestId });
            if (submitEpoch !== generation || await ensure() !== submitEpoch || !ticketIntake) return;
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
  memory.addEventListener('click', async () => { if (remembering) { await clearChat(true); return; } remembering = true; fresh('Public context memory is on for this tab only. No transcript is saved.'); await ensure(); });
  let suggest = null;
  if (Number(c.learning)) {
    suggest = el('button', 'Suggest an improvement'); suggest.type = 'button'; // Workflow retained without a visible widget entry point.
    suggest.addEventListener('click', async () => {
      if (await ensure() === null) return; const box = el('form', '', 'suggestion'); const text = el('textarea'); text.required = true; text.minLength = 10; text.maxLength = 700; text.placeholder = 'Public help information only — no personal details'; text.setAttribute('aria-label', 'Knowledge suggestion');
      const submit = el('button', 'Submit for review'); submit.type = 'submit'; box.append(el('p', 'Admin review required. Do not include personal information.'), text, submit); log.append(box); trimLog();
      box.addEventListener('submit', async e => { e.preventDefault(); const epoch = await ensure(); if (epoch === null) return; submit.disabled = true; try { const result = await request('suggest', { text: text.value }); if (epoch === generation) { box.remove(); bubble(result.message); } } catch (error) { if (epoch === generation && !identityError(error)) { bubble(error.message); submit.disabled = false; } } });
    });
  }
  // Settings only choose a bounded known workflow or a public navigation/question prefill.
  if (Array.isArray(c.quick_actions) && c.quick_actions.length <= 10) {
    [...actions.children].forEach(n => n.remove());
    const presets = {catalog:catalogButton,purchases,tickets,human,payment,memory,suggest}; const seen = new Set();
    for (const row of c.quick_actions) {
      if (!row || typeof row.id !== 'string' || seen.has(row.id) || typeof row.label !== 'string' || !row.label.trim() || row.label.length > 120 || !Object.prototype.hasOwnProperty.call(icons,row.icon)) continue;
      seen.add(row.id); const node = presets[row.id];
      if (node) { if (row.id === 'memory') { memoryBase = row.label; memoryIcon = row.icon; memoryLabel(); } else decorate(node,row.label,row.icon); actions.append(node); continue; }
      if (!/^custom_[1-3]$/.test(row.id)) continue;
      if (row.kind === 'question' && typeof row.value === 'string' && row.value.length > 0 && row.value.length <= 500) { const b=el('button');b.type='button';decorate(b,row.label,row.icon);b.addEventListener('click',async()=>{if(await ensure() === null)return;input.value=row.value;input.focus();});actions.append(b); }
      if (row.kind === 'page' && typeof row.url === 'string') { try { const u = new URL(row.url); if (u.origin !== location.origin || !['http:','https:'].includes(u.protocol) || u.username || u.password || ['download_file','order','key','_wpnonce','add-to-cart','wc-ajax','remove_item','pay_for_order','redirect_to'].some(k=>u.searchParams.has(k))) continue; const a=link('',u.href);decorate(a,row.label,row.icon);actions.append(a); } catch {} }
    }
  }
  const recheck = () => { if (!panel.hidden) { log.hidden = true; actions.hidden = true; form.hidden = true; return open(); } };
  window.addEventListener?.('focus', recheck); window.addEventListener?.('pageshow', recheck);
  document.addEventListener?.('visibilitychange', () => { if (!document.hidden) recheck(); });
  let lastSafetyPoll = 0;
  window.setInterval?.(() => { if (!panel.hidden && !document.hidden && (archiveConsent.checked || Date.now() - lastSafetyPoll >= 30000)) { lastSafetyPoll = Date.now(); return ensure(); } }, 5000);
})();
