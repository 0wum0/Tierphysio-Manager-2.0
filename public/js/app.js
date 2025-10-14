/**
 * Tierphysio Manager 2.0
 * Global App JavaScript
 * Enhanced Modal & Event Management
 */

// Global App Initialization
document.addEventListener('DOMContentLoaded', () => {
    console.log('Tierphysio Manager 2.0 - App initialized');
    
    // Initialize global modal event listeners
    initializeModalEvents();
    
    // Initialize global search
    initializeGlobalSearch();
    
    // Initialize tooltips if available
    initializeTooltips();
});

/**
 * Initialize Global Modal Events
 * Handles patient modal opening from various sources
 */
function initializeModalEvents() {
    // Global click handler for patient cards and buttons
    document.addEventListener('click', (e) => {
        // Check for patient card or button with data-patient-id
        const patientElement = e.target.closest('[data-patient-id]');
        if (patientElement) {
            const patientId = patientElement.getAttribute('data-patient-id');
            if (patientId) {
                openPatientModal(patientId);
                e.preventDefault();
                e.stopPropagation();
            }
        }
        
        // Check for owner element
        const ownerElement = e.target.closest('[data-owner-id]');
        if (ownerElement) {
            const ownerId = ownerElement.getAttribute('data-owner-id');
            if (ownerId) {
                openOwnerModal(ownerId);
                e.preventDefault();
                e.stopPropagation();
            }
        }
    });
    
    // Listen for custom patient modal events
    window.addEventListener('open-patient-modal', (e) => {
        if (e.detail && e.detail.id) {
            openPatientModal(e.detail.id);
        }
    });
    
    // Listen for custom owner modal events
    window.addEventListener('open-owner-modal', (e) => {
        if (e.detail && e.detail.id) {
            openOwnerModal(e.detail.id);
        }
    });
}

/**
 * Open Patient Modal
 * Global function to open patient modal from anywhere
 */
async function openPatientModal(patientId) {
    if (!patientId) return;
    
    try {
        // First, try to get patient data
        const response = await fetch(`/api/patients.php?action=get&id=${patientId}`);
        const data = await response.json();
        
        if (data.status === 'success' && data.patient) {
            // Check if we're on the patients page with Alpine modal
            if (window.Alpine && Alpine.store('patients')) {
                Alpine.store('patients').openModal(data.patient);
            } else if (typeof window.patientModal === 'function') {
                // Use the patientModal function if available
                const modal = window.patientModal();
                await modal.openPatient(patientId);
            } else {
                // Dispatch event for any listening components
                window.dispatchEvent(new CustomEvent('patient-modal-open', {
                    detail: { patient: data.patient, id: patientId }
                }));
            }
        } else {
            showToast('Patientendaten konnten nicht geladen werden.', 'error');
        }
    } catch (error) {
        console.error('Error loading patient:', error);
        showToast('Fehler beim Laden der Patientendaten.', 'error');
    }
}

/**
 * Open Owner Modal
 * Global function to open owner modal or find their patients
 */
async function openOwnerModal(ownerId) {
    if (!ownerId) return;
    
    try {
        // Try to find patients for this owner
        const response = await fetch('/api/patients.php?action=list');
        const data = await response.json();
        
        if (data.status === 'success' || data.ok === true) {
            const items = data.data?.items ?? data.data?.data ?? data.data ?? [];
            const ownerPatients = items.filter(p => p.owner_id === ownerId);
            
            if (ownerPatients.length > 0) {
                // Open the first patient of this owner
                openPatientModal(ownerPatients[0].id);
            } else {
                showToast('Keine Patienten für diesen Besitzer gefunden', 'info');
            }
        }
    } catch (error) {
        console.error('Error finding owner patients:', error);
        showToast('Fehler beim Suchen der Patienten', 'error');
    }
}

/**
 * Initialize Global Search Functionality
 */
function initializeGlobalSearch() {
    const searchInput = document.getElementById('global-search');
    if (!searchInput) return;
    
    let searchTimeout;
    
    searchInput.addEventListener('input', async (e) => {
        clearTimeout(searchTimeout);
        const query = e.target.value.trim();
        
        if (query.length < 2) {
            hideSearchResults();
            return;
        }
        
        searchTimeout = setTimeout(async () => {
            try {
                const response = await fetch(`/api/search.php?q=${encodeURIComponent(query)}`);
                const data = await response.json();
                
                if (data.status === 'success' && data.results) {
                    displaySearchResults(data.results);
                }
            } catch (error) {
                console.error('Search error:', error);
            }
        }, 300);
    });
    
    // Close search results when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('#global-search') && !e.target.closest('#search-results')) {
            hideSearchResults();
        }
    });
}

