@extends('layouts.app')
@section('title', 'Chat — Enterprise KB')

@section('body')
<div x-data="chatApp()" x-init="init()" x-cloak class="h-full flex">

    {{-- ===== SIDEBAR ===== --}}
    <aside :class="sidebarOpen ? 'w-72' : 'w-0'" class="bg-gray-900 text-white flex flex-col transition-all duration-200 overflow-hidden flex-shrink-0">
        <div class="p-4 flex items-center justify-between border-b border-gray-700">
            <h1 class="font-bold text-lg truncate">Enterprise KB</h1>
            <button @click="sidebarOpen = false" class="text-gray-400 hover:text-white p-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="p-3">
            <button @click="createConversation()" class="w-full flex items-center gap-2 px-4 py-2.5 rounded-lg border border-gray-600 hover:bg-gray-800 text-sm transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Nuova Chat
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 space-y-1">
            <template x-for="conv in conversations" :key="conv.id">
                <div @click="selectConversation(conv.id)"
                     :class="currentConversation?.id === conv.id ? 'bg-gray-700' : 'hover:bg-gray-800'"
                     class="group flex items-center gap-2 px-3 py-2.5 rounded-lg cursor-pointer text-sm transition relative">
                    <template x-if="editingId !== conv.id">
                        <span class="flex-1 truncate" x-text="conv.title || 'Nuova chat'"></span>
                    </template>
                    <template x-if="editingId === conv.id">
                        <input x-model="editTitle" @keydown.enter="saveRename(conv.id)" @keydown.escape="editingId = null" @click.stop
                               class="flex-1 bg-gray-600 text-white text-sm px-2 py-1 rounded outline-none">
                    </template>
                    <div class="hidden group-hover:flex items-center gap-1 flex-shrink-0" @click.stop>
                        <button @click="startRename(conv)" class="p-1 hover:text-blue-400" title="Rinomina">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </button>
                        <button @click="deleteConversation(conv.id)" class="p-1 hover:text-red-400" title="Elimina">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>
                </div>
            </template>
        </nav>

        <div class="p-3 border-t border-gray-700">
            <div class="flex items-center justify-between text-sm">
                <span class="truncate text-gray-400">{{ Auth::user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-gray-400 hover:text-white transition" title="Logout">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- ===== MAIN CONTENT ===== --}}
    <main class="flex-1 flex flex-col min-w-0">
        <header class="h-14 flex items-center px-4 border-b border-gray-200 bg-white flex-shrink-0">
            <button x-show="!sidebarOpen" @click="sidebarOpen = true" class="mr-3 text-gray-500 hover:text-gray-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h2 class="text-sm font-medium text-gray-700 truncate" x-text="currentConversation?.title || 'Enterprise Knowledge Base'"></h2>
        </header>

        <div class="flex-1 overflow-y-auto" x-ref="messagesContainer">
            <div x-show="messages.length === 0 && !isLoading" class="flex items-center justify-center h-full">
                <div class="text-center text-gray-400 max-w-md px-4">
                    <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                    <h3 class="text-lg font-medium text-gray-600 mb-2">Inizia una conversazione</h3>
                    <p class="text-sm">Chiedi qualsiasi cosa sulla knowledge base aziendale.</p>
                </div>
            </div>

            <div class="max-w-4xl mx-auto px-4 py-6 space-y-6">
                <template x-for="msg in messages" :key="msg.id">
                    <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                        <div :class="msg.role === 'user'
                                ? 'bg-blue-600 text-white rounded-2xl rounded-br-md max-w-[80%]'
                                : 'bg-white border border-gray-200 text-gray-800 rounded-2xl rounded-bl-md max-w-[85%] shadow-sm'"
                             class="px-4 py-3">

                            {{-- User message --}}
                            <template x-if="msg.role === 'user'">
                                <div class="text-sm whitespace-pre-wrap" x-text="msg.content"></div>
                            </template>

                            {{-- Assistant message: rich rendering --}}
                            <template x-if="msg.role === 'assistant'">
                                <div>
                                    <div class="prose prose-sm max-w-none" x-html="renderRichContent(msg.content, msg.id)"></div>
                                    {{-- Render charts after DOM update --}}
                                    <div x-effect="if (msg.role === 'assistant') $nextTick(() => renderCharts(msg.id, msg.content))"></div>
                                </div>
                            </template>

                            {{-- Citations --}}
                            <template x-if="msg.role === 'assistant' && msg.metadata?.citations?.length">
                                <div class="mt-3 border-t border-gray-100 pt-2" x-data="{ open: false }">
                                    <button @click="open = !open" class="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800 font-medium">
                                        <svg class="w-3.5 h-3.5 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        <span x-text="msg.metadata.citations.length + ' fonte/i'"></span>
                                    </button>
                                    <div x-show="open" x-collapse class="mt-2 space-y-1.5">
                                        <template x-for="cite in msg.metadata.citations" :key="cite.source_path">
                                            <div class="flex items-start gap-2 text-xs text-gray-600 bg-gray-50 rounded-lg px-3 py-2">
                                                <svg class="w-3.5 h-3.5 mt-0.5 flex-shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                <div class="min-w-0">
                                                    <div class="font-medium text-gray-700 truncate" x-text="cite.title"></div>
                                                    <div class="text-gray-400 truncate" x-text="cite.source_path"></div>
                                                    <template x-if="cite.headings?.length">
                                                        <div class="text-gray-500 mt-0.5">
                                                            <template x-for="h in cite.headings" :key="h">
                                                                <span class="inline-block bg-gray-200 rounded px-1.5 py-0.5 mr-1 mt-0.5" x-text="h"></span>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Feedback + Metadata --}}
                            <template x-if="msg.role === 'assistant' && msg.metadata">
                                <div class="mt-2 flex items-center justify-between">
                                    {{-- Feedback thumbs --}}
                                    <div class="flex items-center gap-1">
                                        <button @click="sendFeedback(msg, 'positive')"
                                                :class="msg.rating === 'positive' ? 'text-green-600 bg-green-50' : 'text-gray-400 hover:text-green-600 hover:bg-green-50'"
                                                class="p-1.5 rounded-md transition" title="Utile">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z M4 15h2"/></svg>
                                        </button>
                                        <button @click="sendFeedback(msg, 'negative')"
                                                :class="msg.rating === 'negative' ? 'text-red-600 bg-red-50' : 'text-gray-400 hover:text-red-600 hover:bg-red-50'"
                                                class="p-1.5 rounded-md transition" title="Non utile">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 15V19a3 3 0 003 3l4-9V2H5.72a2 2 0 00-2 1.7l-1.38 9a2 2 0 002 2.3H10z M20 2h-2v9h2"/></svg>
                                        </button>
                                    </div>
                                    {{-- Meta badge --}}
                                    <div class="flex items-center gap-2 text-xs opacity-60">
                                        <span x-text="msg.metadata?.model"></span>
                                        <template x-if="msg.metadata?.latency_ms"><span x-text="msg.metadata.latency_ms + 'ms'"></span></template>
                                        <template x-if="msg.metadata?.chunks_count"><span x-text="msg.metadata.chunks_count + ' chunk'"></span></template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <div x-show="isLoading" class="flex justify-start">
                    <div class="bg-white border border-gray-200 rounded-2xl rounded-bl-md px-4 py-3 shadow-sm">
                        <div class="flex items-center gap-2 text-gray-400 text-sm">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Sto pensando...
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="border-t border-gray-200 bg-white p-4 flex-shrink-0">
            <div class="max-w-4xl mx-auto">
                <form @submit.prevent="sendMessage()" class="flex items-end gap-3">
                    <div class="flex-1 relative">
                        <textarea x-model="newMessage" @keydown.enter.prevent="if (!$event.shiftKey) sendMessage()"
                                  rows="1" x-ref="messageInput"
                                  @input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 200) + 'px'"
                                  placeholder="Scrivi un messaggio..."
                                  :disabled="isLoading"
                                  class="w-full resize-none rounded-xl border border-gray-300 px-4 py-3 pr-12 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none disabled:opacity-50"></textarea>
                    </div>
                    <button type="button" @click="toggleMic()"
                            :class="isRecording ? 'bg-red-500 text-white animate-pulse' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                            :disabled="!speechSupported"
                            class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center transition disabled:opacity-30">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-14 0m7 7v4m-4 0h8m-4-18a3 3 0 00-3 3v4a3 3 0 006 0V5a3 3 0 00-3-3z"/></svg>
                    </button>
                    <button type="submit" :disabled="isLoading || !newMessage.trim()"
                            class="flex-shrink-0 w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V5m0 0l-7 7m7-7l7 7"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </main>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const INITIAL_CONVERSATION_ID = @json($activeConversation?->id);
