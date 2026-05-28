/**
 * SHA 2026 - Contrôle AJAX des Volets
 * Ce code est du JavaScript pur, exécuté par le navigateur.
 */
function sendRoll(master, slave, state, btn) {
    // 1. Feedback visuel : on grise le bouton
    if (btn) btn.classList.add('loading');

    // 2. Appel asynchrone (AJAX)
    // On utilise les "backticks" (touche alt gr + 7) pour injecter les variables
    fetch(`scripts/sendst.php?master=${master}&slave=${slave}&state=${state}&return=rolladen&ajax=1`)
    .then(response => {
        // 3. On retire l'effet "loading" après 500ms
        setTimeout(() => {
            if (btn) btn.classList.remove('loading');
        }, 500);
    })
    .catch(err => {
        if (btn) btn.classList.remove('loading');
        console.error("Erreur SHA Roll:", err);
    });
}

// --- SERVICE WORKER & NOTIFICATIONS SÉCURISÉ ---
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // 💡 On force le chemin absolu /sha/sw.js et on verrouille le scope au sous-dossier
        navigator.serviceWorker.register('/sha/sw.js', { scope: '/sha/' })
            .then(registration => {
                console.log('Service Worker enregistré avec succès:', registration);
            })
            .catch(error => {
                console.error('Échec de l\'enregistrement du Service Worker:', error);
        });
    });
}

// 1. Nettoyage du badge iOS à l'ouverture de l'app
window.addEventListener('load', () => {
    if ('setAppBadge' in navigator) {
        navigator.setAppBadge(0).catch((error) => {
            console.error("Erreur nettoyage badge:", error);
        });
    }
});

// Nouvelle fonction de nettoyage globale
function clearBadge() {
    if (navigator.clearAppBadge) {
        navigator.clearAppBadge().catch(e => console.error(e));
    }
}

  async function subscribeUser() {
            const PUBLIC_VAPID_KEY = 'BHcrWpFdWmmKDpda9RjhkoMwKQUuF1cAKjgYmJM1QDWvAdPNs9FkhW99xvIMXsIK7xGGAac_l5yHkmiD2bAXaKg';
            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(PUBLIC_VAPID_KEY)
                });
                const response = await fetch('scripts/save_sub.php', {
                    method: 'POST',
                    body: JSON.stringify(subscription),
                    headers: { 'Content-Type': 'application/json' }
                });
                const result = await response.json();
                alert(result.message);
                if(typeof toggleSHA === "function") toggleSHA();
            } catch (e) {
                alert("Erreur abonnement : " + e.message);
                console.error(e);
            }
        }

        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
        }

function toggleSHA() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    sidebar.classList.toggle('active');
    overlay.classList.toggle('active');
    document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
}

// ==========================================
// AUTO-REFRESH INVISIBLE (AJAX)
// ==========================================

function startAutoRefresh() {
    // Délai de rafraîchissement (10 secondes = 10000 ms)
    const refreshInterval = 10000; 

    // On s'assure de ne lancer l'auto-refresh que si l'élément .container existe
    if (!document.querySelector('.container')) return;

    setInterval(() => {
        // On récupère l'URL de la page actuelle (ex: strom.php ou rolladen.php)
        const currentUrl = window.location.href;

        // On lance la requête en arrière-plan en forçant le contournement du cache navigateur
        fetch(currentUrl, { cache: "no-store" })
            .then(response => {
                if (!response.ok) throw new Error("Erreur réseau");
                return response.text();
            })
            .then(html => {
                // On transforme le texte reçu en véritable document HTML
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, "text/html");

                // On cible la zone principale (le container)
                const newContainer = doc.querySelector('.container');
                const currentContainer = document.querySelector('.container');

                // Si la requête a réussi et que la structure est bonne, on remplace le contenu
                if (newContainer && currentContainer) {
                    currentContainer.innerHTML = newContainer.innerHTML;
                }
            })
            .catch(error => {
                console.warn("Erreur silencieuse lors du refresh AJAX:", error);
                // On ne bloque pas l'UI, le prochain essai se fera dans 10s
            });
    }, refreshInterval);
}

/**
 * Initialise le contrôle des boutons via délégation d'événement (Compatible PC, Sockets & Lights)
 */
