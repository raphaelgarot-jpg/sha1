/**
 * S.H.A. 2026 - Fonctions JavaScript Core
 */

// --- ÉTAT GLOBAL ---
window.isTaskModalOpen = false;

// ==========================================
// AUTO-REFRESH INVISIBLE (AJAX)
// ==========================================
function startAutoRefresh() {
    const refreshInterval = 10000; 

    if (!document.querySelector('.container')) return;

    setInterval(() => {
        if (window.isTaskModalOpen) {
            return;
        }

        const currentUrl = window.location.href;

        fetch(currentUrl, { cache: "no-store" })
            .then(response => {
                if (!response.ok) throw new Error("Erreur réseau");
                return response.text();
            })
            .then(html => {
                if (window.isTaskModalOpen) return;

                const parser = new DOMParser();
                const doc = parser.parseFromString(html, "text/html");

                const newContainer = doc.querySelector('.container');
                const currentContainer = document.querySelector('.container');

                if (newContainer && currentContainer) {
                    currentContainer.innerHTML = newContainer.innerHTML;
                }
            })
            .catch(error => {
                console.warn("Erreur silencieuse lors du refresh AJAX:", error);
            });
    }, refreshInterval);
}

// ==========================================
// CONTRÔLE DES APPAREILS (STECKDOSEN / LIGHTS)
// ==========================================
function initDeviceToggles() {
    document.addEventListener('click', function(event) {
        var btn = event.target.closest('.toggle-btn');
        
        // Stoppe l'exécution si ce n'est pas un contrôleur d'appareil
        if (!btn || !btn.hasAttribute('data-state')) return;

        var ip = btn.getAttribute('data-ip');
        var relay = btn.getAttribute('data-relay');
        var currentState = btn.getAttribute('data-state');
        var label = btn.getAttribute('data-label');
        var type = btn.getAttribute('data-type') || 'socket'; 
        var mqtt_name = btn.getAttribute('data-mqtt') || '';  

        var nextAction = (currentState === 'ON') ? 'OFF' : 'ON';
        var devRow = btn.closest('.dev-row');

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
        formData.append('type', type);        
        formData.append('mqtt_name', mqtt_name); 

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

function updateDeviceUI(btn, devRow, state) {
    var statusText = devRow ? devRow.querySelector('.status-text') : null;

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

// DIMMER LOGIC
document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('toggle-btn')) {
        const btn = e.target;
        const type = btn.getAttribute('data-type');
        const ip = btn.getAttribute('data-ip');
        const currentState = btn.getAttribute('data-state');
        const isDimmable = btn.getAttribute('data-dimmable') === '1'; 

        if ((type === 'light' || type === 'light_p') && isDimmable) {
            const row = btn.closest('.dev-row');
            if (!row) return;

            const statusContainer = row.querySelector('.status-container');
            if (!statusContainer) return;

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
            else if (currentState === 'ON') {
                const dimmerBlock = statusContainer.querySelector('.direct-dimmer-block');
                if (dimmerBlock) {
                    dimmerBlock.remove();
                }
            }
        }
    }
});

function sendOBKDimmer(ip, action, value) {
    const formData = new FormData();
    formData.append('ip', ip);
    formData.append('action', action);
    formData.append('value', value);

    fetch('steckdose.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        console.log('S.H.A. Gradation envoyée :', action, value + '%');
    })
    .catch(err => {
        console.error('Erreur gradation S.H.A. :', err);
    });
}

// ==========================================
// VOLETS ROULANTS (ROLLÄDEN)
// ==========================================
function sendRoll(master, slave, state, btn) {
    if (btn) btn.classList.add('loading');
    fetch(`scripts/sendst.php?master=${master}&slave=${slave}&state=${state}&return=rolladen&ajax=1`)
    .then(response => {
        setTimeout(() => {
            if (btn) btn.classList.remove('loading');
        }, 500);
    })
    .catch(err => {
        if (btn) btn.classList.remove('loading');
        console.error("Erreur SHA Roll:", err);
    });
}

// ==========================================
// SYSTÈME CLIMATIQUE (HEIZUNG)
// ==========================================
function triggerScan() {
    const overlay = document.createElement('div');
    overlay.style = 'display:flex; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(35,28,27,0.96); z-index:99999; flex-direction:column; justify-content:center; align-items:center; color:#f7f5f5; font-family: "Segoe UI", system-ui, sans-serif;';
    overlay.innerHTML = `
        <div style="font-size:4.5rem; animation:volcanicRotation 2.5s linear infinite; margin-bottom:20px; filter: drop-shadow(0 0 15px rgba(255,145,0,0.3));">⚙️</div>
        <div style="text-align:center;">
            <h2 style="font-size:1.2rem; font-weight:900; letter-spacing:2px; color:var(--orange); text-transform:uppercase; margin:0 0 5px 0;">CUBE HARD SCAN...</h2>
            <p style="font-size:0.8rem; color:#bcaaa4; margin:0 0 15px 0;">Synchronisation des vannes radio EQ-3</p>
            <div style="font-size:2.5rem; color:var(--red); font-weight:900;"><span id="scan-timer">11</span>s</div>
        </div>
        <style>
            @keyframes volcanicRotation { from { transform:rotate(0deg) } to { transform:rotate(360deg) } }
        </style>
    `;
    document.body.appendChild(overlay);
    
    let countdown = 11;
    const interval = setInterval(() => { 
        countdown--; 
        if (countdown >= 0) {
            const timerEl = document.getElementById('scan-timer');
            if(timerEl) timerEl.innerText = countdown;
        } else {
            clearInterval(interval);
        }
    }, 1000);
    
    setTimeout(() => { 
        window.location.href = "heiz.php?scan=1"; 
    }, 150);
}

