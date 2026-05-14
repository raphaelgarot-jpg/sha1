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

// --- SERVICE WORKER & NOTIFICATIONS ---
if ('serviceWorker' in navigator) {
window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js')
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