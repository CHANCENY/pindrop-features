class ChatWidget {
    constructor() {
        this.render(); // inject HTML first

        // elements
        this.fab = document.getElementById('chat-fab');
        this.win = document.getElementById('chat-window');
        this.messagesEl = document.getElementById('chat-messages');
        this.inputEl = document.getElementById('chat-input');
        this.sendBtn = document.getElementById('send-btn');
        this.quickRepliesEl = document.getElementById('quick-replies');
        this.closeBtn = document.getElementById('close-btn');
        this.minimizeBtn = document.getElementById('minimize-btn');

        // state
        this.isOpen = false;
        this.isTyping = false;
        this.typingEl = null;
        this.greeted = false;

        this.botReplies = {
            default: [
                "Thanks for reaching out! Let me connect you with the right information.",
                "Great question! Our team is here to help. Can you share more details?",
                "I understand. Let me look into that for you right away.",
                "Absolutely! I'd be happy to help you with that.",
                "Got it! Give me a moment to pull up the details for you.",
            ],
            hello: "Hey there! 👋 Welcome to support. How can I help you today?",
            hi: "Hi! Great to have you here. What can I help you with?",
            pricing: "Our plans start at $9/mo for Basic, $29/mo for Pro, and custom Enterprise pricing.",
            refund: "Refund requests are processed within 3–5 business days. Can I get your order number?",
            bug: "Sorry you're experiencing a bug! Could you describe it and your browser/device?",
            thanks: "You're very welcome! 😊 Is there anything else?",
            bye: "Take care! We're always here. 👋",
        };

        this.quickOptions = [
            "Pricing & Plans",
            "Request Refund",
            "Report a Bug",
            "Track Order",
        ];

        this.userData = {
            firstName: '',
            lastName: '',
            email: ''
        };

        this.formStep = 'firstName'; // firstName → lastName → email → done
        this.sessionId = null;
        this.ticketId = null;

        this.initEvents();

        this.audioCtx = null;

        // Unlock audio on first user interaction
        document.addEventListener('click', () => {
            if (!this.audioCtx) {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                this.audioCtx = new AudioContext();
            }

            if (this.audioCtx.state === 'suspended') {
                this.audioCtx.resume();
            }
        }, { once: true });

    }

    // inject HTML into page
    render() {
        document.body.insertAdjacentHTML('beforeend', `
    <div class="chat-widget">

        <!-- FAB Button -->
        <button id="chat-fab" aria-label="Open chat">
            <svg class="icon-chat" width="24" height="24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
            </svg>
            <svg class="icon-close" width="20" height="20" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" viewBox="0 0 24 24">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>

        <!-- Chat Window -->
        <div id="chat-window" role="dialog" aria-label="Support Chat">

            <!-- Header -->
            <div class="chat-header">
                <div class="chat-header-avatar">
                    <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                    </svg>
                </div>

                <div class="chat-header-info">
                    <div class="chat-header-name">Support Team</div>
                    <div class="chat-header-status">
                        <span class="status-dot"></span>
                        <span>Online • Usually replies instantly</span>
                    </div>
                </div>

                <div class="chat-header-actions">
                    <button class="hdr-btn" id="minimize-btn" title="Minimize">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                    </button>

                    <button class="hdr-btn" id="close-btn" title="Close">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" viewBox="0 0 24 24">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Messages -->
            <div class="chat-messages" id="chat-messages"></div>

            <!-- Quick Replies -->
            <div class="quick-replies" id="quick-replies"></div>

            <!-- Input -->
            <div class="chat-input-area">
                <div class="chat-input-row">
                    <textarea id="chat-input" rows="1" placeholder="Type a message?" aria-label="Message input"></textarea>

                    <button id="send-btn" aria-label="Send message">
                        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <line x1="22" y1="2" x2="11" y2="13"/>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                    </button>
                </div>

                <div class="input-footer">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                        <path d="M7 11V7a5 5 0 0110 0v4"/>
                    </svg>
                    Secured • Powered by Support AI
                </div>
            </div>

        </div>
    </div>
    `);
    }

    initEvents() {
        this.fab.addEventListener('click', () => this.isOpen ? this.closeChat() : this.openChat());
        this.closeBtn.addEventListener('click', () => this.closeChat());
        this.minimizeBtn.addEventListener('click', () => this.closeChat());

        this.sendBtn.addEventListener('click', () => this.sendMessage(this.inputEl.value));

        this.inputEl.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage(this.inputEl.value);
            }
        });

        this.inputEl.addEventListener('input', () => {
            this.inputEl.style.height = 'auto';
            this.inputEl.style.height = Math.min(this.inputEl.scrollHeight, 100) + 'px';
            this.sendBtn.classList.toggle('active', this.inputEl.value.trim().length > 0);
        });
    }

    getTime() {
        return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    addMessage(text, sender = 'bot') {
        const msg = document.createElement('div');
        msg.className = `msg ${sender}`;

        const initials = sender === 'bot' ? 'AI' : 'You';

        msg.innerHTML = `
            <div class="msg-avatar">${initials}</div>
            <div>
                <div class="msg-bubble">${text}</div>
                <div class="msg-time">${this.getTime()}</div>
            </div>
        `;

        this.messagesEl.appendChild(msg);
        this.scrollToBottom();
    }

    showTyping() {
        if (this.isTyping) return;

        this.isTyping = true;

        const wrapper = document.createElement('div');
        wrapper.className = 'msg bot';
        wrapper.id = 'typing-indicator';

        wrapper.innerHTML = `
            <div class="msg-avatar">AI</div>
            <div class="typing-bubble">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
        `;

        this.messagesEl.appendChild(wrapper);
        this.typingEl = wrapper;
        this.scrollToBottom();
    }

    hideTyping() {
        if (this.typingEl) {
            this.typingEl.remove();
            this.typingEl = null;
        }
        this.isTyping = false;
    }

    scrollToBottom() {
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    }

    getBotReply(userText) {
        const lower = userText.toLowerCase();

        if (/\b(hi|hello|hey)\b/.test(lower)) return this.botReplies.hi;
        if (/pricing|plan|cost|price/.test(lower)) return this.botReplies.pricing;
        if (/refund|money back|return/.test(lower)) return this.botReplies.refund;
        if (/bug|error|crash|issue/.test(lower)) return this.botReplies.bug;
        if (/thank/.test(lower)) return this.botReplies.thanks;
        if (/bye|goodbye/.test(lower)) return this.botReplies.bye;

        const arr = this.botReplies.default;
        return arr[Math.floor(Math.random() * arr.length)];
    }

    botRespond(text) {
        const delay = 800 + Math.random() * 700;

        this.showTyping();

        setTimeout(() => {
            this.hideTyping();
            this.addMessage(text);
        }, delay);
    }

    setQuickReplies(options) {
        this.quickRepliesEl.innerHTML = '';

        options.forEach(opt => {
            const btn = document.createElement('button');
            btn.className = 'quick-reply-btn';
            btn.textContent = opt;
            btn.addEventListener('click', () => this.sendMessage(opt));
            this.quickRepliesEl.appendChild(btn);
        });
    }

    clearQuickReplies() {
        this.quickRepliesEl.innerHTML = '';
    }

    openChat() {
        this.isOpen = true;
        this.fab.classList.add('open');
        this.win.classList.add('visible');
        this.inputEl.focus();

        if (!this.greeted) {
            this.greeted = true;

            setTimeout(() => {
                this.addMessage("👋 Hi! Before we start, what’s your first name?");
            }, 400);
        }
    }

    closeChat() {
        this.isOpen = false;
        this.fab.classList.remove('open');
        this.win.classList.remove('visible');
    }

    async handleForm(input) {
        switch (this.formStep) {
            case 'firstName':
                this.userData.firstName = input;
                this.formStep = 'lastName';

                this.botRespond("Nice to meet you! What’s your last name?");
                break;

            case 'lastName':
                this.userData.lastName = input;
                this.formStep = 'email';

                this.botRespond("Great 👍 What’s your email address?");
                break;

            case 'email':
                if (!this.validateEmail(input)) {
                    this.botRespond("That doesn’t look like a valid email. Try again?");
                    return;
                }

                this.userData.email = input;
                this.formStep = 'done';

                this.botRespond(`Thanks ${this.userData.firstName}! Let me connect you to support...`);

                // 👉 send to backend / store
                if (await this.submitUserData()) {

                    // initialize websocket connection
                    this.initSocket();

                    // 👉 enable quick replies after onboarding
                    setTimeout(() => {
                        this.setQuickReplies(this.quickOptions);
                    }, 500);
                }
                else {
                    alert("Failed to start your session maybe try again later.")
                }

                break;
        }
    }

    validateEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    async submitUserData() {

       const response = await send('/api/chat/customer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: this.userData
        });
       const json = await response.json();

       if (json.customerId) {
           this.sessionId = json.customerId;
           return true;
       }

       return false;
    }

    initSocket() {
        this.socket = new WebSocket("ws://localhost:8000");

        this.socket.onopen = () => {
            console.log("Connected to server");
            this.addMessage("Connected to support server", "bot");

            this.socket.send(JSON.stringify({
                type: "init",
                sessionId: this.sessionId,
                user: this.userData
            }));
        };

        this.socket.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                if (data?.ticket) {
                    this.ticketId = data.ticket
                }
                this.addMessage(data.message, "bot");
                this.playBeepNotification()
            } catch (e) {
                this.addMessage(event.data, "bot");
            }
        };

        this.socket.onerror = (error) => {
            console.error("Socket error:", error);
            this.addMessage("Connection error occurred", "bot");
        };

        this.socket.onclose = () => {
            console.log("Connection closed");
            this.addMessage("Disconnected from support server", "bot");
        };
    }

    async sendMessage(text) {
        text = text.trim();
        if (!text) return;

        this.addMessage(text, 'user');

        this.inputEl.value = '';
        this.inputEl.style.height = 'auto';
        this.sendBtn.classList.remove('active');

        // Handle onboarding form first
        if (this.formStep !== 'done') {
            await this.handleForm(text);
            return;
        }

        // Normal chat after onboarding
        this.clearQuickReplies();

        // Send message to websocket server
        if (this.socket && this.socket.readyState === WebSocket.OPEN) {
            this.socket.send(JSON.stringify({
                type: "customer",
                sessionId: this.sessionId,
                user: this.userData,
                message: text,
                id: this.ticketId
            }));
            this.playBeepNotification()
        } else {
            this.addMessage("Unable to send message. Connection is not available.", "bot");
        }
    }

    playBeepNotification() {
        if (!this.audioCtx) return; // Not unlocked yet

        const oscillator = this.audioCtx.createOscillator();
        const gainNode = this.audioCtx.createGain();

        oscillator.connect(gainNode);
        gainNode.connect(this.audioCtx.destination);

        oscillator.type = 'sine';
        oscillator.frequency.value = 660;
        gainNode.gain.value = 0.1;

        oscillator.start();
        oscillator.stop(this.audioCtx.currentTime + 0.1);
    }
}

// run on load
document.addEventListener('DOMContentLoaded', () => {
    new ChatWidget();
});