function setRoomTemperature(rfId, tempValue, event) {
    const btn = event.currentTarget;
    const originalText = btn.innerText;
    
    btn.innerText = "⏳";
    btn.disabled = true;

    fetch(`heiz.php?ajax_cmd=1&fct=temp&id=${rfId}&temp=${tempValue}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                btn.innerText = "✓";
                btn.style.background = "var(--green)";
                setTimeout(() => {
                    btn.innerText = originalText;
                    btn.style.background = "";
                    btn.disabled = false;
                }, 1200);
            }
        })
        .catch(err => {
            btn.innerText = "❌";
            console.error("Erreur d'envoi de consigne :", err);
        });
}

function triggerRoomBoost(rfId, event) {
    const btn = event.currentTarget;
    btn.innerText = "BOOSTING...";
    btn.classList.add("active");
    btn.disabled = true;

    fetch(`heiz.php?ajax_cmd=1&fct=mode&id=${rfId}&temp=BOOST`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                console.log(`Ordre Boost enregistré pour la vanne ${rfId}`);
            }
        })
        .catch(err => {
            btn.innerText = "BOOST";
            btn.classList.remove("active");
            btn.disabled = false;
            console.error("Erreur d'activation Boost :", err);
        });
}

// ==========================================
// HAUSHALT (TÂCHES) - GESTION MODALE
// ==========================================
function formatToDatetimeLocal(timestamp) {
    if (!timestamp) return ''; 
    const d = new Date(timestamp * 1000);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

function toggleTaskModal(state) {
    document.getElementById('taskModal').style.display = state ? 'flex' : 'none';
    window.isTaskModalOpen = state; 
}

function promptNewTask(room) {
    document.getElementById('taskForm').reset();
    document.getElementById('modalTitle').innerText = 'Nouvelle tâche : ' + room;
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalRoom').value = room;
    document.getElementById('modalId').value = '';
    
    document.getElementById('modalLastDone').value = formatToDatetimeLocal(Math.floor(Date.now() / 1000));
    toggleTaskModal(true);
}

function editTask(room, id, label, effort, freqVal, freqUnit, comment, lastDoneTs) {
    document.getElementById('modalTitle').innerText = 'Modifier : ' + room;
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalRoom').value = room;
    document.getElementById('modalId').value = id;
    
    document.getElementById('modalLabel').value = label;
    document.getElementById('modalEffort').value = effort;
    document.getElementById('modalFreqVal').value = freqVal;
    document.getElementById('modalFreqUnit').value = freqUnit;
    document.getElementById('modalComment').value = comment;
    document.getElementById('modalLastDone').value = formatToDatetimeLocal(lastDoneTs);

    toggleTaskModal(true);
}

function submitTaskForm(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('taskForm'));
    
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(async (r) => {
            if (!r.ok) throw new Error("Erreur HTTP : " + r.status);
            const text = await r.text();
            try {
                return JSON.parse(text);
            } catch (err) {
                console.error("Réponse PHP corrompue (HTML/Erreur au lieu de JSON) :", text);
                throw new Error("PHP a renvoyé une erreur non-JSON. Regarde la console (F12).");
            }
        })
        .then(res => { 
            if (res && res.success) {
                toggleTaskModal(false);
                location.reload(); 
            } else {
                console.error("Erreur logique PHP :", res);
                alert("Le serveur n'a pas renvoyé de succès.");
            }
        })
        .catch(err => {
            console.error("Échec de la soumission AJAX :", err);
            alert(err.message);
        });
}

function doneTask(room, id) {
    const fd = new FormData();
    fd.append('action', 'done'); fd.append('room', room); fd.append('id', id);
    fetch(window.location.href, {method:'POST', body:fd}).then(()=>location.reload());
}

function deleteTask(room, id) {
    if(!confirm('Supprimer cette tâche ?')) return;
    const fd = new FormData();
    fd.append('action', 'delete'); fd.append('room', room); fd.append('id', id);
    fetch(window.location.href, {method:'POST', body:fd}).then(()=>location.reload());
}

// ==========================================
// UI & NOTIFICATIONS SÉCURISÉES (SERVICE WORKER)
// ==========================================
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
       navigator.serviceWorker.register('/sw.js')
            .then(registration => {
                console.log('Service Worker enregistré avec succès:', registration);
            })
            .catch(error => {
                console.error('Échec de l\'enregistrement du Service Worker:', error);
        });
    });
}

window.addEventListener('load', () => {
    if ('setAppBadge' in navigator) {
        navigator.setAppBadge(0).catch((error) => {
            console.error("Erreur nettoyage badge:", error);
        });
    }
});

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

document.addEventListener("click", function(e) {
    const glossary = e.target.closest('.ashes-glossary');
    
    if (glossary) {
        e.stopPropagation(); 
        document.querySelectorAll('.ashes-glossary').forEach(el => {
            if (el !== glossary) el.classList.remove('active');
        });
        glossary.classList.toggle('active');
    } else {
        document.querySelectorAll('.ashes-glossary').forEach(el => el.classList.remove('active'));
    }
});

// ==========================================
// AMORÇAGE
// ==========================================
document.addEventListener("DOMContentLoaded", initDeviceToggles);
document.addEventListener("DOMContentLoaded", startAutoRefresh);