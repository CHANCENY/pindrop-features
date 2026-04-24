class SupportPanel {
    constructor() {
        const conversations = document.getElementById('conversations-list-data').textContent
        this.conversations = JSON.parse(conversations)

        this.activeConv = this.conversations[0];
        this.socket = null;

        this.agent = {}
        const text = document.getElementById("agent").textContent;
        this.agent = JSON.parse(text);

        this.init();

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

        this.sounds = [
            new Audio('/modules/chat_window/assets/mixkit-long-pop-2358.wav'),
            new Audio('/modules/chat_window/assets/mixkit-software-interface-start-2574.wav')
        ];

        this.sounds.forEach(sound => {
            sound.load();
        });
    }

    init() {
        this.renderConvList();
        this.loadConversation(this.activeConv);
        this.bindEvents();
        this.initSocket();
        setTimeout(()=>{
            this.refreshConversations();
        },3000)
    }

    bindEvents() {
        const sendBtn = document.querySelector('#send-reply');
        const input = document.getElementById('reply-input');
        const closeBtn = document.getElementById('close-con');
        const resolvedBtn = document.getElementById('resolved-ticket');
        const assignBtn = document.getElementById('assign-agent')

        if (sendBtn) {
            sendBtn.addEventListener('click', () => {
                this.sendReply();
            });
        }

        if (input) {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendReply();
                }
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', (e)=>{
                const cid = closeBtn.dataset.cid;
                this.socket.send(JSON.stringify({
                    type: "support",
                    sessionId: this.activeConv.cid,
                    conversationId: this.activeConv.id,
                    message: "Thank you for reaching out closed the ticket",
                    agent: this.agent,
                    action: 'close',
                    id: this.activeConv.id
                }));
            })
        }

        if (resolvedBtn) {
            resolvedBtn.addEventListener('click',(e)=>{
                this.socket.send(JSON.stringify({
                    type: "support",
                    sessionId: this.activeConv.cid,
                    conversationId: this.activeConv.id,
                    message: "Thank you, ticket is resolved",
                    agent: this.agent,
                    action: 'resolved',
                    id: this.activeConv.id
                }));
            })
        }

        if (assignBtn) {
            assignBtn.addEventListener('change',(e)=>{
                const id = e.target.value;
                if (id) {
                    const fullname = `${this.agent.first_name} ${this.agent.last_name}`
                    this.socket.send(JSON.stringify({
                        type: "support",
                        sessionId: this.activeConv.cid,
                        conversationId: this.activeConv.id,
                        message: `Agent ${fullname} has been assigned to attend your ticket`,
                        agent: this.agent,
                        action: 'assigned',
                        id: this.activeConv.id,
                        agentId: id
                    }));
                }

            })
        }
    }

    renderConvList() {
        const el = document.getElementById('conv-list');
        el.innerHTML = '';

        this.conversations.forEach(conv => {
            const div = document.createElement('div');
            div.className = 'conv-item' + (conv.unread?' unread':'') + (conv.id===this.activeConv.id?' active':'');
            div.innerHTML = `
      <div class="conv-item-top">
        <div class="conv-avatar" style="background:${conv.color}">${conv.initials}</div>
        <div class="conv-meta">
          <div class="conv-name">${conv.name}</div>
          <div class="conv-time">${conv.time} ago</div>
        </div>
      </div>
      <div class="conv-preview">${conv.preview}</div>
      <div class="conv-tags"><span class="tag tag-${conv.tag}">${conv.tag}</span></div>
    `;

            div.addEventListener('click', () => {
                this.activeConv = conv;
                this.loadConversation(conv);
            });

            el.appendChild(div);
        });
    }

    loadConversation(conversation) {
        document.getElementById('tb-name').textContent = conversation.name;
        document.getElementById('rp-name').textContent = conversation.name;
        document.getElementById('rp-email').textContent = conversation.email;
        document.getElementById('ct-count').textContent = conversation.tickets_count;
        document.getElementById('ct-joined').textContent = conversation.joined;
        document.getElementById('cs-ticket').textContent = "#"+ conversation.id;
        document.getElementById('cs-tag').textContent = conversation.tag.toLocaleUpperCase()
        document.getElementById('tb-sub').textContent = "#"+ conversation.id
        document.getElementById('tb-tickets').innerHTML = `<div class="dot" style="background:#f59e0b"></div>${conversation.tickets_count} open tickets`;
        document.getElementById('close-con').setAttribute('data-cid', conversation.cid);
        document.getElementById('resolved-ticket').setAttribute('data-id', conversation.id);
        document.getElementById('tb-avatar').setAttribute('style', `background:${conversation.color}`);
        document.getElementById('tb-avatar').textContent = conversation.initials;
        document.getElementById('rp-avatar').setAttribute('style', `background:${conversation.color}`);
        document.getElementById('rp-avatar').textContent = conversation.initials;

        const tbStatus = document.getElementById('tb-status');
        if (conversation?.is_online) {
            tbStatus.innerHTML = `<div class="dot" style="background:#22c55e"></div>Online`;
        }
        else {
            tbStatus.innerHTML = `<div class="dot" style="background:#AD250A"></div>Offline`;
        }

        const area = document.getElementById('messages-area');
        area.innerHTML = '';

        conversation.messages.forEach(message => {
            this.addMessage(
                message.sender,
                message.text,
                message.time
            );
        });
    }

    addMessage(sender, text, time = null) {
        const area = document.getElementById('messages-area');

        const isAgent = sender === 'agent';
        const currentTime = time || new Date().toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit'
        });

        const row = document.createElement('div');
        row.className = `msg-row ${isAgent ? 'agent' : ''}`;

        row.innerHTML = `
            <div class="msg-ava"
                 style="background:${isAgent
            ? 'linear-gradient(135deg,#5b4fcf,#7c6fef)'
            : this.activeConv.color}">
                ${isAgent ? 'AG' : this.activeConv.initials}
            </div>

            <div class="msg-body">
                <div class="msg-sender">
                    ${isAgent ? 'Agent' : this.activeConv.name}
                </div>

                <div class="msg-bubble">${text}</div>

                <div class="msg-time-row">
                    <div class="msg-time">${currentTime}</div>
                </div>
            </div>
        `;

        area.appendChild(row);
        area.scrollTop = area.scrollHeight;
    }

    sendReply() {
        const input = document.getElementById('reply-input');
        const message = input.value.trim();

        if (!message) {
            return;
        }

        // Add agent message to UI immediately
        this.addMessage('agent', message);

        input.value = '';

        // Send to websocket
        if (this.socket && this.socket.readyState === WebSocket.OPEN) {
            this.socket.send(JSON.stringify({
                type: "support",
                sessionId: this.activeConv.cid,
                id: this.activeConv.id,
                message: message,
                agent: this.agent
            }));

        } else {
            console.error("WebSocket is not connected");
        }
    }

    initSocket() {
        this.socket = new WebSocket("ws://localhost:8000");

        this.socket.onopen = () => {
            console.log("Connected to support websocket");

            this.socket.send(JSON.stringify({
                type: "agent_init",
                agent: this.agent,
                sessionId: this.agent.id,
                message: "Agent connection"
            }));
        };

        this.socket.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                if (data?.key === 'normal') {
                    if (data?.content?.message_type === "customer") {
                        const conversation = this.conversations.find(
                            c => c.id === data.content.cid
                        );

                        if (conversation) {
                            this.playMessageNotification()
                            conversation.messages.push({
                                sender: "customer",
                                text: data.content.content,
                                time: new Date().toLocaleTimeString([], {
                                    hour: '2-digit',
                                    minute: '2-digit'
                                })
                            });

                            if (this.activeConv.id === conversation.id) {
                                this.addMessage(
                                    "customer",
                                    data.content.content
                                );
                            }
                        }
                    }
                }
                else if (data?.key === 'conversations') {
                    this.conversations = data.tickets;
                    this.renderConvList();
                }

            } catch (error) {
                console.error(error);
            }
        };

        this.socket.onerror = (error) => {
            console.error("Socket error:", error);
        };

        this.socket.onclose = () => {
            console.log("Socket disconnected");
        };
    }

    refreshConversations() {
        setInterval(()=>{
            this.socket.send(JSON.stringify({
                type: "command",
                sessionId: this.activeConv.cid,
                id: this.activeConv.id,
                message: "Refreshing",
                agent: this.agent,
                action: 'conversations'
            }));
        },3000);
    }

    playMessageNotification(index = 0) {
        const audio = this.sounds[index];

        audio.currentTime = 0;

        audio.play().catch(() => {
            this.playBeep();
        });
    }

    playBeep() {
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

document.addEventListener('DOMContentLoaded', () => {
    new SupportPanel();
});