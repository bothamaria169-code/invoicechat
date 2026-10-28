/* InvoiceChat — main chat UI script */
'use strict';

(function () {
  // ── State ────────────────────────────────────────────────────────
  let currentInvoiceId = null;
  let isWaiting        = false;
  let allInvoices      = [];
  let listFilter       = 'all';

  // ── DOM refs ─────────────────────────────────────────────────────
  const messagesArea   = document.getElementById('messagesArea');
  const messageInput   = document.getElementById('messageInput');
  const sendBtn        = document.getElementById('sendBtn');
  const chatList       = document.getElementById('chatList');
  const chatTitle      = document.getElementById('chatTitle');
  const chatSubtitle   = document.getElementById('chatSubtitle');
  const chatIcon       = document.getElementById('chatIcon');
  const headerActions  = document.getElementById('headerActions');
  const typingEl       = document.getElementById('typingIndicator');
  const logoInput      = document.getElementById('logoFileInput');
  const sidebar        = document.getElementById('sidebar');
  const backdrop       = document.getElementById('sidebarBackdrop');
  const csrf           = (window.IC || {}).csrf || '';

  // ── Init ─────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    loadChatList();
    bindEvents();
    // chat.php?open=ID (from the dashboard) opens that document straight away.
    const open = new URLSearchParams(location.search).get('open');
    if (open) {
      loadChat(open);
      history.replaceState(null, '', location.pathname);
    } else {
      sendInit();
    }
  });

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body),
    }).then(r => r.json());
  }

  // ── Events ───────────────────────────────────────────────────────
  function bindEvents() {
    sendBtn.addEventListener('click', handleSend);
    messageInput.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleSend(); }
    });
    messageInput.addEventListener('input', autoGrow);

    document.querySelectorAll('.quick-action-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        if (btn.dataset.fill) {
          // "Describe it": start the message for them and let them type the rest.
          messageInput.value = btn.dataset.fill;
          messageInput.placeholder = 'e.g. Invoice Acme for 3 logos at R500 each, 15% VAT, due in 14 days';
          messageInput.focus();
          return;
        }
        sendMessage(btn.dataset.msg);
      });
    });

    document.getElementById('newChatBtn').addEventListener('click', startNewChat);
    if (logoInput) logoInput.addEventListener('change', handleLogoUpload);

    // Search + filter tabs
    document.getElementById('sidebarSearch').addEventListener('input', () => renderChatList());
    document.querySelectorAll('.sidebar-tabs button').forEach(b => b.addEventListener('click', () => {
      document.querySelectorAll('.sidebar-tabs button').forEach(x => x.classList.toggle('is-on', x === b));
      listFilter = b.dataset.filter;
      renderChatList();
    }));

    // Mobile sidebar
    document.getElementById('mobileMenuBtn').addEventListener('click', openSidebar);
    backdrop.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

    // Light / dark
    document.getElementById('themeBtn').addEventListener('click', () => {
      const root = document.documentElement;
      const dark = root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
      root.dataset.theme = dark ? 'light' : 'dark';
      try { localStorage.setItem('ic-theme', root.dataset.theme); } catch (e) {}
    });
  }

  function openSidebar()  { sidebar.classList.add('open');  backdrop.classList.add('show'); }
  function closeSidebar() { sidebar.classList.remove('open'); backdrop.classList.remove('show'); }

  function autoGrow() {
    messageInput.style.height = 'auto';
    messageInput.style.height = Math.min(messageInput.scrollHeight, 140) + 'px';
  }

  // ── Init call (greeting) ─────────────────────────────────────────
  function sendInit() {
    setWaiting(true);
    post('ajax/send_message.php', { message: '__init__', invoice_id: null })
      .then(data => {
        setWaiting(false);
        (data.messages || []).forEach(m => appendBotMessage(m));
        if (data.invoice_id) currentInvoiceId = data.invoice_id;
        scrollBottom();
      })
      .catch(err => { setWaiting(false); console.error(err); });
  }

  // ── Send message ─────────────────────────────────────────────────
  function handleSend() {
    const text = messageInput.value.trim();
    if (!text || isWaiting) return;
    sendMessage(text);
    messageInput.value = '';
    autoGrow();
  }

  function sendMessage(text) {
    if (isWaiting) return;
    appendUserMessage(text);
    setWaiting(true);

    post('ajax/send_message.php', { message: text, invoice_id: currentInvoiceId })
      .then(data => {
        setWaiting(false);
        if (data.error) { appendBotMessage({ type: 'text', content: 'Error: ' + data.error }); return; }
        if (data.invoice_id) currentInvoiceId = data.invoice_id;
        (data.messages || []).forEach(m => appendBotMessage(m));
        if (data.usage) {
          const badge = document.getElementById('usageBadge');
          if (badge) badge.textContent = data.usage.replace(/^\w+ · /, '').replace(' left', ' documents left').replace(' + ', ' · ');
        }
        scrollBottom();
        loadChatList();
      })
      .catch(err => {
        setWaiting(false);
        appendBotMessage({ type: 'text', content: 'Connection problem. Check your internet and try again.' });
        console.error(err);
      });
  }

  // ── Render messages ───────────────────────────────────────────────
  function appendUserMessage(text) {
    const row = document.createElement('div');
    row.className = 'msg-row user';
    row.innerHTML = `<div class="msg-bubble">${formatText(text)}</div>`;
    messagesArea.appendChild(row);
    scrollBottom();
  }

  function appendBotMessage(msg) {
    switch (msg.type) {
      case 'text':    appendTextBubble(msg.content); break;
      case 'buttons': appendButtonsBubble(msg); break;
      case 'file':    appendFileBubble(msg); break;
      case 'upload':  appendUploadBubble(msg.content); break;
      case 'whatsapp': appendWhatsAppBubble(msg); break;
      default:        if (msg.content) appendTextBubble(msg.content);
    }
  }

  function botRow(inner) {
    const row = document.createElement('div');
    row.className = 'msg-row bot';
    row.innerHTML = `<div class="bot-avatar"></div>${inner}`;
    return row;
  }

  function appendTextBubble(text) {
    messagesArea.appendChild(botRow(`<div class="msg-bubble">${formatText(text)}</div>`));
  }

  function appendButtonsBubble(msg) {
    const group = document.createElement('div');
    group.className = 'msg-group';
    group.appendChild(botRow(`<div class="msg-bubble">${formatText(msg.content)}</div>`));

    const btns = document.createElement('div');
    btns.className = 'msg-buttons';
    (msg.buttons || []).forEach(label => {
      const b = document.createElement('button');
      b.className = 'msg-btn';
      b.textContent = label;
      b.addEventListener('click', () => {
        if (isWaiting) return;
        // A choice is made once: grey the group out so it can't be sent twice.
        btns.querySelectorAll('button').forEach(x => { x.disabled = true; });
        sendMessage(label.split('–')[0].trim()); // command part (before '–')
      });
      btns.appendChild(b);
    });
    group.appendChild(btns);
    messagesArea.appendChild(group);
  }

  function appendFileBubble(msg) {
    messagesArea.appendChild(botRow(`
      <a class="msg-file" href="${escAttr(msg.url)}" target="_blank" rel="noopener">
        <span class="msg-file-icon"><i class="fa-solid fa-file-pdf"></i></span>
        <div>
          <div class="msg-file-label">${escHtml(msg.label || 'Download PDF')}</div>
          <div class="msg-file-sub">Tap to open or download</div>
        </div>
        <i class="fa-solid fa-download"></i>
      </a>`));
  }

  /** Opens the user's own WhatsApp with the message ready, and records that it was sent. */
  function appendWhatsAppBubble(msg) {
    const row = botRow(`
      <a class="msg-file msg-wa" href="${escAttr(msg.url)}" target="_blank" rel="noopener">
        <span class="msg-file-icon"><i class="fa-brands fa-whatsapp"></i></span>
        <div>
          <div class="msg-file-label">${escHtml(msg.label || 'Send on WhatsApp')}</div>
          <div class="msg-file-sub">Opens WhatsApp with the message and PDF link ready</div>
        </div>
        <i class="fa-solid fa-arrow-up-right-from-square"></i>
      </a>`);
    row.querySelector('a').addEventListener('click', () => {
      post('ajax/doc_event.php', { invoice_id: msg.invoice_id, kind: 'whatsapp' })
        .then(() => { showToast('Marked as sent on WhatsApp'); loadChatList(); })
        .catch(() => {});
    });
    messagesArea.appendChild(row);
  }

  function appendUploadBubble(text) {
    const group = document.createElement('div');
    group.className = 'msg-group';
    group.appendChild(botRow(`
      <div class="msg-bubble">
        <div>${formatText(text)}</div>
        <div class="msg-upload-area">
          <label class="upload-btn" for="logoFileInput"><i class="fa-solid fa-image"></i> Choose logo image</label>
          <div class="upload-progress" id="uploadProgress"><i class="fa-solid fa-spinner fa-spin"></i> Uploading…</div>
        </div>
      </div>`));

    const btns = document.createElement('div');
    btns.className = 'msg-buttons';
    const skipBtn = document.createElement('button');
    skipBtn.className = 'msg-btn';
    skipBtn.textContent = 'Skip';
    skipBtn.addEventListener('click', () => { skipBtn.disabled = true; sendMessage('skip'); });
    btns.appendChild(skipBtn);
    group.appendChild(btns);
    messagesArea.appendChild(group);
  }

  // ── Logo upload ───────────────────────────────────────────────────
  function handleLogoUpload(e) {
    const file = e.target.files[0];
    if (!file) return;
    const progress = document.getElementById('uploadProgress');
    if (progress) progress.style.display = 'block';

    const fd = new FormData();
    fd.append('logo', file);

    fetch('ajax/upload_logo.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf } })
      .then(r => r.json())
      .then(data => {
        if (progress) progress.style.display = 'none';
        logoInput.value = '';
        if (data.error) { showToast('Upload failed: ' + data.error); return; }
        showToast('Logo uploaded');
        sendMessage('uploaded');
      })
      .catch(() => {
        if (progress) progress.style.display = 'none';
        showToast('Upload failed. Try again.');
      });
  }

  // ── Chat list ─────────────────────────────────────────────────────
  function loadChatList() {
    fetch('ajax/chat_list.php')
      .then(r => r.json())
      .then(data => { allInvoices = data.invoices || []; renderChatList(); })
      .catch(console.error);
  }

  function renderChatList() {
    const q = document.getElementById('sidebarSearch').value.trim().toLowerCase();
    const list = allInvoices.filter(inv => {
      if (listFilter === 'invoice' && inv.doc_type !== 'invoice') return false;
      if (listFilter === 'quote' && inv.doc_type !== 'quote') return false;
      if (listFilter === 'unpaid' && (inv.doc_type !== 'invoice' || inv.status === 'paid')) return false;
      return !q || `${inv.invoice_number} ${inv.client_name || ''}`.toLowerCase().includes(q);
    });

    chatList.innerHTML = '';
    if (!list.length) {
      chatList.innerHTML = allInvoices.length
        ? '<div class="chat-list-empty"><i class="fa-solid fa-filter-circle-xmark"></i>Nothing matches.</div>'
        : '<div class="chat-list-empty"><i class="fa-regular fa-file-lines"></i>No invoices or quotes yet.<br>Tap <strong>+ New</strong> to create one.</div>';
      return;
    }
    list.forEach(inv => {
      const item = document.createElement('div');
      const isQuote = inv.doc_type === 'quote';
      item.className = 'chat-item' + (inv.id == currentInvoiceId ? ' active' : '');
      item.dataset.id = inv.id;
      item.innerHTML = `
        <div class="chat-item-avatar avatar-${isQuote ? 'quote' : 'invoice'}"><i class="fa-solid ${isQuote ? 'fa-file-signature' : 'fa-file-invoice'}"></i></div>
        <div class="chat-item-body">
          <div class="chat-item-title">${escHtml(inv.client_name || 'No client yet')}</div>
          <div class="chat-item-sub">${escHtml(inv.invoice_number)}${inv.total_amount > 0 ? ' · ' + formatAmount(inv.total_amount, inv.currency) : ''}</div>
        </div>
        <div class="chat-item-meta">
          <span class="chat-item-date">${formatDate(inv.created_at)}</span>
          <span class="pill badge-${escAttr(inv.status)}">${escHtml(inv.status)}</span>
        </div>
        <button class="chat-item-delete" title="Delete" aria-label="Delete ${escAttr(inv.invoice_number)}"><i class="fa-solid fa-trash-can"></i></button>`;

      item.addEventListener('click', e => {
        if (e.target.closest('.chat-item-delete')) return;
        loadChat(inv.id);
        closeSidebar();
      });
      item.querySelector('.chat-item-delete').addEventListener('click', e => {
        e.stopPropagation();
        deleteInvoice(inv.id, item);
      });
      chatList.appendChild(item);
    });
  }

  // ── Load past chat ────────────────────────────────────────────────
  function loadChat(invoiceId) {
    currentInvoiceId = invoiceId;
    messagesArea.innerHTML = '';
    document.querySelectorAll('.chat-item').forEach(el => el.classList.toggle('active', el.dataset.id == invoiceId));

    fetch(`ajax/load_chat.php?invoice_id=${encodeURIComponent(invoiceId)}`)
      .then(r => r.json())
      .then(data => {
        if (data.invoice) updateHeaderForInvoice(data.invoice);
        let lastDay = '';
        (data.messages || []).forEach(m => {
          const day = (m.timestamp || '').slice(0, 10);
          if (day && day !== lastDay) {
            lastDay = day;
            const sep = document.createElement('div');
            sep.className = 'day-sep';
            sep.textContent = new Date(day.replace(/-/g, '/')).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
            messagesArea.appendChild(sep);
          }
          if (m.sender === 'user') { appendUserMessage(m.content); return; }
          const msg = m.extra || { type: m.type, content: m.content };
          if (msg.type === 'buttons' && msg.buttons) appendButtonsBubble(msg);
          else if (msg.type === 'file' && msg.url) appendFileBubble(msg);
          else if (msg.type === 'whatsapp' && msg.url) appendWhatsAppBubble(msg);
          else appendTextBubble(m.content);
        });
        // Old choices can't be re-sent from history.
        messagesArea.querySelectorAll('.msg-btn').forEach(b => { b.disabled = true; });

        if (data.invoice && data.invoice.status !== 'draft') {
          const isQuote = data.invoice.doc_type === 'quote';
          appendBotMessage({
            type: 'buttons',
            content: `${isQuote ? 'Quote' : 'Invoice'} *${data.invoice.invoice_number}* — what would you like to do?`,
            buttons: isQuote
              ? ['Send by Email', 'Send via WhatsApp', 'Accept quote', 'Convert to Invoice']
              : ['Send by Email', 'Send via WhatsApp', '/paid – Mark as paid'],
          });
        }
        scrollBottom();
      })
      .catch(console.error);
  }

  // ── New chat ──────────────────────────────────────────────────────
  function startNewChat() {
    currentInvoiceId = null;
    messagesArea.innerHTML = '';
    updateHeaderForInvoice(null);
    document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
    closeSidebar();
    sendMessage('/new');
  }

  // ── Delete invoice ────────────────────────────────────────────────
  function deleteInvoice(invoiceId, itemEl) {
    if (!confirm('Delete this document and its chat history? This cannot be undone.')) return;
    post('ajax/delete_invoice.php', { invoice_id: invoiceId })
      .then(data => {
        if (!data.success) { showToast(data.error || 'Could not delete.'); return; }
        itemEl.remove();
        allInvoices = allInvoices.filter(i => i.id != invoiceId);
        if (invoiceId == currentInvoiceId) {
          currentInvoiceId = null;
          messagesArea.innerHTML = '';
          updateHeaderForInvoice(null);
        }
        if (!allInvoices.length) renderChatList();
        showToast('Deleted.');
      })
      .catch(console.error);
  }

  // ── Header ────────────────────────────────────────────────────────
  function headerButton(icon, label, cmd, primary) {
    const b = document.createElement('button');
    b.className = 'header-btn' + (primary ? ' is-primary' : '');
    b.innerHTML = `<i class="fa-solid ${icon}"></i><span>${label}</span>`;
    b.title = label;
    b.addEventListener('click', () => sendMessage(cmd));
    headerActions.appendChild(b);
  }

  function updateHeaderForInvoice(invoice) {
    headerActions.innerHTML = '';
    if (!invoice) {
      chatIcon.innerHTML = '<i class="fa-solid fa-robot"></i>';
      chatTitle.textContent = 'Assistant';
      chatSubtitle.innerHTML = '<span class="online">● online</span> · creates invoices & quotes';
      return;
    }
    const isQuote = invoice.doc_type === 'quote';
    chatIcon.innerHTML = `<i class="fa-solid ${isQuote ? 'fa-file-signature' : 'fa-file-invoice'}"></i>`;
    chatTitle.textContent = (invoice.client_name || 'No client') + ' · ' + invoice.invoice_number;
    chatSubtitle.textContent = `${isQuote ? 'Quote' : 'Invoice'} · ${formatAmount(invoice.total_amount, invoice.currency) || 'R0.00'} · ${invoice.status}`;

    if (isQuote) {
      if (invoice.status !== 'accepted') headerButton('fa-check', 'Accept', '/accept', false);
      headerButton('fa-arrow-right-arrow-left', 'To invoice', '/convert', true);
    } else if (invoice.status !== 'paid') {
      headerButton('fa-check', 'Mark paid', '/paid', true);
    }
  }

  // ── Helpers ───────────────────────────────────────────────────────
  function setWaiting(state) {
    isWaiting = state;
    sendBtn.disabled = state;
    typingEl.classList.toggle('visible', state);
    if (state) scrollBottom();
  }

  function scrollBottom() {
    requestAnimationFrame(() => { messagesArea.scrollTop = messagesArea.scrollHeight; });
  }

  function escHtml(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  const escAttr = s => escHtml(s).replace(/'/g, '&#39;');

  function formatText(str) {
    return escHtml(str)
      .replace(/\*([^*\n]+)\*/g, '<strong>$1</strong>')   // *bold*
      .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>')
      .replace(/\n/g, '<br>');
  }

  function formatDate(dt) {
    if (!dt) return '';
    const d = new Date(String(dt).replace(' ', 'T'));
    const today = new Date();
    if (d.toDateString() === today.toDateString()) return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }

  function formatAmount(amount, currency) {
    if (!amount || amount == 0) return '';
    const sym = { USD:'$', EUR:'€', GBP:'£', JPY:'¥', INR:'₹', BRL:'R$', ZAR:'R', NGN:'₦', CAD:'CA$', AUD:'A$' };
    const s = sym[currency] || (currency + ' ');
    return s + Number(amount).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function showToast(msg) {
    const toast = document.getElementById('toast');
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(showToast.t);
    showToast.t = setTimeout(() => toast.classList.remove('show'), 3000);
  }

})();
