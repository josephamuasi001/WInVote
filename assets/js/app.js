(() => {
  const events = [
    { title: "Campus Voices Awards", org: "Northbridge University", date: "Voting closes 28 Nov", category: "University", status: "Live", art: "", votes: "12.8k", count: "18 nominees" },
    { title: "The New Sound Showcase", org: "Soundwave Collective", date: "Starts 12 Dec", category: "Music", status: "Upcoming", art: "art-coral", votes: "—", count: "24 nominees" },
    { title: "Women Who Lead 2026", org: "Forward Ghana Foundation", date: "Voting closes 02 Dec", category: "Community", status: "Live", art: "art-violet", votes: "8.4k", count: "12 nominees" },
    { title: "Coastal Style & Culture", org: "Coastline Creative", date: "Results published", category: "Arts", status: "Completed", art: "art-gold", votes: "32.1k", count: "16 nominees" },
    { title: "Future Founders Challenge", org: "Accra Innovation Hub", date: "Starts 05 Dec", category: "Business", status: "Upcoming", art: "art-blue", votes: "—", count: "10 nominees" },
    { title: "Community Impact Honors", org: "Civic Bridge Network", date: "Voting closes 30 Nov", category: "Community", status: "Live", art: "art-green", votes: "5.2k", count: "14 nominees" }
  ];
  const root = document.querySelector("[data-app]");
  if (!root) return;
  const page = root.dataset.page || "home";
  const scope = root.dataset.scope || "public";
  const relative = scope === "public" ? "" : "../";
  const serverMode = location.protocol !== "file:";
  const eventsUrl = serverMode ? "events.php" : "events.html";
  const path = location.pathname.toLowerCase();
  const activeFile = path.split("/").pop() || "dashboard.html";
  if (serverMode && page === "home") {
    window.location.replace(`${relative}index.php`);
    return;
  }
  if (serverMode && page === "event-details") {
    const eventId = new URLSearchParams(location.search).get("event_id");
    window.location.replace(eventId ? `${relative}event.php?event_id=${encodeURIComponent(eventId)}` : `${relative}events.php`);
    return;
  }
  if (serverMode && page === "contestant") {
    const contestantId = new URLSearchParams(location.search).get("contestant_id");
    window.location.replace(contestantId ? `${relative}contestant.php?contestant_id=${encodeURIComponent(contestantId)}` : `${relative}events.php`);
    return;
  }
  if (serverMode && page === "events" && path.endsWith("events.html")) {
    window.location.replace("events.php");
    return;
  }
  if (serverMode && scope === "admin") {
    window.location.replace("../auth.php?action=login");
    return;
  }
  if (serverMode && scope === "organizer") {
    window.location.replace("events.php");
    return;
  }
  const authUrl = (action) => serverMode
    ? `${relative}auth.php?action=${action}`
    : `${relative}${action === "register" ? "register.html" : "login.html"}`;
  const resultUrl = serverMode ? "results.php" : "results.html";
  const nominationUrl = serverMode ? "nominate.php" : "nominations.html";
  const voteUrl = serverMode ? "vote.php" : "vote.html";
  const esc = (value) => String(value).replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char]);
  const titleCase = (value) => value.replace(/[-_]/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
  const toast = (message) => {
    let node = document.querySelector(".toast");
    if (!node) {
      node = document.createElement("div");
      node.className = "toast";
      node.setAttribute("role", "status");
      document.body.append(node);
    }
    node.textContent = message;
    node.classList.add("show");
    window.clearTimeout(toast.timer);
    toast.timer = window.setTimeout(() => node.classList.remove("show"), 2800);
  };
  const brand = `<a class="brand" href="${relative}${serverMode ? "index.php" : "index.html"}"><span class="brand-mark">W</span><span>WIn<em>Vote</em></span></a>`;
  const publicHeader = `
    <header class="site-header"><div class="container nav-inner">
      ${brand}
      <button class="mobile-menu" aria-label="Toggle navigation" data-action="public-menu">☰</button>
      <nav class="public-nav">
        <a href="${relative}${eventsUrl}">Explore events</a><a href="${relative}${resultUrl}">Results</a>
        <a href="${relative}${nominationUrl}">Nominations</a><a href="${relative}about.html">About</a>
      </nav>
      <div class="nav-actions"><a class="btn btn-secondary btn-sm" href="${authUrl("login")}">Log in</a><a class="btn btn-primary btn-sm" href="${authUrl("register")}">Create account</a></div>
    </div></header>`;
  const footer = `
    <footer class="site-footer"><div class="container">
      <div class="footer-grid"><div class="footer-about">${brand}<p>A thoughtful home for community awards, competitions and elections. Every voice deserves a fair way to be heard.</p></div>
      <div class="footer-col"><strong>Discover</strong><a href="${relative}${eventsUrl}">Browse events</a><a href="${relative}${resultUrl}">Results</a><a href="${relative}${nominationUrl}">Submit a nomination</a></div>
      <div class="footer-col"><strong>For organizers</strong><a data-register-link href="${authUrl("register")}">Create an account</a><a href="${relative}${serverMode ? "organizer/events.php" : "organizer/dashboard.html"}">${serverMode ? "Manage events" : "Workspace preview (demo)"}</a><a href="${relative}support.html">Help & support</a></div>
      <div class="footer-col"><strong>WInVote</strong><a href="${relative}about.html">About us</a><a href="${relative}support.html">Contact</a><a href="${relative}support.html#faq">FAQs</a></div></div>
      <div class="footer-bottom"><span>© 2026 WInVote · A more thoughtful way to celebrate every voice.</span><span>Privacy · Terms · Accessibility</span></div>
    </div></footer>`;
  const shell = (content) => `${publicHeader}${serverMode ? "" : '<div class="container preview-mode-notice" role="note">Static preview only — sample data is not connected to your database. Run the PHP site to view stored records.</div>'}${content}${footer}`;
  const eventCard = (event) => {
    const statusClass = event.status === "Live" ? "pill-live" : event.status === "Upcoming" ? "pill-upcoming" : "pill-closed";
    return `<article class="event-card" data-status="${esc(event.status)}" data-category="${esc(event.category)}">
      <a href="${relative}event-details.html" aria-label="View ${esc(event.title)}">
        <div class="event-art ${event.art}"><span class="event-kicker">${esc(event.category)} · 2026</span><h3>${esc(event.title)}</h3></div>
      </a><div class="event-card-body"><div class="event-meta"><span>◷ ${esc(event.date)}</span><span>♙ ${esc(event.count)}</span></div>
      <div class="event-card-bottom"><span class="organizer-mini">${esc(event.org)}<small>${esc(event.votes)} votes</small></span><span class="pill ${statusClass}">${esc(event.status)}</span></div>
    </div></article>`;
  };
  const publicPage = (content) => shell(content);
  const home = () => publicPage(`
    <main>
      <section class="hero"><div class="container hero-grid"><div>
        <span class="eyebrow">A better way to celebrate every voice</span>
        <h1>Where every vote<br>finds its <span>moment.</span></h1>
        <p class="hero-copy">Discover the people, ideas and communities shaping what comes next. Explore events, support your favorites and make your voice count.</p>
        <div class="hero-actions"><a class="btn btn-primary" href="${relative}${eventsUrl}">Explore events <span aria-hidden="true">→</span></a><a class="btn btn-secondary" data-auth-register-cta href="${authUrl("register")}">Host an event</a></div>
        <div class="hero-proof"><div class="avatar-stack"><span>AK</span><span>JM</span><span>ES</span><span>+</span></div><span>Built for communities that care</span></div>
      </div><div class="hero-preview"><div class="preview-top"><span>Trending this week</span><span class="live-dot">Voting live</span></div>
        <div class="preview-event"><strong><small>FEATURED · NORTHBRIDGE UNIVERSITY</small>Campus Voices Awards</strong></div>
        <div class="preview-stats"><div class="preview-stat"><strong>18</strong><small>Nominees</small></div><div class="preview-stat"><strong>12.8k</strong><small>Votes cast</small></div><div class="preview-stat"><strong>3 days</strong><small>Remaining</small></div></div>
      </div></div></section>
      <section class="section"><div class="container"><div class="section-heading"><div><h2>Events worth showing up for</h2><p>Meet the people and ideas making a difference.</p></div><a class="text-link" href="${relative}${eventsUrl}">View all events →</a></div><div class="event-grid">${events.slice(0,3).map(eventCard).join("")}</div></div></section>
      <section class="trust-band"><div class="container trust-row"><div class="trust-item"><strong>120+</strong><span>Community events</span></div><div class="trust-item"><strong>48k</strong><span>Voices counted</span></div><div class="trust-item"><strong>32</strong><span>Partner organizations</span></div><div class="trust-item"><strong>One</strong><span>Place to participate</span></div></div></section>
      <section class="section"><div class="container"><div class="section-heading"><div><h2>Simple to take part</h2><p>From discovery to a meaningful result, every step is clear.</p></div></div><div class="how-grid">
        <div class="how-card"><div class="how-icon">01</div><h3>Find your event</h3><p>Explore live competitions, awards and community elections in one place.</p></div>
        <div class="how-card"><div class="how-icon">02</div><h3>Choose a nominee</h3><p>Get to know the people behind each nomination before you decide.</p></div>
        <div class="how-card"><div class="how-icon">03</div><h3>Make your voice count</h3><p>Follow each event's clear voting rules and see published results.</p></div>
      </div></div></section>
      <section class="section" style="padding-top:0"><div class="container"><div class="section-heading"><div><h2>Coming up next</h2><p>Get ready for the next round of community celebrations.</p></div><a class="text-link" href="${relative}${eventsUrl}">See upcoming →</a></div><div class="event-grid">${events.slice(3).map(eventCard).join("")}</div></div></section>
      <section class="section" style="padding-top:0"><div class="container"><div class="cta-panel"><div><h2>Bring your community together.</h2><p>Create a thoughtful, transparent event experience from nominations to results.</p></div><a class="btn btn-primary" data-auth-register-cta href="${authUrl("register")}">Start organizing →</a></div></div></section>
    </main>`);
  const pageHeader = (crumb, heading, desc) => `<section class="page-hero"><div class="container"><div class="breadcrumb">${crumb}</div><h1>${heading}</h1><p>${desc}</p></div></section>`;
  const eventListing = () => publicPage(`<main>${pageHeader("HOME / EVENTS", "Find your next moment.", "Explore live voting, upcoming competitions and the results your community is talking about.")}<section class="content-section"><div class="container">
    <div class="filter-row"><label class="search-wrap"><span class="search-icon">⌕</span><input class="input" id="event-search" placeholder="Search events, organizers..." aria-label="Search events"></label>
      <select class="select" id="category-filter" aria-label="Filter by category"><option value="">All categories</option><option>University</option><option>Music</option><option>Community</option><option>Arts</option><option>Business</option></select>
      <select class="select" id="status-filter" aria-label="Filter by status"><option value="">All statuses</option><option>Live</option><option>Upcoming</option><option>Completed</option></select>
    </div><div class="tabs" role="tablist"><button class="tab active" data-status-tab="All">All events</button><button class="tab" data-status-tab="Live">Voting live</button><button class="tab" data-status-tab="Upcoming">Upcoming</button><button class="tab" data-status-tab="Completed">Completed</button></div>
    <div class="event-grid" id="event-list">${events.map(eventCard).join("")}</div><div class="empty-state" id="event-empty" hidden><div class="empty-icon">⌕</div><strong>No matching events</strong><p>Try a different search or filter.</p><button class="btn btn-secondary btn-sm" data-action="clear-filters">Clear filters</button></div>
    <div class="pagination" aria-label="Event pages"><button aria-label="Previous page" disabled>‹</button><button class="active" aria-current="page" disabled>1</button><button aria-label="Next page" disabled>›</button></div>
  </div></section></main>`);
  const details = () => publicPage(`<main>${pageHeader("HOME / EVENTS / CAMPUS VOICES", "Celebrate the voices shaping tomorrow.", "A community celebration of the people making campus life more creative, connected and inspiring.")}<section class="content-section"><div class="container detail-layout">
    <div><div class="detail-banner"><div><p>NORTHBRIDGE UNIVERSITY · 2026</p><h1>Campus Voices Awards</h1></div></div>
      <div class="panel" style="margin-top:16px"><h2>About this event</h2><p>The Campus Voices Awards recognize the students, groups and creators who make our university community a place to belong. Explore the categories and support the voices that inspire you.</p><h3 style="margin-top:20px">Categories & nominees</h3>
        <div class="tabs" id="event-category-tabs" role="tablist"><button class="tab active" role="tab" aria-selected="true" data-category-tab="Community builder">Community builder</button><button class="tab" role="tab" aria-selected="false" data-category-tab="Creative voice">Creative voice</button><button class="tab" role="tab" aria-selected="false" data-category-tab="Student initiative">Student initiative</button></div>
        <div class="contestant-grid" id="event-contestants">${[["AM","Ama Mensah","Community builder",""],["KO","Kojo Owusu","Community builder","portrait-coral"],["ES","Esi Sarfo","Community builder","portrait-gold"]].map(([initials,name,cat,style])=>`<article class="contestant-card"><div class="portrait ${style}">${initials}</div><div class="contestant-body"><a class="table-primary" href="${relative}contestant.html">${name}</a><small>${cat}</small><a class="btn btn-primary btn-sm" href="${relative}${voteUrl}">Vote for nominee</a></div></article>`).join("")}</div>
      </div><div class="panel"><h2>How voting works</h2><p>Review the event rules before voting. Any configured vote limit applies to your total across the event. Results are published according to the event's visibility settings.</p></div>
    </div><aside class="detail-sidebar"><div class="panel"><span class="pill pill-live">● Voting live</span><h3 style="margin-top:15px">Event at a glance</h3><ul class="detail-list"><li><span>Organizer</span><strong>Northbridge University</strong></li><li><span>Voting opens</span><strong>12 Nov 2026</strong></li><li><span>Voting closes</span><strong>28 Nov 2026</strong></li><li><span>Categories</span><strong>6 categories</strong></li><li><span>Voting mode</span><strong>Free · event vote limits apply</strong></li></ul><a href="${relative}${voteUrl}" class="btn btn-primary" style="width:100%;margin-top:16px">Explore voting</a></div><div class="panel"><h3>Event guidelines</h3><p>Sign in with a voter account and follow the published event vote limit. Restricted elections allow one vote per category.</p></div></aside>
  </div></section></main>`);
  const contestant = () => publicPage(`<main><section class="content-section"><div class="container detail-layout"><div><div class="panel contestant-profile">
    <div class="portrait contestant-profile-portrait">AM</div><div><div class="breadcrumb">CAMPUS VOICES / COMMUNITY BUILDER</div><h1 style="font-size:30px;letter-spacing:-1px;margin:8px 0">Ama Mensah</h1><p>Student organizer · Northbridge University</p><p>Ama brings people together through welcoming campus projects, peer mentorship and a belief that small acts of kindness can change a community.</p><div class="event-meta"><span>♧ Campus Voices Awards</span><span>◉ Community builder</span></div></div></div>
    <div class="panel"><h2>About Ama</h2><p>From starting a weekly peer-support circle to coordinating the campus welcome program, Ama makes space for students to connect. Her nomination celebrates a year of thoughtful leadership and community care.</p><h3>Why people nominated Ama</h3><p>“Ama is the first person to notice when someone feels left out. She made our campus feel smaller, warmer and more welcoming.”</p></div></div>
    <aside class="detail-sidebar"><div class="panel"><span class="pill pill-live">Voting live</span><h3 style="margin-top:15px">Support this nominee</h3><p>Votes can be cast while the event voting window is open. Published event limits are enforced.</p><a class="btn btn-primary" style="width:100%" href="${relative}${voteUrl}">Vote for Ama</a><a class="btn btn-secondary" style="width:100%;margin-top:8px" href="${relative}event-details.html">View event</a></div></aside>
  </div></section></main>`);
  const vote = () => publicPage(`<main>${pageHeader("HOME / CAMPUS VOICES / VOTE", "Your vote, your voice.", "Review your selection before continuing. This demonstration uses a local mock confirmation; no real vote or payment is submitted.")}<section class="content-section"><div class="container vote-layout"><div class="panel"><h2>Your selection</h2><div class="field"><label for="vote-event">Event</label><select class="select" id="vote-event" style="width:100%"><option data-price="0">Campus Voices Awards · free</option><option data-price="2">The New Sound Showcase · GHS 2.00 per vote</option></select></div><div class="field"><label for="vote-category">Category</label><select class="select" id="vote-category" style="width:100%"><option>Community builder</option><option>Creative voice</option><option>Student initiative</option></select></div><div class="field"><label for="vote-contestant">Nominee</label><select class="select" id="vote-contestant" style="width:100%"><option>Ama Mensah</option><option>Kojo Owusu</option><option>Esi Sarfo</option></select></div>
    <div class="field"><label>Number of votes</label><div class="quantity-control"><button data-action="quantity-minus" aria-label="Remove one vote">−</button><output id="vote-quantity">1</output><button data-action="quantity-plus" aria-label="Add one vote">+</button><span class="field-hint">The event-wide vote limit applies. Paid voting is not available.</span></div></div><div class="notice warning">Demo only: vote submission is simulated in this frontend. No real ballot is recorded.</div></div>
    <aside><div class="panel"><h2>Review your vote</h2><div class="summary-row"><span>Event</span><strong id="summary-event">Campus Voices Awards · free</strong></div><div class="summary-row"><span>Category</span><strong id="summary-category">Community builder</strong></div><div class="summary-row"><span>Nominee</span><strong id="summary-contestant">Ama Mensah</strong></div><div class="summary-row"><span>Votes</span><strong id="summary-quantity">1</strong></div><div class="summary-row"><span>Price per vote</span><strong id="summary-price">GHS 0.00</strong></div><div class="summary-row total"><span>Total</span><strong id="summary-total">GHS 0.00</strong></div><button class="btn btn-primary" style="width:100%" data-action="submit-vote">Continue to demo payment</button><p class="field-hint" style="text-align:center;margin-top:12px">By continuing, you confirm that you have read the event rules.</p></div></aside></div></section></main>`);
  const results = () => publicPage(`<main>${pageHeader("HOME / RESULTS", "See what your community decided.", "Explore published results and live standings. Live figures are illustrative mock data for the frontend preview.")}<section class="content-section"><div class="container"><div class="filter-row"><label class="search-wrap"><span class="search-icon">⌕</span><input class="input" placeholder="Search results..." aria-label="Search results"></label><select class="select"><option>All event types</option><option>University</option><option>Community</option></select></div>
    <div class="event-grid">${events.filter(item=>item.status!=="Upcoming").map(eventCard).join("")}</div><div class="panel" style="margin-top:20px"><div class="section-heading"><div><h2>Campus Voices Awards</h2><p>Community builder · Live standings</p></div><span class="pill pill-live">Updated just now</span></div>
      ${[["Ama Mensah","4,820","42%"],["Kojo Owusu","3,910","34%"],["Esi Sarfo","2,745","24%"]].map(([name,votes,pct],i)=>`<div class="result-row"><span class="rank">${i+1}</span><strong>${name}</strong><span>${votes}</span><div class="bar"><span style="width:${pct}"></span></div><span>${pct}</span></div>`).join("")}
      <p class="field-hint">Live standings may change until voting closes. Final results are confirmed by the event organizer.</p></div></div></section></main>`);
  const nominations = () => publicPage(`<main>${pageHeader("HOME / NOMINATIONS", "Know someone who deserves the spotlight?", "Find an open nomination and tell us about the person, team or idea making a difference.")}<section class="content-section"><div class="container detail-layout"><div class="panel"><h2>Open nominations</h2>
    <article class="event-card" style="margin-bottom:13px"><div class="event-art art-blue"><span class="event-kicker">NORTHBRIDGE UNIVERSITY</span><h3>Campus Voices Awards</h3></div><div class="event-card-body"><div class="event-meta"><span>Deadline: 05 Nov 2026</span><span>6 categories</span></div><div class="event-card-bottom"><span class="organizer-mini">Student Experience Office<small>University awards</small></span><button class="btn btn-primary btn-sm" data-action="open-nomination">Nominate someone</button></div></div></article>
    <article class="event-card"><div class="event-art art-green"><span class="event-kicker">CIVIC BRIDGE NETWORK</span><h3>Community Impact Honors</h3></div><div class="event-card-body"><div class="event-meta"><span>Deadline: 18 Nov 2026</span><span>4 categories</span></div><div class="event-card-bottom"><span class="organizer-mini">Civic Bridge Network<small>Community recognition</small></span><button class="btn btn-primary btn-sm" data-action="open-nomination">Nominate someone</button></div></div></article></div>
    <aside class="panel" id="nomination-form-panel"><h2>Nomination application</h2><p>Choose an open event to get started. Your application is saved as a local mock demonstration only.</p><form data-form="nomination"><div class="field"><label for="nominee-name">Nominee full name</label><input class="input" required id="nominee-name" placeholder="e.g. Ama Mensah"></div><div class="field"><label for="nominee-email">Your email</label><input class="input" required type="email" id="nominee-email" placeholder="you@example.com"></div><div class="field"><label for="nominee-category">Category</label><select class="select" id="nominee-category" style="width:100%"><option>Community builder</option><option>Creative voice</option><option>Student initiative</option></select></div><div class="field"><label for="nominee-story">Why are you nominating them?</label><textarea class="textarea" required id="nominee-story" rows="4" placeholder="Share a few details..."></textarea></div><div class="field"><label for="nominee-file">Supporting photo (optional)</label><input class="input" id="nominee-file" type="file" accept="image/*"><span class="field-hint">Demo interface only; files are not uploaded or stored.</span></div><button class="btn btn-primary">Submit demo nomination</button></form></aside></div></section></main>`);
  const about = () => publicPage(`<main>${pageHeader("HOME / ABOUT", "Every community has a story worth hearing.", "WInVote brings communities, organizers and participants together around fair, clear and memorable events.")}<section class="section"><div class="container detail-layout"><div><div class="panel"><span class="eyebrow" style="color:var(--teal)">OUR PURPOSE</span><h2 style="margin-top:15px;font-size:27px">Make participation feel meaningful.</h2><p>We believe a great event gives people more than a place to cast a vote. It creates a welcoming way to discover nominees, understand the rules and celebrate what a community values.</p><p>WInVote is an original platform concept built for organizers who want a clearer and more connected event experience—from nominations through to results.</p></div><div class="how-grid" style="margin-top:16px"><div class="how-card"><div class="how-icon">01</div><h3>Clarity</h3><p>Understand event rules, timelines and results at every step.</p></div><div class="how-card"><div class="how-icon">02</div><h3>Community</h3><p>Give people a shared space to recognize their peers.</p></div><div class="how-card"><div class="how-icon">03</div><h3>Care</h3><p>Design each experience around its community's needs.</p></div></div></div><aside class="panel"><h2>Built for many kinds of moments</h2><ul class="detail-list"><li><span>Education</span><strong>Student elections & awards</strong></li><li><span>Arts & culture</span><strong>Creative competitions</strong></li><li><span>Organizations</span><strong>Leadership & recognition</strong></li><li><span>Communities</span><strong>Local impact celebrations</strong></li></ul></aside></div></section></main>`);
  const support = () => publicPage(`<main>${pageHeader("HOME / SUPPORT", "We're here to help.", "Questions about an event or setting up your organizer account? Find an answer or send the support team a note.")}<section class="content-section"><div class="container detail-layout"><div class="panel"><h2>Send us a message</h2><form data-form="support"><div class="form-grid"><div class="field"><label for="support-name">Your name</label><input id="support-name" class="input" required placeholder="Full name"></div><div class="field"><label for="support-email">Email address</label><input id="support-email" type="email" class="input" required placeholder="you@example.com"></div></div><div class="field"><label for="support-topic">How can we help?</label><select class="select" id="support-topic" style="width:100%"><option>Voting or event question</option><option>Organizer support</option><option>Account assistance</option><option>Report a concern</option></select></div><div class="field"><label for="support-message">Message</label><textarea id="support-message" class="textarea" rows="5" required placeholder="Tell us a little more..."></textarea></div><button class="btn btn-primary">Send demo message</button><p class="field-hint">This frontend demonstration does not send or store your message.</p></form></div><aside><div class="panel"><h2>Common questions</h2><div id="faq"><details><summary>How do I vote in an event?</summary><p>Open the event, review its rules and voting period, then choose a category and nominee.</p></details><details><summary>Can I create my own event?</summary><p>Yes. Create an organizer account to begin a demo event setup.</p></details><details><summary>When are results final?</summary><p>Each organizer sets result visibility and confirms finalized results after voting closes.</p></details></div></div><div class="panel"><h3>Contact</h3><p>Email: hello@winvote.example<br>Support hours: Monday–Friday</p></div></aside></div></section></main>`);
  const authPage = (kind) => {
    const configs = {
      login: ["Welcome back", "Sign in to continue to your WInVote account.", "Email address", "Password", "Log in"],
      register: ["Create your account", "Join a community event or start organizing.", "Full name", "Password", "Create account"],
      "forgot-password": ["Reset your password", "Enter your email and we'll guide you through a reset.", "Email address", "", "Send reset instructions"],
      "reset-password": ["Choose a new password", "Make sure it's strong and unique to this account.", "New password", "Confirm password", "Update password"]
    };
    const [heading, intro, firstLabel, secondLabel, submit] = configs[kind];
    const isRegister = kind==="register";
    const emailLabel = isRegister ? "Email address" : firstLabel;
    const requiresEmail = ["login", "register", "forgot-password"].includes(kind);
    return publicPage(`<main class="content-section"><div class="container"><div class="form-shell"><div class="form-card"><div class="brand" style="margin-bottom:22px"><span class="brand-mark">W</span><span>WIn<em style="color:var(--teal)">Vote</em></span></div><h1>${heading}</h1><p class="form-intro">${intro}</p><form data-form="auth">
      ${isRegister ? '<div class="field"><label for="auth-name">Full name</label><input class="input" required id="auth-name" autocomplete="name" placeholder="Your full name"></div>' : ""}
      ${requiresEmail ? `<div class="field"><label for="auth-email">${emailLabel}</label><input class="input" required type="email" id="auth-email" autocomplete="email" placeholder="you@example.com"></div>` : ""}
      ${kind==="reset-password" ? '<div class="field"><label for="auth-password">New password</label><input class="input" required type="password" id="auth-password" minlength="8" autocomplete="new-password"></div><div class="field"><label for="auth-confirm">Confirm password</label><input class="input" required type="password" id="auth-confirm" minlength="8" autocomplete="new-password"></div>' : ""}
      ${kind==="login" || isRegister ? `<div class="field"><label for="auth-password">${isRegister?"Create password":secondLabel}</label><div style="display:flex;gap:7px"><input class="input" required type="password" id="auth-password" minlength="8" autocomplete="${isRegister?"new-password":"current-password"}"><button type="button" class="btn btn-secondary btn-sm" data-action="toggle-password">Show</button></div></div>` : ""}
      ${kind==="login" ? '<div style="text-align:right;margin:4px 0 15px"><a class="text-link" href="forgot-password.html">Forgot password?</a></div>' : ""}
      ${isRegister ? '<div class="field"><label for="account-role">I am here to</label><select class="select" style="width:100%" id="account-role"><option>Participate as a voter</option><option>Organize events</option><option>Join as a contestant</option></select></div>' : ""}
      ${isRegister ? '<label class="inline-check"><input type="checkbox" required> I agree to the terms and privacy notice.</label>' : ""}
      <button class="btn btn-primary">${submit}</button></form><p class="form-note">${kind==="login"?`New to WInVote? <a class="text-link" href="${authUrl("register")}">Create an account</a>`:kind==="register"?`Already have an account? <a class="text-link" href="${authUrl("login")}">Log in</a>`:`<a class="text-link" href="${authUrl("login")}">Return to login</a>`}</p><div class="notice warning" style="margin-top:17px">Mock frontend only. Account details are not sent, authenticated or stored.</div></div></div></div></main>`);
  };

  const organizerNav = [["Overview","dashboard.html","⌂"],["Events",serverMode?"events.php":"events.html","▦"],["Categories",serverMode?"categories.php":"categories.html","◇"],["Contestants",serverMode?"contestants.php":"contestants.html","♙"],["Nominations",serverMode?"nominations.php":"nominations.html","✳"],["Voting activity","votes.html","◉"],["Transactions","transactions.html","＄"],["Results","results.html","▥"],["Reports","reports.html","▤"],["Settings","settings.html","⚙"],["Profile","profile.html","○"]];
  const adminNav = [["Overview","dashboard.html","⌂"],["Organizers","organizers.html","♙"],["Events","events.html","▦"],["Users","users.html","♧"],["Contestants","contestants.html","♙"],["Nominations","nominations.html","✳"],["Transactions","transactions.html","＄"],["Payments","payments.html","↔"],["Revenue","revenue.html","▥"],["Withdrawals","withdrawals.html","⇧"],["Reports","reports.html","▤"],["Support","support.html","? "],["Settings","settings.html","⚙"],["Audit logs","audit-logs.html","≡"],["Profile","profile.html","○"]];
  const nav = scope==="admin" ? adminNav : organizerNav;
  const organizerEventsUrl = serverMode ? "events.php" : "events.html";
  const dashboardTable = (heading, headers, rows) => `<div class="panel"><div class="section-heading"><div><h2>${heading}</h2><p>Recent activity in your workspace</p></div><a class="text-link" href="${scope==="admin"?"events.html":organizerEventsUrl}">View all →</a></div><div class="table-wrap"><table class="data-table"><thead><tr>${headers.map(item=>`<th>${item}</th>`).join("")}</tr></thead><tbody>${rows.map(row=>`<tr>${row.map((cell,index)=>`<td>${index===0?`<span class="table-primary">${cell}</span>`:cell}</td>`).join("")}</tr>`).join("")}</tbody></table></div></div>`;
  const dashboardHome = () => {
    const isAdmin = scope==="admin";
    const cards = isAdmin ? [["Organizers","32","+4 this month"],["Published events","86","+12 this month"],["Votes recorded","48.2k","+18% this week"],["Platform revenue","GHS 24,680","+8.4% this month"]] : [["Total events","8","Across your workspace"],["Active events","3","Currently accepting votes"],["Total votes","12,840","+12% this week"],["Gross revenue","GHS 8,420","Mock reporting figure"]];
    const rows = isAdmin ? [["Campus Voices Awards","Northbridge University",'<span class="pill pill-live">Live</span>',"12,840"],["Women Who Lead 2026","Forward Ghana Foundation",'<span class="pill pill-live">Live</span>',"8,402"],["New Sound Showcase","Soundwave Collective",'<span class="pill pill-upcoming">Review</span>',"—"]] : [["Campus Voices Awards","12,840",'<span class="pill pill-live">Live</span>',"28 Nov 2026"],["Community Impact Honors","5,212",'<span class="pill pill-live">Live</span>',"30 Nov 2026"],["Student Leadership Awards","—",'<span class="pill pill-upcoming">Upcoming</span>',"12 Dec 2026"]];
    return `<div class="dash-title-row"><div><h1>Good morning, ${isAdmin?"Jordan":"Alex"}.</h1><p>Here's what's happening in your ${isAdmin?"platform":"organizer"} workspace today.</p></div><a class="btn btn-primary" href="${isAdmin?"events.html":organizerEventsUrl}">${isAdmin?"Review events":"＋ Create event"}</a></div>
      <div class="stat-grid">${cards.map(([label,value,note],i)=>`<div class="stat-card"><div class="stat-card-top"><span>${label}</span><span class="stat-symbol">${["▦","◉","♧","＄"][i]}</span></div><strong class="stat-value">${value}</strong><div class="stat-change">${note}</div></div>`).join("")}</div>
      <div class="dash-grid"><div class="panel"><div class="section-heading"><div><h2>Voting activity</h2><p>Votes recorded over the last 7 days</p></div><select class="select"><option>This week</option><option>This month</option></select></div><div class="chart-placeholder" aria-label="Illustrative bar chart">${[38,56,46,74,60,89,68,96,80,66,100,77,87,64,92,72].map(height=>`<span class="chart-bar" style="height:${height}%"></span>`).join("")}</div><div class="chart-labels"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div><p class="field-hint">Illustrative mock chart · connect live reporting in a later phase.</p></div>
      <div class="panel"><div class="section-heading"><div><h2>Recent activity</h2><p>Latest updates across your events</p></div></div><div class="activity-list"><div class="activity-item"><span class="activity-mark">✓</span><div><strong>New nomination received</strong><small>Campus Voices Awards · 14 minutes ago</small></div></div><div class="activity-item"><span class="activity-mark">↗</span><div><strong>Voting milestone reached</strong><small>Community Impact Honors · 1 hour ago</small></div></div><div class="activity-item"><span class="activity-mark">＋</span><div><strong>Contestant profile updated</strong><small>Campus Voices Awards · 3 hours ago</small></div></div></div></div></div>
      ${dashboardTable(isAdmin?"Events to review":"Your events",isAdmin?["Event","Organizer","Status","Votes"]:["Event","Votes","Status","Closes"],rows)}`;
  };
  const tablePageContent = () => {
    const isAdmin=scope==="admin";
    const label=titleCase(page);
    const configs={
      "events":{title:"Events",desc:"Create, review and manage event experiences.",headers:["Event","Organizer","Status","Votes","Actions"],rows:[["Campus Voices Awards","Northbridge University",'<span class="pill pill-live">Live</span>',"12,840","Manage"],["New Sound Showcase","Soundwave Collective",'<span class="pill pill-upcoming">Upcoming</span>',"—","Review"],["Women Who Lead 2026","Forward Ghana Foundation",'<span class="pill pill-live">Live</span>',"8,402","Manage"]]},
      "contestants":{title:"Contestants",desc:"Manage nominee profiles and category assignments.",headers:["Contestant","Category","Event","Status","Actions"],rows:[["Ama Mensah","Community builder","Campus Voices",'<span class="pill pill-live">Approved</span>',"Edit"],["Kojo Owusu","Community builder","Campus Voices",'<span class="pill pill-live">Approved</span>',"Edit"],["Esi Sarfo","Creative voice","Campus Voices",'<span class="pill pill-upcoming">Pending</span>',"Review"]]},
      "nominations":{title:"Nominations",desc:"Review applications and keep applicants informed.",headers:["Applicant","Nominee","Event","Submitted","Status"],rows:[["Nana Osei","Ama Mensah","Campus Voices","Today, 10:42",'<span class="pill pill-upcoming">Pending</span>'],["Mina Boakye","Kwame Armah","Community Impact","Yesterday",'<span class="pill pill-upcoming">Pending</span>'],["Kofi Annan","Esi Sarfo","Campus Voices","25 Oct",'<span class="pill pill-live">Approved</span>']]},
      "transactions":{title:"Transactions",desc:"Review payment activity and transaction records.",headers:["Reference","Event","Voter","Amount","Status"],rows:[["WV-20481","Campus Voices","voter@example.com","GHS 20.00",'<span class="pill pill-live">Successful</span>'],["WV-20480","Campus Voices","guest@example.com","GHS 5.00",'<span class="pill pill-upcoming">Pending</span>'],["WV-20479","Community Impact","member@example.com","GHS 10.00",'<span class="pill pill-closed">Refunded</span>']]},
      "votes":{title:"Voting activity",desc:"Monitor event voting activity and configured limits.",headers:["Nominee","Category","Event","Votes","Recorded"],rows:[["Ama Mensah","Community builder","Campus Voices","4,820","2 min ago"],["Kojo Owusu","Community builder","Campus Voices","3,910","8 min ago"],["Esi Sarfo","Creative voice","Campus Voices","2,745","13 min ago"]]},
      "results":{title:"Results",desc:"Review standings and published event results.",headers:["Event","Category","Leading nominee","Votes","Visibility"],rows:[["Campus Voices","Community builder","Ama Mensah","4,820",'<span class="pill pill-live">Live</span>'],["Coastal Style & Culture","Emerging designer","Akua Boateng","5,210",'<span class="pill pill-closed">Final</span>'],["Community Impact","Local champion","Nii Lante","1,904",'<span class="pill pill-live">Live</span>']]},
      "organizers":{title:"Organizers",desc:"Review organizer accounts and platform access.",headers:["Organization","Account owner","Events","Status","Actions"],rows:[["Northbridge University","Alex Morgan","8",'<span class="pill pill-live">Approved</span>',"View"],["Soundwave Collective","Mina Asare","3",'<span class="pill pill-upcoming">Pending</span>',"Review"],["Forward Ghana Foundation","Jordan Kusi","6",'<span class="pill pill-live">Approved</span>',"View"]]},
      "users":{title:"Users",desc:"Manage user accounts and access status.",headers:["Name","Email","Role","Joined","Status"],rows:[["Alex Morgan","alex@example.com","Organizer","10 Oct 2026",'<span class="pill pill-live">Active</span>'],["Ama Mensah","ama@example.com","Contestant","09 Oct 2026",'<span class="pill pill-live">Active</span>'],["Kofi Asare","kofi@example.com","Voter","08 Oct 2026",'<span class="pill pill-live">Active</span>']]},
      "payments":{title:"Payments",desc:"Review payment attempts and reconciliation indicators.",headers:["Payment ref","Provider","Event","Amount","Status"],rows:[["PAY-03851","Hubtel · mock","Campus Voices","GHS 20.00",'<span class="pill pill-live">Verified</span>'],["PAY-03850","Hubtel · mock","Campus Voices","GHS 5.00",'<span class="pill pill-upcoming">Pending</span>'],["PAY-03849","Hubtel · mock","Community Impact","GHS 10.00",'<span class="pill pill-closed">Failed</span>']]},
      "revenue":{title:"Revenue",desc:"Illustrative platform revenue and commission overview.",headers:["Period","Gross volume","Platform fees","Organizer share","Status"],rows:[["October 2026","GHS 24,680","GHS 1,234","GHS 23,446",'<span class="pill pill-live">Current</span>'],["September 2026","GHS 18,420","GHS 921","GHS 17,499",'<span class="pill pill-closed">Closed</span>']]},
      "withdrawals":{title:"Withdrawals",desc:"Review settlement and withdrawal requests.",headers:["Organizer","Requested","Amount","Destination","Status"],rows:[["Northbridge University","28 Oct","GHS 1,840","•••• 4821",'<span class="pill pill-upcoming">Review</span>'],["Coastline Creative","25 Oct","GHS 920","•••• 1944",'<span class="pill pill-live">Processed</span>']]},
      "reports":{title:"Reports",desc:"Explore event performance and export mock report summaries.",headers:["Report","Scope","Updated","Format","Action"],rows:[["Voting summary","All events","Today","CSV","Download"],["Financial overview","October 2026","Yesterday","CSV","Download"],["Nomination outcomes","Campus Voices","25 Oct","CSV","Download"]]},
      "support":{title:"Support requests",desc:"Review and respond to platform support requests.",headers:["Ticket","Requester","Topic","Updated","Status"],rows:[["#WV-0241","M. Boateng","Voting question","Today",'<span class="pill pill-upcoming">Open</span>'],["#WV-0240","A. Morgan","Organizer account","Yesterday",'<span class="pill pill-live">Resolved</span>']]},
      "audit-logs":{title:"Audit logs",desc:"Review administrative actions recorded in the platform.",headers:["Action","Actor","Resource","Timestamp","Outcome"],rows:[["Event approved","Jordan Kusi","Campus Voices","30 Oct, 09:14",'<span class="pill pill-live">Success</span>'],["Organizer updated","Jordan Kusi","Soundwave Collective","29 Oct, 16:40",'<span class="pill pill-live">Success</span>'],["Results finalized","Alex Morgan","Coastal Style","28 Oct, 13:06",'<span class="pill pill-live">Success</span>']]},
      "categories":{title:"Categories",desc:"Configure the categories and voting rules for your events.",headers:["Category","Event","Nominees","Vote rule","Actions"],rows:[["Community builder","Campus Voices","6","Event-wide vote limit","Edit"],["Creative voice","Campus Voices","4","Event-wide vote limit","Edit"],["Local champion","Community Impact","8","Paid voting disabled","Edit"]]}
    };
    const config=configs[page]||{title:label,desc:`Manage ${label.toLowerCase()} for your workspace.`,headers:["Name","Event","Updated","Status","Actions"],rows:[["Sample record","Campus Voices","Today",'<span class="pill pill-live">Active</span>',"Manage"],["Another record","Community Impact","Yesterday",'<span class="pill pill-upcoming">Pending</span>',"Review"]]};
    let actionButton="";
    if(page==="events"&&!isAdmin) actionButton=`<a href="create-event.html" class="btn btn-primary">＋ Create event</a>`;
    else if(page==="events"&&isAdmin) actionButton=`<button class="btn btn-primary" data-action="mock-new">Review queue</button>`;
    else if(page==="contestants"&&!isAdmin) actionButton=`<a href="add-contestant.html" class="btn btn-primary">＋ Add contestant</a>`;
    else if(page==="categories") actionButton=`<button class="btn btn-primary" data-action="mock-new">＋ Add category</button>`;
    const rows=config.rows.map((row)=>row.map(cell=>cell==="Manage"||cell==="Review"||cell==="Edit"||cell==="View"||cell==="Download"?`<button class="table-action" data-action="row-action">${cell}</button>`:cell));
    let extra="";
    if(page==="categories") extra=`<div class="panel" style="margin-top:14px"><h2>Voting configuration</h2><div class="form-grid"><div class="field"><label>Voting mode</label><select class="select" style="width:100%"><option>Free voting</option><option>Paid multiple voting</option><option>Restricted election</option></select></div><div class="field"><label>Maximum votes per voter</label><input class="input" type="number" value="5" min="1"></div></div><button class="btn btn-primary btn-sm" data-action="save-settings">Save mock settings</button></div>`;
    if(page==="settings"||page==="profile") extra=`<div class="panel" style="margin-top:14px"><h2>${label} preferences</h2><form data-form="settings"><div class="form-grid"><div class="field"><label>Display name</label><input class="input" value="${isAdmin?"Jordan Kusi":"Alex Morgan"}"></div><div class="field"><label>Contact email</label><input class="input" type="email" value="alex@example.com"></div></div><div class="field"><label>Timezone</label><select class="select"><option>Africa/Accra (GMT)</option><option>UTC</option></select></div><button class="btn btn-primary">Save demo preferences</button></form></div>`;
    return `<div class="dash-title-row"><div><h1>${config.title}</h1><p>${config.desc}</p></div>${actionButton}</div><div class="panel"><div class="filter-row" style="margin-bottom:15px"><label class="search-wrap"><span class="search-icon">⌕</span><input class="input table-search" placeholder="Search ${config.title.toLowerCase()}..." aria-label="Search ${config.title}"></label><select class="select"><option>All statuses</option><option>Live</option><option>Upcoming</option><option>Pending</option><option>Completed</option></select><button class="btn btn-secondary btn-sm" data-action="mock-filter">Filter</button></div><div class="table-wrap"><table class="data-table"><thead><tr>${config.headers.map(header=>`<th>${header}</th>`).join("")}</tr></thead><tbody>${rows.map(row=>`<tr>${row.map((cell,index)=>`<td>${index===0?`<span class="table-primary">${cell}</span>`:cell}</td>`).join("")}</tr>`).join("")}</tbody></table></div><div class="pagination" aria-label="Table pages"><button aria-label="Previous page" disabled>‹</button><button class="active" aria-current="page" disabled>1</button><button aria-label="Next page" disabled>›</button></div><p class="field-hint">Sample interface data only. No records are changed or transmitted.</p></div>${extra}`;
  };
  const formPageContent = () => {
    const create=page==="create-event.html"||page==="create-event";
    const isContest=page==="add-contestant.html"||page==="add-contestant";
    const isEdit=page==="edit-event.html"||page==="edit-event";
    const heading=create?"Create an event":isContest?"Add a contestant":isEdit?"Edit an event":"Workspace overview";
    const form=create||isEdit?`<form data-form="mock-create"><div class="form-grid"><div class="field"><label>Event name</label><input class="input" required value="${isEdit?"Campus Voices Awards":""}" placeholder="e.g. Campus Voices Awards"></div><div class="field"><label>Event type</label><select class="select"><option>University awards</option><option>Competition</option><option>Election</option></select></div></div><div class="field"><label>Description</label><textarea class="textarea" rows="3" required placeholder="What is this event about?">${isEdit?"Recognizing the students and groups making our campus community more inspiring.":""}</textarea></div><div class="form-grid"><div class="field"><label>Voting opens</label><input class="input" type="date" required value="${isEdit?"2026-11-12":""}"></div><div class="field"><label>Voting closes</label><input class="input" type="date" required value="${isEdit?"2026-11-28":""}"></div></div><div class="form-grid"><div class="field"><label>Voting mode</label><select class="select"><option>Free voting</option><option>Paid voting</option><option>Restricted one-vote election</option></select></div><div class="field"><label>Results visibility</label><select class="select"><option>Publish after voting closes</option><option>Show live standings</option><option>Organizer only</option></select></div></div><label class="inline-check"><input type="checkbox"> Save as draft (demo)</label><button class="btn btn-primary">${isEdit?"Save mock changes":"Create mock event"}</button></form>`:
      `<form data-form="mock-create"><div class="form-grid"><div class="field"><label>Full name</label><input class="input" required placeholder="Contestant name"></div><div class="field"><label>Event</label><select class="select"><option>Campus Voices Awards</option><option>Community Impact Honors</option></select></div></div><div class="field"><label>Category</label><select class="select"><option>Community builder</option><option>Creative voice</option></select></div><div class="field"><label>Biography</label><textarea class="textarea" rows="4" required placeholder="Introduce this nominee..."></textarea></div><div class="field"><label>Profile image</label><input class="input" type="file" accept="image/*"><span class="field-hint">Mock interface only; no file is uploaded or stored.</span></div><button class="btn btn-primary">Save mock contestant</button></form>`;
    return `<div class="dash-title-row"><div><h1>${heading}</h1><p>Complete the details below. This frontend preview does not save to a server.</p></div><a href="${isContest?"contestants.html":"events.html"}" class="btn btn-secondary">← Back</a></div><div class="panel" style="max-width:780px"><span class="pill pill-upcoming">Draft preview</span><div style="margin-top:18px">${form}</div></div>`;
  };
  const eventDetailsContent = () => `<div class="dash-title-row"><div><h1>Campus Voices Awards</h1><p>Event workspace · Northbridge University</p></div><a href="edit-event.html" class="btn btn-primary">Edit event</a></div>
    <div class="stat-grid"><div class="stat-card"><div class="stat-card-top">Total votes<span class="stat-symbol">◉</span></div><strong class="stat-value">12,840</strong><div class="stat-change">Illustrative sample</div></div><div class="stat-card"><div class="stat-card-top">Categories<span class="stat-symbol">◇</span></div><strong class="stat-value">6</strong><div class="stat-change">4 with nominees</div></div><div class="stat-card"><div class="stat-card-top">Contestants<span class="stat-symbol">♙</span></div><strong class="stat-value">18</strong><div class="stat-change">2 awaiting review</div></div><div class="stat-card"><div class="stat-card-top">Voting status<span class="stat-symbol">◷</span></div><strong class="stat-value" style="font-size:17px">Live</strong><div class="stat-change">Closes 28 Nov 2026</div></div></div>
    <div class="dash-grid"><div class="panel"><div class="section-heading"><div><h2>Event overview</h2><p>Voting period and public details</p></div><span class="pill pill-live">Published</span></div><ul class="detail-list"><li><span>Event type</span><strong>University awards</strong></li><li><span>Voting opens</span><strong>12 Nov 2026</strong></li><li><span>Voting closes</span><strong>28 Nov 2026</strong></li><li><span>Voting mode</span><strong>Free · event-wide voter limit</strong></li><li><span>Results visibility</span><strong>Live standings</strong></li></ul></div><div class="panel"><h2>Quick actions</h2><div class="activity-list"><a class="activity-item" href="categories.html"><span class="activity-mark">◇</span><div><strong>Manage categories</strong><small>Review event voting categories</small></div></a><a class="activity-item" href="contestants.html"><span class="activity-mark">♙</span><div><strong>Manage contestants</strong><small>Review nominee profiles</small></div></a><a class="activity-item" href="results.html"><span class="activity-mark">▥</span><div><strong>View results</strong><small>Explore current standings</small></div></a></div></div></div>
    ${dashboardTable("Category performance",["Category","Nominees","Votes","Status"],[["Community builder","6","6,410",'<span class="pill pill-live">Voting</span>'],["Creative voice","4","3,280",'<span class="pill pill-live">Voting</span>'],["Student initiative","8","3,150",'<span class="pill pill-live">Voting</span>']])}`;
  const dashboard = () => {
    const active=nav.some(item=>item[1]===activeFile)?activeFile:"dashboard.html";
    const title=scope==="admin"?"Platform administration":"Organizer workspace";
    const sideLinks=nav.map(([label,href,icon])=>`<a class="side-link ${href===active?"active":""}" href="${href}"><span class="side-icon">${icon}</span>${label}</a>`).join("");
    let content=activeFile==="dashboard.html"?dashboardHome():["create-event.html","edit-event.html","add-contestant.html"].includes(activeFile)?formPageContent():activeFile==="event-details.html"?eventDetailsContent():tablePageContent();
    return `<div class="dashboard-shell"><aside class="sidebar" id="dashboard-sidebar">${brand}<div class="side-label">${scope==="admin"?"Platform":"Workspace"}</div>${sideLinks}<div class="sidebar-bottom"><a class="side-link" href="../index.html"><span class="side-icon">↗</span>View public site</a><a class="side-link" href="${authUrl("login")}"><span class="side-icon">○</span>Account</a></div></aside>
      <div class="dash-main"><header class="dash-topbar"><div class="dash-topbar-left"><button class="mobile-menu" data-action="dashboard-menu" aria-label="Toggle dashboard menu">☰</button><span>${title}</span></div><div class="dash-user"><span>Demo account</span><span class="user-avatar">${scope==="admin"?"JK":"AM"}</span></div></header><main class="dash-content"><div class="notice warning workspace-preview-notice" role="note"><strong>Public prototype preview:</strong> This workspace is not protected or connected to live records. All information is sample data and actions are demonstrations only. Do not enter real information.</div>${content}<p class="field-hint" style="margin-top:20px">WInVote frontend demonstration · All metrics and records shown are sample data.</p></main></div></div>`;
  };
  const routes = {
    home, events:eventListing, "event-details":details, contestant, vote, results, nominations, about, support,
    login:()=>authPage("login"), register:()=>authPage("register"), "forgot-password":()=>authPage("forgot-password"), "reset-password":()=>authPage("reset-password")
  };
  root.innerHTML=scope==="public"?(routes[page]||home)():dashboard();
  if(scope==="public"&&serverMode) {
    fetch(`${relative}auth.php?action=status`,{credentials:"same-origin",headers:{Accept:"application/json"}})
      .then(response=>{
        if(!response.ok) throw new Error(`Account status request failed (${response.status}).`);
        return response.json();
      })
      .then(status=>{
        if(!status.authenticated||!status.user) return;
        const user=status.user;
        const roles=Array.isArray(user.roles)?user.roles:[];
        const role=roles.includes("super_admin")?"Super admin":roles.includes("organizer")?(user.organizer_status==="approved"?"Approved organizer":"Organizer · approval pending"):"WInVote account";
        const initial=esc(Array.from(user.full_name||"W").slice(0,1).join("").toUpperCase());
        const navActions=document.querySelector(".nav-actions");
        if(navActions) navActions.innerHTML=`<details class="account-menu"><summary class="account-trigger"><span class="user-avatar" aria-hidden="true">${initial}</span><span class="account-trigger-name">${esc(user.full_name)}</span><span aria-hidden="true">⌄</span></summary><div class="account-dropdown"><strong>${esc(user.full_name)}</strong><span class="account-email">${esc(user.email)}</span><span class="account-role">${role}</span>${roles.includes("organizer")&&user.organizer_status==="approved"?`<a href="${relative}organizer/events.php">Manage events</a>`:""}<a href="${relative}auth.php?action=account">Account details</a><form method="post" action="${relative}auth.php?action=logout"><input type="hidden" name="_token" value="${esc(status.csrf_token||"")}"><button type="submit">Sign out</button></form></div></details>`;
        document.querySelectorAll("[data-register-link], [data-auth-register-cta]").forEach(link=>{
          const canManageEvents=roles.includes("organizer")&&user.organizer_status==="approved";
          link.href=canManageEvents?`${relative}organizer/events.php`:`${relative}auth.php?action=account`;
          link.textContent=canManageEvents?"Manage events":"Account details";
        });
      })
      .catch(error=>console.error("WInVote account navigation could not be updated:",error));
  }

  const applyEventFilters=()=>{
    const search=(document.querySelector("#event-search")?.value||"").toLowerCase();
    const category=document.querySelector("#category-filter")?.value||"";
    const status=document.querySelector("#status-filter")?.value||"";
    let tab=document.querySelector(".tab.active[data-status-tab]")?.dataset.statusTab||"All";
    const cards=[...document.querySelectorAll("#event-list .event-card")];
    let visible=0;
    cards.forEach((card)=>{
      const matchText=card.textContent.toLowerCase().includes(search);
      const matchCategory=!category||card.dataset.category===category;
      const matchStatus=(!status||card.dataset.status===status)&&(tab==="All"||card.dataset.status===tab);
      card.hidden=!(matchText&&matchCategory&&matchStatus);
      if(!card.hidden) visible++;
    });
    const empty=document.querySelector("#event-empty");
    if(empty) empty.hidden=visible!==0;
  };
  const updateVoteSummary=()=>{
    const eventSelect=document.querySelector("#vote-event");
    const quantity=Number(document.querySelector("#vote-quantity")?.value||document.querySelector("#vote-quantity")?.textContent||1);
    if(!eventSelect) return;
    const price=Number(eventSelect.selectedOptions[0].dataset.price||0);
    document.querySelector("#summary-event").textContent=eventSelect.value;
    document.querySelector("#summary-category").textContent=document.querySelector("#vote-category").value;
    document.querySelector("#summary-price").textContent=price===0?"GHS 0.00":`GHS ${price.toFixed(2)}`;
    document.querySelector("#summary-total").textContent=`GHS ${(price*quantity).toFixed(2)}`;
  };
  const renderEventCategory=(category)=>{
    const nominees={
      "Community builder":[["AM","Ama Mensah",""],["KO","Kojo Owusu","portrait-coral"],["ES","Esi Sarfo","portrait-gold"]],
      "Creative voice":[["NA","Nia Asante","portrait-violet"],["KD","Kwame Dapaah",""],["ES","Esi Sarfo","portrait-coral"]],
      "Student initiative":[["KO","Kojo Owusu","portrait-blue"],["AM","Ama Mensah","portrait-gold"],["YA","Yaw Addo",""]]
    };
    const list=document.querySelector("#event-contestants");
    if(!list) return;
    list.innerHTML=nominees[category].map(([initials,name,style])=>`<article class="contestant-card"><div class="portrait ${style}">${initials}</div><div class="contestant-body"><a class="table-primary" href="${relative}contestant.html">${name}</a><small>${category}</small><a class="btn btn-primary btn-sm" href="${relative}${voteUrl}">Vote for nominee</a></div></article>`).join("");
  };
  const filterDashboardRows=()=>{
    const term=(document.querySelector(".table-search")?.value||"").toLowerCase();
    const status=[...document.querySelectorAll(".dash-content .filter-row select")].at(-1)?.value||"All statuses";
    document.querySelectorAll(".data-table tbody tr").forEach(row=>{
      const matchesSearch=row.textContent.toLowerCase().includes(term);
      const matchesStatus=status==="All statuses"||row.textContent.toLowerCase().includes(status.toLowerCase());
      row.hidden=!(matchesSearch&&matchesStatus);
    });
  };
  document.addEventListener("input",(event)=>{
    if(["event-search","category-filter","status-filter"].includes(event.target.id)) applyEventFilters();
    if(event.target.id==="vote-contestant") document.querySelector("#summary-contestant").textContent=event.target.value;
    if(event.target.matches(".table-search")) filterDashboardRows();
  });
  document.addEventListener("change",(event)=>{
    if(["category-filter","status-filter"].includes(event.target.id)) applyEventFilters();
    if(event.target.id==="vote-contestant") document.querySelector("#summary-contestant").textContent=event.target.value;
    if(event.target.id==="vote-event"||event.target.id==="vote-category") updateVoteSummary();
    if(event.target.matches(".dash-content .filter-row select")) filterDashboardRows();
  });
  document.addEventListener("click",(event)=>{
    const button=event.target.closest("[data-action]");
    if(button) {
      const action=button.dataset.action;
      if(action==="public-menu") document.querySelector(".public-nav")?.classList.toggle("open");
      if(action==="dashboard-menu") document.querySelector("#dashboard-sidebar")?.classList.toggle("open");
      if(action==="quantity-minus"||action==="quantity-plus") {
        const output=document.querySelector("#vote-quantity");
        const amount=Math.max(1,Math.min(5,Number(output.value||output.textContent)+(action==="quantity-plus"?1:-1)));
        output.value=amount; output.textContent=amount;
        const summary=document.querySelector("#summary-quantity"); if(summary) summary.textContent=amount;
        updateVoteSummary();
      }
      if(action==="submit-vote") toast("Demo vote confirmed locally. No ballot was recorded.");
      if(action==="toggle-password") {
        const input=document.querySelector("#auth-password");
        if(input) { input.type=input.type==="password"?"text":"password"; button.textContent=input.type==="password"?"Show":"Hide"; }
      }
      if(action==="clear-filters") {
        document.querySelector("#event-search").value="";
        document.querySelector("#category-filter").value="";
        document.querySelector("#status-filter").value="";
        document.querySelectorAll("[data-status-tab]").forEach(tab=>tab.classList.toggle("active",tab.dataset.statusTab==="All"));
        applyEventFilters();
      }
      if(action==="open-nomination") { document.querySelector("#nominee-name")?.focus(); toast("Choose a nominee and complete the demo application."); }
      if(["mock-new","row-action","save-settings"].includes(action)) toast("Demo interaction only. No platform data was changed.");
      if(action==="mock-filter") { filterDashboardRows(); toast("Showing matching sample records."); }
    }
    const tab=event.target.closest("[data-status-tab]");
    if(tab) {
      document.querySelectorAll("[data-status-tab]").forEach(item=>item.classList.remove("active"));
      tab.classList.add("active"); applyEventFilters();
    }
    const categoryTab=event.target.closest("[data-category-tab]");
    if(categoryTab) {
      document.querySelectorAll("[data-category-tab]").forEach(item=>{
        const selected=item===categoryTab;
        item.classList.toggle("active",selected);
        item.setAttribute("aria-selected",String(selected));
      });
      renderEventCategory(categoryTab.dataset.categoryTab);
    }
  });
  document.addEventListener("submit",(event)=>{
    const form=event.target.closest("form[data-form]");
    if(!form) return;
    event.preventDefault();
    if(form.dataset.form==="auth" && page==="reset-password") {
      const password=form.querySelector("#auth-password").value;
      const confirm=form.querySelector("#auth-confirm").value;
      if(password!==confirm) { toast("Passwords do not match."); return; }
    }
    const messages={auth:"Demo only: authentication is not connected.",nomination:"Demo nomination submitted locally. No information was stored.",support:"Demo message prepared locally. It was not sent.",settings:"Demo preferences saved for this preview.", "mock-create":"Mock details accepted for this preview only."};
    toast(messages[form.dataset.form]||"Demo form completed.");
    if(form.dataset.form==="nomination") {
      form.reset();
      const panel=document.querySelector("#nomination-form-panel");
      if(panel) panel.insertAdjacentHTML("afterbegin",'<div class="notice" role="status">Thanks for the demo submission. Your application has not been stored or sent.</div>');
    }
  });
})();
