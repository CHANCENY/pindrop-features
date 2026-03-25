// Mock Data
const mockMovies = [
    {
        id: 1,
        title: "Cosmic Odyssey",
        year: 2024,
        type: "movie",
        rating: 8.5,
        duration: "2h 28min",
        description: "An epic journey through space and time, where humanity's future hangs in the balance.",
        poster: "https://picsum.photos/seed/cosmic-odyssey/300/450.jpg",
        videoUrl: "https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4",
        genres: ["Sci-Fi", "Action", "Adventure"],
        cast: ["Chris Evans", "Zendaya", "Oscar Isaac"]
    },
    {
        id: 2,
        title: "Neon Nights",
        year: 2023,
        type: "movie",
        rating: 7.8,
        duration: "2h 15min",
        description: "A cyberpunk thriller set in a dystopian future where memories can be stolen.",
        poster: "https://picsum.photos/seed/neon-nights/300/450.jpg",
        videoUrl: "https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ElephantsDream.mp4",
        genres: ["Sci-Fi", "Thriller", "Action"],
        cast: ["Ryan Gosling", "Ana de Armas", "Sylvester Stallone"]
    },
    {
        id: 3,
        title: "The Last Algorithm",
        year: 2024,
        type: "movie",
        rating: 8.2,
        duration: "2h 05min",
        description: "A mind-bending sci-fi tale about artificial consciousness and human connection.",
        poster: "https://picsum.photos/seed/last-algorithm/300/450.jpg",
        videoUrl: "https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4",
        genres: ["Sci-Fi", "Drama"],
        cast: ["Jesse Eisenberg", "Emma Stone", "Mark Ruffalo"]
    },
    {
        id: 4,
        title: "Digital Dreams",
        year: 2023,
        type: "movie",
        rating: 7.5,
        duration: "1h 55min",
        description: "A visual masterpiece exploring the intersection of technology and human emotion.",
        poster: "https://picsum.photos/seed/digital-dreams/300/450.jpg",
        videoUrl: "https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerEscapes.mp4",
        genres: ["Drama", "Romance"],
        cast: ["Timothée Chalamet", "Saoirse Ronan"]
    },
    {
        id: 5,
        title: "Quantum Paradox",
        year: 2024,
        type: "movie",
        rating: 8.9,
        duration: "2h 35min",
        description: "Physicists discover parallel universes, but each discovery comes with a deadly price.",
        poster: "https://picsum.photos/seed/quantum-paradox/300/450.jpg",
        videoUrl: "https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerFun.mp4",
        genres: ["Sci-Fi", "Thriller", "Mystery"],
        cast: ["Matthew McConaughey", "Anne Hathaway", "Jessica Chastain"]
    },
    {
        id: 6,
        title: "Shadow Protocol",
        year: 2023,
        type: "movie",
        rating: 7.2,
        duration: "2h 10min",
        description: "An espionage thriller where the line between hero and villain blurs.",
        poster: "https://picsum.photos/seed/shadow-protocol/300/450.jpg",
        videoUrl: "https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerJoyrides.mp4",
        genres: ["Action", "Thriller"],
        cast: ["Tom Cruise", "Rebecca Ferguson", "Simon Pegg"]
    }
];

const mockTVShows = [
    {
        id: 7,
        title: "Tech Titans",
        year: 2024,
        type: "tv",
        rating: 8.7,
        seasons: 2,
        episodes: 16,
        status: "ongoing",
        description: "A drama series following the rise and fall of Silicon Valley's most ambitious startups.",
        poster: "https://picsum.photos/seed/tech-titans/300/450.jpg",
        videoUrl: "https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerMeltdowns.mp4",
        genres: ["Drama", "Business"],
        cast: ["Kumail Nanjiani", "Zoe Chao", "O'Shea Jackson Jr."]
    },
    {
        id: 8,
        title: "Mystic Chronicles",
        year: 2023,
        type: "tv",
        rating: 8.1,
        seasons: 3,
        episodes: 24,
        status: "ongoing",
        description: "Fantasy series where ancient magic meets modern technology in an epic battle for reality.",
        poster: "https://picsum.photos/seed/mystic-chronicles/300/450.jpg",
        videoUrl: "https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/Sintel.mp4",
        genres: ["Fantasy", "Adventure", "Drama"],
        cast: ["Henry Cavill", "Anya Taylor-Joy", "Idris Elba"]
    },
    {
        id: 9,
        title: "Urban Legends",
        year: 2024,
        type: "tv",
        rating: 7.9,
        seasons: 1,
        episodes: 8,
        status: "ongoing",
        description: "Anthology series exploring modern myths and urban legends with a technological twist.",
        poster: "https://picsum.photos/seed/urban-legends/300/450.jpg",
        videoUrl: "https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/TearsOfSteel.mp4",
        genres: ["Horror", "Anthology", "Thriller"],
        cast: ["Jordan Peele", "Keke Palmer", "Daniel Kaluuya"]
    },
    {
        id: 10,
        title: "Code Breakers",
        year: 2023,
        type: "tv",
        rating: 8.3,
        seasons: 2,
        episodes: 18,
        status: "completed",
        description: "Cybersecurity experts race against time to prevent global digital catastrophes.",
        poster: "https://picsum.photos/seed/code-breakers/300/450.jpg",
        videoUrl: "https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/WeAreGoingOnBullrun.mp4",
        genres: ["Thriller", "Crime", "Drama"],
        cast: ["Rami Malek", "Portia Doubleday", "Carly Chaikin"]
    }
];

