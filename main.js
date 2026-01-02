/*!
 * File: assets/js/main.js
 * Purpose: Subfolder-safe API helper + auth helpers (PHP 7.3 compatible backend)
 */
// -----------------------------------------------------------------------------
// FORCE HTTP (per requirement)
// If a user lands on https:// (often with invalid SSL), the browser will:
//  - block http:// API calls as mixed content, and
//  - set Secure cookies that won't be sent on http://
// So we hard-redirect to http:// before any API call happens.
// -----------------------------------------------------------------------------
if (window.location.protocol === 'https:') {
  const httpUrl = 'http://' + window.location.host + window.location.pathname + window.location.search + window.location.hash;
  window.location.replace(httpUrl);
}

const API_BASE_URL = new URL("api/", window.location.href);

async function apiCall(endpoint, options = {}) {
  const url = new URL(endpoint, API_BASE_URL).toString();

  const opts = Object.assign(
    {
      method: "GET",
      credentials: "include", // required for PHP sessions
      headers: {},
    },
    options || {}
  );

  // Normalize body handling
  if (opts.body instanceof URLSearchParams) {
    opts.headers = Object.assign(
      { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      opts.headers || {}
    );
  } else if (opts.body && typeof opts.body === "object" && !(opts.body instanceof FormData)) {
    opts.headers = Object.assign({ "Content-Type": "application/json" }, opts.headers || {});
    opts.body = JSON.stringify(opts.body);
  }

  const res = await fetch(url, opts);

  // action=me returns 401 when not logged in — treat as "no user" not an error.
  if (res.status === 401 && endpoint.indexOf("user_auth.php?action=me") !== -1) {
    return null;
  }

  let data = null;
  const ct = res.headers.get("content-type") || "";
  if (ct.indexOf("application/json") !== -1) {
    data = await res.json().catch(() => null);
  } else {
    data = await res.text().catch(() => null);
  }

  if (!res.ok) {
    const msg = data && data.error ? data.error : (typeof data === "string" ? data : "Request failed");
    const err = new Error(msg);
    err.status = res.status;
    err.data = data;
    throw err;
  }

  return data;
}

const Auth = {
  async register(payload) {
    // Use form-urlencoded (often WAF-friendlier on shared hosting)
    const body = new URLSearchParams(payload);
    return apiCall("user_auth.php?action=register", { method: "POST", body });
  },

  async login(payload) {
    const body = new URLSearchParams(payload);
    return apiCall("user_auth.php?action=login", { method: "POST", body });
  },

  async logout() {
    return apiCall("user_auth.php?action=logout", { method: "POST" });
  },

  async me() {
    return apiCall("user_auth.php?action=me", { method: "GET" });
  },
};

async function getCurrentUser() {
  try {
    const res = await Auth.me();

    // Auth.me() returns null when 401 (not logged in). Normalize for callers.
    if (!res || !res.success || !res.user) {
      return { success: false, user: null };
    }

    // Normalize naming fields across older templates.
    if (!res.user.full_name && res.user.name) res.user.full_name = res.user.name;
    if (!res.user.name && res.user.full_name) res.user.name = res.user.full_name;
    return res;
  } catch (_e) {
    return { success: false, user: null };
  }
}

// Backwards-compatible alias used by dashboards and index.html
Auth.getCurrentUser = getCurrentUser;

window.API = { apiCall, Auth, getCurrentUser };


// Jobs API
const Jobs = {
  // Public jobs list for jobseekers (supports filters used by jobseeker-dashboard.js)
  async list(filters = {}) {
    const params = new URLSearchParams();
    if (filters.q) params.set('search', filters.q);
    if (filters.search) params.set('search', filters.search);
    if (filters.location) params.set('location', filters.location);
    if (filters.job_type) params.set('job_type', filters.job_type);
    if (filters.experience_min != null && filters.experience_min !== '') params.set('experience_min', String(filters.experience_min));
    const qs = params.toString();
    return apiCall(`jobs.php?action=list${qs ? '&' + qs : ''}`, { method: 'GET' });
  },
  async create(payload) {
    return apiCall("jobs.php?action=create", { method: "POST", body: payload });
  },
  async getMyJobs() {
    return apiCall("jobs.php?action=my-jobs", { method: "GET" });
  },
  async delete(jobId) {
    return apiCall(`jobs.php?action=delete&job_id=${jobId}`, { method: "POST" });
  },
};

// Applications API
const Applications = {
  // Jobseeker: apply to a job
  async apply(payload) {
    return apiCall('applications.php?action=apply', { method: 'POST', body: payload });
  },

  // Jobseeker: get own applications
  async getMyApplications() {
    return apiCall('applications.php?action=my-applications', { method: 'GET' });
  },

  // Employer: list applications for a job
  async getJobApplications(jobId) {
    return apiCall(`applications.php?action=job-applications&job_id=${jobId}`, { method: 'GET' });
  },

  // Backwards-compatible alias (older templates)
  async listByJob(jobId) {
    return apiCall(`applications.php?action=job-applications&job_id=${jobId}`, { method: 'GET' });
  },

  // Employer: update status
  async updateStatus(payload) {
    return apiCall('applications.php?action=update-status', { method: 'POST', body: payload });
  }
};

// Tokens API
const Tokens = {
  async balance() {
    return apiCall("tokens.php?action=balance", { method: "GET" });
  },
};

// Search API
const Search = {
  async candidates(query) {
    return apiCall("search_with_tokens.php?action=search", {
      method: "POST",
      body: { query },
    });
  },
};

// UI Helpers
const UI = {
  showAlert(message, type = "info", duration = 3000) {
    const alertEl = document.createElement("div");
    alertEl.className = `alert alert-${type}`;
    alertEl.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 1rem 1.5rem;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      z-index: 10000;
      max-width: 400px;
      font-weight: 500;
      animation: slideInRight 0.3s ease-out;
    `;

    const colors = {
      success: { bg: "#10b981", color: "#fff" },
      error: { bg: "#ef4444", color: "#fff" },
      warning: { bg: "#f59e0b", color: "#fff" },
      info: { bg: "#0369a1", color: "#fff" },
    };

    const color = colors[type] || colors.info;
    alertEl.style.background = color.bg;
    alertEl.style.color = color.color;
    alertEl.textContent = message;

    document.body.appendChild(alertEl);

    setTimeout(() => {
      alertEl.style.animation = "slideOutRight 0.3s ease-out";
      setTimeout(() => alertEl.remove(), 300);
    }, duration);
  },

  showLoading(message = "Loading...") {
    let loader = document.getElementById("global-loader");
    if (!loader) {
      loader = document.createElement("div");
      loader.id = "global-loader";
      loader.style.cssText = `
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        flex-direction: column;
        gap: 1rem;
      `;
      loader.innerHTML = `
        <div style="width: 48px; height: 48px; border: 4px solid #fff; border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
        <div style="color: white; font-weight: 600;">${message}</div>
      `;
      document.body.appendChild(loader);
    }
  },

  hideLoading() {
    const loader = document.getElementById("global-loader");
    if (loader) loader.remove();
  },
};

// Add CSS animations
if (!document.getElementById("ui-animations")) {
  const style = document.createElement("style");
  style.id = "ui-animations";
  style.textContent = `
    @keyframes slideInRight {
      from { transform: translateX(100%); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
      from { transform: translateX(0); opacity: 1; }
      to { transform: translateX(100%); opacity: 0; }
    }
    @keyframes spin {
      to { transform: rotate(360deg); }
    }
  `;
  document.head.appendChild(style);
}

// Export to window
window.api = {
  auth: Auth,
  jobs: Jobs,
  applications: Applications,
  tokens: Tokens,
  search: Search,
};

window.ui = UI;

