<?php require __DIR__ . '/src/core.php'; db(); ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(setting('bot_name')) ?> - <?= h(setting('institution')) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%8E%93%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/style.css"></head>
<body class="public">
<header class="hero"><div class="wrap"><div class="brand"><span class="logo">&#127891;</span><div><b><?= h(setting('institution')) ?></b><small>Admission information service</small></div></div><a class="staff" href="admin.php">Staff login</a></div></header>
<main class="wrap grid">
    <section class="chat-card" aria-label="Chat">
        <div class="chat-h"><span class="dot"></span><div><b id="botName"><?= h(setting('bot_name')) ?></b><small>Online 24 hours &middot; answers from verified admission information</small></div>
            <button id="btnOfficer" class="btn light">Talk to an officer</button></div>
        <div id="log" class="log" aria-live="polite"></div>
        <div id="chips" class="chips"></div>
        <form id="form" class="composer" autocomplete="off"><input id="msg" maxlength="500" placeholder="Type your admission question..." aria-label="Your question"><button class="btn primary" id="send">Send</button></form>
        <p class="note">Information is general guidance. Always confirm dates, fees and cut-off marks on the official admission notice.</p>
    </section>
    <aside class="side">
        <div class="card"><h2>Admission steps</h2><ol class="steps"><li>Register for UTME or Direct Entry with JAMB</li><li>Complete Post-UTME screening</li><li>Check your admission on the JAMB portal</li><li>Accept the offer and pay the acceptance fee</li><li>Document verification and registration</li></ol></div>
        <div class="card"><h2>Popular questions</h2><div id="popular" class="popular"></div></div>
        <div class="card"><h2>Office hours</h2><p><?= h(setting('office_hours')) ?></p></div>
    </aside>
</main>
<div id="modal" class="modal" hidden><form id="ticketForm" class="card"><h2>Talk to an admission officer</h2><p class="muted">Leave your details and question. An officer will reply by email.</p>
    <label>Full name</label><input name="name" required><label>Email address</label><input type="email" name="email" required><label>Phone (optional)</label><input name="phone"><label>Subject</label><input name="subject" value="Admission enquiry"><label>Your question</label><textarea name="message" rows="4" required minlength="10"></textarea>
    <p id="ticketMsg" class="muted"></p><div class="actions"><button type="button" class="btn" id="cancelTicket">Cancel</button><button class="btn primary">Send to officer</button></div></form></div>
<script src="assets/chat.js"></script></body></html>
