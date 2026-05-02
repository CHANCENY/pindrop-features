async function enableNotification(settings) {

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') return;

    // Register SW properly
    const registration = await navigator.serviceWorker.register( '/modules/push_notification/assets/sw.js',
    { scope: '/' });

     console.log('sw 1');

    // Wait until ready
    const sw = await navigator.serviceWorker.ready;


     console.log('sw 2');

    // Get VAPID key
    const applicationServerKey = await getVapidPublicKey();

    try {
        const subscription = await sw.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey
        });

        const response = await fetch('/push/notifiction/settings/user', {
            method: 'PUT',
            body: JSON.stringify(subscription),
            headers: {
                'Content-Type': 'application/json'
            }
        });

        const results = await response.json();
        console.log(results);

    } catch (error) {
        console.error("Subscription failed:", error);
    }
}

async function getVapidPublicKey() {
  const response = await fetch("/push/notifiction/vapid/token");
  const settings = await response.json();
  return settings?.publicKey;
}

async function subscriberNowRedirect(settings) {
    console.log(settings)
    const url = settings?.subscribe_link;
    if (url) {
        // open in new tab
        const a = document.createElement('a');
        a.target = '_blank';
        a.href = url;
        a.id = 'hidden-one';
        if (document.getElementById('hidden-one')) document.getElementById('hidden-one').remove();
        document.body.appendChild(a);
        a.click();
    }
    else {
        console.log('no link')
    }
}

async function init() {
  const response = await fetch("/push/notifiction/settings/configurations");
  const settings = await response.json();

  if (settings?.is_enabled === "1" && settings?.is_login) {
    injectSubscribeButton(subscriberNowRedirect, settings)
  }
}

function showNotificationModal(settings) {

    // prevent duplicate modal
    if (document.getElementById('push-modal')) document.getElementById('push-modal').remove();

    const modalHTML = `
        <div id="push-modal" class="push-modal-overlay">
            <div class="push-modal">
                <h3>${settings.title}</h3>
                <p>Subscribe to receive notifications.</p>

                <div class="push-modal-actions">
                    <button id="push-subscribe-btn">Subscribe</button>
                    <button id="push-close-btn">Cancel</button>
                </div>
            </div>
        </div>
    `;

    // inject into DOM

    document.body.insertAdjacentHTML('beforeend', modalHTML);

    const modal = document.getElementById('push-modal');
    const subscribeBtn = document.getElementById('push-subscribe-btn');
    const closeBtn = document.getElementById('push-close-btn');

    // subscribe listener
    subscribeBtn.addEventListener('click', async () => {
        subscribeBtn.disabled = true;
        subscribeBtn.innerText = "Subscribing...";

        try {
            await enableNotification(settings); // your existing function
            closeModal();
        } catch (e) {
            console.error(e);
            subscribeBtn.disabled = false;
            subscribeBtn.innerText = "Subscribe";
        }
    });

    // close modal
    closeBtn.addEventListener('click', closeModal);

    // click outside to close
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    function closeModal() {
        modal.remove();
    }
}

function injectSubscribeButton(onClick, settings) {

    if (settings?.is_subscribed === true) return;
    // prevent duplicates
    if (document.getElementById('push-subscribe-edge-btn')) document.getElementById('push-subscribe-edge-btn').remove();

    const html = `
        <div id="push-subscribe-edge-btn" class="push-edge-btn">
            Subscribe Now
        </div>
    `;

    document.body.insertAdjacentHTML('beforeend', html);

    const btn = document.getElementById('push-subscribe-edge-btn');

    // click listener
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        if (typeof onClick === 'function') {
            onClick(settings);
        }
    });
}

init();
