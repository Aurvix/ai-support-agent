(() => {
  'use strict';
  const root = document.querySelector('.aisa-admin');
  if (!root) return;
  const themeChoice = root.querySelector('[name="ui_theme"]');
  const appearanceChoice = root.querySelector('[name="admin_theme"]');
  const themePreview = root.querySelector('[data-aisa-theme-preview]');
  if (themeChoice && appearanceChoice && themePreview) {
    let previewPalettes={};try{previewPalettes=JSON.parse(themePreview.dataset?.palettes || '{}');}catch{}
    const previewTheme = () => {
      themePreview.setAttribute('data-ui-theme',['classic','messenger','ocean','slate','forest','indigo','rose','amber','teal','plum'].includes(themeChoice.value)?themeChoice.value:'classic');
      const appearance=appearanceChoice.value==='dark'?'dark':'light';themePreview.setAttribute('data-theme',appearance);
      const id=['classic','messenger','ocean','slate','forest','indigo','rose','amber','teal','plum'].includes(themeChoice.value)?themeChoice.value:'classic';
      const palette=previewPalettes[id]?.[appearance]||{};
      for(const [key,value]of Object.entries(palette))if(/^--ui-(primary-ink|accent|focus|bg|panel|text|muted|line|outgoing|incoming|hover|hero|hero-text|hero-muted)$/.test(key)&&typeof value==='string'&&/^#[0-9a-f]{6}$/i.test(value))themePreview.style.setProperty(key,value);
    };
    themeChoice.addEventListener('change',previewTheme); appearanceChoice.addEventListener('change',previewTheme);
  }
  const config = root.querySelector('#aisa-provider-config');
  if (config) {
    let presets = {}; try { presets = JSON.parse(config.dataset.presets || '{}'); } catch (_) { /* Server validation remains authoritative. */ }
    const provider = root.querySelector('[name="provider"]');
    const choice = root.querySelector('[name="model_choice"]');
    const summary = root.querySelector('#aisa-provider-summary');
    const custom = root.querySelector('#aisa-custom-endpoint');
    const manual = root.querySelector('#aisa-manual-model');
    const model = root.querySelector('[name="model"]');
    const option = (value, text) => { const node = document.createElement('option'); node.value = value; node.textContent = text; choice.append(node); };
    const sync = changed => {
      const preset = presets[provider.value];
      const models = preset?.models || {};
      if (changed) {
        Array.from(choice.options).forEach(node => node.remove());
        if (preset) option('__auto__', 'Keep current automatic default — ' + preset.model);
        Object.entries(models).forEach(([id, entry]) => option(id, entry.name + ' — ' + id));
        option('__manual__', 'Manual override — enter an exact model ID');
        // A provider change is still unsaved. Never silently move its stored credential.
        choice.value = Object.keys(models)[0] || '__manual__';
      }
      custom.hidden = !!preset;
      manual.hidden = choice.value !== '__manual__';
      if (!preset || choice.value === '__manual__') config.querySelector('details').open = true;
      const id = choice.value === '__auto__' ? (preset?.model || '') : (choice.value === '__manual__' ? model.value.trim() : choice.value);
      const availability = models[id]?.availability || 'Manual/gateway model; availability not checked';
      summary.textContent = 'Selected model: ' + (id || 'Enter an exact model ID') + '. ' + availability + (preset ? '. Official URL: ' + preset.endpoint : '. Use the model catalog and HTTPS URL supplied by your gateway') + '. Save this view to apply changes. No request is sent by selecting a model.';
    };
    provider.addEventListener('change', () => sync(true)); choice.addEventListener('change', () => sync(false)); model.addEventListener('input', () => sync(false)); sync(false);
  }
  const avatarButton = root.querySelector('[data-aisa-avatar]');
  if (avatarButton) avatarButton.addEventListener('click', () => {
    const input = root.querySelector('[name="avatar_id"]');
    if (!input || !window.wp?.media) { avatarButton.textContent = 'Open Media Library; enter a public image ID above'; return; }
    const picker = window.wp.media({title:'Choose a public profile image',button:{text:'Use this image'},library:{type:['image/jpeg','image/png','image/gif','image/webp','image/avif']},multiple:false});
    picker.on('select', () => { const id = Number(picker.state().get('selection').first()?.get('id')); if (!Number.isSafeInteger(id) || id < 1) return; input.value = String(id); input.dispatchEvent(new Event('input',{bubbles:true})); });
    picker.open();
  });
  const settings = root.querySelector('#aisa-settings');
  const probe = root.querySelector('[data-aisa-probe]');
  let dirty = false, submitting = false;
  const changed = event => { if (event.target.form === settings) { dirty = true; if (probe) { probe.disabled = true; probe.title = 'Save model changes before testing the saved connection.'; } } };
  root.addEventListener('change', changed); root.addEventListener('input', changed);
  root.addEventListener('submit', event => { if (event.target === settings) submitting = true; });
  window.addEventListener('beforeunload', event => { if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; } });
  const desk = root.querySelector('[data-aisa-inbox]');
  if (desk) {
    let busy = false, replyDirty = false, replySubmitting = false;
    const transcript = desk.querySelector('[role="log"]');
    if (transcript) transcript.scrollTop = transcript.scrollHeight;
    // Local presentation tools never submit a reply, transcript or settings.
    const back = desk.querySelector('[data-aisa-chat-back]');
    if (back) {
      desk.dataset.enhanced = 'true'; desk.dataset.view = 'chat';
      back.addEventListener('click', () => {
        desk.dataset.view = 'list'; desk.querySelector('input[name="chat_search"]')?.focus();
      });
      for (const selected of desk.querySelectorAll?.('[data-chat-list-id][aria-current="true"]') || []) {
        selected.addEventListener('click', event => {
          event.preventDefault(); desk.dataset.view = 'chat'; transcript?.focus();
        });
      }
    }
    const query = desk.querySelector('[data-aisa-thread-query]');
    if (query) {
      desk.querySelector('[data-aisa-thread-search]')?.removeAttribute('hidden');
      const messages = [...(desk.querySelectorAll?.('.aisa-staff-message') || [])];
      const previous = desk.querySelector('[data-aisa-thread-prev]');
      const next = desk.querySelector('[data-aisa-thread-next]');
      const count = desk.querySelector('[data-aisa-thread-count]');
      let matches = [], current = -1;
      const report = () => { if (count) count.textContent = query.value ? (matches.length ? (current >= 0 ? (current + 1) + ' of ' : '') + matches.length + ' matching messages' : 'No matching messages') : ''; };
      const search = () => {
        const text = query.value.slice(0, 100).trim().toLocaleLowerCase(); current = -1;
        matches = text ? messages.filter(m => m.textContent.toLocaleLowerCase().includes(text)) : [];
        for (const m of messages) { m.classList.toggle('aisa-message-match', matches.includes(m)); m.classList.remove('aisa-message-current'); }
        if (previous) previous.disabled = !matches.length; if (next) next.disabled = !matches.length; report();
      };
      const advance = direction => {
        if (!matches.length) return;
        current = current < 0 ? (direction > 0 ? 0 : matches.length - 1) : (current + direction + matches.length) % matches.length;
        for (const m of messages) m.classList.toggle('aisa-message-current', m === matches[current]);
        matches[current].scrollIntoView?.({block:'nearest',behavior:'auto'}); matches[current].focus(); report();
      };
      query.addEventListener('input', search);
      query.addEventListener('keydown', event => {
        if (event.isComposing) return;
        if (event.key === 'Enter') { event.preventDefault(); advance(event.shiftKey ? -1 : 1); }
        if (event.key === 'Escape') { event.preventDefault(); query.value = ''; search(); }
      });
      previous?.addEventListener('click', () => advance(-1)); next?.addEventListener('click', () => advance(1));
    }
    const insert = desk.querySelector('[data-aisa-insert-reply]');
    if (insert) desk.querySelector('[data-aisa-reply-shortcuts]')?.removeAttribute('hidden');
    if (insert) insert.addEventListener('click', () => {
      const choices = {greeting:'Hello! How can I help you today?',details:'Could you share a little more detail about the issue? Please leave out passwords and payment details.'};
      const choice = desk.querySelector('[data-aisa-reply-template]');
      const textarea = desk.querySelector('.aisa-chat-composer textarea[name="message"]');
      const feedback = desk.querySelector('[data-aisa-reply-feedback]');
      const text = choice && Object.hasOwn(choices, choice.value) ? choices[choice.value] : '';
      if (!textarea || textarea.disabled || !text) { if (feedback) feedback.textContent = 'Choose a quick reply first.'; return; }
      const draft = textarea.value + (textarea.value ? '\n' : '') + text;
      if (draft.length > 2000) { if (feedback) feedback.textContent = 'The draft is too long. No text was inserted.'; return; }
      textarea.value = draft; choice.value = ''; textarea.dispatchEvent(new Event('input',{bubbles:true})); textarea.focus();
      if (feedback) feedback.textContent = 'Added to your draft. Review before sending.';
    });
    root.addEventListener('input',event=>{if(event.target.form?.querySelector('input[name="operation"]')?.value==='human_reply')replyDirty=true;});
    root.addEventListener('submit',event=>{if(event.target.querySelector('input[name="operation"]')?.value==='human_reply')replySubmitting=true;});
    window.addEventListener('beforeunload',event=>{if(replyDirty&&!replySubmitting){event.preventDefault();event.returnValue='';}});
    const note = root.querySelector('[data-aisa-inbox-updates]');
    const refresh = async () => {
      if (busy || document.hidden) return; busy = true;
      const controller = new AbortController(); const timer = setTimeout(()=>controller.abort(),8000);
      try {
        const url = new URL(desk.dataset.api, location.origin);
        if (url.origin !== location.origin) throw new Error('origin');
        const response = await fetch(url.href,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'Content-Type':'application/json','X-WP-Nonce':desk.dataset.nonce},body:'{}'});
        if (!response.ok) throw new Error('status'); const result = await response.json();
        if (!Array.isArray(result.chats) || result.chats.length > 100) throw new Error('shape');
        let changed = false;
        for (const chat of result.chats) {
          if (!Number.isSafeInteger(chat.id) || !Number.isSafeInteger(chat.revision) || !['ai','human','closed'].includes(chat.mode) || typeof chat.pending !== 'boolean') throw new Error('shape');
          const row = desk.querySelector('[data-chat-id="'+chat.id+'"]'); if (!row) continue;
          const badge = row.querySelector('[data-chat-status]'); if (badge) badge.textContent = chat.pending ? 'AI preparing reply' : ({ai:'AI active',human:'Support agent active',closed:'Resolved'}[chat.mode]);
          if (String(chat.revision) !== row.dataset.chatRevision) { changed = true; const claim = row.querySelector('input[name="operation"][value="takeover"]')?.form?.querySelector('input[name="revision"]'); if (claim) claim.value = String(chat.revision); }
        }
        if (note) note.textContent = changed ? 'New messages or actions arrived. Refresh Inbox to read them; your typed reply has not been changed.' : '';
      } catch (_) { if (note) note.textContent = 'Live status unavailable. Refresh Inbox before taking action. No reply was sent.'; }
      finally { clearTimeout(timer); busy = false; }
    };
    window.setInterval?.(refresh,5000);
  }
})();
