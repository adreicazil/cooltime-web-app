(() => {
  if (document.getElementById('cooltime-chatbot')) return;
  const style = document.createElement('style');
  style.textContent = `
    #cooltime-chatbot{position:fixed;right:24px;bottom:24px;z-index:9999;font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif;color:#172033}
    #cooltime-chatbot *{box-sizing:border-box} #cooltime-chatbot button{font:inherit;cursor:pointer}
    .ct-launch{width:58px;height:58px;border:0;border-radius:50%;background:#0878d1;color:#fff;box-shadow:0 5px 22px #092b4960;font-size:25px}
    .ct-badge{position:absolute;right:66px;bottom:14px;background:#fff;border-radius:14px;padding:7px 12px;box-shadow:0 2px 12px #0002;white-space:nowrap;font-size:13px}
    .ct-panel{position:absolute;right:0;bottom:74px;width:min(370px,calc(100vw - 32px));height:min(540px,calc(100dvh - 120px));background:#fff;border-radius:16px;box-shadow:0 12px 45px #10243d40;display:flex;flex-direction:column;overflow:hidden;border:1px solid #dce4ed}
    .ct-panel[hidden]{display:none}.ct-head{background:#0878d1;color:#fff;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;font-weight:700}
    .ct-close{background:transparent;color:#fff;border:0;font-size:25px;line-height:1}.ct-messages{padding:14px;overflow:auto;flex:1;background:#f7f9fc;display:flex;flex-direction:column;gap:10px}
    .ct-msg{max-width:88%;padding:9px 12px;border-radius:13px;white-space:pre-wrap;overflow-wrap:anywhere}.ct-msg.bot{align-self:flex-start;background:#fff;border:1px solid #e0e7ef}.ct-msg.user{align-self:flex-end;background:#0878d1;color:white}
    .ct-quick{display:flex;gap:7px;overflow:auto;padding:9px 12px;border-top:1px solid #edf0f4}.ct-chip{white-space:nowrap;border:1px solid #0878d1;background:#fff;color:#0878d1;border-radius:16px;padding:6px 9px;font-size:12px}
    .ct-form{display:flex;gap:8px;padding:10px 12px;border-top:1px solid #edf0f4}.ct-input{min-width:0;flex:1;border:1px solid #cbd5e1;border-radius:20px;padding:10px 13px;font:inherit}.ct-send{border:0;border-radius:20px;padding:0 14px;background:#0878d1;color:#fff;font-weight:600}.ct-send:disabled{opacity:.6;cursor:wait}
    .ct-typing{display:flex;gap:4px;align-items:center;height:18px}.ct-typing i{width:6px;height:6px;border-radius:50%;background:#7d8da0;animation:ctblink 1s infinite alternate}.ct-typing i:nth-child(2){animation-delay:.2s}.ct-typing i:nth-child(3){animation-delay:.4s}@keyframes ctblink{to{opacity:.2;transform:translateY(-3px)}}
    @media(max-width:480px){#cooltime-chatbot{right:16px;bottom:16px}.ct-panel{position:fixed;right:10px;bottom:84px;width:calc(100vw - 20px);height:min(580px,calc(100dvh - 110px))}}
  `;
  document.head.append(style);
  const root = document.createElement('div'); root.id = 'cooltime-chatbot';
  root.innerHTML = `<div class="ct-panel" role="dialog" aria-modal="false" aria-label="CoolTime AI Assistant" hidden><div class="ct-head"><span>CoolTime AI Assistant</span><button class="ct-close" type="button" aria-label="Close chat">×</button></div><div class="ct-messages" aria-live="polite"></div><div class="ct-quick" aria-label="Quick questions"></div><form class="ct-form"><input class="ct-input" maxlength="1000" placeholder="Ask about our services…" aria-label="Your message" autocomplete="off"><button class="ct-send" type="submit">Send</button></form></div><span class="ct-badge">Chat now!</span><button class="ct-launch" type="button" aria-label="Open chat" aria-expanded="false">☏</button>`;
  document.body.append(root);
  const panel = root.querySelector('.ct-panel'), launch = root.querySelector('.ct-launch'), messages = root.querySelector('.ct-messages'), form = root.querySelector('.ct-form'), input = root.querySelector('.ct-input'), send = root.querySelector('.ct-send'), badge = root.querySelector('.ct-badge');
  const addMessage = (text, role) => { const item = document.createElement('div'); item.className = `ct-msg ${role}`; item.textContent = text; messages.append(item); messages.scrollTop = messages.scrollHeight; return item; };
  const toggle = open => { panel.hidden = !open; badge.hidden = open; launch.setAttribute('aria-expanded', String(open)); if (open) input.focus(); };
  launch.addEventListener('click', () => toggle(panel.hidden)); root.querySelector('.ct-close').addEventListener('click', () => toggle(false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) toggle(false); });
  addMessage('Hello! I can help with CoolTime services, branches, hours, and bookings.', 'bot');
  const quick = root.querySelector('.ct-quick');
  ['Services offered', 'Branch locations', 'Operating hours', 'How to book'].forEach(label => { const button = document.createElement('button'); button.type = 'button'; button.className = 'ct-chip'; button.textContent = label; button.addEventListener('click', () => sendMessage(label)); quick.append(button); });
  async function sendMessage(value) {
    const message = value.trim(); if (!message || send.disabled) return;
    addMessage(message, 'user'); input.value = ''; send.disabled = true; input.disabled = true;
    const typing = document.createElement('div'); typing.className = 'ct-msg bot'; typing.setAttribute('aria-label', 'Assistant is typing'); typing.innerHTML = '<span class="ct-typing"><i></i><i></i><i></i></span>'; messages.append(typing); messages.scrollTop = messages.scrollHeight;
    try {
      const response = await fetch('api/chatbot.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message }) });
      const data = await response.json(); typing.remove();
      const reply = data && data.success && typeof data.reply === 'string' ? data.reply : (data && typeof data.error === 'string' ? data.error : 'Sorry, the chat is temporarily unavailable.');
      const cleanedReply = reply.replace(/\*\*(.*?)\*\*/g, '$1').replace(/^\s*-\s+/gm, '• ');
      addMessage(cleanedReply, 'bot');
    } catch (_) { typing.remove(); addMessage('Sorry, I could not connect. Please try again.', 'bot'); }
    finally { send.disabled = false; input.disabled = false; input.focus(); }
  }
  form.addEventListener('submit', e => { e.preventDefault(); sendMessage(input.value); });
})();
