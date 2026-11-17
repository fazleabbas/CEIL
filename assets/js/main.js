/**
 * Main JavaScript File
 * Common functions and utilities
 */

$(document).ready(function() {
    // Auto-dismiss session alerts after 5 seconds (only those with alert-auto-dismiss class or alert-dismissible without alert-permanent)
    setTimeout(function() {
        $('.alert.alert-dismissible:not(.alert-permanent)').fadeOut('slow');
    }, 5000);
    
    // Confirm before delete
    $('.confirm-delete').on('click', function(e) {
        if (!confirm('Are you sure you want to delete this item? This action cannot be undone.')) {
            e.preventDefault();
        }
    });
    
    // Number formatting
    $('.format-number').each(function() {
        let num = parseFloat($(this).text());
        if (!isNaN(num)) {
            $(this).text(formatNumber(num));
        }
    });
    
    // Currency formatting
    $('.format-currency').each(function() {
        let num = parseFloat($(this).text());
        if (!isNaN(num)) {
            $(this).text(formatCurrency(num));
        }
    });
    
    // Auto-focus first input in forms
    $('form:not(.no-autofocus) input:not([type="hidden"]):first').focus();
    
    // Prevent double form submission
    $('form').on('submit', function() {
        $(this).find('button[type="submit"]').prop('disabled', true);
        return true;
    });
});

/**
 * Format number with commas
 */
function formatNumber(num, decimals = 2) {
    // Convert to number, handle null/undefined/string
    const n = parseFloat(num);
    if (isNaN(n)) return '0.00';
    return n.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

/**
 * Format currency with optional symbol override
 */
function formatCurrency(amount, decimals, symbol) {
    // Use defaults from global config if not provided
    if (decimals === undefined || decimals === null) {
        decimals = window.DEFAULT_CURRENCY_DECIMALS !== undefined ? window.DEFAULT_CURRENCY_DECIMALS : 2;
    }
    symbol = symbol || window.DEFAULT_CURRENCY_SYMBOL || 'TZS';

    const n = parseFloat(amount);
    if (isNaN(n)) return symbol + '. 0' + (decimals > 0 ? '.' + '0'.repeat(decimals) : '');
    return symbol + '. ' + formatNumber(n, decimals);
}

/**
 * Show loading spinner
 */
function showLoading() {
    const spinner = `
        <div class="spinner-overlay" id="loadingSpinner">
            <div class="spinner-border spinner-border-lg text-light" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `;
    $('body').append(spinner);
}

/**
 * Hide loading spinner
 */
function hideLoading() {
    $('#loadingSpinner').remove();
}

/**
 * Show alert message
 */
function showAlert(message, type = 'success') {
    // Remove any existing page alerts
    $('.page-alert').remove();
    
    const alert = $(`
        <div class="alert alert-${type} alert-dismissible fade show page-alert" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `);
    
    // Style it to be fixed position
    alert.css({
        'position': 'fixed',
        'top': '80px',
        'left': '50%',
        'transform': 'translateX(-50%)',
        'z-index': '9999',
        'min-width': '400px',
        'max-width': '600px',
        'box-shadow': '0 4px 6px rgba(0,0,0,0.2)'
    });
    
    $('body').append(alert);
    
    // Auto-dismiss after 5 seconds
    setTimeout(function() {
        alert.fadeOut('slow', function() {
            $(this).remove();
        });
    }, 5000);
}

/**
 * AJAX error handler
 */
function handleAjaxError(xhr, status, error) {
    hideLoading();
    console.error('AJAX Error:', status, error);
    showAlert('An error occurred. Please try again.', 'danger');
}

/**
 * Validate form
 */
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form.checkValidity()) {
        form.classList.add('was-validated');
        return false;
    }
    return true;
}

/**
 * Reset form
 */
function resetForm(formId) {
    const form = document.getElementById(formId);
    form.reset();
    form.classList.remove('was-validated');
}

/**
 * Confirm action
 */
function confirmAction(message = 'Are you sure?') {
    return confirm(message);
}

/**
 * Calculate total from quantity and rate
 */
function calculateTotal(quantity, rate) {
    const qty = parseFloat(quantity) || 0;
    const rt = parseFloat(rate) || 0;
    return qty * rt;
}

/**
 * Debounce function for search
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Export table to CSV
 */
function exportTableToCSV(tableId, filename = 'export.csv') {
    const table = document.getElementById(tableId);
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [];
        const cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            // Skip action columns
            if (!cols[j].classList.contains('no-export')) {
                row.push(cols[j].innerText);
            }
        }
        
        csv.push(row.join(','));
    }
    
    downloadCSV(csv.join('\n'), filename);
}

/**
 * Download CSV file
 */
function downloadCSV(csv, filename) {
    const csvFile = new Blob([csv], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

/**
 * Print element
 */
function printElement(elementId) {
    const element = document.getElementById(elementId);
    const originalContent = document.body.innerHTML;
    const printContent = element.innerHTML;
    
    document.body.innerHTML = printContent;
    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}

/**
 * Format date input to display format
 */
function formatDateInput(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return `${day}-${month}-${year}`;
}

/**
 * Format date from display to database format
 */
function formatDateForDB(dateString) {
    if (!dateString) return '';
    const parts = dateString.split('-');
    if (parts.length === 3) {
        return `${parts[2]}-${parts[1]}-${parts[0]}`; // YYYY-MM-DD
    }
    return dateString;
}

/**
 * Copy to clipboard
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        showAlert('Copied to clipboard!', 'success');
    }, function() {
        showAlert('Failed to copy to clipboard', 'danger');
    });
}

/**
 * Check if mobile device
 */
function isMobile() {
    return window.innerWidth <= 768;
}

/**
 * Smooth scroll to element
 */
function scrollToElement(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

/**
 * Get query parameter
 */
function getQueryParam(param) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(param);
}

/**
 * Set query parameter
 */
function setQueryParam(param, value) {
    const url = new URL(window.location);
    url.searchParams.set(param, value);
    window.history.pushState({}, '', url);
}

/**
 * Generate random string
 */
function generateRandomString(length = 8) {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    for (let i = 0; i < length; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return result;
}

/**
 * Local storage helpers
 */
const storage = {
    set: function(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (e) {
            console.error('Error saving to localStorage:', e);
            return false;
        }
    },
    
    get: function(key) {
        try {
            const item = localStorage.getItem(key);
            return item ? JSON.parse(item) : null;
        } catch (e) {
            console.error('Error reading from localStorage:', e);
            return null;
        }
    },
    
    remove: function(key) {
        try {
            localStorage.removeItem(key);
            return true;
        } catch (e) {
            console.error('Error removing from localStorage:', e);
            return false;
        }
    },
    
    clear: function() {
        try {
            localStorage.clear();
            return true;
        } catch (e) {
            console.error('Error clearing localStorage:', e);
            return false;
        }
    }
};

/**
 * Date picker initialization (if using a date picker library)
 */
function initDatePickers() {
    // Add date picker initialization here if needed
    // For now, using HTML5 date input with custom format
}

/**
 * Initialize tooltips
 */
function initTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

/**
 * Initialize popovers
 */
function initPopovers() {
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
}

// Initialize tooltips and popovers on page load
$(document).ready(function() {
    initTooltips();
    initPopovers();
});