/**
 * Display Search Results
 */
function displaySearchResults(results) {
    let resultsContainer = document.getElementById('search-results');
    
    if (!resultsContainer) {
        const searchInput = document.getElementById('global-search');
        if (!searchInput) return;
        
        resultsContainer = document.createElement('div');
        resultsContainer.id = 'search-results';
        resultsContainer.className = 'absolute z-50 mt-1 w-full min-w-[20rem] bg-slate-800 border border-slate-700 rounded-xl shadow-2xl max-h-72 overflow-y-auto hidden';
        searchInput.parentElement.appendChild(resultsContainer);
    }
    
    if (results.length === 0) {
        resultsContainer.classList.add('hidden');
        return;
    }
    
    resultsContainer.innerHTML = results.map(item => `
        <div class="px-4 py-3 hover:bg-indigo-600/70 text-gray-200 cursor-pointer flex justify-between items-center transition-colors duration-150 border-b border-slate-700/50 last:border-0"
             onclick="handleSearchResultClick(${JSON.stringify(item).replace(/"/g, '&quot;')})">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-full bg-indigo-500/20 flex items-center justify-center">
                    <span class="text-lg">${item.icon || '🐾'}</span>
                </div>
                <div>
                    <span class="font-medium block">${item.name}</span>
                    <span class="text-xs text-gray-400 block">${item.subtitle || ''}</span>
                </div>
            </div>
            <span class="text-xs px-2 py-1 rounded-full font-medium ${
                item.type === 'patient' ? 'bg-green-500/20 text-green-400' : 'bg-blue-500/20 text-blue-400'
            }">
                ${item.type === 'patient' ? 'Patient' : 'Besitzer'}
            </span>
        </div>
    `).join('');
    
    resultsContainer.classList.remove('hidden');
}

/**
 * Hide Search Results
 */
function hideSearchResults() {
    const resultsContainer = document.getElementById('search-results');
    if (resultsContainer) {
        resultsContainer.classList.add('hidden');
    }
}

/**
 * Handle Search Result Click
 */
function handleSearchResultClick(item) {
    hideSearchResults();
    
    if (item.type === 'patient') {
        openPatientModal(item.id);
    } else if (item.type === 'owner') {
        openOwnerModal(item.id);
    }
    
    // Clear search input
    const searchInput = document.getElementById('global-search');
    if (searchInput) {
        searchInput.value = '';
    }
}

/**
 * Initialize Tooltips
 */
function initializeTooltips() {
    // Initialize tooltips if Tippy.js is available
    if (typeof tippy !== 'undefined') {
        tippy('[data-tooltip]', {
            content: (reference) => reference.getAttribute('data-tooltip'),
            theme: 'dark'
        });
    }
}

/**
 * Show Toast Notification
 * Global notification function
 */
function showToast(message, type = 'info', duration = 5000) {
    const toastContainer = document.getElementById('toastContainer') || createToastContainer();
    
    const toast = document.createElement('div');
    const colors = {
        success: 'bg-green-500',
        error: 'bg-red-500',
        warning: 'bg-yellow-500',
        info: 'bg-blue-500'
    };
    
    const icons = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ'
    };
    
    toast.className = `flex items-center p-4 mb-4 ${colors[type]} text-white rounded-lg shadow-lg transform transition-all duration-300 translate-x-full`;
    toast.innerHTML = `
        <span class="text-xl mr-3">${icons[type]}</span>
        <span class="font-medium">${message}</span>
        <button onclick="this.parentElement.remove()" class="ml-auto -mx-1.5 -my-1.5 rounded-lg p-1.5 hover:bg-white/20 inline-flex h-8 w-8">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
            </svg>
        </button>
    `;
    
    toastContainer.appendChild(toast);
    
    // Animate in
    setTimeout(() => {
        toast.classList.remove('translate-x-full');
        toast.classList.add('translate-x-0');
    }, 100);
    
    // Auto remove
    setTimeout(() => {
        toast.classList.add('translate-x-full');
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

/**
 * Create Toast Container
 */
function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'fixed bottom-4 right-4 z-50 space-y-2';
    document.body.appendChild(container);
    return container;
}

// Export functions for global use
window.openPatientModal = openPatientModal;
window.openOwnerModal = openOwnerModal;
window.showToast = showToast;
window.handleSearchResultClick = handleSearchResultClick;