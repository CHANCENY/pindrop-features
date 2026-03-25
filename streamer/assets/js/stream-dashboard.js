class StreamDashboard {
    constructor() {
        const configs = document.querySelector("noscript#configs").textContent;
        this.config = JSON.parse(configs);
        document.querySelector("noscript#configs").remove();
    }

    /**
     *
     * @param {number} movieID
     */
    async getMovieDetail(movieID) {
        const response = await fetch(this.config.front.detail.path,{
            method: this.config.front.detail.method,
            body: JSON.stringify({
                searchType: 'movie',
                movieID: movieID
            })
        });

        const detail = await response.json();
        return detail.movie || {};
    }

    async getMovieDetailLocal(movieID) {
        const response = await fetch(this.config.front.local.movie.path,{
            method: this.config.front.local.movie.method,
            body: JSON.stringify({
                searchType: 'movie',
                movieId: movieID
            })
        });

        const detail = await response.json();
        return detail || {};
    }

    async getShowDetail(showId) {
        const response = await fetch(this.config.front.detail.path,{
            method: this.config.front.detail.method,
            body: JSON.stringify({
                searchType: 'show',
                showId: showId
            })
        });

        const detail = await response.json();
        return detail.show || {};
    }

    /**
     * @param {HTMLElement} fileInput
     * @param {callback} callback
     */
    fileUpload(fileInput, callback) {
        if (!fileInput) return;

        fileInput.addEventListener('change', (e) => {
            this.handleFileUpload(e, callback);
        });
    }

    /**
     * Update progress UI
     * @param {number} percent
     * @param {string} message
     */
    updateProgressUI(percent, message) {
        const progressBar = document.getElementById('progressBar');
        const progressText = document.getElementById('progressText');

        if (progressBar) {
            progressBar.style.width = percent + '%';
        }

        if (progressText) {
            progressText.textContent = message;
        }
    }

    /**
     * @param {Event} event
     * @param {callback} callback
     */
    handleFileUpload(event,callback) {
        const input = event.target;

        if (!input.files || input.files.length === 0) {
            this.updateProgressUI(0, 'No file selected');
            return;
        }

        const file = input.files[0];
        const formData = new FormData();
        formData.append('file', file);
        formData.append('uploadType', event.target.getAttribute('data-type'))

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/w/dashboard/internal/upload', true);

        // Start
        this.updateProgressUI(0, 'Starting upload...');

        // Progress tracking
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                this.updateProgressUI(percent, `Uploading... ${percent}%`);
            }
        });

        // Success
        xhr.onload = () => {
            if (xhr.status === 200) {
                this.updateProgressUI(100, 'Upload complete ✅');
                input.value = '';
                if (callback) {
                    callback(xhr.responseText)
                }
            } else {
                this.updateProgressUI(0, `Upload failed (${xhr.status}) ❌`);
            }
        };

        // Error
        xhr.onerror = () => {
            this.updateProgressUI(0, 'Upload error ❌');
        };

        xhr.send(formData);
    }


}