// Application State
let playlists = JSON.parse(localStorage.getItem('playlists')) || [];
let currentPlaylist = null;
let currentVideoIndex = 0;
let isPlayingPlaylist = false;
let currentVideo = null;
let watchHistory = JSON.parse(localStorage.getItem('watchHistory')) || [];

// DOM Elements
const videoModal = document.getElementById('videoModal');
const addToPlaylistModal = document.getElementById('addToPlaylistModal');
const createPlaylistModal = document.getElementById('createPlaylistModal');
const playlistDetailsModal = document.getElementById('playlistDetailsModal');
const addItemsModal = document.getElementById('addItemsModal');
const settingsModal = document.getElementById('settingsModal');
const shareModal = document.getElementById('shareModal');
const videoPlayer = document.getElementById('videoPlayer');
const mainVideoPlayer = document.getElementById('mainVideoPlayer');

// Initialize Application
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
});

function initializeApp() {
    setupEventListeners();
    loadPageContent();
    updateWatchHistory();
}

function loadPageContent() {
    const currentPage = window.location.pathname.split('/').pop();
    
    switch(currentPage) {
        case 'movies.html':
            loadMoviesPage();
            break;
        case 'tv-shows.html':
            loadTVShowsPage();
            break;
        case 'playlists.html':
            loadPlaylistsPage();
            break;
        case 'player.html':
            loadPlayerPage();
            break;
        default:
            loadHomePage();
    }
}

function loadHomePage() {
    loadContinueWatching();
    loadTrendingContent();
    loadPopularMovies();
    loadPopularTVShows();
    loadNewReleases();
}

function loadMoviesPage() {
    renderMoviesGrid(mockMovies);
    setupMovieFilters();
}

function loadTVShowsPage() {
    renderTVShowsGrid(mockTVShows);
    setupTVShowFilters();
}

function loadPlaylistsPage() {
    renderPlaylists();
}

function loadPlayerPage() {
    const urlParams = new URLSearchParams(window.location.search);
    const videoId = urlParams.get('id');
    const playlistId = urlParams.get('playlist');
    
    if (videoId) {
        const video = findContentById(parseInt(videoId));
        if (video) {
            playVideoOnPlayer(video);
        }
    } else if (playlistId) {
        playPlaylistOnPlayer(parseInt(playlistId));
    }
}

// Home Page Functions
function loadContinueWatching() {
    const container = document.getElementById('continue-watching');
    if (!container) return;
    
    const continueWatching = watchHistory.slice(0, 5);
    container.innerHTML = '';
    
    continueWatching.forEach(item => {
        const card = createContinueWatchingCard(item);
        container.appendChild(card);
    });
}

function loadTrendingContent() {
    const container = document.getElementById('trending-content');
    if (!container) return;
    
    const trending = [...mockMovies.slice(0, 3), ...mockTVShows.slice(0, 2)];
    container.innerHTML = '';
    
    trending.forEach(item => {
        const card = createContentCard(item);
        container.appendChild(card);
    });
}

function loadPopularMovies() {
    const container = document.getElementById('popular-movies');
    if (!container) return;
    
    container.innerHTML = '';
    mockMovies.slice(0, 6).forEach(movie => {
        const card = createContentCard(movie);
        container.appendChild(card);
    });
}

