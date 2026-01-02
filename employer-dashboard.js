(function () {
  const ui = window.ui;
  const api = window.api;

  let currentSearchMode = 'ai'; // 'ai' or 'normal'
  let tokenCosts = {
    ai: { search: 150, unmask_email: 25, unmask_mobile: 50, download_profile: 100 },
    normal: { search: 50, unmask_email: 15, unmask_mobile: 30, download_profile: 75 }
  };

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  async function requireEmployer() {
    const res = await api.auth.getCurrentUser();
    const role = (res && res.user && res.user.role) ? String(res.user.role).toLowerCase() : '';
    if (!res.success || !res.user || !(role === 'employer' || role === 'company' || role === 'recruiter' || role === 'hr')) {
      window.location.href = 'index.html';
      return null;
    }
    return res.user;
  }

  function updateSearchModeUI() {
    const toggle = document.getElementById('searchModeToggle');
    const aiLabel = document.getElementById('aiLabel');
    const normalLabel = document.getElementById('normalLabel');
    const costInfo = document.getElementById('searchCostInfo');
    
    if (toggle.checked) {
      currentSearchMode = 'ai';
      aiLabel.classList.add('active');
      normalLabel.classList.remove('active');
      costInfo.innerHTML = `<strong>${tokenCosts.ai.search}</strong> tokens per search`;
    } else {
      currentSearchMode = 'normal';
      normalLabel.classList.add('active');
      aiLabel.classList.remove('active');
      costInfo.innerHTML = `<strong>${tokenCosts.normal.search}</strong> tokens per search`;
    }
  }

  function renderJobs(jobs) {
    if (!jobs || jobs.length === 0) {
      return '<div class=\"empty-state\"><p>No jobs posted yet. Create your first job posting above!</p></div>';
    }

    return jobs.map(j => `
      <div class=\"job-item\">
        <div class=\"job-header\">
          <div>
            <div class=\"job-title\">${escapeHtml(j.title)}</div>
            <div class=\"job-meta\">\ud83d\udccd ${escapeHtml(j.location || '')} \u2022 ${escapeHtml(j.job_type || '')} \u2022 ${j.application_count || 0} applications</div>
          </div>
          <div class=\"job-actions\">
            <button class=\"btn btn-sm btn-primary\" data-action=\"apps\" data-id=\"${j.id}\">View Applications</button>
            <button class=\"btn btn-sm btn-secondary\" data-action=\"delete\" data-id=\"${j.id}\">Delete</button>
          </div>
        </div>
      </div>
    `).join('');
  }

  function renderApplications(apps) {
    if (!apps || apps.length === 0) {
      return '<div class=\"empty-state\"><p>No applications found for this job.</p></div>';
    }

    return apps.map(a => `
      <div class=\"candidate-card\">
        <div style=\"font-weight: 600; color: var(--primary-color); margin-bottom: 0.5rem;\">
          ${escapeHtml(a.applicant_name || a.full_name || 'Applicant')}
        </div>
        <div style=\"color: var(--text-gray); font-size: 0.875rem; margin-bottom: 0.5rem;\">
          \u2709\ufe0f ${escapeHtml(a.applicant_email || a.email || '')} \u2022 Status: ${escapeHtml(a.status || 'pending')}
        </div>
        ${a.cover_letter ? `
          <div style=\"margin-top: 1rem; padding: 1rem; background: white; border-radius: 6px;\">
            <strong style=\"font-size: 0.875rem;\">Cover Letter:</strong>
            <div style=\"margin-top: 0.5rem; white-space: pre-wrap; color: var(--text-gray);\">${escapeHtml(a.cover_letter)}</div>
          </div>
        ` : ''}
      </div>
    `).join('');
  }

  function renderSearchResults(results, searchMode) {
    if (!results || results.length === 0) {
      return '<div class=\"empty-state\"><p>No candidates found. Try adjusting your search query.</p></div>';
    }

    return results.map(candidate => {
      const profile = candidate.profile || '';
      const name = candidate.name || 'Candidate';
      const email = candidate.email || 'Not available';
      const mobile = candidate.mobile || 'Not available';
      const emailMasked = candidate.email_masked !== false;
      const mobileMasked = candidate.mobile_masked !== false;
      
      return `
        <div class=\"candidate-card\">
          <div style=\"display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;\">
            <div>
              <div style=\"font-weight: 600; color: var(--primary-color); font-size: 1.125rem;\">${escapeHtml(name)}</div>
              <div style=\"color: var(--text-gray); font-size: 0.875rem; margin-top: 0.25rem;\">
                <span style=\"color: var(--secondary-color); font-weight: 600;\">${searchMode.toUpperCase()}</span> Search Result
              </div>
            </div>
          </div>
          <div style=\"background: white; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;\">
            <div style=\"font-size: 0.875rem; margin-bottom: 0.5rem;\">
              <strong>\u2709\ufe0f Email:</strong> ${escapeHtml(email)} 
              ${emailMasked ? '<span style=\"color: var(--warning-color);\">(Masked)</span>' : '<span style=\"color: var(--success-color);\">(Unmasked)</span>'}
            </div>
            <div style=\"font-size: 0.875rem;\">
              <strong>\ud83d\udcf1 Mobile:</strong> ${escapeHtml(mobile)} 
              ${mobileMasked ? '<span style=\"color: var(--warning-color);\">(Masked)</span>' : '<span style=\"color: var(--success-color);\">(Unmasked)</span>'}
            </div>
          </div>
          <details style=\"cursor: pointer;\">
            <summary style=\"font-weight: 600; color: var(--primary-color); padding: 0.5rem 0;\">View Profile</summary>
            <div style=\"background: #f9fafb; padding: 1rem; border-radius: 6px; margin-top: 0.5rem; white-space: pre-wrap; font-size: 0.875rem; max-height: 300px; overflow-y: auto;\">
              ${escapeHtml(profile.substring(0, 2000))}${profile.length > 2000 ? '...' : ''}
            </div>
          </details>
        </div>
      `;
    }).join('');
  }

  async function loadTokenCosts() {
    try {
      const response = await fetch('api/search_unified.php?action=get-costs', {
        credentials: 'include'
      });
      const data = await response.json();
      if (data.success) {
        tokenCosts.ai = data.ai_costs;
        tokenCosts.normal = data.normal_costs;
        updateSearchModeUI();
      }
    } catch (e) {
      console.error('Failed to load token costs:', e);
    }
  }

  async function refreshTokens() {
    const res = await api.tokens.balance();
    document.getElementById('tokenBalance').textContent = String(res.token_balance ?? res.balance ?? '0');
  }

  async function refreshJobs() {
    const res = await api.jobs.getMyJobs();
    const jobs = res.jobs || [];
    document.getElementById('jobsList').innerHTML = renderJobs(jobs);
    document.getElementById('activeJobsCount').textContent = String(jobs.length);
    
    // Calculate total applications
    const totalApps = jobs.reduce((sum, job) => sum + (job.application_count || 0), 0);
    document.getElementById('totalApplicationsCount').textContent = String(totalApps);
  }

  async function loadApplications(jobId) {
    const res = await api.applications.getJobApplications(jobId);
    document.getElementById('applicationsList').innerHTML = renderApplications(res.applications || []);
  }

  // Event Listeners
  document.getElementById('searchModeToggle').addEventListener('change', updateSearchModeUI);

  document.getElementById('refreshTokensBtn').addEventListener('click', async () => {
    ui.showLoading();
    try { 
      await refreshTokens(); 
      ui.showAlert('Token balance refreshed!', 'success');
    } catch (e) { 
      ui.showAlert(e.message, 'error'); 
    }
    ui.hideLoading();
  });

  document.getElementById('refreshJobsBtn').addEventListener('click', async () => {
    ui.showLoading();
    try { 
      await refreshJobs(); 
      ui.showAlert('Jobs refreshed!', 'success');
    } catch (e) { 
      ui.showAlert(e.message, 'error'); 
    }
    ui.hideLoading();
  });

  document.getElementById('jobsList').addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const action = btn.getAttribute('data-action');
    const id = parseInt(btn.getAttribute('data-id'), 10);

    ui.showLoading();
    try {
      if (action === 'apps') {
        await loadApplications(id);
        ui.showAlert('Applications loaded', 'success');
      } else if (action === 'delete') {
        if (confirm('Are you sure you want to delete this job?')) {
          await api.jobs.delete(id);
          await refreshJobs();
          ui.showAlert('Job deleted successfully', 'success');
        }
      }
    } catch (err) {
      ui.showAlert(err.message, 'error');
    }
    ui.hideLoading();
  });

  document.getElementById('jobForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;

    const payload = {
      title: form.title.value.trim(),
      location: form.location.value.trim(),
      skills: form.skills.value.trim(),
      job_type: form.job_type.value.trim(),
      experience_min: form.experience_min.value ? parseInt(form.experience_min.value, 10) : null,
      experience_max: form.experience_max.value ? parseInt(form.experience_max.value, 10) : null,
      salary_min: form.salary_min.value ? parseInt(form.salary_min.value, 10) : null,
      salary_max: form.salary_max.value ? parseInt(form.salary_max.value, 10) : null,
      description: form.description.value.trim(),
      status: 'active'
    };

    ui.showLoading();
    try {
      await api.jobs.create(payload);
      form.reset();
      await refreshJobs();
      ui.showAlert('Job created successfully!', 'success');
    } catch (err) {
      ui.showAlert(err.message, 'error');
    }
    ui.hideLoading();
  });

  document.getElementById('searchForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const query = document.getElementById('searchQuery').value.trim();
    if (!query) {
      ui.showAlert('Please enter a search query', 'error');
      return;
    }

    ui.showLoading();
    try {
      // Use unified search endpoint
      const response = await fetch('api/search_unified.php?action=search', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ query, search_mode: currentSearchMode })
      });

      const data = await response.json();
      
      if (data.error) {
        ui.showAlert(data.error, 'error');
        document.getElementById('searchResults').innerHTML = 
          `<div class=\"empty-state\"><p style=\"color: var(--danger-color);\">${escapeHtml(data.error)}</p></div>`;
      } else {
        const results = data.results || [];
        document.getElementById('searchResults').innerHTML = renderSearchResults(results, currentSearchMode);
        ui.showAlert(`Found ${results.length} candidates (${data.tokens_deducted} tokens used)`, 'success');
        await refreshTokens();
      }
    } catch (err) {
      ui.showAlert('Search failed: ' + err.message, 'error');
      document.getElementById('searchResults').innerHTML = 
        '<div class=\"empty-state\"><p style=\"color: var(--danger-color);\">Search failed. Please try again.</p></div>';
    }
    ui.hideLoading();
  });

  document.getElementById('logoutBtn').addEventListener('click', async (e) => {
    e.preventDefault();
    try { await api.auth.logout(); } catch (_) {}
    window.location.href = 'index.html';
  });

  // Initialize
  (async () => {
    ui.showLoading();
    try {
      const me = await requireEmployer();
      if (!me) return;
      
      const userName = (me.full_name || me.name || me.username || 'User').trim();
      const companyName = me.company_name ? ` - ${me.company_name}` : '';
      document.getElementById('meLine').textContent = `${userName}${companyName} (${me.email})`;
      
      await loadTokenCosts();
      await refreshTokens();
      await refreshJobs();
      
      ui.showAlert('Welcome to your dashboard!', 'success');
    } catch (e) {
      ui.showAlert(e.message, 'error');
      setTimeout(() => window.location.href = 'index.html', 2000);
    }
    ui.hideLoading();
  })();
})();