function initDeviceToggles() {
    document.addEventListener('click', function(event) {
        var button = event.target.closest('.toggle-btn');
        if (!button) return;

        var btn = button;
        var ip = btn.getAttribute('data-ip');
        var relay = btn.getAttribute('data-relay');
        var currentState = btn.getAttribute('data-state');
        var label = btn.getAttribute('data-label');
        var type = btn.getAttribute('data-type') || 'socket'; // 💡 Récupération du type de périphérique
        var mqtt_name = btn.getAttribute('data-mqtt') || '';  // 💡 AJOUT : Récupération du nom MQTT depuis le HTML

        var nextAction = (currentState === 'ON') ? 'OFF' : 'ON';
        var devRow = btn.closest('.dev-row');

        // 🔐 DEUTSCHE SICHERHEIT : Schutz vor Missklicks beim Ausschalten
        if (nextAction === 'OFF') {
            var confirmCut = confirm("⚠️ S.H.A. Sicherheit: Sind Sie sicher, dass Sie das Gerät \"" + label + "\" AUSSCHALTEN möchten?");
            if (!confirmCut) return;
        }

        btn.style.opacity = "0.5";
        btn.disabled = true;

        var formData = new FormData();
        formData.append('action', nextAction);
        formData.append('ip', ip);
        formData.append('relay', relay);
        formData.append('type', type);         // 💡 Envoi du type de périphérique à functions.php
        formData.append('mqtt_name', mqtt_name); // 💡 AJOUT : Envoi du nom MQTT à functions.php

        fetch('steckdose.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) throw new Error("HTTP-Fehler " + response.status);
            return response.text();
        })
        .then(text => {
            btn.style.opacity = "1";
            btn.disabled = false;

            try {
                var cleanJson = text.substring(text.indexOf('{'), text.lastIndexOf('}') + 1);
                var data = JSON.parse(cleanJson);
            } catch(e) {
                var data = { success: true, new_state: nextAction };
            }

            if (data.success) {
                updateDeviceUI(btn, devRow, data.new_state);
            } else {
                alert("❌ Fehlgeschlagen: " + (data.message || "Kommunikationsfehler."));
            }
        })
        .catch(error => {
            btn.style.opacity = "1";
            btn.disabled = false;
            updateDeviceUI(btn, devRow, nextAction);
        });
    });
}

/**
 * Met à jour l'interface graphique d'une ligne de périphérique
 */
function updateDeviceUI(btn, devRow, state) {
    var statusText = devRow.querySelector('.status-text');

    if (state === 'ON') {
        btn.className = "toggle-btn btn-on";
        btn.textContent = "OFF";
        btn.setAttribute('data-state', 'ON');

        if (devRow) devRow.className = "dev-row state-on";
        if (statusText) {
            statusText.className = "status-text on";
            statusText.innerHTML = "🟢 ON";
        }
    } else {
        btn.className = "toggle-btn btn-off";
        btn.textContent = "ON";
        btn.setAttribute('data-state', 'OFF');

        if (devRow) devRow.className = "dev-row state-off";
        if (statusText) {
            statusText.className = "status-text off";
            statusText.innerHTML = "⚫ OFF";
        }
    }
}

/**
 * S.H.A. 2026 - Fonctions JavaScript Core
 */

// Écouteur global pour intercepter le clic sur le bouton ON/OFF général
document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('toggle-btn')) {
        const btn = e.target;
        const type = btn.getAttribute('data-type');
        const ip = btn.getAttribute('data-ip');
        const currentState = btn.getAttribute('data-state'); // "ON" ou "OFF"
        
        // 💡 AJOUT : On vérifie si la lampe est dimmable (vaut "1")
        const isDimmable = btn.getAttribute('data-dimmable') === '1'; 

        // 💡 MODIFICATION : On restreint l'action aux lampes gradables uniquement
        if (type === 'light' && isDimmable) {
            const row = btn.closest('.dev-row');
            if (!row) return;

            const statusContainer = row.querySelector('.status-container');
            if (!statusContainer) return;

            // 🚀 ACTION INSTANTANÉE AU CLIC SUR "ON" : On injecte la tirette à 100%
            if (currentState === 'OFF') {
                if (!statusContainer.querySelector('.direct-dimmer-block')) {
                    const dimmerHtml = `
                        <span class="direct-dimmer-block" style="display: inline-flex; align-items: center; gap: 8px;">
                            <input type="range" min="0" max="100" value="100" style="width: 90px; accent-color: #ff9800; margin: 0; cursor: pointer;" oninput="this.nextElementSibling.innerText = this.value + '%'" onchange="sendOBKDimmer('${ip}', 'dimmer', this.value)">
                            <span style="font-size: 0.7rem; font-weight: bold; color: #ff9800; min-width: 32px; text-align: right;">100%</span>
                        </span>
                    `;
                    statusContainer.insertAdjacentHTML('beforeend', dimmerHtml);
                }
            }
            // 🚀 ACTION INSTANTANÉE AU CLIC SUR "OFF" : On vire la tirette immédiatement
            else if (currentState === 'ON') {
                const dimmerBlock = statusContainer.querySelector('.direct-dimmer-block');
                if (dimmerBlock) {
                    dimmerBlock.remove();
                }
            }
        }
    }
});

/**
 * Transmet la valeur de la réglette d'intensité en temps réel à handle_device_action()
 */
function sendOBKDimmer(ip, action, value) {
    const formData = new FormData();
    formData.append('ip', ip);
    formData.append('action', action); // 'dimmer'
    formData.append('value', value);

    fetch('steckdose.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        console.log('S.H.A. Gradation envoyée avec succès :', action, value + '%');
    })
    .catch(err => {
        console.error('Erreur lors de l\'envoi de la gradation S.H.A. :', err);
    });
}

// Amorçage des scripts globaux
document.addEventListener("DOMContentLoaded", initDeviceToggles);
document.addEventListener("DOMContentLoaded", startAutoRefresh);