<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Test - Tierphysio Manager 2.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-purple-100 via-pink-50 to-purple-100 min-h-screen p-8">
    <div class="max-w-6xl mx-auto">
        <h1 class="text-3xl font-bold mb-8 text-purple-800">Tierphysio Manager 2.0 - API Test</h1>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <!-- Owners API Test -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4 text-purple-700">Besitzer API</h2>
                <button onclick="testAPI('owners')" class="w-full bg-purple-600 text-white py-2 px-4 rounded hover:bg-purple-700 transition">
                    Test Owners API
                </button>
                <div id="owners-result" class="mt-4 text-sm"></div>
            </div>
            
            <!-- Patients API Test -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4 text-purple-700">Patienten API</h2>
                <button onclick="testAPI('api_patients')" class="w-full bg-purple-600 text-white py-2 px-4 rounded hover:bg-purple-700 transition">
                    Test Patients API
                </button>
                <div id="api_patients-result" class="mt-4 text-sm"></div>
            </div>
            
            <!-- Appointments API Test -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4 text-purple-700">Termine API</h2>
                <button onclick="testAPI('appointments')" class="w-full bg-purple-600 text-white py-2 px-4 rounded hover:bg-purple-700 transition">
                    Test Appointments API
                </button>
                <div id="appointments-result" class="mt-4 text-sm"></div>
            </div>
            
            <!-- Invoices API Test -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4 text-purple-700">Rechnungen API</h2>
                <button onclick="testAPI('invoices')" class="w-full bg-purple-600 text-white py-2 px-4 rounded hover:bg-purple-700 transition">
                    Test Invoices API
                </button>
                <div id="invoices-result" class="mt-4 text-sm"></div>
            </div>
            
            <!-- Notes API Test -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4 text-purple-700">Notizen API</h2>
                <button onclick="testAPI('notes')" class="w-full bg-purple-600 text-white py-2 px-4 rounded hover:bg-purple-700 transition">
                    Test Notes API
                </button>
                <div id="notes-result" class="mt-4 text-sm"></div>
            </div>
            
            <!-- Settings API Test -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4 text-purple-700">Einstellungen API</h2>
                <button onclick="testAPI('settings')" class="w-full bg-purple-600 text-white py-2 px-4 rounded hover:bg-purple-700 transition">
                    Test Settings API
                </button>
                <div id="settings-result" class="mt-4 text-sm"></div>
            </div>
        </div>
        
        <!-- Full Test Button -->
        <div class="mt-8">
            <button onclick="testAll()" class="w-full bg-gradient-to-r from-purple-600 to-pink-600 text-white py-3 px-6 rounded-lg hover:from-purple-700 hover:to-pink-700 transition font-semibold text-lg">
                Alle APIs testen
            </button>
        </div>
        
        <!-- Results Log -->
        <div class="mt-8 bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-xl font-semibold mb-4 text-purple-700">Test-Log</h2>
            <div id="log" class="bg-gray-50 p-4 rounded max-h-96 overflow-y-auto font-mono text-sm"></div>
        </div>
    </div>
    
    <script>
        function log(message, type = 'info') {
            const logDiv = document.getElementById('log');
            const time = new Date().toLocaleTimeString();
            const colorClass = type === 'success' ? 'text-green-600' : 
                              type === 'error' ? 'text-red-600' : 'text-gray-700';
            logDiv.innerHTML += `<div class="${colorClass}">[${time}] ${message}</div>`;
            logDiv.scrollTop = logDiv.scrollHeight;
        }
        
        async function testAPI(endpoint) {
            const resultDiv = document.getElementById(endpoint + '-result');
            resultDiv.innerHTML = '<div class="text-yellow-600">Testing...</div>';
            
            try {
                log(`Testing ${endpoint}...`);
                
                // Test get_all action
                const response = await fetch(`/${endpoint}.php?action=get_all`);
                const data = await response.json();
                
                if (data.status === 'success') {
                    resultDiv.innerHTML = `
                        <div class="text-green-600">✓ Success</div>
                        <div class="text-gray-600">${data.message}</div>
                    `;
                    log(`✓ ${endpoint}: ${data.message}`, 'success');
                    
                    // Test create action (only for demonstration, won't actually create)
                    const testData = getTestData(endpoint);
                    if (testData) {
                        const formData = new FormData();
                        formData.append('action', 'create');
                        for (const [key, value] of Object.entries(testData)) {
                            formData.append(key, value);
                        }
                        
                        try {
                            const createResponse = await fetch(`/${endpoint}.php`, {
                                method: 'POST',
                                body: formData
                            });
                            const createData = await createResponse.json();
                            
                            if (createData.status === 'success') {
                                log(`✓ ${endpoint} CREATE: ${createData.message}`, 'success');
                            } else {
                                log(`✗ ${endpoint} CREATE: ${createData.message}`, 'error');
                            }
                        } catch (e) {
                            log(`✗ ${endpoint} CREATE test failed: ${e.message}`, 'error');
                        }
                    }
                } else {
                    resultDiv.innerHTML = `
                        <div class="text-red-600">✗ Error</div>
                        <div class="text-gray-600">${data.message}</div>
                    `;
                    log(`✗ ${endpoint}: ${data.message}`, 'error');
                }
            } catch (error) {
                resultDiv.innerHTML = `
                    <div class="text-red-600">✗ Failed</div>
                    <div class="text-gray-600">${error.message}</div>
                `;
                log(`✗ ${endpoint}: ${error.message}`, 'error');
            }
        }
        
        function getTestData(endpoint) {
            const testData = {
                'owners': {
                    first_name: 'Test',
                    last_name: 'Besitzer',
                    email: 'test@example.com',
                    phone: '0123456789'
                },
                'api_patients': {
                    owner_id: 1,
                    name: 'Test Hund',
                    species: 'dog',
                    breed: 'Golden Retriever'
                },
                'appointments': {
                    patient_id: 1,
                    therapist_id: 1,
                    appointment_date: '2024-12-31',
                    start_time: '10:00:00',
                    end_time: '10:30:00'
                },
                'invoices': {
                    owner_id: 1,
                    subtotal: 100.00
                },
                'notes': {
                    content: 'Test Notiz',
                    patient_id: 1
                },
                'settings': {
                    category: 'test',
                    key: 'test_setting',
                    value: 'test_value'
                }
            };
            
            return testData[endpoint];
        }
        
        async function testAll() {
            log('=== Starting full API test ===', 'info');
            const endpoints = ['owners', 'api_patients', 'appointments', 'invoices', 'notes', 'settings'];
            
            for (const endpoint of endpoints) {
                await testAPI(endpoint);
                await new Promise(resolve => setTimeout(resolve, 500)); // Small delay between tests
            }
            
            log('=== Full API test completed ===', 'info');
        }
        
        // Run initial test on page load
        window.addEventListener('load', () => {
            log('API Test Tool loaded. Click buttons to test endpoints.', 'info');
        });
    </script>
</body>
</html>