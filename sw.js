self.addEventListener('push', function(event) {
    const data = event.data ? event.data.json() : { title: 'Alerte', body: 'Nouveau message', badge: 1 };

    // 1. Affichage de la notification
    const promiseChain = self.registration.showNotification(data.title, {
        body: data.body,
        icon: '/assets/img/favicon.png',
        badge: '/assets/img/favicon.png'
    });

    // 2. Mise à jour de la pastille (Badge)
    if (navigator.setAppBadge && data.badge !== undefined) {
        navigator.setAppBadge(data.badge).catch(error => {
            console.error("Erreur badge:", error);
        });
    }

    event.waitUntil(promiseChain);
});
