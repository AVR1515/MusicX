const get = id => document.getElementById(id);
const {tracks, token} = JSON.parse(get('data').textContent);
const audio = get('audio');
const queue = [];
const api = 'https://api.audius.co/v1';
const titles = {all:'Tu biblioteca', liked:'Favoritos', queue:'Cola de reproducción'};
let view = 'all';
let request;
let currentTrack;
const history = [];

function control(label, symbol, action) {
    const button = document.createElement('button');
    button.type = 'button';
    button.title = label;
    button.setAttribute('aria-label', label);
    button.textContent = symbol;
    button.onclick = action;
    return button;
}

function play(track, remember = true) {
    if (remember && currentTrack && currentTrack !== track) history.push(currentTrack);
    currentTrack = track;
    get("toggle").disabled = false;
    get("now-art").style.setProperty("--hue", (track.title.charCodeAt(0) * 39) % 360);
    audio.src = track.demo ? "demo.php?track=" + track.id : track.remote ? api + '/tracks/' + encodeURIComponent(track.id) + '/stream?app_name=MusicX' : '?audio=' + track.id;
    get('playing').textContent = track.title;
    get('artist').textContent = track.artist || (track.remote ? 'Audius' : 'Biblioteca local');
    get('notice').textContent = '';
    audio.play().catch(error => {
        if (error.name !== 'AbortError') get('notice').textContent = 'Esta pista no se pudo reproducir.';
    });
}

function next() {
    if (!queue.length) return;
    const index = get('shuffle').getAttribute('aria-pressed') === 'true' ? Math.floor(Math.random() * queue.length) : 0;
    play(queue.splice(index, 1)[0]);
    render();
}

async function favorite(track, button) {
    button.disabled = true;
    try {
        const result = await fetch('./', {method:'POST', body:new URLSearchParams({token, favorite:track.id})});
        if (!result.ok || result.redirected) throw new Error();
        track.favorite = Number(!Number(track.favorite));
        render();
    } catch {
        get('notice').textContent = 'No se pudo guardar el favorito.';
        button.disabled = false;
    }
}

function rows(container, list, isQueue = false) {
    container.replaceChildren();
    list.forEach((track, index) => {
        const row = document.createElement('article');
        row.className = 'track';
        const playButton = control('Reproducir ' + track.title, '▶', () => play(track));
        playButton.className = 'art';
        playButton.style.setProperty('--hue', (track.title.charCodeAt(0) * 39) % 360);
        const details = document.createElement('div');
        const title = document.createElement('b');
        title.textContent = track.title;
        const artist = document.createElement('small');
        artist.textContent = track.artist || (track.remote ? 'Audius' : 'Biblioteca local');
        details.append(title, artist);
        const actions = document.createElement('div');
        actions.className = 'actions';
        actions.append(isQueue ? control('Quitar de la cola', '×', () => {
            queue.splice(queue.indexOf(track, index), 1);
            render();
        }) : control('Añadir a la cola', '＋', () => {
            queue.push(track);
            get('notice').textContent = 'Añadida a la cola: ' + track.title;
        }));
        if (!track.remote && !isQueue) {
            const isFavorite = Boolean(Number(track.favorite));
            const heart = control(isFavorite ? 'Quitar favorito' : 'Guardar favorito', isFavorite ? '♥' : '♡', () => favorite(track, heart));
            heart.setAttribute('aria-pressed', String(isFavorite));
            actions.append(heart, control("Consultar ficha musical", "ⓘ", () => showMetadata(track)));
        }
        row.append(playButton, details, actions);
        container.append(row);
    });
}

function render() {
    get('discover').hidden = view !== 'discover';
    get('featured').hidden = view !== 'all';
    get('collection').hidden = view === 'discover';
    get('filter').disabled = view === 'discover' || view === 'queue';
    if (view === 'discover') return;
    const query = get('filter').value.trim().toLocaleLowerCase('es');
    const list = view === 'queue' ? queue : tracks.filter(track =>
        (view !== 'liked' || Number(track.favorite)) &&
        (track.title + ' ' + track.artist).toLocaleLowerCase('es').includes(query));
    get('heading').textContent = titles[view];
    get('count').textContent = list.length + (list.length === 1 ? ' canción' : ' canciones');
    rows(get('tracks'), list, view === 'queue');
    if (!list.length) {
        const empty = document.createElement('p');
        empty.className = 'empty';
        empty.textContent = view === 'all' && !tracks.length ? 'Importa tu primera canción para empezar.' : 'Esta lista está vacía.';
        get('tracks').append(empty);
    }
}

