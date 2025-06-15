/*
 * Modal
 *
 * Pico.css - https://picocss.com
 * Copyright 2019-2024 - Licensed under MIT
 */

class Modal {
    constructor() {
        this.isOpenClass = "modal-is-open";
        this.openingClass = "modal-is-opening";
        this.closingClass = "modal-is-closing";
        this.animationDuration = 400; // ms
        this.modalElement = null;
        this.resolvePromise = null;
    }

    createModal() {
        const modal = document.createElement('dialog');
        modal.innerHTML = `
            <article>
                <header>
                    <h3>title</h3>
                </header>
                <p>
                    message
                </p>
                <footer>
                    <button role="button" class="confirm-btn" data-target="modal-example">
                        Confirmer</button>
                        <button autofocus class="cancel-btn secondary">
                        Annuler
                    </button>
                </footer>
            </article>
        `;
        document.body.appendChild(modal);
        return modal;
    }

    async confirm(message, title = 'Confirm') {
        if (!this.modalElement) {
            this.modalElement = this.createModal();
            this.setupEventListeners();
        }

        this.modalElement.querySelector('h3').textContent = title;
        this.modalElement.querySelector('p').textContent = message;

        return new Promise((resolve) => {
            this.resolvePromise = resolve;
            this.openModal();
        });
    }

    setupEventListeners() {
        this.modalElement.querySelector('.confirm-btn').addEventListener('click', () => {
            this.closeModal(true);
        });

        this.modalElement.querySelector('.cancel-btn').addEventListener('click', () => {
            this.closeModal(false);
        });

        this.modalElement.addEventListener('click', (event) => {
            const modalContent = this.modalElement.querySelector('article');
            if (!modalContent.contains(event.target)) {
                this.closeModal(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.modalElement.open) {
                this.closeModal(false);
            }
        });
    }

    openModal() {
        const { documentElement: html } = document;
        html.classList.add(this.isOpenClass, this.openingClass);

        setTimeout(() => {
            html.classList.remove(this.openingClass);
        }, this.animationDuration);

        this.modalElement.showModal();
    }

    closeModal(result) {
        const { documentElement: html } = document;
        html.classList.add(this.closingClass);

        setTimeout(() => {
            html.classList.remove(this.closingClass, this.isOpenClass);
            this.modalElement.close();
            if (this.resolvePromise) {
                this.resolvePromise(result);
                this.resolvePromise = null;
            }
        }, this.animationDuration);
    }
}

// Export a singleton instance
const modal = new Modal();