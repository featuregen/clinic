/**
 * Advanced Clinic Suite - Main Application JavaScript
 * Sidebar, Notifications, AJAX, Modals, Search
 */

document.addEventListener("DOMContentLoaded", function () {
  initSidebar();
  initSearch();
  initDropdowns();
  initToasts();
  initModals();
  initTabs();
  initAlerts();
});

// ============================================
// SIDEBAR
// ============================================

function initSidebar() {
  const toggle = document.getElementById("sidebarToggle");
  const sidebar = document.querySelector(".sidebar");
  const overlay = document.querySelector(".sidebar-overlay");

  if (toggle) {
    toggle.addEventListener("click", () => {
      sidebar.classList.toggle("open");
      if (overlay) overlay.classList.toggle("active");
    });
  }

  if (overlay) {
    overlay.addEventListener("click", () => {
      sidebar.classList.remove("open");
      overlay.classList.remove("active");
    });
  }

  // Active nav link
  const currentPath = window.location.pathname;
  document.querySelectorAll(".nav-link").forEach((link) => {
    if (
      link.getAttribute("href") &&
      currentPath.includes(link.getAttribute("href"))
    ) {
      link.classList.add("active");
    }
  });
}

// ============================================
// GLOBAL SEARCH
// ============================================

function initSearch() {
  const searchInput = document.getElementById("globalSearch");
  if (!searchInput) return;

  let debounceTimer;
  searchInput.addEventListener("input", function () {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      const query = this.value.trim();
      if (query.length >= 2) {
        performSearch(query);
      }
    }, 300);
  });
}

async function performSearch(query) {
  try {
    const response = await fetch(
      `${BASE_URL}/api/search.php?q=${encodeURIComponent(query)}`,
    );
    const data = await response.json();
    // Will be extended with search results dropdown
    console.log("Search results:", data);
  } catch (error) {
    console.error("Search error:", error);
  }
}

// ============================================
// DROPDOWNS
// ============================================

function initDropdowns() {
  document.querySelectorAll("[data-dropdown]").forEach((trigger) => {
    trigger.addEventListener("click", function (e) {
      e.stopPropagation();
      const menuId = this.getAttribute("data-dropdown");
      const menu = document.getElementById(menuId);

      // Close other dropdowns
      document.querySelectorAll(".dropdown-menu.show").forEach((m) => {
        if (m.id !== menuId) m.classList.remove("show");
      });

      if (menu) menu.classList.toggle("show");
    });
  });

  // Close on outside click
  document.addEventListener("click", () => {
    document
      .querySelectorAll(".dropdown-menu.show")
      .forEach((m) => m.classList.remove("show"));
  });
}

// ============================================
// TOAST NOTIFICATIONS
// ============================================

function initToasts() {
  // Create container if not exists
  if (!document.querySelector(".toast-container")) {
    const container = document.createElement("div");
    container.className = "toast-container";
    document.body.appendChild(container);
  }
}

function showToast(message, type = "info", title = null, duration = 5000) {
  const container = document.querySelector(".toast-container");
  const icons = {
    success: "fas fa-check-circle",
    error: "fas fa-times-circle",
    warning: "fas fa-exclamation-triangle",
    info: "fas fa-info-circle",
  };

  const titles = {
    success: "Success",
    error: "Error",
    warning: "Warning",
    info: "Information",
  };

  const toast = document.createElement("div");
  toast.className = `toast ${type}`;
  toast.innerHTML = `
        <i class="toast-icon ${icons[type] || icons.info}"></i>
        <div class="toast-content">
            <div class="toast-title">${title || titles[type] || "Notification"}</div>
            <div class="toast-message">${message}</div>
        </div>
        <button class="toast-close" onclick="this.closest('.toast').remove()">
            <i class="fas fa-times"></i>
        </button>
    `;

  container.appendChild(toast);

  // Auto remove
  setTimeout(() => {
    if (toast.parentElement) {
      toast.style.animation = "fadeOut 0.3s ease forwards";
      setTimeout(() => toast.remove(), 300);
    }
  }, duration);
}

// ============================================
// MODALS
// ============================================

function initModals() {
  // Close on overlay click
  document.querySelectorAll(".modal-overlay").forEach((overlay) => {
    overlay.addEventListener("click", function (e) {
      if (e.target === this) closeModal(this.id);
    });
  });

  // Close on ESC
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      document
        .querySelectorAll(".modal-overlay.active")
        .forEach((m) => closeModal(m.id));
    }
  });
}

function openModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.add("active");
    document.body.style.overflow = "hidden";
  }
}

function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.remove("active");
    document.body.style.overflow = "";
  }
}

// ============================================
// TABS
// ============================================

function initTabs() {
  document.querySelectorAll(".tab-btn").forEach((btn) => {
    btn.addEventListener("click", function () {
      const tabGroup = this.closest(".tabs");
      const target = this.getAttribute("data-tab");
      const container =
        this.closest(".card, .tab-container, section") || document;

      // Deactivate all tabs in group
      tabGroup
        .querySelectorAll(".tab-btn")
        .forEach((b) => b.classList.remove("active"));
      this.classList.add("active");

      // Show target content
      container
        .querySelectorAll(".tab-content")
        .forEach((c) => c.classList.remove("active"));
      const targetTab = container.querySelector(`#${target}`);
      if (targetTab) targetTab.classList.add("active");
    });
  });
}