function loadPopularTVShows() {
    const container = document.getElementById('popular-tv');
    if (!container) return;
    
    container.innerHTML = '';
    mockTVShows.slice(0, 6).forEach(show => {
        const card = createContentCard(show);
        container.appendChild(card);
    });
}

function loadNewReleases() {
    const container = document.getElementById('new-releases');
    if (!container) return;
    
    const newReleases = [...mockMovies.slice(2, 5), ...mockTVShows.slice(1, 3)];
    container.innerHTML = '';
    
    newReleases.forEach(item => {
        const card = createContentCard(item);
        container.appendChild(card);
    });
}

// Movies Page Functions
function renderMoviesGrid(movies) {
    const container = document.getElementById('moviesGrid');
    if (!container) return;
    
    container.innerHTML = '';
    movies.forEach(movie => {
        const card = createMovieCard(movie);
        container.appendChild(card);
    });
}

function setupMovieFilters() {
    const genreFilter = document.getElementById('genreFilter');
    const yearFilter = document.getElementById('yearFilter');
    const ratingFilter = document.getElementById('ratingFilter');
    const sortByFilter = document.getElementById('sortByFilter');
    
    if (genreFilter) {
        genreFilter.addEventListener('change', applyMovieFilters);
    }
    if (yearFilter) {
        yearFilter.addEventListener('change', applyMovieFilters);
    }
    if (ratingFilter) {
        ratingFilter.addEventListener('change', applyMovieFilters);
    }
    if (sortByFilter) {
        sortByFilter.addEventListener('change', applyMovieFilters);
    }
}

function applyMovieFilters() {
    const genre = document.getElementById('genreFilter')?.value || '';
    const year = document.getElementById('yearFilter')?.value || '';
    const rating = document.getElementById('ratingFilter')?.value || '';
    const sortBy = document.getElementById('sortByFilter')?.value || 'popular';
    
    let filtered = [...mockMovies];
    
    if (genre) {
        filtered = filtered.filter(movie => movie.genres.includes(genre));
    }
    
    if (year) {
        filtered = filtered.filter(movie => movie.year.toString() === year);
    }
    
    if (rating) {
        filtered = filtered.filter(movie => movie.rating >= parseFloat(rating));
    }
    
    // Sort
    switch(sortBy) {
        case 'newest':
            filtered.sort((a, b) => b.year - a.year);
            break;
        case 'rating':
            filtered.sort((a, b) => b.rating - a.rating);
            break;
        case 'title':
            filtered.sort((a, b) => a.title.localeCompare(b.title));
            break;
    }
    
    renderMoviesGrid(filtered);
}

// TV Shows Page Functions
function renderTVShowsGrid(shows) {
    const container = document.getElementById('tvShowsGrid');
    if (!container) return;
    
    container.innerHTML = '';
    shows.forEach(show => {
        const card = createTVShowCard(show);
        container.appendChild(card);
    });
}

function setupTVShowFilters() {
    const genreFilter = document.getElementById('genreFilter');
    const statusFilter = document.getElementById('statusFilter');
    const yearFilter = document.getElementById('yearFilter');
    const sortByFilter = document.getElementById('sortByFilter');
    
    [genreFilter, statusFilter, yearFilter, sortByFilter].forEach(filter => {
        if (filter) {
            filter.addEventListener('change', applyTVShowFilters);
        }
    });
}

function applyTVShowFilters() {
    const genre = document.getElementById('genreFilter')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const year = document.getElementById('yearFilter')?.value || '';
    const sortBy = document.getElementById('sortByFilter')?.value || 'popular';
    
    let filtered = [...mockTVShows];
    
    if (genre) {
        filtered = filtered.filter(show => show.genres.includes(genre));
    }
    
    if (status) {
        filtered = filtered.filter(show => show.status === status);
    }
    
    if (year) {
        filtered = filtered.filter(show => show.year.toString() === year);
    }
    
    // Sort
    switch(sortBy) {
        case 'newest':
            filtered.sort((a, b) => b.year - a.year);
            break;
        case 'rating':
            filtered.sort((a, b) => b.rating - a.rating);
            break;
        case 'title':
            filtered.sort((a, b) => a.title.localeCompare(b.title));
            break;
    }
    
    renderTVShowsGrid(filtered);
}

