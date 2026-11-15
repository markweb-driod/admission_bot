/* Public chat widget of the Admission Chatbot. */
(function () {
    'use strict';
    const $ = (s) => document.querySelector(s);
    let sid = localStorage.getItem('admbot_sid');
    if (!sid) { sid = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2)); localStorage.setItem('admbot_sid', sid); }
    const log = $('#log'), form = $('#form'), msg = $('#msg');
    const esc = (s) => s.replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fmt = (s) => esc(s).replace(/\n/g, '<br>');
    const time = (d) => (d ? new Date(d.replace(' ', 'T')) : new Date()).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});

    async function api(a, body) {
        const r = await fetch('api.php?a=' + a + (body ? '' : '&session=' + sid), body ? {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({session: sid, ...body})} : undefined);
        const j = await r.json().catch(() => ({})); if (!r.ok) throw new Error(j.error || 'Request failed'); return j;
    }

    function bubble(role, html, extra) {
        const d = document.createElement('div'); d.className = 'b ' + role;
        d.innerHTML = '<div class="t">' + html + '</div><small>' + time(extra && extra.created) + '</small>';
        log.appendChild(d); log.scrollTop = log.scrollHeight; return d;
    }
    function pills(el, items, pick) {
        if (!items || !items.length) return;
        const w = document.createElement('div'); w.className = 'pills';
        items.forEach((i) => { const b = document.createElement('button'); b.type = 'button'; b.className = 'pill'; b.textContent = i.question; b.onclick = () => ask(i.question, i.id, true); w.appendChild(b); });
        el.appendChild(w);
    }
    function actions(el, r) {
        const w = document.createElement('div'); w.className = 'fbk';
        if (r.message_id && r.level !== 'smalltalk') {
            w.innerHTML = '<span>Was this helpful?</span><button type="button" data-r="1" title="Helpful">&#128077;</button><button type="button" data-r="-1" title="Not helpful">&#128078;</button>';
            w.querySelectorAll('button').forEach((b) => b.onclick = async () => { try { await api('feedback', {message_id: r.message_id, rating: +b.dataset.r}); w.innerHTML = '<span>Thank you for your feedback.</span>'; } catch (e) { w.innerHTML = '<span>' + esc(e.message) + '</span>'; } });
        }
        if (r.escalate) { const b = document.createElement('button'); b.type = 'button'; b.className = 'btn sm'; b.textContent = 'Talk to an officer'; b.onclick = openModal; w.appendChild(b); }
        if (w.children.length) el.appendChild(w);
    }

    async function ask(text, entryId, pick) {
        if (!text.trim()) return;
        bubble('user', fmt(text)); msg.value = ''; $('#chips').innerHTML = '';
        const typing = bubble('bot typing', '<span class="dots"><i></i><i></i><i></i></span>');
        $('#send').disabled = true;
        try {
            const r = await api('chat', {message: text, entry_id: entryId || null, pick: !!pick});
            await new Promise((ok) => setTimeout(ok, 350));
            typing.remove(); const b = bubble('bot ' + r.level, fmt(r.answer));
            pills(b.querySelector('.t'), r.suggestions, true); pills(b.querySelector('.t'), r.followups && r.followups.length ? r.followups : [], true); actions(b.querySelector('.t'), r);
            if (r.followups && r.followups.length) { const l = document.createElement('div'); l.className = 'rel'; l.textContent = 'Related questions'; b.querySelector('.pills')?.before(l); }
        } catch (e) { typing.remove(); bubble('bot err', esc(e.message)); }
        $('#send').disabled = false; msg.focus();
    }

    form.addEventListener('submit', (e) => { e.preventDefault(); ask(msg.value); });
    $('#btnOfficer').onclick = openModal;
    function openModal() { $('#modal').hidden = false; $('#ticketMsg').textContent = ''; const last = [...document.querySelectorAll('.b.user .t')].pop(); if (last && !$('#ticketForm [name=message]').value) $('#ticketForm [name=message]').value = last.textContent; }
    $('#cancelTicket').onclick = () => { $('#modal').hidden = true; };
    $('#ticketForm').addEventListener('submit', async (e) => {
        e.preventDefault(); const f = Object.fromEntries(new FormData(e.target));
        try { const r = await api('ticket', f); $('#modal').hidden = true; bubble('bot high', fmt(r.message)); e.target.reset(); e.target.subject.value = 'Admission enquiry'; }
        catch (er) { $('#ticketMsg').textContent = er.message; $('#ticketMsg').className = 'err'; }
    });

    (async function init() {
        const cfg = await api('config');
        const h = await api('history');
        if (h.messages.length) { h.messages.forEach((m) => bubble(m.sender === 'user' ? 'user' : 'bot', fmt(m.text), m)); }
        else bubble('bot high', fmt(cfg.welcome));
        const p = await api('popular');
        $('#popular').innerHTML = ''; p.questions.forEach((q) => { const b = document.createElement('button'); b.className = 'pop'; b.textContent = q.question; b.onclick = () => ask(q.question, q.id, true); $('#popular').appendChild(b); });
        $('#chips').innerHTML = ''; ['How do I apply for admission?', 'What documents do I need?', 'What is Post-UTME screening?', 'How do I check my admission status?'].forEach((t) => { const b = document.createElement('button'); b.className = 'pill'; b.textContent = t; b.onclick = () => ask(t); $('#chips').appendChild(b); });
    })();
})();
