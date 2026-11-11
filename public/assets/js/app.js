/**
 * watchrss - Frontend-Logik.
 *
 * Kein Framework, keine Abhaengigkeiten: die Seite besteht aus drei Karten,
 * der Zustand ist eine Liste von Kanaelen plus ein paar Einstellungen.
 *
 * Der Startzustand kommt als JSON aus dem Template (#watchrss-state), damit
 * Server und Client dieselbe Quelle benutzen.
 */
(function () {
    'use strict';

    /* ---------------------------------------------------------------------
     * Kleine Helfer
     * ------------------------------------------------------------------ */

    const $ = (selector, scope) => (scope || document).querySelector(selector);

    /** Zeigt kurz eine Meldung am unteren Bildschirmrand. */
    const toast = (() => {
        const element = $('#toast');
        let timer = null;

        return function show(message, isError) {
            if (!element) { return; }
            element.textContent = message;
            element.classList.toggle('is-error', Boolean(isError));
            element.classList.add('is-visible');

            window.clearTimeout(timer);
            timer = window.setTimeout(() => element.classList.remove('is-visible'), isError ? 6000 : 3500);
        };
    })();

    /** fetch()-Wrapper: schickt und erwartet JSON, wirft bei Fehlern. */
    async function request(url, options) {
        const response = await fetch(url, Object.assign({
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin'
        }, options || {}));

        let payload = {};
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('Unerwartete Antwort vom Server (HTTP ' + response.status + ').');
        }

        if (!response.ok) {
            throw new Error(payload.error || 'Fehler (HTTP ' + response.status + ').');
        }

        return payload;
    }

    /** Buttons bekommen waehrend eines Requests einen Spinner. */
    function setBusy(button, busy) {
        if (!button) { return; }
        button.disabled = busy;
        const spinner = $('.spinner', button);
        if (spinner) { spinner.hidden = !busy; }
    }

    /* ---------------------------------------------------------------------
     * Der Baukasten
     * ------------------------------------------------------------------ */

    class FeedBuilder {
        constructor(state) {
            this.state = state;
            this.channels = (state.channels || []).map(FeedBuilder.normalizeChannel);

            this.form         = $('#builder');
            this.input        = $('#channel-input');
            this.resolveBtn   = $('#resolve-btn');
            this.saveBtn      = $('#save-btn');
            this.list         = $('#channel-list');
            this.emptyNote    = $('#channel-empty');
            this.errorBox     = $('#resolve-errors');
            this.counter      = $('#channel-counter');
            this.result       = $('#result');
            this.feedUrlField = $('#feed-url');
            this.editUrlField = $('#edit-url');
            this.rotateBtn    = $('#rotate-btn');

            this.bind();
            this.render();
        }

        /** Vereinheitlicht die Kanalobjekte aus API und Template. */
        static normalizeChannel(raw) {
            return {
                channel_id:     raw.channel_id,
                title:          raw.title || raw.channel_id,
                handle:         raw.handle || null,
                avatar_url:     raw.avatar_url || null,
                channel_url:    raw.channel_url || ('https://www.youtube.com/channel/' + raw.channel_id),
                include_long:   raw.include_long !== false,
                include_shorts: raw.include_shorts !== false
            };
        }

        bind() {
            this.resolveBtn.addEventListener('click', () => this.resolve());

            // Strg/Cmd + Enter im Textfeld loest das Pruefen aus.
            this.input.addEventListener('keydown', (event) => {
                if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                    event.preventDefault();
                    this.resolve();
                }
            });

            this.form.addEventListener('submit', (event) => {
                event.preventDefault();
                this.save();
            });

            // Kanal-Buttons per Event-Delegation: die Liste wird neu gerendert.
            this.list.addEventListener('click', (event) => {
                const button = event.target.closest('button');
                if (!button) { return; }

                const row = button.closest('.channel');
                const id  = row && row.dataset.channelId;
                if (!id) { return; }

                if (button.dataset.toggle) {
                    this.toggle(id, button.dataset.toggle);
                } else if (button.dataset.remove !== undefined) {
                    this.remove(id);
                }
            });

            if (this.rotateBtn) {
                this.rotateBtn.addEventListener('click', () => this.rotate());
            }

            // Kopier-Buttons (Feed- und Bearbeiten-Link)
            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-copy]');
                if (!button) { return; }
                const field = $(button.dataset.copy);
                if (field) { FeedBuilder.copy(field.value); }
            });
        }

        /* --- Aktionen ---------------------------------------------------- */

        /** Freitext an den Server geben und die gefundenen Kanaele uebernehmen. */
        async resolve() {
            const value = this.input.value.trim();
            if (value === '') {
                toast('Bitte erst einen Kanal eintragen.', true);
                this.input.focus();
                return;
            }

            setBusy(this.resolveBtn, true);
            try {
                const data = await request(this.state.endpoints.resolve, {
                    method: 'POST',
                    body: JSON.stringify({ input: value })
                });

                const added = this.merge(data.channels || []);
                this.showErrors(data.errors || []);

                if (added > 0) {
                    this.input.value = '';
                    toast(added === 1 ? '1 Kanal hinzugefügt.' : added + ' Kanäle hinzugefügt.');
                } else if ((data.errors || []).length === 0) {
                    toast('Diese Kanäle sind bereits in der Liste.');
                }

                this.render();
            } catch (error) {
                toast(error.message, true);
            } finally {
                setBusy(this.resolveBtn, false);
            }
        }

        /** Feed anlegen bzw. speichern. */
        async save() {
            if (this.channels.length === 0) {
                toast('Ohne Kanäle gibt es keinen Feed.', true);
                return;
            }

            const isUpdate = Boolean(this.state.editToken);
            const endpoint = isUpdate ? this.state.endpoints.update : this.state.endpoints.create;

            setBusy(this.saveBtn, true);
            try {
                const data = await request(endpoint, {
                    method: 'POST',
                    body: JSON.stringify({
                        title:         $('#feed-title').value.trim(),
                        ttl_minutes:   parseInt($('#feed-ttl').value, 10),
                        prefix_titles: $('#feed-prefix').checked,
                        channels:      this.channels.map((channel) => ({
                            channel_id:     channel.channel_id,
                            include_long:   channel.include_long,
                            include_shorts: channel.include_shorts
                        }))
                    })
                });

                this.applySaved(data);
                toast(isUpdate ? 'Gespeichert.' : 'Feed erstellt – Adresse steht unten.');
            } catch (error) {
                toast(error.message, true);
            } finally {
                setBusy(this.saveBtn, false);
            }
        }

        /**
         * Neue öffentliche Adresse erzeugen. Die alte wird dabei ungültig -
         * deshalb die Rückfrage.
         */
        async rotate() {
            if (!this.state.endpoints.rotate) { return; }

            const confirmed = window.confirm(
                'Neue RSS-Adresse erzeugen?\n\n'
                + 'Die bisherige Adresse funktioniert danach nicht mehr. '
                + 'Wer den Feed abonniert hat, muss ihn neu eintragen.'
            );
            if (!confirmed) { return; }

            try {
                const data = await request(this.state.endpoints.rotate, { method: 'POST' });
                this.applySaved(data);
                toast('Neue Adresse erzeugt – die alte ist ab sofort ungültig.');
            } catch (error) {
                toast(error.message, true);
            }
        }

        /**
         * Nach dem Speichern bzw. Rotieren: Adressen anzeigen und - beim
         * ersten Speichern - aus dem Anlegen- in den Bearbeiten-Modus wechseln.
         */
        applySaved(data) {
            this.state.editToken = data.edit_token;

            // Die API liefert die Endpunkte gleich mit; sie hängen am
            // Edit-Token, den das Frontend beim Anlegen noch nicht kannte.
            if (data.api) {
                Object.assign(this.state.endpoints, data.api);
            }

            if (this.feedUrlField) { this.feedUrlField.value = data.feed_url; }
            if (this.editUrlField) { this.editUrlField.value = data.edit_url; }

            if (this.result) {
                this.result.hidden = false;
                this.result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            const label = $('.btn__label', this.saveBtn);
            if (label) { label.textContent = 'Änderungen speichern'; }
        }

        /**
         * Neue Kanaele anhaengen, Duplikate ignorieren.
         * @returns {number} Anzahl tatsaechlich hinzugefuegter Kanaele
         */
        merge(incoming) {
            const known = new Set(this.channels.map((channel) => channel.channel_id));
            let added = 0;

            incoming.forEach((raw) => {
                if (known.has(raw.channel_id)) { return; }
                if (this.channels.length >= this.state.maxChannels) { return; }

                this.channels.push(FeedBuilder.normalizeChannel(raw));
                known.add(raw.channel_id);
                added += 1;
            });

            return added;
        }

        toggle(channelId, field) {
            const channel = this.channels.find((item) => item.channel_id === channelId);
            if (!channel) { return; }

            channel[field] = !channel[field];
            this.render();
        }

        remove(channelId) {
            this.channels = this.channels.filter((channel) => channel.channel_id !== channelId);
            this.render();
        }

        /* --- Darstellung ------------------------------------------------- */

        render() {
            this.list.innerHTML = '';
            this.channels.forEach((channel) => this.list.appendChild(this.renderChannel(channel)));

            this.emptyNote.hidden = this.channels.length > 0;
            this.counter.textContent = this.channels.length === 0
                ? ''
                : this.channels.length + ' von ' + this.state.maxChannels;
        }

        renderChannel(channel) {
            const row = document.createElement('li');
            row.className = 'channel';
            row.dataset.channelId = channel.channel_id;
            if (!channel.include_long && !channel.include_shorts) {
                row.classList.add('is-muted');
            }

            // Avatar (oder Initiale, wenn YouTube keins geliefert hat)
            if (channel.avatar_url) {
                const avatar = document.createElement('img');
                avatar.className = 'channel__avatar';
                avatar.src = channel.avatar_url;
                avatar.alt = '';
                avatar.loading = 'lazy';
                avatar.width = 48;
                avatar.height = 48;
                row.appendChild(avatar);
            } else {
                const placeholder = document.createElement('div');
                placeholder.className = 'channel__avatar channel__avatar--placeholder';
                placeholder.textContent = (channel.title || '?').charAt(0).toUpperCase();
                row.appendChild(placeholder);
            }

            const info = document.createElement('div');
            info.className = 'channel__info';

            const name = document.createElement('a');
            name.className = 'channel__name';
            name.href = channel.channel_url;
            name.target = '_blank';
            name.rel = 'noopener noreferrer';
            name.textContent = channel.title;
            info.appendChild(name);

            const meta = document.createElement('div');
            meta.className = 'channel__meta';
            meta.textContent = channel.handle ? '@' + channel.handle : channel.channel_id;
            info.appendChild(meta);

            row.appendChild(info);

            const controls = document.createElement('div');
            controls.className = 'channel__controls';
            controls.appendChild(FeedBuilder.renderToggle('include_long', 'Longform', channel.include_long, 'long'));
            controls.appendChild(FeedBuilder.renderToggle('include_shorts', 'Shorts', channel.include_shorts, 'short'));
            row.appendChild(controls);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'channel__remove';
            remove.dataset.remove = '';
            remove.title = 'Kanal entfernen';
            remove.setAttribute('aria-label', channel.title + ' entfernen');
            remove.innerHTML = '&times;';
            row.appendChild(remove);

            return row;
        }

        static renderToggle(field, label, active, variant) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'toggle toggle--' + variant;
            button.dataset.toggle = field;
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.textContent = label;

            return button;
        }

        showErrors(errors) {
            if (!this.errorBox) { return; }

            this.errorBox.innerHTML = '';
            this.errorBox.hidden = errors.length === 0;

            errors.forEach((error) => {
                const item = document.createElement('li');
                const code = document.createElement('code');
                code.textContent = error.input;
                item.appendChild(code);
                item.appendChild(document.createTextNode(' – ' + error.message));
                this.errorBox.appendChild(item);
            });
        }

        /** Zwischenablage mit Fallback fuer aeltere Browser/HTTP-Kontexte. */
        static copy(text) {
            const done = () => toast('In die Zwischenablage kopiert.');

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done, () => FeedBuilder.copyFallback(text, done));
            } else {
                FeedBuilder.copyFallback(text, done);
            }
        }

        static copyFallback(text, done) {
            const helper = document.createElement('textarea');
            helper.value = text;
            helper.setAttribute('readonly', '');
            helper.style.position = 'fixed';
            helper.style.opacity = '0';
            document.body.appendChild(helper);
            helper.select();

            try {
                document.execCommand('copy');
                done();
            } catch (error) {
                toast('Kopieren hat nicht geklappt – bitte manuell markieren.', true);
            } finally {
                document.body.removeChild(helper);
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Start
     * ------------------------------------------------------------------ */

    document.addEventListener('DOMContentLoaded', () => {
        const stateElement = document.getElementById('watchrss-state');
        if (!stateElement || !document.getElementById('builder')) { return; }

        let state;
        try {
            state = JSON.parse(stateElement.textContent);
        } catch (error) {
            toast('Die Seite konnte nicht initialisiert werden.', true);
            return;
        }

        new FeedBuilder(state);
    });
})();
