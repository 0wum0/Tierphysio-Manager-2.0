<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Test - Tierphysio Manager 2.0</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        h1 {
            color: #667eea;
            margin-bottom: 30px;
        }
        .test-section {
            margin-bottom: 30px;
            padding: 20px;
            background: #f7f9fc;
            border-radius: 8px;
        }
        .test-section h2 {
            color: #333;
            margin-top: 0;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        button:hover {
            opacity: 0.9;
        }
        .result {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            padding: 15px;
            margin-top: 15px;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 400px;
            overflow-y: auto;
        }
        .success {
            background: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        input, select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🐾 Tierphysio Manager 2.0 - API Test</h1>
        
        <!-- Database Connection Test -->
        <div class="test-section">
            <h2>1️⃣ Datenbankverbindung testen</h2>
            <button onclick="testDatabase()">Verbindung testen</button>
            <div id="db-result" class="result" style="display:none;"></div>
        </div>

        <!-- Patients API Test -->
        <div class="test-section">
            <h2>2️⃣ Patienten API testen</h2>
            <button onclick="testPatientsList()">Liste laden</button>
            <button onclick="showCreatePatientForm()">Neuer Patient</button>
            <div id="patient-result" class="result" style="display:none;"></div>
            
            <!-- Create Patient Form -->
            <div id="patient-form" style="display:none; margin-top:20px;">
                <h3>Neuen Patienten anlegen:</h3>
                <div class="grid">
                    <div class="form-group">
                        <label>Patient Name *</label>
                        <input type="text" id="patient_name" placeholder="z.B. Bella">
                    </div>
                    <div class="form-group">
                        <label>Tierart *</label>
                        <select id="species">
                            <option value="">Bitte wählen</option>
                            <option value="dog">🐕 Hund</option>
                            <option value="cat">🐈 Katze</option>
                            <option value="horse">🐴 Pferd</option>
                            <option value="rabbit">🐰 Hase</option>
                            <option value="bird">🦜 Vogel</option>
                            <option value="other">🐾 Andere</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Rasse</label>
                        <input type="text" id="breed" placeholder="z.B. Labrador">
                    </div>
                    <div class="form-group">
                        <label>Geburtsdatum</label>
                        <input type="date" id="birthdate">
                    </div>
                    <div class="form-group">
                        <label>Besitzer Vorname *</label>
                        <input type="text" id="owner_first" placeholder="z.B. Max">
                    </div>
                    <div class="form-group">
                        <label>Besitzer Nachname *</label>
                        <input type="text" id="owner_last" placeholder="z.B. Mustermann">
                    </div>
                    <div class="form-group">
                        <label>Telefon</label>
                        <input type="text" id="phone" placeholder="z.B. 0171-1234567">
                    </div>
                    <div class="form-group">
                        <label>E-Mail</label>
                        <input type="email" id="email" placeholder="z.B. max@example.com">
                    </div>
                </div>
                <div class="form-group">
                    <label>Notizen</label>
                    <input type="text" id="notes" placeholder="Besonderheiten...">
                </div>
                <button onclick="createPatient()">Patient speichern</button>
                <button onclick="hideCreatePatientForm()">Abbrechen</button>
            </div>
        </div>

        <!-- Owners API Test -->
        <div class="test-section">
            <h2>3️⃣ Besitzer API testen</h2>
            <button onclick="testOwnersList()">Liste laden</button>
            <div id="owner-result" class="result" style="display:none;"></div>
        </div>
    </div>

    <script>
        // Test database connection
        async function testDatabase() {
            const resultDiv = document.getElementById('db-result');
            resultDiv.style.display = 'block';
            resultDiv.className = 'result';
            resultDiv.textContent = 'Teste Verbindung...';
            
            try {
                const response = await fetch('/public/api/patients.php?action=list');
                const data = await response.json();
                
                if (data.status === 'success') {
                    resultDiv.className = 'result success';
                    resultDiv.textContent = '✅ Datenbankverbindung erfolgreich!\n\n' + 
                                          'Gefundene Patienten: ' + (data.data ? data.data.length : 0);
                } else {
                    resultDiv.className = 'result error';
                    resultDiv.textContent = '❌ Fehler: ' + data.message;
                }
            } catch (error) {
                resultDiv.className = 'result error';
                resultDiv.textContent = '❌ Verbindungsfehler:\n' + error.message;
            }
        }

        // Test patients list
        async function testPatientsList() {
            const resultDiv = document.getElementById('patient-result');
            resultDiv.style.display = 'block';
            resultDiv.className = 'result';
            resultDiv.textContent = 'Lade Patienten...';
            
            try {
                const response = await fetch('/public/api/patients.php?action=list');
                const data = await response.json();
                
                if (data.status === 'success' && data.data) {
                    resultDiv.className = 'result success';
                    let output = '✅ ' + data.data.length + ' Patienten gefunden:\n\n';
                    
                    data.data.forEach((patient, index) => {
                        output += `${index + 1}. ${patient.name} (${patient.species || 'Unbekannt'})\n`;
                        output += `   Besitzer: ${patient.first_name || ''} ${patient.last_name || ''}\n`;
                        output += `   ID: ${patient.id}\n\n`;
                    });
                    
                    resultDiv.textContent = output;
                } else {
                    resultDiv.className = 'result error';
                    resultDiv.textContent = '❌ ' + (data.message || 'Keine Daten erhalten');
                }
            } catch (error) {
                resultDiv.className = 'result error';
                resultDiv.textContent = '❌ Fehler beim Laden:\n' + error.message;
            }
        }

        // Test owners list
        async function testOwnersList() {
            const resultDiv = document.getElementById('owner-result');
            resultDiv.style.display = 'block';
            resultDiv.className = 'result';
            resultDiv.textContent = 'Lade Besitzer...';
            
            try {
                const response = await fetch('/public/api/owners.php?action=list');
                const data = await response.json();
                
                if (data.status === 'success' && data.data) {
                    resultDiv.className = 'result success';
                    let output = '✅ ' + data.data.length + ' Besitzer gefunden:\n\n';
                    
                    data.data.forEach((owner, index) => {
                        output += `${index + 1}. ${owner.first_name} ${owner.last_name}\n`;
                        output += `   Patienten: ${owner.patient_count || 0}\n`;
                        output += `   Tel: ${owner.phone || 'N/A'}\n`;
                        output += `   ID: ${owner.id}\n\n`;
                    });
                    
                    resultDiv.textContent = output;
                } else {
                    resultDiv.className = 'result error';
                    resultDiv.textContent = '❌ ' + (data.message || 'Keine Daten erhalten');
                }
            } catch (error) {
                resultDiv.className = 'result error';
                resultDiv.textContent = '❌ Fehler beim Laden:\n' + error.message;
            }
        }

        // Show create patient form
        function showCreatePatientForm() {
            document.getElementById('patient-form').style.display = 'block';
        }

        // Hide create patient form
        function hideCreatePatientForm() {
            document.getElementById('patient-form').style.display = 'none';
            // Clear form
            document.getElementById('patient_name').value = '';
            document.getElementById('species').value = '';
            document.getElementById('breed').value = '';
            document.getElementById('birthdate').value = '';
            document.getElementById('owner_first').value = '';
            document.getElementById('owner_last').value = '';
            document.getElementById('phone').value = '';
            document.getElementById('email').value = '';
            document.getElementById('notes').value = '';
        }

        // Create new patient
        async function createPatient() {
            const resultDiv = document.getElementById('patient-result');
            resultDiv.style.display = 'block';
            resultDiv.className = 'result';
            resultDiv.textContent = 'Speichere Patient...';
            
            const formData = new FormData();
            formData.append('patient_name', document.getElementById('patient_name').value);
            formData.append('species', document.getElementById('species').value);
            formData.append('breed', document.getElementById('breed').value);
            formData.append('birthdate', document.getElementById('birthdate').value);
            formData.append('owner_first_name', document.getElementById('owner_first').value);
            formData.append('owner_last_name', document.getElementById('owner_last').value);
            formData.append('phone', document.getElementById('phone').value);
            formData.append('email', document.getElementById('email').value);
            formData.append('notes', document.getElementById('notes').value);
            
            try {
                const response = await fetch('/public/api/patients.php?action=create', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.status === 'success') {
                    resultDiv.className = 'result success';
                    resultDiv.textContent = '✅ Patient erfolgreich angelegt!\n\n' +
                                          'Patient ID: ' + data.data.patient_id + '\n' +
                                          'Besitzer ID: ' + data.data.owner_id + '\n\n' +
                                          data.message;
                    hideCreatePatientForm();
                    
                    // Reload list after 2 seconds
                    setTimeout(() => testPatientsList(), 2000);
                } else {
                    resultDiv.className = 'result error';
                    resultDiv.textContent = '❌ Fehler: ' + data.message;
                }
            } catch (error) {
                resultDiv.className = 'result error';
                resultDiv.textContent = '❌ Fehler beim Speichern:\n' + error.message;
            }
        }

        // Auto-test on load
        window.addEventListener('load', () => {
            console.log('🐾 Tierphysio Manager 2.0 - API Test geladen');
        });
    </script>
</body>
</html>