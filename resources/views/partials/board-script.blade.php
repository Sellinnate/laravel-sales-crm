{{--
    Drag and drop of the cards between and within the columns (native HTML5 drag events, no
    library: it works the same with Livewire 3 and 4). The card moves at once where it is dropped
    (optimistic); the server confirms, or the next render puts it back and the mini form opens.
--}}
<script>
    window.salesCrmBoard = window.salesCrmBoard || function (wire) {
        return {
            dragging: null,
            placeholder: null,

            start(event) {
                const card = event.target.closest('[data-deal]');

                if (! card) {
                    return;
                }

                this.dragging = card;
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', card.dataset.deal);
                requestAnimationFrame(() => card.setAttribute('data-dragging', 'true'));
            },

            end() {
                this.dragging?.removeAttribute('data-dragging');
                this.dragging = null;
                this.clear();
            },

            over(event) {
                if (! this.dragging) {
                    return;
                }

                event.preventDefault();
                const list = event.currentTarget;
                list.dataset.over = 'true';

                if (! this.placeholder) {
                    this.placeholder = document.createElement('div');
                    this.placeholder.className = 'crm-drop-placeholder';
                    this.placeholder.setAttribute('aria-hidden', 'true');
                }

                const next = this.cardAfter(list, event.clientY);
                next ? list.insertBefore(this.placeholder, next) : list.appendChild(this.placeholder);
            },

            leave(event) {
                if (! event.currentTarget.contains(event.relatedTarget)) {
                    delete event.currentTarget.dataset.over;
                }
            },

            drop(event, stageId) {
                event.preventDefault();

                if (! this.dragging) {
                    return;
                }

                const list = event.currentTarget;
                const card = this.dragging;
                const anchor = this.placeholder && this.placeholder.parentNode === list ? this.placeholder : null;
                let previous = anchor ? anchor.previousElementSibling : list.lastElementChild;

                while (previous && (! previous.dataset || ! previous.dataset.deal || previous === card)) {
                    previous = previous.previousElementSibling;
                }

                anchor ? list.insertBefore(card, anchor) : list.appendChild(card);
                this.end();
                wire.moveDeal(card.dataset.deal, stageId, previous ? previous.dataset.deal : null);
            },

            clear() {
                this.placeholder?.remove();
                this.placeholder = null;
                document.querySelectorAll('.crm-column-list[data-over]').forEach((list) => delete list.dataset.over);
            },

            cardAfter(list, y) {
                const cards = [...list.querySelectorAll('[data-deal]:not([data-dragging])')];

                return cards.find((card) => {
                    const box = card.getBoundingClientRect();

                    return y < box.top + box.height / 2;
                }) || null;
            },
        };
    };

    window.salesCrmShortcuts = window.salesCrmShortcuts || function (wire) {
        return {
            handle(event) {
                const target = event.target;
                const typing = target.closest('input, textarea, select, [contenteditable="true"]');
                // Not behind an open dialog or deal panel.
                const dialog = document.querySelector('.sales-crm .crm-modal:not([style*="display: none"]), .sales-crm .crm-slide-over:not([style*="display: none"])');

                if (typing || dialog || event.metaKey || event.ctrlKey || event.altKey) {
                    return;
                }

                const card = target.closest('[data-deal]');

                if (card && (event.key === '[' || event.key === ']')) {
                    event.preventDefault();
                    wire.shiftDeal(parseInt(card.dataset.deal, 10), event.key === '[' ? -1 : 1);

                    return;
                }

                switch (event.key) {
                    case 'n': case 'N': event.preventDefault(); wire.startCreate(); break;
                    case '/': event.preventDefault(); this.$root.querySelector('[data-search]')?.focus(); break;
                    case 'k': case 'K': wire.setView('kanban'); break;
                    case 'l': case 'L': wire.setView('list'); break;
                    case '?': wire.set('showShortcuts', true); break;
                }
            },
        };
    };
</script>
