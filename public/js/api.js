/**
 * Tierphysio Manager 2.0
 * API Client for CRUD Operations
 */

class TierphysioAPI {
    constructor() {
        this.baseUrl = '/public';
        this.headers = {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        };
    }

    /**
     * Send a request to the API
     */
    async request(endpoint, action, method = 'GET', data = null) {
        const url = `${this.baseUrl}/${endpoint}.php?action=${action}`;
        
        const options = {
            method: method,
            headers: this.headers
        };

        if (data && method !== 'GET') {
            if (data instanceof FormData) {
                delete options.headers['Content-Type'];
                options.body = data;
            } else {
                options.body = new URLSearchParams(data).toString();
            }
        }

        try {
            const response = await fetch(url, options);
            const result = await response.json();
            
            if (result.status === 'error') {
                throw new Error(result.message || 'Ein Fehler ist aufgetreten');
            }
            
            return result;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    }

    /**
     * Get all records
     */
    async getAll(endpoint, filters = {}) {
        const queryString = new URLSearchParams(filters).toString();
        const url = `${this.baseUrl}/${endpoint}.php?action=get_all${queryString ? '&' + queryString : ''}`;
        
        const response = await fetch(url);
        return await response.json();
    }

    /**
     * Get single record by ID
     */
    async getById(endpoint, id) {
        return this.request(endpoint, 'get_by_id&id=' + id, 'GET');
    }

    /**
     * Create new record
     */
    async create(endpoint, data) {
        return this.request(endpoint, 'create', 'POST', data);
    }

    /**
     * Update existing record
     */
    async update(endpoint, id, data) {
        data.id = id;
        return this.request(endpoint, 'update', 'POST', data);
    }

    /**
     * Delete record
     */
    async delete(endpoint, id) {
        return this.request(endpoint, 'delete', 'POST', { id: id });
    }

    /**
     * Show notification with animation
     */
    showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg transition-all transform translate-x-full z-50`;
        
        if (type === 'success') {
            notification.className += ' bg-green-500 text-white';
        } else if (type === 'error') {
            notification.className += ' bg-red-500 text-white';
        } else {
            notification.className += ' bg-blue-500 text-white';
        }
        
        notification.innerHTML = `
            <div class="flex items-center">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    ${type === 'success' 
                        ? '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>'
                        : '<path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>'
                    }
                </svg>
                <span>${message}</span>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Animate in
        anime({
            targets: notification,
            translateX: 0,
            duration: 500,
            easing: 'easeOutExpo'
        });
        
        // Remove after 3 seconds
        setTimeout(() => {
            anime({
                targets: notification,
                translateX: '100%',
                opacity: 0,
                duration: 500,
                easing: 'easeInExpo',
                complete: () => notification.remove()
            });
        }, 3000);
    }

