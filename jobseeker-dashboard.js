(function () {
  const ui = window.ui;
  const api = window.api;

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  async function requireJobSeeker() {
    const res = await api.auth.getCurrentUser();
    if (!res.success || !res.user) {
      window.location.href = 'index.html';
      return null;
    }
    const role = res.user.role;
    if (!(role === 'jobseeker' || role === 'job_seeker')) {
      window.location.href = 'index.html';
      return null;
    }
    return res.user;
  }

  function renderJobs(jobs) {
    if (!jobs || jobs.length === 0) return '<p>No jobs found.</p>';
    return jobs.map(j => `
      <div class="card" style="margin:.75rem 0;">
        <div style="display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
          <div>
            <strong>${escapeHtml(j.title)}</strong>
            <div style="opacity:.8; margin-top:.25rem;">${escapeHtml(j.company_name || '')}</div>
            <div style="opacity:.8; margin-top:.25rem;">${escapeHtml(j.location || '')} • ${escapeHtml(j.job_type || '')}</div>
          </div>
          <div>
            <button class="btn btn-primary" data-action="apply" data-id="${j.id}">Apply</button>
          </div>
        </div>
      </div>
    `).join('');
  }

  function renderApplications(apps) {
    if (!apps || apps.length === 0) return '<p>No applications yet.</p>';
    return apps.map(a => `
      <div class="card" style="margin:.75rem 0;">
        <div><strong>${escapeHtml(a.title || '')}</strong> • ${escapeHtml(a.status || '')}</div>
        <div style="opacity:.8; margin-top:.25rem;">${escapeHtml(a.location || '')} • ${escapeHtml(a.job_type || '')}</div>
        <div style="opacity:.8; margin-top:.25rem;">${escapeHtml(a.employer_company || a.company_name || '')}</div>
      </div>
    `).join('');
  }

  async function loadJobs(filters) {
    const res = await api.jobs.list(filters || {});
    document.getElementById('jobList').innerHTML = renderJobs(res.jobs || []);
  }

  async function loadMyApplications() {
    const res = await api.applications.getMyApplications();
    document.getElementById('myApplications').innerHTML = renderApplications(res.applications || []);
  }

  document.getElementById('filterForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const filters = {};
    const q = form.q.value.trim();
    const location = form.location.value.trim();
    if (q) filters.q = q;
    if (location) filters.location = location;

    ui.showLoading();
    try {
      await loadJobs(filters);
    } catch (err) {
      ui.showAlert(err.message, 'error');
    }
    ui.hideLoading();
  });

  document.getElementById('jobList').addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-action="apply"]');
    if (!btn) return;

    const jobId = parseInt(btn.getAttribute('data-id'), 10);
    const cover = prompt('Cover letter (optional):') || '';
    const resume = prompt('Resume text (optional):') || '';

    ui.showLoading();
    try {
      await api.applications.apply({ job_id: jobId, cover_letter: cover, resume_text: resume });
      ui.showAlert('Applied successfully', 'success');
      await loadMyApplications();
    } catch (err) {
      ui.showAlert(err.message, 'error');
    }
    ui.hideLoading();
  });

  document.getElementById('refreshAppsBtn').addEventListener('click', async () => {
    ui.showLoading();
    try {
      await loadMyApplications();
    } catch (err) {
      ui.showAlert(err.message, 'error');
    }
    ui.hideLoading();
  });

  document.getElementById('logoutBtn').addEventListener('click', async (e) => {
    e.preventDefault();
    try { await api.auth.logout(); } catch (_) {}
    window.location.href = 'index.html';
  });

  (async () => {
    ui.showLoading();
    try {
      const me = await requireJobSeeker();
      if (!me) return;
      document.getElementById('meLine').textContent = `${(me.full_name || me.name || me.username || '').trim()} (${me.email})`;
      await loadJobs({});
      await loadMyApplications();
    } catch (e) {
      ui.showAlert(e.message, 'error');
      window.location.href = 'index.html';
    }
    ui.hideLoading();
  })();
})();