// ============================================
// FLASH ALERTS
// ============================================

function initAlerts() {
  // Auto-dismiss flash alerts
  document.querySelectorAll(".alert[data-auto-dismiss]").forEach((alert) => {
    const delay = parseInt(alert.getAttribute("data-auto-dismiss")) || 5000;
    setTimeout(() => {
      alert.style.animation = "fadeOut 0.3s ease forwards";
      setTimeout(() => alert.remove(), 300);
    }, delay);
  });
}

// ============================================
// AJAX HELPER
// ============================================

async function apiCall(url, options = {}) {
  const defaults = {
    method: "GET",
    headers: {
      "Content-Type": "application/json",
      "X-Requested-With": "XMLHttpRequest",
    },
  };

  const config = { ...defaults, ...options };

  if (
    config.body &&
    typeof config.body === "object" &&
    !(config.body instanceof FormData)
  ) {
    config.body = JSON.stringify(config.body);
  }

  if (config.body instanceof FormData) {
    delete config.headers["Content-Type"];
  }

  // Add CSRF token
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
  if (csrfToken) {
    config.headers["X-CSRF-Token"] = csrfToken;
  }

  try {
    const response = await fetch(url, config);
    const data = await response.json();

    if (!response.ok) {
      throw new Error(data.error || "Request failed");
    }

    return data;
  } catch (error) {
    showToast(error.message || "An error occurred", "error");
    throw error;
  }
}

// ============================================
// FORM HELPERS
// ============================================

function serializeForm(form) {
  const data = {};
  const formData = new FormData(form);
  for (const [key, value] of formData.entries()) {
    if (data[key]) {
      if (!Array.isArray(data[key])) data[key] = [data[key]];
      data[key].push(value);
    } else {
      data[key] = value;
    }
  }
  return data;
}

function resetForm(formId) {
  const form = document.getElementById(formId);
  if (form) form.reset();
}

function validateForm(form) {
  let isValid = true;
  form.querySelectorAll("[required]").forEach((field) => {
    if (!field.value.trim()) {
      field.classList.add("is-invalid");
      isValid = false;
    } else {
      field.classList.remove("is-invalid");
    }
  });
  return isValid;
}

// ============================================
// CONFIRMATION DIALOG
// ============================================

function confirmAction(message, callback) {
  const overlay = document.createElement("div");
  overlay.className = "modal-overlay active";
  overlay.innerHTML = `
        <div class="modal" style="max-width: 420px;">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle text-warning"></i> Confirm Action</h3>
            </div>
            <div class="modal-body">
                <p>${message}</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="confirmCancel">Cancel</button>
                <button class="btn btn-danger" id="confirmOk">Confirm</button>
            </div>
        </div>
    `;

  document.body.appendChild(overlay);

  overlay
    .querySelector("#confirmCancel")
    .addEventListener("click", () => overlay.remove());
  overlay.querySelector("#confirmOk").addEventListener("click", () => {
    overlay.remove();
    if (typeof callback === "function") callback();
  });
}

// ============================================
// DELETE HANDLER
// ============================================

function deleteRecord(url, itemName, callback) {
  confirmAction(
    `Are you sure you want to delete this ${itemName}? This action cannot be undone.`,
    async () => {
      try {
        const result = await apiCall(url, { method: "DELETE" });
        showToast(`${itemName} deleted successfully`, "success");
        if (typeof callback === "function") callback(result);
      } catch (error) {
        console.error("Delete error:", error);
      }
    },
  );
}

// ============================================
// FORMAT HELPERS
// ============================================

function formatCurrency(amount) {
  return (
    "₹ " +
    parseFloat(amount || 0).toLocaleString("en-IN", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })
  );
}

function formatDate(dateStr) {
  if (!dateStr) return "-";
  const date = new Date(dateStr);
  return date.toLocaleDateString("en-IN", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
}

function formatTime(timeStr) {
  if (!timeStr) return "-";
  const [hours, minutes] = timeStr.split(":");
  const h = parseInt(hours);
  const ampm = h >= 12 ? "PM" : "AM";
  const hour12 = h % 12 || 12;
  return `${hour12}:${minutes} ${ampm}`;
}

// ============================================
// PRINT
// ============================================

function printContent(elementId) {
  const content = document.getElementById(elementId);
  if (!content) return;

  const printWindow = window.open("", "_blank");
  printWindow.document.write(`
        <html>
        <head>
            <title>Print</title>
            <link rel="stylesheet" href="${ASSETS_URL}/css/style.css">
            <style>
                body { padding: 20px; background: white; }
                @media print { .no-print { display: none !important; } }
            </style>
        </head>
        <body>${content.innerHTML}</body>
        </html>
    `);
  printWindow.document.close();
  printWindow.onload = () => {
    printWindow.print();
    printWindow.close();
  };
}

// ============================================
// DATA TABLE HELPERS
// ============================================

function initDataTableSearch(tableId, searchInputId) {
  const input = document.getElementById(searchInputId);
  const table = document.getElementById(tableId);
  if (!input || !table) return;

  input.addEventListener("input", function () {
    const query = this.value.toLowerCase();
    table.querySelectorAll("tbody tr").forEach((row) => {
      const text = row.textContent.toLowerCase();
      row.style.display = text.includes(query) ? "" : "none";
    });
  });
}
