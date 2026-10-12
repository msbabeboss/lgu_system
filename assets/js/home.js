// LGU Landing Page — paged navigation

// ── Live Date/Time Display ────────────────────────────────
function updateDateTime() {
    const el = document.getElementById('currentDateTime');
    if (!el) return;
    const now = new Date();
    const options = {
        weekday: 'long', year: 'numeric', month: 'long',
        day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit'
    };
    el.textContent = now.toLocaleDateString('en-PH', options);
}
updateDateTime();
setInterval(updateDateTime, 1000);

// ── Animated Counter ──────────────────────────────────────
function animateCounter(el, target, duration) {
    let start = 0;
    const step = Math.ceil(target / (duration / 30));
    const timer = setInterval(() => {
        start += step;
        if (start >= target) { start = target; clearInterval(timer); }
        el.textContent = start.toLocaleString();
    }, 30);
}

// ── Load stats ────────────────────────────────────────────
function loadStats() {
    fetch('ajax/get_landing_stats.php')
        .then(r => r.json())
        .then(data => {
            animateCounter(document.getElementById('totalResidents'), data.residents || 0, 1500);
            animateCounter(document.getElementById('activeUsers'),    data.users     || 0, 1200);
            animateCounter(document.getElementById('recordsStored'),  data.records   || 0, 1800);
        })
        .catch(() => {
            animateCounter(document.getElementById('totalResidents'), 12480, 1500);
            animateCounter(document.getElementById('activeUsers'),    24,    1200);
            animateCounter(document.getElementById('recordsStored'),  38650, 1800);
        });
}

// ── Paged Navigation ──────────────────────────────────────
let currentPage  = 'page-home';
let pageChanging = false;

function showPage(pageId, updateHash) {
    if (pageChanging || currentPage === pageId) return;
    pageChanging = true;

    const from = document.getElementById(currentPage);
    const to   = document.getElementById(pageId);
    if (!from || !to) { pageChanging = false; return; }

    // Deactivate current
    from.classList.remove('active');

    // Activate new
    to.classList.add('active');
    to.scrollTop = 0;   // scroll-to-top on page switch

    // Update nav active state
    document.querySelectorAll('.nav-link').forEach(a => {
        a.classList.toggle('active', a.dataset.page === pageId);
    });

    currentPage  = pageId;

    // Update URL hash without page jump
    if (updateHash !== false) {
        const hash = '#' + pageId.replace('page-', '');
        history.replaceState(null, '', hash);
    }

    setTimeout(() => { pageChanging = false; }, 380);
}

// Wire up nav links
document.querySelectorAll('.nav-link').forEach(a => {
    a.addEventListener('click', e => {
        e.preventDefault();
        showPage(a.dataset.page);
    });
});

// Wire up footer quick links (same targets)
document.querySelectorAll('a[href^="#"]').forEach(a => {
    if (a.classList.contains('nav-link')) return; // already handled
    a.addEventListener('click', e => {
        const hash = a.getAttribute('href').replace('#', '');
        const pageId = 'page-' + hash;
        const target = document.getElementById(pageId);
        if (target) {
            e.preventDefault();
            showPage(pageId);
        }
    });
});

// Handle URL hash on load
function initFromHash() {
    const hash = window.location.hash.replace('#', '');
    const map  = { home: 'page-home', features: 'page-features', about: 'page-about', contact: 'page-contact' };
    const pageId = map[hash];
    if (pageId && pageId !== currentPage) {
        // Directly set without animation on initial load
        document.getElementById(currentPage)?.classList.remove('active');
        document.getElementById(pageId)?.classList.add('active');
        document.querySelectorAll('.nav-link').forEach(a => {
            a.classList.toggle('active', a.dataset.page === pageId);
        });
        currentPage = pageId;
    }
}

// ── Intersection Observer for fade-in animations ──────────
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) entry.target.classList.add('visible');
    });
}, { threshold: 0.1 });

document.querySelectorAll('.feature-card, .announcement-card, .stat-card').forEach(el => {
    el.style.opacity   = '0';
    el.style.transform = 'translateY(20px)';
    el.style.transition= 'opacity 0.5s ease, transform 0.5s ease';
    observer.observe(el);
});

const style = document.createElement('style');
style.textContent = '.visible { opacity: 1 !important; transform: translateY(0) !important; }';
document.head.appendChild(style);

// ── Init ──────────────────────────────────────────────────
window.addEventListener('load', () => {
    initFromHash();
    loadStats();
});
