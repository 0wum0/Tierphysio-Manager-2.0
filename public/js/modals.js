/**
 * Tierphysio Manager 2.0
 * Modal System for CRUD Operations
 */

class ModalManager {
    constructor() {
        this.currentModal = null;
    }

    /**
     * Create and show a modal
     */
    show(title, content, options = {}) {
        // Remove existing modal if any
        if (this.currentModal) {
            this.close();
        }

        // Create modal structure
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 z-50 overflow-y-auto';
        modal.innerHTML = `
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <!-- Background overlay -->
                <div class="fixed inset-0 transition-opacity bg-black bg-opacity-50" aria-hidden="true"></div>

                <!-- Modal panel -->
                <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                    <!-- Header -->
                    <div class="bg-gradient-to-r from-purple-600 to-indigo-600 px-6 py-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-medium leading-6 text-white">${title}</h3>
                            <button onclick="modalManager.close()" class="text-white hover:text-gray-200 transition">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="px-6 py-4">
                        ${content}
                    </div>

                    <!-- Footer -->
                    ${options.footer ? `
                        <div class="bg-gray-50 dark:bg-gray-700 px-6 py-3 sm:flex sm:flex-row-reverse">
                            ${options.footer}
                        </div>
                    ` : ''}
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        this.currentModal = modal;

        // Animate in
        anime({
            targets: modal.querySelector('.bg-black'),
            opacity: [0, 0.5],
            duration: 300,
            easing: 'easeOutExpo'
        });

        anime({
            targets: modal.querySelector('.inline-block'),
            scale: [0.9, 1],
            opacity: [0, 1],
            duration: 300,
            easing: 'easeOutExpo'
        });

        // Close on backdrop click
        modal.querySelector('.bg-black').addEventListener('click', () => this.close());
    }

    /**
     * Close current modal
     */
    close() {
        if (!this.currentModal) return;

        const modal = this.currentModal;

        anime({
            targets: modal.querySelector('.bg-black'),
            opacity: 0,
            duration: 300,
            easing: 'easeInExpo'
        });

        anime({
            targets: modal.querySelector('.inline-block'),
            scale: 0.9,
            opacity: 0,
            duration: 300,
            easing: 'easeInExpo',
            complete: () => {
                modal.remove();
                this.currentModal = null;
            }
        });
    }

    /**
     * Show form modal
     */
    showForm(title, fields, onSubmit, data = {}) {
        const formId = 'modal-form-' + Date.now();
        
        const formContent = `
            <form id="${formId}" class="space-y-4">
                ${fields.map(field => this.renderField(field, data[field.name])).join('')}
            </form>
        `;

        const footer = `
            <button type="button" onclick="modalManager.close()" class="px-4 py-2 bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-md hover:bg-gray-400 dark:hover:bg-gray-500 transition mr-2">
                Abbrechen
            </button>
            <button type="submit" form="${formId}" class="px-4 py-2 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-md hover:from-purple-700 hover:to-indigo-700 transition">
                Speichern
            </button>
        `;

        this.show(title, formContent, { footer });

        // Handle form submission
        document.getElementById(formId).addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            
            try {
                await onSubmit(formData);
                this.close();
            } catch (error) {
                console.error('Form submission error:', error);
            }
        });
    }

    /**
     * Render form field
     */
    renderField(field, value = '') {
        const { type, name, label, required, options, placeholder } = field;
        const requiredMark = required ? '<span class="text-red-500">*</span>' : '';

        switch (type) {
            case 'select':
                return `
                    <div>
                        <label for="${name}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            ${label} ${requiredMark}
                        </label>
                        <select id="${name}" name="${name}" ${required ? 'required' : ''} 
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-purple-500 focus:border-purple-500">
                            <option value="">Bitte wählen...</option>
                            ${options.map(opt => `
                                <option value="${opt.value}" ${value === opt.value ? 'selected' : ''}>
                                    ${opt.label}
                                </option>
                            `).join('')}
                        </select>
                    </div>
                `;

            case 'textarea':
                return `
                    <div>
                        <label for="${name}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            ${label} ${requiredMark}
                        </label>
                        <textarea id="${name}" name="${name}" rows="3" ${required ? 'required' : ''}
                                  placeholder="${placeholder || ''}"
                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-purple-500 focus:border-purple-500">${value}</textarea>
                    </div>
                `;

            case 'date':
                return `
                    <div>
                        <label for="${name}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            ${label} ${requiredMark}
                        </label>
                        <input type="date" id="${name}" name="${name}" value="${value}" ${required ? 'required' : ''}
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-purple-500 focus:border-purple-500">
                    </div>
                `;

            case 'number':
                return `
                    <div>
                        <label for="${name}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            ${label} ${requiredMark}
                        </label>
                        <input type="number" id="${name}" name="${name}" value="${value}" ${required ? 'required' : ''}
                               step="${field.step || '0.01'}" min="${field.min || ''}" max="${field.max || ''}"
                               placeholder="${placeholder || ''}"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-purple-500 focus:border-purple-500">
                    </div>
                `;

            case 'email':
                return `
                    <div>
                        <label for="${name}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            ${label} ${requiredMark}
                        </label>
                        <input type="email" id="${name}" name="${name}" value="${value}" ${required ? 'required' : ''}
                               placeholder="${placeholder || ''}"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-purple-500 focus:border-purple-500">
                    </div>
                `;

            default:
                return `
                    <div>
                        <label for="${name}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            ${label} ${requiredMark}
                        </label>
                        <input type="text" id="${name}" name="${name}" value="${value}" ${required ? 'required' : ''}
                               placeholder="${placeholder || ''}"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-purple-500 focus:border-purple-500">
                    </div>
                `;
        }
    }
}

// Initialize global modal manager
const modalManager = new ModalManager();

/**
 * Patient Form Modal
 */
function showPatientForm(patient = null) {
    const title = patient ? 'Patient bearbeiten' : 'Neuen Patient anlegen';
    
    const fields = [
        { type: 'text', name: 'name', label: 'Name', required: true },
        { 
            type: 'select', 
            name: 'species', 
            label: 'Tierart', 
            required: true,
            options: [
                { value: 'dog', label: '🐕 Hund' },
                { value: 'cat', label: '🐈 Katze' },
                { value: 'horse', label: '🐴 Pferd' },
                { value: 'rabbit', label: '🐰 Kaninchen' },
                { value: 'bird', label: '🦜 Vogel' },
                { value: 'reptile', label: '🦎 Reptil' },
                { value: 'other', label: '🐾 Andere' }
            ]
        },
        { type: 'text', name: 'breed', label: 'Rasse' },
        { type: 'text', name: 'color', label: 'Farbe' },
        { 
            type: 'select', 
            name: 'gender', 
            label: 'Geschlecht',
            options: [
                { value: 'male', label: 'Männlich' },
                { value: 'female', label: 'Weiblich' },
                { value: 'neutered_male', label: 'Kastriert (männlich)' },
                { value: 'spayed_female', label: 'Sterilisiert (weiblich)' },
                { value: 'unknown', label: 'Unbekannt' }
            ]
        },
        { type: 'date', name: 'birth_date', label: 'Geburtsdatum' },
        { type: 'number', name: 'weight', label: 'Gewicht (kg)', step: '0.1' },
        { type: 'text', name: 'microchip', label: 'Chip-Nr.' },
        { type: 'textarea', name: 'medical_history', label: 'Krankengeschichte' },
        { type: 'textarea', name: 'allergies', label: 'Allergien' },
        { type: 'textarea', name: 'medications', label: 'Medikamente' },
        { type: 'textarea', name: 'notes', label: 'Notizen' }
    ];

    modalManager.showForm(title, fields, async (formData) => {
        if (patient) {
            formData.append('id', patient.id);
        }
        
        const result = await patientManager.savePatient(formData);
        if (result) {
            patientManager.loadPatients();
        }
    }, patient || {});
}

/**
 * Owner Form Modal
 */
function showOwnerForm(owner = null) {
    const title = owner ? 'Besitzer bearbeiten' : 'Neuen Besitzer anlegen';
    
    const fields = [
        { 
            type: 'select', 
            name: 'salutation', 
            label: 'Anrede',
            options: [
                { value: 'Herr', label: 'Herr' },
                { value: 'Frau', label: 'Frau' },
                { value: 'Divers', label: 'Divers' },
                { value: 'Firma', label: 'Firma' }
            ]
        },
        { type: 'text', name: 'first_name', label: 'Vorname', required: true },
        { type: 'text', name: 'last_name', label: 'Nachname', required: true },
        { type: 'text', name: 'company', label: 'Firma' },
        { type: 'email', name: 'email', label: 'E-Mail' },
        { type: 'text', name: 'phone', label: 'Telefon' },
        { type: 'text', name: 'mobile', label: 'Mobil' },
        { type: 'text', name: 'street', label: 'Straße' },
        { type: 'text', name: 'house_number', label: 'Hausnummer' },
        { type: 'text', name: 'postal_code', label: 'PLZ' },
        { type: 'text', name: 'city', label: 'Stadt' },
        { type: 'textarea', name: 'notes', label: 'Notizen' }
    ];

    modalManager.showForm(title, fields, async (formData) => {
        try {
            const endpoint = 'owners';
            const result = owner 
                ? await api.update(endpoint, owner.id, formData)
                : await api.create(endpoint, formData);
                
            if (result.status === 'success') {
                api.showNotification(owner ? 'Besitzer aktualisiert' : 'Besitzer erstellt', 'success');
                // Reload data if needed
                location.reload();
            }
        } catch (error) {
            api.showNotification(error.message || 'Fehler beim Speichern', 'error');
        }
    }, owner || {});
}