const CHART_COLORS = ['#3b82f6','#ef4444','#10b981','#f59e0b','#8b5cf6','#ec4899','#06b6d4','#f97316'];
const chartInstances = {};

function headers(extra = {}) {
    return { 'X-CSRF-TOKEN': CSRF, 'Content-Type': 'application/json', 'Accept': 'application/json', ...extra };
}

function chatApp() {
    return {
        conversations: [], currentConversation: null, messages: [], newMessage: '',
        isLoading: false, sidebarOpen: true, editingId: null, editTitle: '',
        isRecording: false, speechSupported: false, recognition: null,

        async init() {
            this.initSpeech();
            await this.loadConversations();
            if (INITIAL_CONVERSATION_ID) await this.selectConversation(INITIAL_CONVERSATION_ID);
        },

        // ── Speech ───────────────────────────────────────────────

        initSpeech() {
            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SR) return;
            this.speechSupported = true;
            this.recognition = new SR();
            this.recognition.lang = 'it-IT';
            this.recognition.interimResults = true;
            this.recognition.continuous = false;
            let finalTranscript = '';
            this.recognition.onresult = (e) => {
                let interim = '';
                for (let i = e.resultIndex; i < e.results.length; i++) {
                    const t = e.results[i][0].transcript;
                    e.results[i].isFinal ? (finalTranscript += t + ' ') : (interim += t);
                }
                this.newMessage = finalTranscript + interim;
            };
            this.recognition.onend = () => { this.isRecording = false; this.newMessage = finalTranscript.trim(); finalTranscript = ''; };
            this.recognition.onerror = () => { this.isRecording = false; finalTranscript = ''; };
        },
        toggleMic() { if (!this.recognition) return; this.isRecording ? this.recognition.stop() : (this.isRecording = true, this.recognition.start()); },

        // ── Conversations ────────────────────────────────────────

        async loadConversations() { this.conversations = await (await fetch('/conversations', { headers: headers() })).json(); },

        async selectConversation(id) {
            this.currentConversation = this.conversations.find(c => c.id === id) || { id };
            this.messages = await (await fetch(`/conversations/${id}/messages`, { headers: headers() })).json();
            this.$nextTick(() => this.scrollToBottom());
            history.replaceState(null, '', `/chat/${id}`);
        },

        async createConversation() {
            const conv = await (await fetch('/conversations', { method: 'POST', headers: headers(), body: '{}' })).json();
            this.conversations.unshift(conv);
            this.currentConversation = conv;
            this.messages = [];
            history.replaceState(null, '', `/chat/${conv.id}`);
            this.$nextTick(() => this.$refs.messageInput?.focus());
        },

        async deleteConversation(id) {
            if (!confirm('Eliminare questa conversazione?')) return;
            await fetch(`/conversations/${id}`, { method: 'DELETE', headers: headers() });
            this.conversations = this.conversations.filter(c => c.id !== id);
            if (this.currentConversation?.id === id) { this.currentConversation = null; this.messages = []; history.replaceState(null, '', '/chat'); }
        },

        startRename(conv) { this.editingId = conv.id; this.editTitle = conv.title || ''; },

        async saveRename(id) {
            if (!this.editTitle.trim()) { this.editingId = null; return; }
            await fetch(`/conversations/${id}`, { method: 'PATCH', headers: headers(), body: JSON.stringify({ title: this.editTitle.trim() }) });
            const conv = this.conversations.find(c => c.id === id);
            if (conv) conv.title = this.editTitle.trim();
            if (this.currentConversation?.id === id) this.currentConversation.title = this.editTitle.trim();
            this.editingId = null;
        },

        // ── Messages ─────────────────────────────────────────────

        async sendMessage() {
            const content = this.newMessage.trim();
            if (!content || this.isLoading) return;
            if (!this.currentConversation) await this.createConversation();
            const convId = this.currentConversation.id;
            this.messages.push({ id: 'temp-' + Date.now(), role: 'user', content, metadata: null, rating: null, created_at: new Date().toISOString() });
            this.newMessage = '';
            this.isLoading = true;
            this.$nextTick(() => { this.scrollToBottom(); if (this.$refs.messageInput) this.$refs.messageInput.style.height = 'auto'; });
            try {
                const res = await fetch(`/conversations/${convId}/messages`, { method: 'POST', headers: headers(), body: JSON.stringify({ content }) });
                if (!res.ok) throw new Error();
                const msg = await res.json();
                this.messages.push(msg);
                if (this.messages.filter(m => m.role === 'user').length === 1) this.generateTitle(convId);
            } catch { this.messages.push({ id: 'err-' + Date.now(), role: 'assistant', content: 'Si e\' verificato un errore. Riprova.', metadata: null, rating: null }); }
            finally { this.isLoading = false; this.$nextTick(() => this.scrollToBottom()); }
        },

        async generateTitle(convId) {
            try { const d = await (await fetch(`/conversations/${convId}/generate-title`, { method: 'POST', headers: headers() })).json(); const c = this.conversations.find(x => x.id === convId); if (c) c.title = d.title; if (this.currentConversation?.id === convId) this.currentConversation.title = d.title; } catch {}
        },

        // ── Feedback ─────────────────────────────────────────────

        async sendFeedback(msg, rating) {
            if (!this.currentConversation || String(msg.id).startsWith('temp') || String(msg.id).startsWith('err')) return;
            try {
                const res = await fetch(`/conversations/${this.currentConversation.id}/messages/${msg.id}/feedback`, {
                    method: 'POST', headers: headers(), body: JSON.stringify({ rating }),
                });
                const data = await res.json();
                msg.rating = data.rating;
            } catch {}
        },

        // ── Rich Content Rendering ──────────────────────────────

        renderRichContent(text, msgId) {
            if (!text) return '';
            // Extract chart blocks and replace with canvas placeholders
            let processed = text.replace(/~~~chart\s*\n([\s\S]*?)\n~~~/g, (_, json) => {
                const chartId = 'chart-' + msgId + '-' + Math.random().toString(36).substr(2, 6);
                return `<div class="my-3 p-3 bg-gray-50 rounded-lg border"><canvas id="${chartId}" data-chart='${json.replace(/'/g, "&#39;")}'></canvas></div>`;
            });
            // Extract action blocks and replace with buttons
            processed = processed.replace(/~~~actions\s*\n([\s\S]*?)\n~~~/g, (_, json) => {
                try {
                    const actions = JSON.parse(json);
                    return '<div class="my-3 flex flex-wrap gap-2">' + actions.map(a => {
                        if (a.action === 'copy') {
                            return `<button onclick="navigator.clipboard.writeText(this.dataset.content).then(()=>{this.textContent='Copiato!';setTimeout(()=>this.textContent='${a.label}',1500)})" data-content="${a.data?.replace(/"/g,'&quot;') || ''}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100 transition"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>${a.label}</button>`;
                        }
                        if (a.action === 'download') {
                            const blob = `data:text/plain;charset=utf-8,${encodeURIComponent(a.data || '')}`;
                            return `<a href="${blob}" download="${a.filename || 'file.txt'}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-green-200 bg-green-50 text-green-700 hover:bg-green-100 transition"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>${a.label}</a>`;
                        }
                        return '';
                    }).join('') + '</div>';
                } catch { return ''; }
            });
            // Render markdown
            try {
                let html = marked.parse(processed, { breaks: true, gfm: true });
                // Add copy button to code blocks
                html = html.replace(/<pre><code([^>]*)>([\s\S]*?)<\/code><\/pre>/g, (match, attrs, code) => {
                    const raw = code.replace(/<[^>]+>/g, '').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&').replace(/&quot;/g,'"');
                    return `<pre style="position:relative"><code${attrs}>${code}</code><button class="code-copy-btn" onclick="navigator.clipboard.writeText(this.dataset.code).then(()=>{this.textContent='Copiato!';setTimeout(()=>this.textContent='Copia',1500)})" data-code="${raw.replace(/"/g,'&quot;')}">Copia</button></pre>`;
                });
                return html;
            } catch { return text; }
        },

        renderCharts(msgId, text) {
            if (!text || !text.includes('~~~chart')) return;
            this.$nextTick(() => {
                document.querySelectorAll(`canvas[id^="chart-${msgId}"]`).forEach(canvas => {
                    if (chartInstances[canvas.id]) return; // already rendered
                    try {
                        const cfg = JSON.parse(canvas.dataset.chart);
                        const datasets = (cfg.datasets || []).map((ds, i) => ({
                            ...ds,
                            backgroundColor: cfg.type === 'pie' || cfg.type === 'doughnut'
                                ? CHART_COLORS
                                : CHART_COLORS[i % CHART_COLORS.length] + '99',
                            borderColor: CHART_COLORS[i % CHART_COLORS.length],
                            borderWidth: cfg.type === 'pie' || cfg.type === 'doughnut' ? 2 : 2,
                        }));
                        chartInstances[canvas.id] = new Chart(canvas, {
                            type: cfg.type || 'bar',
                            data: { labels: cfg.labels || [], datasets },
                            options: {
                                responsive: true,
                                plugins: { title: { display: !!cfg.title, text: cfg.title || '' }, legend: { position: 'bottom' } },
                                scales: (cfg.type === 'pie' || cfg.type === 'doughnut') ? {} : { y: { beginAtZero: true } },
                            },
                        });
                    } catch (e) { console.error('Chart render error:', e); }
                });
            });
        },

        // ── Helpers ──────────────────────────────────────────────

        scrollToBottom() { const el = this.$refs.messagesContainer; if (el) el.scrollTop = el.scrollHeight; },
    };
}
</script>
@endsection
