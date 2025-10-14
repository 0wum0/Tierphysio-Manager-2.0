/**
 * Tierphysio Manager 2.0
 * Patient Management JavaScript
 * Enhanced Modal functionality with Alpine.js + anime.js
 */

// Initialize patient modal functionality
function patientModal() {
    return {
        open: false,
        patient: null,
        tab: 'info',
        records: [],
        notes: [],
        documents: [],
        newRecord: '',
        newNote: '',
        
        init() {
            // Listen for open-patient event from main component
            window.addEventListener('open-patient-modal', async (e) => {
                await this.openPatient(e.detail.id);
            });
        },
        
        async openPatient(id) {
            this.open = true;
            this.tab = 'info';
            this.newRecord = '';
            this.newNote = '';
            
            // Animate modal opening
            this.$nextTick(() => {
                if (typeof anime !== 'undefined') {
                    anime({
                        targets: '#patientModalBox',
                        opacity: [0, 1],
                        scale: [0.9, 1],
                        duration: 300,
                        easing: 'easeOutExpo'
                    });
                }
            });
            
            try {
                const res = await fetch(`/api/patients.php?action=get&id=${id}`);
                const data = await res.json();
                
                if (data.status === "success" || data.ok === true) {
                    this.patient = data.patient || data.data?.items?.[0] || data.data?.[0] || null;
                    
                    // Ensure owner_full_name is properly set
                    if (this.patient) {
                        this.patient.owner_full_name = this.patient.owner_full_name && this.patient.owner_full_name !== "0"
                            ? this.patient.owner_full_name
                            : (this.patient.owner_name || 
                               (this.patient.owner_first_name && this.patient.owner_last_name 
                                ? `${this.patient.owner_first_name} ${this.patient.owner_last_name}`
                                : "Unbekannter Besitzer"));
                    }
                    
                    // Load all related data in parallel
                    await Promise.all([
                        this.loadRecords(id),
                        this.loadNotes(id),
                        this.loadDocuments(id)
                    ]);
                } else {
                    this.showNotification("Fehler: " + (data.message || data.error || 'Unbekannter Fehler'), 'error');
                }
            } catch (err) {
                console.error("API Fehler:", err);
                this.showNotification("Patientendaten konnten nicht geladen werden.", 'error');
            }
        },
        
        async loadRecords(id) {
            try {
                const res = await fetch(`/api/patients.php?action=get_records&id=${id}`);
                const data = await res.json();
                this.records = (data.status === "success" || data.ok === true) ? (data.records || data.data || []) : [];
            } catch (err) {
                console.error('Fehler beim Laden der Befunde:', err);
                this.records = [];
            }
        },
        
        async loadNotes(id) {
            try {
                const res = await fetch(`/api/patients.php?action=get_notes&id=${id}`);
                const data = await res.json();
                this.notes = (data.status === "success" || data.ok === true) ? (data.notes || data.data || []) : [];
            } catch (err) {
                console.error('Fehler beim Laden der Notizen:', err);
                this.notes = [];
            }
        },
        
        async loadDocuments(id) {
            try {
                const res = await fetch(`/api/patients.php?action=get_documents&id=${id}`);
                const data = await res.json();
                this.documents = (data.status === "success" || data.ok === true) ? (data.documents || data.data || []) : [];
            } catch (err) {
                console.error('Fehler beim Laden der Dokumente:', err);
                this.documents = [];
            }
        },
        
        async saveRecord() {
            if (!this.newRecord.trim()) return;
            
            try {
                const res = await fetch('/api/patients.php?action=save_record', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        patient_id: this.patient.id,
                        content: this.newRecord
                    })
                });
                
                const data = await res.json();
                
                if (data.status === 'success') {
                    this.records.unshift(data.record);
                    this.newRecord = '';
                    this.showNotification('Befund gespeichert', 'success');
                } else {
                    this.showNotification(data.error || 'Fehler beim Speichern', 'error');
                }
            } catch (err) {
                console.error('Fehler beim Speichern:', err);
                this.showNotification('Fehler beim Speichern des Befunds', 'error');
            }
        },
        
        async saveNote() {
            if (!this.newNote.trim()) return;
            
            try {
                const res = await fetch('/api/patients.php?action=save_note', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        patient_id: this.patient.id,
                        content: this.newNote
                    })
                });
                
                const data = await res.json();
                
                if (data.status === 'success') {
                    this.notes.unshift(data.note);
                    this.newNote = '';
                    this.showNotification('Notiz gespeichert', 'success');
                } else {
                    this.showNotification(data.error || 'Fehler beim Speichern', 'error');
                }
            } catch (err) {
                console.error('Fehler beim Speichern:', err);
                this.showNotification('Fehler beim Speichern der Notiz', 'error');
            }
        },
        
        async uploadDocument(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            const form = new FormData();
            form.append('file', file);
            form.append('patient_id', this.patient.id);
            
            try {
                const res = await fetch('/api/patients.php?action=upload_pdf', {
                    method: 'POST',
                    body: form
                });
                
                const data = await res.json();
                
                if (data.status === 'success') {
                    this.documents.unshift(data.doc);
                    e.target.value = ''; // Reset file input
                    this.showNotification('Dokument hochgeladen', 'success');
                } else {
                    this.showNotification(data.error || 'Fehler beim Upload', 'error');
                }
            } catch (err) {
                console.error('Upload-Fehler:', err);
                this.showNotification('Fehler beim Hochladen des Dokuments', 'error');
            }
        },
        
        formatDate(dateString) {
            if (!dateString) return '—';
            try {
                const date = new Date(dateString);
                return date.toLocaleDateString('de-DE', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                });
            } catch {
                return dateString;
            }
        },
        
        getSpeciesName(species) {
            const names = {
                'dog': 'Hund',
                'cat': 'Katze',
                'horse': 'Pferd',
                'rabbit': 'Hase',
                'bird': 'Vogel',
                'reptile': 'Reptil',
                'other': 'Andere'
            };
            return names[species] || 'Unbekannt';
        },
        
        getGenderName(gender) {
            const names = {
                'male': 'Männlich',
                'female': 'Weiblich',
                'neutered_male': 'Kastriert (männlich)',
                'spayed_female': 'Kastriert (weiblich)',
                'unknown': 'Unbekannt'
            };
            return names[gender] || 'Unbekannt';
        },
        
        showNotification(message, type = 'success') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 px-6 py-3 rounded-lg text-white z-[100] shadow-lg ${
                type === 'success' ? 'bg-green-500' : 'bg-red-500'
            }`;
            notification.textContent = message;
            document.body.appendChild(notification);

            // Animate if anime.js is available
            if (typeof anime !== 'undefined') {
                anime({
                    targets: notification,
                    translateX: [100, 0],
                    opacity: [0, 1],
                    duration: 300,
                    easing: 'easeOutQuad'
                });

                setTimeout(() => {
                    anime({
                        targets: notification,
                        translateX: [0, 100],
                        opacity: [1, 0],
                        duration: 300,
                        easing: 'easeInQuad',
                        complete: () => notification.remove()
                    });
                }, 3000);
            } else {
                // Fallback without animation
                setTimeout(() => notification.remove(), 3000);
            }
        }
    };
}

// Document ready - ensure Alpine.js components are registered
document.addEventListener('DOMContentLoaded', () => {
    console.log('Patients JS loaded - Modal functionality ready');
    
    // Register the patientModal function globally for Alpine.js
    if (window.Alpine) {
        window.patientModal = patientModal;
    }
});

// Export for use in Alpine.js
window.patientModal = patientModal;