// Playlists Page Functions
function renderPlaylists() {
    const container = document.getElementById('playlistsGrid');
    const emptyState = document.getElementById('emptyState');
    
    if (!container) return;
    
    if (playlists.length === 0) {
        container.innerHTML = '';
        if (emptyState) emptyState.style.display = 'block';
        return;
    }
    
    if (emptyState) emptyState.style.display = 'none';
    container.innerHTML = '';
    
    playlists.forEach(playlist => {
        const card = createPlaylistCard(playlist);
        container.appendChild(card);
    });
}

function createPlaylistCard(playlist) {
    const card = document.createElement('div');
    card.className = 'playlist-card';
    
    const previewImages = playlist.items.slice(0, 3).map(itemId => {
        const item = findContentById(itemId);
        return item ? `<img src="${item.poster}" alt="${item.title}">` : '';
    }).join('');
    
    card.innerHTML = `
        <div class="playlist-header">
            <div class="playlist-title">${playlist.name}</div>
            <div class="playlist-count">${playlist.items.length} items</div>
        </div>
        <div class="playlist-description">${playlist.description || 'No description'}</div>
        <div class="playlist-preview">
            ${previewImages || '<div style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 2rem;">Empty playlist</div>'}
        </div>
        <div class="playlist-actions">
            <button class="btn-primary" onclick="playPlaylist(${playlist.id})">▶ Play</button>
            <button class="btn-secondary" onclick="openPlaylistDetails(${playlist.id})">ℹ Details</button>
            <button class="btn-secondary" onclick="deletePlaylist(${playlist.id})">🗑️ Delete</button>
        </div>
    `;
    
    return card;
}

// Card Creation Functions
function createContentCard(item) {
    const card = document.createElement('div');
    card.className = 'content-card';
    
    // Add action buttons
    const actionsHtml = `
        <div class="content-card-actions">
            <button class="card-action-btn" onclick="playVideo(${item.id})" title="Play">▶</button>
            <button class="card-action-btn" onclick="addToPlaylist(${item.id})" title="Add to Playlist">+</button>
        </div>
    `;
    
    card.innerHTML = `
        ${actionsHtml}
        <img src="${item.poster}" alt="${item.title}">
        <div class="content-card-overlay">
            <div class="content-card-title">${item.title}</div>
            <div class="content-card-year">${item.year} • ${item.type === 'movie' ? 'Movie' : 'TV Show'}</div>
        </div>
        <div class="content-card-rating">⭐ ${item.rating}</div>
    `;
    
    card.addEventListener('click', (e) => {
        // Only navigate if not clicking on action buttons
        if (!e.target.closest('.content-card-actions')) {
            navigateToDetail(item);
        }
    });
    
    return card;
}

function createContinueWatchingCard(item) {
    const card = document.createElement('div');
    card.className = 'content-card continue-watching';
    const progress = calculateProgress(item);
    
    // Add action buttons
    const actionsHtml = `
        <div class="content-card-actions">
            <button class="card-action-btn" onclick="playVideo(${item.id})" title="Resume">▶</button>
            <button class="card-action-btn" onclick="addToPlaylist(${item.id})" title="Add to Playlist">+</button>
        </div>
    `;
    
    card.innerHTML = `
        ${actionsHtml}
        <img src="${item.poster}" alt="${item.title}">
        <div class="progress-bar-container">
            <div class="progress-bar" style="width: ${progress}%"></div>
        </div>
        <div class="content-card-overlay">
            <div class="content-card-title">${item.title}</div>
            <div class="content-card-year">${progress}% Complete • ${item.type === 'movie' ? 'Movie' : 'TV Show'}</div>
        </div>
    `;
    
    card.addEventListener('click', (e) => {
        if (!e.target.closest('.content-card-actions')) {
            navigateToDetail(item);
        }
    });
    
    return card;
}

function createMovieCard(movie) {
    const card = document.createElement('div');
    card.className = 'content-card';
    
    // Add action buttons
    const actionsHtml = `
        <div class="content-card-actions">
            <button class="card-action-btn" onclick="playVideo(${movie.id})" title="Play">▶</button>
            <button class="card-action-btn" onclick="addToPlaylist(${movie.id})" title="Add to Playlist">+</button>
        </div>
    `;
    
    card.innerHTML = `
        ${actionsHtml}
        <img src="${movie.poster}" alt="${movie.title}">
        <div class="content-card-overlay">
            <div class="content-card-title">${movie.title}</div>
            <div class="content-card-year">${movie.year} • ${movie.duration}</div>
        </div>
        <div class="content-card-rating">⭐ ${movie.rating}</div>
    `;
    
    card.addEventListener('click', (e) => {
        if (!e.target.closest('.content-card-actions')) {
            navigateToDetail(movie);
        }
    });
    
    return card;
}

