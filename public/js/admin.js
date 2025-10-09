/**
 * Admin Panel JavaScript
 * Alpine.js components and functionality
 */

// Admin Panel Alpine Component
function adminPanel() {
    return {
        // State
        activeTab: 'overview',
        darkMode: localStorage.getItem('darkMode') === 'true',
        showUserModal: false,
        showProgressModal: false,
        showToast: false,
        
        // Data
        stats: {},
        users: [],
        recentActivities: [],
        logs: [],
        filteredLogs: [],
        logFilter: '',
        databaseTables: [],
        lastBackup: null,
        
        // Settings
        settings: {
            practice_name: '',
            practice_email: '',
            practice_phone: '',
            practice_address: '',
            practice_website: '',
            practice_logo: '',
            currency: 'EUR',
            language: 'de',
            timezone: 'Europe/Berlin'
        },
        
        // Theme customization
        theme: {
            primaryColor: '#9b5de5',
            secondaryColor: '#7C4DFF',
            accentColor: '#6c63ff',
            gradientStyle: 'lilac'
        },
        
        // User form
        userForm: {
            username: '',
            first_name: '',
            last_name: '',
            email: '',
            password: '',
            role: 'employee'
        },
        editingUser: null,
        
        // Progress modal
        progressMessage: '',
        progressDetail: '',
        
        // Toast
        toastMessage: '',
        toastType: 'success',
        
        // Charts
        treatmentChart: null,
        typeChart: null,
        
        // Initialize
        async init() {
            // Apply dark mode
            if (this.darkMode) {
                document.documentElement.classList.add('dark');
            }
            
            // Load initial data
            await this.loadData();
            
            // Initialize charts
            this.$nextTick(() => {
                this.initCharts();
            });
            
            // Auto-refresh every 60 seconds
            setInterval(() => {
                if (this.activeTab === 'overview' || this.activeTab === 'logs') {
                    this.refreshData();
                }
            }, 60000);
        },
        
        // Load all data
        async loadData() {
            try {
                // Load stats
                await this.loadStats();
                
                // Load users
                await this.loadUsers();
                
                // Load recent activities
                await this.loadActivities();
                
                // Load settings
                await this.loadSettings();
                
                // Load database info
                await this.loadDatabaseInfo();
                
                // Load logs
                await this.loadLogs();
                
            } catch (error) {
                console.error('Error loading data:', error);
                this.showToastMessage('Fehler beim Laden der Daten', 'error');
            }
        },
        
        // Load statistics
        async loadStats() {
            try {
                const response = await fetch('/api/stats.php?type=overview');
                const result = await response.json();
                
                if (result.status === 'success') {
                    this.stats = result.data;
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        },
        
        // Load users
        async loadUsers() {
            try {
                const response = await fetch('/api/stats.php?type=users');
                const result = await response.json();
                
                if (result.status === 'success') {
                    this.users = result.data;
                }
            } catch (error) {
                console.error('Error loading users:', error);
            }
        },
        
        // Load activities
        async loadActivities() {
            try {
                const response = await fetch('/api/stats.php?type=activity');
                const result = await response.json();
                
                if (result.status === 'success') {
                    this.recentActivities = result.data;
                }
            } catch (error) {
                console.error('Error loading activities:', error);
            }
        },
        
        // Load settings
        async loadSettings() {
            try {
                const response = await fetch('/api/settings.php?category=general');
                const result = await response.json();
                
                if (result.status === 'success') {
                    // Map settings to form
                    result.data.forEach(setting => {
                        if (this.settings.hasOwnProperty(setting.key)) {
                            this.settings[setting.key] = setting.value;
                        }
                    });
                }
            } catch (error) {
                console.error('Error loading settings:', error);
            }
        },
        
        // Save settings
        async saveSettings() {
            try {
                const settingsArray = Object.keys(this.settings).map(key => ({
                    category: 'general',
                    key: key,
                    value: this.settings[key],
                    type: 'string'
                }));
                
                const response = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        settings: settingsArray
                    })
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    this.showToastMessage('Einstellungen gespeichert', 'success');
                } else {
                    this.showToastMessage(result.message || 'Fehler beim Speichern', 'error');
                }
            } catch (error) {
                console.error('Error saving settings:', error);
                this.showToastMessage('Fehler beim Speichern', 'error');
            }
        },
        
        // Load database info
        async loadDatabaseInfo() {
            try {
                const response = await fetch('/api/stats.php?type=database');
                const result = await response.json();
                
                if (result.status === 'success') {
                    this.databaseTables = result.data;
                }
                
                // Load last backup info
                const backupResponse = await fetch('/api/backup.php');
                const backupResult = await backupResponse.json();
                
                if (backupResult.status === 'success' && backupResult.data.length > 0) {
                    this.lastBackup = backupResult.data[0].created;
                }
            } catch (error) {
                console.error('Error loading database info:', error);
            }
        },
        
        // Load logs
        async loadLogs() {
            try {
                const response = await fetch('/api/stats.php?type=activity');
                const result = await response.json();
                
                if (result.status === 'success') {
                    this.logs = result.data;
                    this.filterLogs();
                }
            } catch (error) {
                console.error('Error loading logs:', error);
            }
        },
        
        // Filter logs
        filterLogs() {
            if (!this.logFilter) {
                this.filteredLogs = this.logs;
            } else {
                this.filteredLogs = this.logs.filter(log => 
                    log.action === this.logFilter
                );
            }
        },
        
        // Create backup
        async createBackup() {
            this.showProgress('Backup wird erstellt...', 'Bitte warten Sie einen Moment');
            
            try {
                const response = await fetch('/api/backup.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                const result = await response.json();
                
                this.hideProgress();
                
                if (result.status === 'success') {
                    this.lastBackup = result.data.created;
                    this.showToastMessage('Backup erfolgreich erstellt', 'success');
                } else {
                    this.showToastMessage(result.message || 'Fehler beim Backup', 'error');
                }
            } catch (error) {
                console.error('Error creating backup:', error);
                this.hideProgress();
                this.showToastMessage('Fehler beim Backup', 'error');
            }
        },
        
        // Run migration
        async runMigration() {
            this.showProgress('Migration wird ausgeführt...', 'Datenbankstruktur wird aktualisiert');
            
            try {
                const response = await fetch('/api/migrate.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                const result = await response.json();
                
                this.hideProgress();
                
                if (result.status === 'success' || result.status === 'partial') {
                    const message = result.data.executed.length > 0 
                        ? `${result.data.executed.length} Migration(en) ausgeführt` 
                        : 'Keine neuen Migrationen';
                    this.showToastMessage(message, 'success');
                    
                    if (result.data.errors.length > 0) {
                        console.error('Migration errors:', result.data.errors);
                    }
                } else {
                    this.showToastMessage(result.message || 'Fehler bei Migration', 'error');
                }
            } catch (error) {
                console.error('Error running migration:', error);
                this.hideProgress();
                this.showToastMessage('Fehler bei Migration', 'error');
            }
        },
        
        // User management
        openUserModal(user = null) {
            this.editingUser = user;
            if (user) {
                this.userForm = {
                    username: user.username,
                    first_name: user.first_name,
                    last_name: user.last_name,
                    email: user.email,
                    role: user.role,
                    password: ''
                };
            } else {
                this.userForm = {
                    username: '',
                    first_name: '',
                    last_name: '',
                    email: '',
                    password: '',
                    role: 'employee'
                };
            }
            this.showUserModal = true;
        },
        
        editUser(user) {
            this.openUserModal(user);
        },
        
        async saveUser() {
            try {
                const url = this.editingUser 
                    ? `/api/users.php?id=${this.editingUser.id}`
                    : '/api/users.php';
                    
                const method = this.editingUser ? 'PUT' : 'POST';
                
                const response = await fetch(url, {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(this.userForm)
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    this.showToastMessage(
                        this.editingUser ? 'Benutzer aktualisiert' : 'Benutzer erstellt', 
                        'success'
                    );
                    this.showUserModal = false;
                    await this.loadUsers();
                } else {
                    this.showToastMessage(result.message || 'Fehler beim Speichern', 'error');
                }
            } catch (error) {
                console.error('Error saving user:', error);
                this.showToastMessage('Fehler beim Speichern', 'error');
            }
        },
        
        async toggleUserStatus(user) {
            try {
                const response = await fetch(`/api/users.php?id=${user.id}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ action: 'toggle_status' })
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    this.showToastMessage('Status geändert', 'success');
                    await this.loadUsers();
                } else {
                    this.showToastMessage(result.message || 'Fehler bei Statusänderung', 'error');
                }
            } catch (error) {
                console.error('Error toggling user status:', error);
                this.showToastMessage('Fehler bei Statusänderung', 'error');
            }
        },
        
        async resetPassword(user) {
            if (!confirm(`Passwort für ${user.first_name} ${user.last_name} zurücksetzen?`)) {
                return;
            }
            
            try {
                const response = await fetch(`/api/users.php?id=${user.id}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ action: 'reset_password' })
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    const newPassword = result.data.new_password;
                    alert(`Neues Passwort: ${newPassword}\n\nBitte notieren Sie das Passwort und geben es dem Benutzer weiter.`);
                    this.showToastMessage('Passwort zurückgesetzt', 'success');
                } else {
                    this.showToastMessage(result.message || 'Fehler beim Passwort-Reset', 'error');
                }
            } catch (error) {
                console.error('Error resetting password:', error);
                this.showToastMessage('Fehler beim Passwort-Reset', 'error');
            }
        },
        
        // Theme management
        applyTheme() {
            // Apply CSS variables
            document.documentElement.style.setProperty('--primary-color', this.theme.primaryColor);
            document.documentElement.style.setProperty('--secondary-color', this.theme.secondaryColor);
            document.documentElement.style.setProperty('--accent-color', this.theme.accentColor);
            
            // Apply gradient style
            const gradients = {
                lilac: 'linear-gradient(135deg, #9b5de5 0%, #7C4DFF 50%, #6c63ff 100%)',
                purple: 'linear-gradient(135deg, #8B5CF6 0%, #7C3AED 50%, #6D28D9 100%)',
                blue: 'linear-gradient(135deg, #3B82F6 0%, #2563EB 50%, #1E40AF 100%)',
                green: 'linear-gradient(135deg, #10B981 0%, #059669 50%, #047857 100%)',
                sunset: 'linear-gradient(135deg, #F59E0B 0%, #EF4444 50%, #DC2626 100%)'
            };
            
            const gradient = gradients[this.theme.gradientStyle] || gradients.lilac;
            document.documentElement.style.setProperty('--gradient-bg', gradient);
        },
        
        async saveTheme() {
            try {
                const themeSettings = Object.keys(this.theme).map(key => ({
                    category: 'theme',
                    key: key,
                    value: this.theme[key],
                    type: 'string'
                }));
                
                const response = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        settings: themeSettings
                    })
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    this.showToastMessage('Design gespeichert', 'success');
                    this.applyTheme();
                } else {
                    this.showToastMessage(result.message || 'Fehler beim Speichern', 'error');
                }
            } catch (error) {
                console.error('Error saving theme:', error);
                this.showToastMessage('Fehler beim Speichern', 'error');
            }
        },
        
        resetTheme() {
            this.theme = {
                primaryColor: '#9b5de5',
                secondaryColor: '#7C4DFF',
                accentColor: '#6c63ff',
                gradientStyle: 'lilac'
            };
            this.applyTheme();
        },
        
        // Logo upload
        async uploadLogo(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            // Check file type
            if (!file.type.startsWith('image/')) {
                this.showToastMessage('Bitte wählen Sie eine Bilddatei', 'error');
                return;
            }
            
            // Check file size (max 2MB)
            if (file.size > 2 * 1024 * 1024) {
                this.showToastMessage('Datei ist zu groß (max. 2MB)', 'error');
                return;
            }
            
            // Convert to base64
            const reader = new FileReader();
            reader.onload = async (e) => {
                this.settings.practice_logo = e.target.result;
                this.showToastMessage('Logo hochgeladen. Bitte speichern Sie die Einstellungen.', 'success');
            };
            reader.readAsDataURL(file);
        },
        
        // Utility functions
        toggleDarkMode() {
            this.darkMode = !this.darkMode;
            localStorage.setItem('darkMode', this.darkMode);
            
            if (this.darkMode) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        },
        
        async refreshData() {
            await this.loadData();
            this.updateCharts();
            this.showToastMessage('Daten aktualisiert', 'success');
        },
        
        formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleString('de-DE', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        },
        
        showProgress(message, detail = '') {
            this.progressMessage = message;
            this.progressDetail = detail;
            this.showProgressModal = true;
        },
        
        hideProgress() {
            this.showProgressModal = false;
            this.progressMessage = '';
            this.progressDetail = '';
        },
        
        showToastMessage(message, type = 'success') {
            this.toastMessage = message;
            this.toastType = type;
            this.showToast = true;
            
            setTimeout(() => {
                this.showToast = false;
            }, 3000);
        },
        
        showLogDetails(log) {
            // TODO: Implement log details modal
            console.log('Log details:', log);
        },
        
        // Initialize charts
        initCharts() {
            // Check if Chart.js is loaded
            if (typeof Chart === 'undefined') {
                console.warn('Chart.js not loaded');
                return;
            }
            
            // Treatment chart
            const treatmentCtx = document.getElementById('treatmentChart');
            if (treatmentCtx) {
                this.initTreatmentChart(treatmentCtx);
            }
            
            // Type chart
            const typeCtx = document.getElementById('typeChart');
            if (typeCtx) {
                this.initTypeChart(typeCtx);
            }
        },
        
        async initTreatmentChart(ctx) {
            try {
                const response = await fetch('/api/stats.php?type=charts');
                const result = await response.json();
                
                if (result.status === 'success') {
                    const data = result.data.treatments;
                    
                    this.treatmentChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.map(d => d.label),
                            datasets: [{
                                label: 'Behandlungen',
                                data: data.map(d => d.count),
                                borderColor: '#9b5de5',
                                backgroundColor: 'rgba(155, 93, 229, 0.1)',
                                borderWidth: 2,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error initializing treatment chart:', error);
            }
        },
        
        async initTypeChart(ctx) {
            try {
                const response = await fetch('/api/stats.php?type=charts');
                const result = await response.json();
                
                if (result.status === 'success') {
                    const data = result.data.types;
                    
                    this.typeChart = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: data.map(d => d.type),
                            datasets: [{
                                data: data.map(d => d.count),
                                backgroundColor: [
                                    '#9b5de5',
                                    '#7C4DFF',
                                    '#6c63ff',
                                    '#60A5FA',
                                    '#A78BFA'
                                ]
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        color: '#9CA3AF'
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error initializing type chart:', error);
            }
        },
        
        updateCharts() {
            if (this.treatmentChart) {
                this.initTreatmentChart(document.getElementById('treatmentChart'));
            }
            if (this.typeChart) {
                this.initTypeChart(document.getElementById('typeChart'));
            }
        }
    };
}