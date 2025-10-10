/**
 * Tierphysio Manager 2.0
 * Patient Detail Modal Handler
 * Uses Alpine.js + anime.js for smooth animations
 */

document.addEventListener("DOMContentLoaded", () => {
  // Initialize patient detail modal functionality
  initPatientDetailModal();
});

/**
 * Initialize patient detail modal click handlers
 */
function initPatientDetailModal() {
  // Handle click on patient cards
  document.addEventListener("click", async (e) => {
    // Check if click is on patient card or its children
    const patientCard = e.target.closest('.patient-card[data-id]');
    
    if (patientCard) {
      // Prevent click on action buttons (edit, delete, etc.)
      if (e.target.closest('button')) {
        return;
      }
      
      e.preventDefault();
      const patientId = patientCard.dataset.id;
      
      if (patientId) {
        await openPatientDetailModal(patientId);
      }
    }
  });
}

/**
 * Open patient detail modal
 * @param {string|number} patientId 
 */
async function openPatientDetailModal(patientId) {
  try {
    // Fetch patient details from API
    const response = await fetch(`/api/patients.php?action=get&id=${patientId}`);
    
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    
    const data = await response.json();
    
    if (data.status === "success" || data.ok === true) {
      // Get patient data
      const patient = data.data?.items?.[0] || data.data?.[0] || data.patient || null;
      
      if (!patient) {
        throw new Error("Patientendaten nicht gefunden");
      }
      
      // Find the modal Alpine.js component
      const modalElement = document.getElementById('patientDetailModal');
      
      if (modalElement && modalElement.__x) {
        // Update Alpine data
        modalElement.__x.$data.patient = patient;
        modalElement.__x.$data.open = true;
        
        // Animate modal with anime.js
        animateModalOpen();
      } else {
        console.error("Modal Element oder Alpine.js Komponente nicht gefunden");
      }
    } else {
      throw new Error(data.message || data.error || "Fehler beim Laden des Patienten");
    }
  } catch (err) {
    console.error("Fehler beim Laden der Patientendaten:", err);
    showNotification("Fehler beim Laden der Patientendaten: " + err.message, "error");
  }
}

/**
 * Animate modal opening with anime.js
 */
function animateModalOpen() {
  const modal = document.querySelector("#patientDetailModalContent");
  
  if (modal && typeof anime !== 'undefined') {
    // Initial state
    anime.set(modal, {
      scale: 0.9,
      opacity: 0
    });
    
    // Animate in
    anime({
      targets: modal,
      scale: [0.9, 1],
      opacity: [0, 1],
      easing: "easeOutExpo",
      duration: 400
    });
  }
}

/**
 * Animate modal closing with anime.js
 */
function animateModalClose(callback) {
  const modal = document.querySelector("#patientDetailModalContent");
  
  if (modal && typeof anime !== 'undefined') {
    anime({
      targets: modal,
      scale: [1, 0.9],
      opacity: [1, 0],
      easing: "easeInExpo",
      duration: 300,
      complete: callback
    });
  } else if (callback) {
    callback();
  }
}

/**
 * Show notification message
 * @param {string} message 
 * @param {string} type - 'success' or 'error'
 */
function showNotification(message, type = 'success') {
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

/**
 * Format date to German format
 * @param {string} dateString 
 * @returns {string}
 */
function formatDate(dateString) {
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
}

/**
 * Calculate age from birth date
 * @param {string} birthDate 
 * @returns {string}
 */
function calculateAge(birthDate) {
  if (!birthDate) return '';
  
  try {
    const birth = new Date(birthDate);
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    const monthDiff = today.getMonth() - birth.getMonth();
    
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
      age--;
    }
    
    if (age === 0) {
      // Calculate months for young animals
      const months = monthDiff + (monthDiff < 0 ? 12 : 0);
      return months === 1 ? '1 Monat' : `${months} Monate`;
    }
    
    return age === 1 ? '1 Jahr' : `${age} Jahre`;
  } catch {
    return '';
  }
}

/**
 * Get species name in German
 * @param {string} species 
 * @returns {string}
 */
function getSpeciesName(species) {
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
}

/**
 * Get gender name in German
 * @param {string} gender 
 * @returns {string}
 */
function getGenderName(gender) {
  const names = {
    'male': 'Männlich',
    'female': 'Weiblich',
    'neutered_male': 'Kastriert (männlich)',
    'spayed_female': 'Kastriert (weiblich)',
    'unknown': 'Unbekannt'
  };
  return names[gender] || 'Unbekannt';
}

// Export functions for global use if needed
window.patientModalFunctions = {
  openPatientDetailModal,
  animateModalClose,
  formatDate,
  calculateAge,
  getSpeciesName,
  getGenderName
};