function createTVShowCard(show) {
    const card = document.createElement('div');
    card.className = 'content-card';
    
    // Add action buttons
    const actionsHtml = `
        <div class="content-card-actions">
            <button class="card-action-btn" onclick="playVideo(${show.id})" title="Play First Episode">▶</button>
            <button class="card-action-btn" onclick="addToPlaylist(${show.id})" title="Add to Playlist">+</button>
        </div>
    `;
    
    card.innerHTML = `
        ${actionsHtml}
        <img src="${show.poster}" alt="${show.title}">
        <div class="content-card-overlay">
            <div class="content-card-title">${show.title}</div>
            <div class="content-card-year">${show.seasons} Seasons • ${show.status}</div>
        </div>
        <div class="content-card-rating">⭐ ${show.rating}</div>
    `;
    
    card.addEventListener('click', (e) => {
        if (!e.target.closest('.content-card-actions')) {
            navigateToDetail(show);
        }
    });
    
    return card;
}

// Navigation Functions
function navigateToDetail(item) {
    if (item.type === 'movie') {
        window.location.href = `movie-detail.html?id=${item.id}`;
    } else if (item.type === 'tv') {
        window.location.href = `tv-show-detail.html?id=${item.id}`;
    }
}

function playVideo(itemId) {
    // Navigate to player page
    window.location.href = `player.html?id=${itemId}`;
}

function addToPlaylist(itemId) {
    // Store the item ID for the add to playlist functionality
    localStorage.setItem('itemToAdd', itemId);
    
    // Navigate to playlists page with add intent
    window.location.href = `playlists.html?add=${itemId}`;
}

// Video Player Functions
function playVideo(item) {
    currentVideo = item;
    
    if (window.location.pathname.includes('player.html')) {
        playVideoOnPlayer(item);
    } else {
        openVideoModal(item);
    }
    
    addToWatchHistory(item);
}

function openVideoModal(item) {
    const modalTitle = document.getElementById('modalTitle');
    const videoTitle = document.getElementById('videoTitle');
    const videoDescription = document.getElementById('videoDescription');
    
    modalTitle.textContent = item.title;
    videoTitle.textContent = item.title;
    videoDescription.textContent = item.description;
    videoPlayer.src = item.videoUrl;
    
    videoModal.style.display = 'block';
    videoPlayer.play();
    
    videoModal.dataset.currentVideoId = item.id;
}

function playVideoOnPlayer(item) {
    const videoTitle = document.getElementById('videoTitle');
    const mainVideoTitle = document.getElementById('mainVideoTitle');
    const videoDescription = document.getElementById('videoDescription');
    const videoYear = document.getElementById('videoYear');
    const videoRating = document.getElementById('videoRating');
    const videoDuration = document.getElementById('videoDuration');
    const videoGenres = document.getElementById('videoGenres');
    
    videoTitle.textContent = item.title;
    mainVideoTitle.textContent = item.title;
    videoDescription.textContent = item.description;
    videoYear.textContent = item.year;
    videoRating.textContent = `⭐ ${item.rating}`;
    videoDuration.textContent = item.duration || '2h 15min';
    
    // Add genres
    if (videoGenres) {
        videoGenres.innerHTML = item.genres.map(genre => 
            `<span class="genre-tag">${genre}</span>`
        ).join('');
    }
    
    mainVideoPlayer.src = item.videoUrl;
    mainVideoPlayer.play();
    
    setupVideoControls();
}

function showTVShowDetails(show) {
    // This would open a modal with show details and episodes
    playVideo(show);
}

// Playlist Functions
function createPlaylist() {
    const name = document.getElementById('playlistName')?.value.trim();
    const description = document.getElementById('playlistDescription')?.value.trim();
    
    if (!name) {
        alert('Please enter a playlist name');
        return;
    }
    
    const playlist = {
        id: Date.now(),
        name: name,
        description: description || '',
        items: [],
        createdAt: new Date().toISOString()
    };
    
    playlists.push(playlist);
    savePlaylists();
    renderPlaylists();
    closeCreatePlaylistModal();
    
    alert('Playlist created successfully!');
}

