/* Dashboard charts of the administrator console. */
(function () {
    'use strict';
    if (!window.CHARTS || !window.Chart) return;
    const P = ['#0b6b4b', '#d4a017', '#2563eb', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];
    const label = {high: 'Answered (high)', medium: 'Answered (medium)', suggest: 'Suggestions', fallback: 'Referred to officer', smalltalk: 'Greetings and small talk'};
    fetch('admin.php?p=chart').then((r) => r.json()).then((d) => {
        const mk = (id, type, labels, data) => { const el = document.getElementById(id); if (!el) return; new Chart(el, {type, data: {labels, datasets: [{data, backgroundColor: type === 'line' ? '#0b6b4b22' : P, borderColor: type === 'line' ? '#0b6b4b' : '#fff', borderWidth: 2, fill: type === 'line', tension: .3}]}, options: {responsive: true, maintainAspectRatio: false, plugins: {legend: {display: type === 'doughnut', position: 'bottom'}}, scales: type === 'doughnut' ? {} : {y: {beginAtZero: true, ticks: {precision: 0}}}, indexAxis: type === 'bar' ? 'y' : 'x'}}); };
        mk('cDays', 'line', d.days.map((x) => x.d.slice(5)), d.days.map((x) => x.n));
        mk('cLevels', 'doughnut', d.levels.map((x) => label[x.level] || x.level), d.levels.map((x) => x.n));
        mk('cCats', 'bar', d.categories.map((x) => x.intent), d.categories.map((x) => x.n));
    });
})();
