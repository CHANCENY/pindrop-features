self.addEventListener("push", (event) => {
    const notification = event.data.json();
    // {"title":"Hi" , "body":"something amazing!" , "url":"./?message=123"}
   
    let otherOptions = {};
    if (notification?.settings?.push_sound === '1') {
        otherOptions.silent = false;
    }

    if (notification?.settings?.vibrate === '1') {

        if (otherOptions?.silent === true) {
            otherOptions['vibrate'] = [200, 100, 200, 100, 200, 100, 200];
        }
    }

    event.waitUntil(self.registration.showNotification(notification.title, {
        body: notification.body,
        icon: notification?.settings?.icon_path,
        data: {
            notifURL: notification.url
        },
        ...otherOptions,
    }));
});

self.addEventListener("notificationclick", (event) => {
    event.waitUntil(clients.openWindow(event.notification.data.notifURL));
});