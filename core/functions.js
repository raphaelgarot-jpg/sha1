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

// On lance le script une fois que le navigateur a fini de charger la structure de la page
document.addEventListener("DOMContentLoaded", startAutoRefresh);