    /**
     * Confirm dialog with animation
     */
    async confirm(message) {
        return new Promise((resolve) => {
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-black/50 flex items-center justify-center z-50';
            modal.style.opacity = '0';
            
            modal.innerHTML = `
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 max-w-md mx-4 shadow-2xl transform scale-95">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Bestätigung</h3>
                    <p class="text-gray-600 dark:text-gray-400 mb-6">${message}</p>
                    <div class="flex gap-3 justify-end">
                        <button class="cancel-btn px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                            Abbrechen
                        </button>
                        <button class="confirm-btn px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition">
                            Löschen
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Animate in
            anime({
                targets: modal,
                opacity: 1,
                duration: 300,
                easing: 'easeOutExpo'
            });
            
            anime({
                targets: modal.querySelector('div'),
                scale: 1,
                duration: 300,
                easing: 'easeOutExpo'
            });
            
            // Handle buttons
            modal.querySelector('.cancel-btn').addEventListener('click', () => {
                anime({
                    targets: modal,
                    opacity: 0,
                    duration: 300,
                    easing: 'easeInExpo',
                    complete: () => {
                        modal.remove();
                        resolve(false);
                    }
                });
            });
            
            modal.querySelector('.confirm-btn').addEventListener('click', () => {
                anime({
                    targets: modal,
                    opacity: 0,
                    duration: 300,
                    easing: 'easeInExpo',
                    complete: () => {
                        modal.remove();
                        resolve(true);
                    }
                });
            });
        });
    }
}

// Initialize global API instance
const api = new TierphysioAPI();

/**
 * Patient Management Functions
 */
class PatientManager {
    constructor() {
        this.endpoint = 'api_patients';
        this.loadPatients();
    }

    async loadPatients(filters = {}) {
        try {
            const result = await api.getAll(this.endpoint, filters);
            if (result.ok) {
                this.renderPatients(result.items || []);
            } else if (result.status === 'success') {
                this.renderPatients(result.data || []);
            }
        } catch (error) {
            api.showNotification('Fehler beim Laden der Patienten', 'error');
        }
    }

    renderPatients(patients) {
        const container = document.getElementById('patients-grid');
        if (!container) return;

        container.innerHTML = patients.map(patient => `
            <div class="patient-card bg-white/10 dark:bg-gray-800/50 backdrop-blur-md rounded-2xl border border-purple-500/20 overflow-hidden hover:shadow-2xl hover:border-purple-400/40 transition-all duration-300 group" data-id="${patient.id}">
                <div class="aspect-video bg-gradient-to-br from-purple-600/20 to-indigo-600/20 relative">
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="w-24 h-24 bg-white/20 rounded-full flex items-center justify-center">
                            ${this.getAnimalIcon(patient.species)}
                        </div>
                    </div>
                    <span class="absolute top-3 right-3 px-2 py-1 ${patient.is_active ? 'bg-green-500/80' : 'bg-red-500/80'} text-white text-xs rounded-full">
                        ${patient.is_active ? 'Aktiv' : 'Inaktiv'}
                    </span>
                </div>
                <div class="p-5">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">${patient.patient_name || patient.name || 'Unbekannt'}</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">${this.getSpeciesName(patient.species)} ${patient.breed ? '• ' + patient.breed : ''}</p>
                    <p class="text-sm text-purple-600 dark:text-purple-400 mt-2">Besitzer: ${patient.owner_name || '—'}</p>
                    <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-200/20">
                        <div class="flex gap-2">
                            <button onclick="patientManager.editPatient(${patient.id})" class="text-purple-500 hover:text-purple-400 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                            </button>
                            <button onclick="patientManager.deletePatient(${patient.id})" class="text-red-500 hover:text-red-400 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </button>
                        </div>
                        <button onclick="patientManager.viewPatient(${patient.id})" class="text-purple-500 hover:text-purple-400 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        `).join('');

        // Animate cards in
        anime({
            targets: '.patient-card',
            opacity: [0, 1],
            translateY: [20, 0],
            delay: anime.stagger(100),
            duration: 500,
            easing: 'easeOutExpo'
        });
    }

    getAnimalIcon(species) {
        const icons = {
            'dog': '🐕',
            'cat': '🐈',
            'horse': '🐴',
            'rabbit': '🐰',
            'bird': '🦜',
            'reptile': '🦎',
            'other': '🐾'
        };
        return `<span class="text-4xl">${icons[species] || icons.other}</span>`;
    }

    getSpeciesName(species) {
        const names = {
            'dog': 'Hund',
            'cat': 'Katze',
            'horse': 'Pferd',
            'rabbit': 'Kaninchen',
            'bird': 'Vogel',
            'reptile': 'Reptil',
            'other': 'Anderes'
        };
        return names[species] || 'Unbekannt';
    }

    async viewPatient(id) {
        // New UI: open patient in modal (Alpine) instead of navigating away.
        // Fallback: if no modal component is present, navigate to the legacy view URL.
        const numericId = Number(id);

        // Fire an event that the Alpine modal listens to.
        try {
            window.dispatchEvent(new CustomEvent('open-patient-modal', { detail: { id: numericId } }));
        } catch (e) {
            // ignore
        }

        // If the modal exists, keep the user on the list URL (remove ?action=view&id=...).
        const hasModal = !!document.querySelector('[x-data^="patientModal"], [x-data*="patientModal("]');
        if (hasModal) {
            try {
                const cleanUrl = window.location.pathname; // keep current path, drop query
                window.history.replaceState({}, '', cleanUrl);
            } catch (e) {
                // ignore
            }
            return;
        }

        // Legacy fallback (old view page)
        window.location.href = `/public/patients.php?action=view&id=${numericId}`;
    }

    async editPatient(id) {
        window.location.href = `/public/patients.php?action=edit&id=${id}`;
    }

    async deletePatient(id) {
        if (await api.confirm('Möchten Sie diesen Patienten wirklich löschen?')) {
            try {
                const result = await api.delete(this.endpoint, id);
                if (result.status === 'success') {
                    api.showNotification('Patient erfolgreich gelöscht', 'success');
                    this.loadPatients();
                }
            } catch (error) {
                api.showNotification(error.message || 'Fehler beim Löschen', 'error');
            }
        }
    }

    async savePatient(formData) {
        try {
            const id = formData.get('id');
            const result = id 
                ? await api.update(this.endpoint, id, formData)
                : await api.create(this.endpoint, formData);
                
            if (result.status === 'success') {
                api.showNotification(id ? 'Patient aktualisiert' : 'Patient erstellt', 'success');
                return result.data;
            }
        } catch (error) {
            api.showNotification(error.message || 'Fehler beim Speichern', 'error');
            throw error;
        }
    }
}

// Initialize managers when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Initialize based on current page
    const path = window.location.pathname;
    
    if (path.includes('patients')) {
        window.patientManager = new PatientManager();
    }
    
    // Setup search functionality
    const searchInput = document.querySelector('input[type="text"][placeholder*="Name"]');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (window.patientManager) {
                    window.patientManager.loadPatients({ search: e.target.value });
                }
            }, 500);
        });
    }
    
    // Setup species filter
    const speciesSelect = document.querySelector('select');
    if (speciesSelect) {
        speciesSelect.addEventListener('change', (e) => {
            if (window.patientManager) {
                window.patientManager.loadPatients({ species: e.target.value });
            }
        });
    }
});