function playPlaylist(playlistId) {
    const playlist = playlists.find(p => p.id === playlistId);
    if (!playlist || playlist.items.length === 0) {
        alert('Playlist is empty');
        return;
    }
    
    currentPlaylist = playlist;
    currentVideoIndex = 0;
    isPlayingPlaylist = true;
    
    const firstItem = findContentById(playlist.items[0]);
    if (firstItem) {
        if (window.location.pathname.includes('player.html')) {
            playVideoOnPlayer(firstItem);
            showPlaylistQueue();
        } else {
            window.location.href = `player.html?playlist=${playlistId}`;
        }
    }
}

function playPlaylistOnPlayer(playlistId) {
    const playlist = playlists.find(p => p.id === playlistId);
    if (!playlist || playlist.items.length === 0) return;
    
    currentPlaylist = playlist;
    currentVideoIndex = 0;
    isPlayingPlaylist = true;
    
    const firstItem = findContentById(playlist.items[0]);
    if (firstItem) {
        playVideoOnPlayer(firstItem);
        showPlaylistQueue();
    }
}

function playNextVideo() {
    if (!currentPlaylist || currentVideoIndex >= currentPlaylist.items.length - 1) {
        isPlayingPlaylist = false;
        alert('End of playlist');
        return;
    }
    
    currentVideoIndex++;
    const nextItem = findContentById(currentPlaylist.items[currentVideoIndex]);
    if (nextItem) {
        playVideoOnPlayer(nextItem);
        updatePlaylistQueue();
    }
}

function deletePlaylist(playlistId) {
    if (confirm('Are you sure you want to delete this playlist?')) {
        playlists = playlists.filter(p => p.id !== playlistId);
        savePlaylists();
        renderPlaylists();
    }
}

function openPlaylistDetails(playlistId) {
    const playlist = playlists.find(p => p.id === playlistId);
    if (!playlist) return;
    
    // This would open a detailed view of the playlist
    alert(`Playlist: ${playlist.name}\nItems: ${playlist.items.length}\nDescription: ${playlist.description}`);
}

// Utility Functions
function findContentById(id) {
    return mockMovies.find(m => m.id === id) || mockTVShows.find(t => t.id === id);
}

function savePlaylists() {
    localStorage.setItem('playlists', JSON.stringify(playlists));
}

function addToWatchHistory(item) {
    const existingIndex = watchHistory.findIndex(h => h.id === item.id);
    
    if (existingIndex !== -1) {
        watchHistory[existingIndex].lastWatched = new Date().toISOString();
        watchHistory[existingIndex].progress = Math.min(100, watchHistory[existingIndex].progress + 10);
    } else {
        watchHistory.unshift({
            ...item,
            lastWatched: new Date().toISOString(),
            progress: 10
        });
    }
    
    watchHistory = watchHistory.slice(0, 20); // Keep only last 20 items
    localStorage.setItem('watchHistory', JSON.stringify(watchHistory));
}

function updateWatchHistory() {
    // Update continue watching section if on home page
    if (window.location.pathname.endsWith('index.html') || window.location.pathname === '/') {
        loadContinueWatching();
    }
}

function calculateProgress(item) {
    const historyItem = watchHistory.find(h => h.id === item.id);
    return historyItem ? historyItem.progress : 0;
}

function goBack() {
    window.history.back();
}

// Event Listeners
function setupEventListeners() {
    // Modal close buttons
    const closeButtons = document.querySelectorAll('.close-btn');
    closeButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('.modal-content, .video-modal, .playlist-modal');
            if (modal) {
                modal.parentElement.style.display = 'none';
            }
        });
    });
    
    // Video modal close
    const closeModal = document.getElementById('closeModal');
    if (closeModal) {
        closeModal.addEventListener('click', closeVideoModal);
    }
    
    // Create playlist
    const createPlaylistBtn = document.getElementById('createPlaylistBtn');
    const createFirstPlaylistBtn = document.getElementById('createFirstPlaylistBtn');
    const savePlaylistBtn = document.getElementById('savePlaylistBtn');
    
    [createPlaylistBtn, createFirstPlaylistBtn].forEach(btn => {
        if (btn) {
            btn.addEventListener('click', openCreatePlaylistModal);
        }
    });
    
    if (savePlaylistBtn) {
        savePlaylistBtn.addEventListener('click', createPlaylist);
    }
    
    // Add to playlist
    const addToPlaylistBtn = document.getElementById('addToPlaylistBtn');
    if (addToPlaylistBtn) {
        addToPlaylistBtn.addEventListener('click', openAddToPlaylistModal);
    }
    
    // Search functionality
    const searchInputs = document.querySelectorAll('.search-input');
    searchInputs.forEach(input => {
        input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                performSearch(input.value);
            }
        });
    });
    
    const searchButtons = document.querySelectorAll('.search-btn');
    searchButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const input = btn.previousElementSibling;
            if (input) performSearch(input.value);
        });
    });
    
    // Mobile menu toggle
    const menuToggle = document.querySelector('.menu-toggle');
    if (menuToggle) {
        menuToggle.addEventListener('click', toggleMobileMenu);
    }
    
    // Video player controls (for player.html)
    if (window.location.pathname.includes('player.html')) {
        setupPlayerControls();
    }
}