for (const button of document.querySelectorAll('[data-view]')) {
    button.onclick = () => {
        view = button.dataset.view;
        for (const item of document.querySelectorAll('[data-view]')) {
            item.setAttribute('aria-pressed', String(item === button));
        }
        render();
    };
}
get('filter').oninput = render;
get('import').onclick = () => get('upload').showModal();
get('cancel').onclick = () => get('upload').close();
get('next').onclick = audio.onended = next;
for (const id of ['shuffle', 'repeat']) {
    get(id).onclick = event => {
        const button = event.currentTarget;
        const enabled = button.getAttribute('aria-pressed') !== 'true';
        button.setAttribute('aria-pressed', String(enabled));
        if (id === 'repeat') audio.loop = enabled;
    };
}
get('search').onsubmit = async event => {
    event.preventDefault();
    const query = get('query').value.trim();
    if (!query) return;
    request?.abort();
    const search = new AbortController();
    request = search;
    get('search-status').textContent = 'Buscando…';
    get('results').replaceChildren();
    try {
        const params = new URLSearchParams({query, app_name:'MusicX'});
        const response = await fetch(api + '/tracks/search?' + params, {signal:search.signal});
        if (!response.ok) throw new Error();
        const {data} = await response.json();
        if (search !== request) return;
        const list = data.filter(track => track.id && track.is_streamable !== false)
            .map(track => ({id:track.id, title:track.title, artist:track.user?.name, remote:true}));
        rows(get('results'), list);
        get('search-status').textContent = list.length ? list.length + ' resultados' : 'Sin coincidencias.';
    } catch (error) {
        if (error.name !== 'AbortError' && search === request) get('search-status').textContent = 'Audius no está disponible en este momento.';
    }
};
render();


function clock(seconds) {
    const value = Number.isFinite(seconds) ? Math.floor(seconds) : 0;
    return Math.floor(value / 60) + ':' + String(value % 60).padStart(2, '0');
}
function syncPlayer() {
    const playable = Number.isFinite(audio.duration) && audio.duration > 0;
    get('seek').disabled = !playable;
    get('seek').value = playable ? audio.currentTime / audio.duration * 1000 : 0;
    get('elapsed').textContent = clock(audio.currentTime);
    get('duration').textContent = clock(audio.duration);
    get('toggle').textContent = audio.paused ? '▶' : 'Ⅱ';
    get('toggle').setAttribute('aria-label', audio.paused ? 'Reproducir' : 'Pausar');
}
for (const event of ['play', 'pause', 'ended', 'timeupdate', 'durationchange', 'emptied']) {
    audio.addEventListener(event, syncPlayer);
}
get('toggle').onclick = () => {
    if (!audio.paused) audio.pause();
    else audio.play().catch(() => { get('notice').textContent = 'Esta pista no se pudo reproducir.'; });
};
get('seek').oninput = event => { audio.currentTime = audio.duration * event.target.value / 1000; };
get('volume').oninput = event => { audio.volume = Number(event.target.value); };
get('previous').onclick = () => {
    if (!currentTrack) return;
    if (audio.currentTime < 3 && history.length) {
        if (!audio.ended) queue.unshift(currentTrack);
        play(history.pop(), false);
        render();
    } else if (Number.isFinite(audio.duration)) {
        audio.currentTime = 0;
    }
};

let metadataRequest;
async function showMetadata(track) {
    const dialog = get('metadata');
    const status = get('metadata-status');
    const results = get('metadata-results');
    metadataRequest?.abort();
    const request = new AbortController();
    metadataRequest = request;
    dialog.showModal();
    status.textContent = 'Buscando en MusicBrainz…';
    results.replaceChildren();
    try {
        const response = await fetch('metadata.php', {
            method:'POST',
            body:new URLSearchParams({token, title:track.title, artist:track.artist}),
            signal:request.signal
        });
        if (!response.ok) throw new Error();
        const matches = await response.json();
        if (request !== metadataRequest) return;
        status.textContent = matches.length ? 'Coincidencias de MusicBrainz' : 'No encontramos coincidencias.';
        for (const match of matches) {
            const item = document.createElement('p');
            const link = document.createElement('a');
            link.href = 'https://musicbrainz.org/recording/' + encodeURIComponent(match.id);
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = match.title;
            const artist = (match['artist-credit'] || []).map(credit => credit.name + (credit.joinphrase || '')).join('');
            const detail = [artist, match.releases?.[0]?.title, match['first-release-date']].filter(Boolean);
            item.append(link, detail.length ? ' · ' + detail.join(' · ') : '');
            results.append(item);
        }
    } catch {
        if (request === metadataRequest && !request.signal.aborted) status.textContent = 'No se pudo consultar MusicBrainz. Intenta de nuevo.';
    }
}
get('metadata').addEventListener('close', () => metadataRequest?.abort());


const demos = [
    {id:1, title:'Neón nocturno', artist:'MusicX · Arpegio', demo:true},
    {id:2, title:'Órbita lenta', artist:'MusicX · Downtempo', demo:true},
    {id:3, title:'Luz de tránsito', artist:'MusicX · Electrónica', demo:true}
];
for (const [index, track] of demos.entries()) {
    const card = control('Escuchar ' + track.title, '', () => play(track));
    card.className = 'demo-card';
    card.style.setProperty('--hue', [275, 190, 24][index]);
    const title = document.createElement('strong');
    title.textContent = track.title;
    const subtitle = document.createElement('small');
    subtitle.textContent = track.artist;
    const icon = document.createElement('span');
    icon.className = 'demo-play';
    icon.textContent = '▶';
    icon.setAttribute('aria-hidden', 'true');
    card.append(icon, title, subtitle);
    get('demo-grid').append(card);
}
