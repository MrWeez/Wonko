const SW_PATH = '/sw.js';

function toBase64(arrayBuffer) {
    const bytes = new Uint8Array(arrayBuffer);
    let binary = '';

    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary);
}

function normalizeKey(byteString) {
    if (typeof byteString !== 'string') {
        return toBase64(byteString);
    }

    return byteString;
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; i++) {
        outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
}

function isPushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

async function currentSubscription() {
    if (!isPushSupported()) {
        return null;
    }

    const registration = await navigator.serviceWorker.ready;

    return registration.pushManager.getSubscription();
}

document.addEventListener('alpine:init', () => {
    Alpine.data('pushBrowserDevices', ({ vapidKey, endpoints }) => ({
        supported: isPushSupported(),
        permission: isPushSupported() ? Notification.permission : 'unsupported',
        currentEndpoint: null,

        get isSubscribed() {
            return this.currentEndpoint !== null && endpoints.includes(this.currentEndpoint);
        },

        async init() {
            if (!this.supported) {
                return;
            }

            try {
                await navigator.serviceWorker.register(SW_PATH);
                const subscription = await currentSubscription();
                this.currentEndpoint = subscription?.endpoint ?? null;
            } catch (error) {
                this.supported = false;
            }
        },

        async enable() {
            try {
                const applicationServerKey = urlBase64ToUint8Array(vapidKey);
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey,
                });

                this.currentEndpoint = subscription.endpoint;

                await $wire.subscribeDevice(
                    subscription.endpoint,
                    normalizeKey(subscription.getKey('p256dh')),
                    normalizeKey(subscription.getKey('auth')),
                );
            } catch (error) {
                this.permission = Notification.permission;
            }
        },

        async disable() {
            const subscription = await currentSubscription();

            if (subscription?.endpoint) {
                await $wire.unsubscribeDevice(subscription.endpoint);
                await subscription.unsubscribe();
            }

            this.currentEndpoint = null;
        },
    }));
});