function setupPlayerControls() {
    const playPauseBtn = document.getElementById('playPauseBtn');
    const volumeBtn = document.getElementById('volumeBtn');
    const volumeSlider = document.getElementById('volumeSlider');
    const fullscreenBtn = document.getElementById('fullscreenBtn');
    const settingsBtn = document.getElementById('settingsBtn');
    const nextBtn = document.getElementById('nextBtn');
    
    if (playPauseBtn) {
        playPauseBtn.addEventListener('click', togglePlayPause);
    }
    
    if (volumeBtn) {
        volumeBtn.addEventListener('click', toggleMute);
    }
    
    if (volumeSlider) {
        volumeSlider.addEventListener('input', changeVolume);
    }
    
    if (fullscreenBtn) {
        fullscreenBtn.addEventListener('click', toggleFullscreen);
    }
    
    if (settingsBtn) {
        settingsBtn.addEventListener('click', openSettingsModal);
    }
    
    if (nextBtn && isPlayingPlaylist) {
        nextBtn.addEventListener('click', playNextVideo);
        nextBtn.style.display = 'block';
    }
    
    // Video events
    if (mainVideoPlayer) {
        mainVideoPlayer.addEventListener('ended', () => {
            if (isPlayingPlaylist) {
                playNextVideo();
            }
        });
    }
}

function setupVideoControls() {
    const videoWrapper = document.querySelector('.video-wrapper');
    if (videoWrapper) {
        videoWrapper.addEventListener('click', togglePlayPause);
    }
}

// Player Control Functions
function togglePlayPause() {
    if (!mainVideoPlayer) return;
    
    if (mainVideoPlayer.paused) {
        mainVideoPlayer.play();
        const playPauseBtn = document.getElementById('playPauseBtn');
        if (playPauseBtn) playPauseBtn.textContent = '⏸';
    } else {
        mainVideoPlayer.pause();
        const playPauseBtn = document.getElementById('playPauseBtn');
        if (playPauseBtn) playPauseBtn.textContent = '▶';
    }
}

function toggleMute() {
    if (!mainVideoPlayer) return;
    
    mainVideoPlayer.muted = !mainVideoPlayer.muted;
    const volumeBtn = document.getElementById('volumeBtn');
    if (volumeBtn) {
        volumeBtn.textContent = mainVideoPlayer.muted ? '🔇' : '🔊';
    }
}

function changeVolume(e) {
    if (!mainVideoPlayer) return;
    
    mainVideoPlayer.volume = e.target.value / 100;
}

function toggleFullscreen() {
    if (!mainVideoPlayer) return;
    
    if (!document.fullscreenElement) {
        mainVideoPlayer.requestFullscreen();
    } else {
        document.exitFullscreen();
    }
}

// Modal Functions
function closeVideoModal() {
    if (videoModal) {
        videoModal.style.display = 'none';
        videoPlayer.pause();
        videoPlayer.src = '';
    }
}

function openCreatePlaylistModal() {
    if (createPlaylistModal) {
        createPlaylistModal.style.display = 'block';
    }
}

function closeCreatePlaylistModal() {
    if (createPlaylistModal) {
        createPlaylistModal.style.display = 'none';
        const nameInput = document.getElementById('playlistName');
        const descInput = document.getElementById('playlistDescription');
        if (nameInput) nameInput.value = '';
        if (descInput) descInput.value = '';
    }
}

