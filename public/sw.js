self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data?.json() ?? {};
    } catch (error) {
        payload = {};
    }

    const title = payload.title ?? 'Wonko';
    const options = {
        body: payload.body ?? '',
        icon: payload.icon ?? '/apple-touch-icon.png',
        badge: payload.badge ?? '/favicon.ico',
        actions: payload.actions ?? [],
        data: {
            url: payload.data?.url ?? '/',
        },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data?.url ?? '/';

    event.waitUntil(
        (async () => {
            const windowClients = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });

            for (const client of windowClients) {
                if ('focus' in client) {
                    await client.navigate(url);
                    await client.focus();

                    return;
                }
            }

            if ('openWindow' in self.clients) {
                await self.clients.openWindow(url);
            }
        })(),
    );
});