function openAddToPlaylistModal() {
    const videoId = videoModal?.dataset.currentVideoId || currentVideo?.id;
    if (!videoId) return;
    
    const container = document.getElementById('playlistList');
    if (!container) return;
    
    container.innerHTML = '';
    
    if (playlists.length === 0) {
        container.innerHTML = '<p style="color: var(--text-muted); text-align: center; padding: 2rem;">No playlists available. Create a playlist first!</p>';
    } else {
        playlists.forEach(playlist => {
            const item = document.createElement('div');
            item.className = 'playlist-item';
            item.innerHTML = `
                <div class="playlist-item-title">${playlist.name}</div>
                <div class="playlist-item-count">${playlist.items.length} items</div>
            `;
            item.addEventListener('click', () => addToPlaylist(playlist.id, parseInt(videoId)));
            container.appendChild(item);
        });
    }
    
    if (addToPlaylistModal) {
        addToPlaylistModal.style.display = 'block';
    }
}

function addToPlaylist(playlistId, videoId) {
    const playlist = playlists.find(p => p.id === playlistId);
    if (!playlist) return;
    
    if (playlist.items.includes(videoId)) {
        alert('This item is already in the playlist');
        return;
    }
    
    playlist.items.push(videoId);
    savePlaylists();
    renderPlaylists();
    
    if (addToPlaylistModal) {
        addToPlaylistModal.style.display = 'none';
    }
    
    alert('Added to playlist successfully!');
}

function openSettingsModal() {
    if (settingsModal) {
        settingsModal.style.display = 'block';
    }
}

function showPlaylistQueue() {
    const queue = document.getElementById('playlistQueue');
    const queueList = document.getElementById('queueList');
    
    if (!queue || !queueList || !currentPlaylist) return;
    
    queueList.innerHTML = '';
    
    currentPlaylist.items.forEach((itemId, index) => {
        const item = findContentById(itemId);
        if (item) {
            const queueItem = document.createElement('div');
            queueItem.className = 'queue-item';
            queueItem.innerHTML = `
                <img src="${item.poster}" alt="${item.title}" style="width: 60px; height: 90px; object-fit: cover; border-radius: 4px;">
                <div style="flex: 1; margin-left: 1rem;">
                    <div style="font-weight: 600;">${item.title}</div>
                    <div style="color: var(--text-muted); font-size: 0.875rem;">${item.year} • ${item.type}</div>
                </div>
                ${index === currentVideoIndex ? '<span style="color: var(--primary-purple);">▶ Playing</span>' : ''}
            `;
            queueList.appendChild(queueItem);
        }
    });
    
    queue.classList.add('open');
}

function updatePlaylistQueue() {
    showPlaylistQueue();
}

// Search Function
function performSearch(query) {
    if (!query.trim()) return;
    
    const allContent = [...mockMovies, ...mockTVShows];
    const filtered = allContent.filter(item => 
        item.title.toLowerCase().includes(query.toLowerCase()) ||
        item.description.toLowerCase().includes(query.toLowerCase()) ||
        item.genres.some(genre => genre.toLowerCase().includes(query.toLowerCase()))
    );
    
    // Update current page with search results
    const currentPage = window.location.pathname.split('/').pop();
    
    switch(currentPage) {
        case 'movies.html':
            renderMoviesGrid(filtered.filter(item => item.type === 'movie'));
            break;
        case 'tv-shows.html':
            renderTVShowsGrid(filtered.filter(item => item.type === 'tv'));
            break;
        default:
            // For home page, update all sections
            const trendingContainer = document.getElementById('trending-content');
            if (trendingContainer) {
                trendingContainer.innerHTML = '';
                filtered.slice(0, 5).forEach(item => {
                    const card = createContentCard(item);
                    trendingContainer.appendChild(card);
                });
            }
    }
}

// Mobile Menu
function toggleMobileMenu() {
    const navMenu = document.querySelector('.nav-menu');
    if (navMenu) {
        navMenu.style.display = navMenu.style.display === 'flex' ? 'none' : 'flex';
        navMenu.style.position = 'absolute';
        navMenu.style.top = '100%';
        navMenu.style.left = '0';
        navMenu.style.right = '0';
        navMenu.style.background = 'var(--bg-darker)';
        navMenu.style.flexDirection = 'column';
        navMenu.style.padding = '1rem';
        navMenu.style.borderTop = '1px solid var(--border-color)';
    }
}
