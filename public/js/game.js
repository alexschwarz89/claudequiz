'use strict';

const CATEGORY_LABELS = {
    true_false:       'Wahr oder Falsch',
    song_guess:       'Song erraten',
    image_reveal:     'Bild erraten',
    location:         'Ort erraten',
    flag_mc:          'Flagge erkennen',
    multiple_choice:  'Multiple Choice',
    kopfrechnen:      'Kopfrechnen',
    gedaechtnisspiel: 'Gedächtnisspiel',
    film_scene:       'Film erraten',
    youtube_creator:  'Creator erraten',
};

const PLAYER_ICONS = ['🟣', '🟡'];

const IMAGE_REVEAL_SECONDS = 20;

let currentRevealImagePath = null;
let songAudio        = null;
let videoEl          = null;
let questionTimerId  = null;
let questionAnswered = false;
let questionStartedAt = 0;

const sounds = {
    right:   new Audio('/sounds/right_answer.mp3'),
    wrong:   new Audio('/sounds/wrong_answer.mp3'),
    victory: new Audio('/sounds/victory.mp3'),
};

function playSound(name) {
    const s = sounds[name];
    if (!s) return;
    s.currentTime = 0;
    s.play().catch(() => {});
}

const state = {
    players: ['Spieler 1', 'Spieler 2'],
    scores: [0, 0],
    streaks: [0, 0],
    questions: [],
    questionIndex: 0,
    playerIndex: 0,
    questionsPerPlayer: 10,
    twoPlayer: true,
    activeCategories: Object.keys(CATEGORY_LABELS),
    activeTimers: [],
    stats: null,
};

// --- Utilities ---

function el(id) { return document.getElementById(id); }

function esc(str) {
    const d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
}

function normalizeAnswer(text) {
    return String(text).toLowerCase().replace(/\s+/g, '');
}

function answerMatchesWord(input, answer) {
    const normalized = String(input).toLowerCase().trim();
    if (normalized.length === 0) return false;
    return String(answer).toLowerCase().split(/\s+/).some(word => word === normalized);
}

function showScreen(name) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    el(`screen-${name}`).classList.add('active');
}

function clearTimers() {
    state.activeTimers.forEach(id => clearInterval(id));
    state.activeTimers = [];
    if (questionTimerId !== null) { clearInterval(questionTimerId); questionTimerId = null; }
}

function setAnswered() {
    questionAnswered = true;
    if (questionTimerId !== null) { clearInterval(questionTimerId); questionTimerId = null; }
    const timerEl = el('q-timer');
    if (timerEl) { timerEl.style.display = 'none'; timerEl.className = 'q-timer'; }
}

function updateStreak(correct) {
    const pi = state.playerIndex;
    if (correct) {
        state.streaks[pi]++;
        if (state.stats && state.streaks[pi] > state.stats.maxStreak[pi]) {
            state.stats.maxStreak[pi] = state.streaks[pi];
        }
    } else {
        state.streaks[pi] = 0;
    }
}

function recordAnswer(correct) {
    if (!state.stats || online.active) return;
    const question = state.questions[state.questionIndex];
    if (!question || question.type === 'kopfrechnen') return;
    const pi = state.playerIndex;
    state.stats.answerTimes[pi].push(Date.now() - questionStartedAt);
    const cat = question.category;
    state.stats.categoryTotal[pi][cat] = (state.stats.categoryTotal[pi][cat] || 0) + 1;
    if (correct) {
        state.stats.categoryCorrect[pi][cat] = (state.stats.categoryCorrect[pi][cat] || 0) + 1;
    }
}

function renderComboBar(streak) {
    const bar = el('combo-bar');
    if (!bar) return;
    if (streak < 2) { bar.className = 'combo-bar'; return; }
    let tier = 'tier-base';
    if (streak >= 10)     tier = 'tier-legendary';
    else if (streak >= 5) tier = 'tier-gold';
    else if (streak >= 3) tier = 'tier-fire';
    bar.className   = `combo-bar ${tier}${streak > 5 ? ' combo-glow' : ''}`;
    bar.textContent = `COMBO x${streak}`;
    if (streak > 3) {
        void bar.offsetWidth;
        bar.classList.add('combo-flash');
    }
}

function resolveTimeoutMs(question) {
    if (question.type === 'song_guess')      return 60_000;
    if (question.type === 'film_scene')      return 60_000;
    if (question.type === 'youtube_creator') return 60_000;
    if (question.type === 'location')        return online.active ? 60_000 : 30_000;
    return state.players.length > 1 ? 30_000 : 60_000;
}

function startQuestionTimer(question, startEpochMs = Date.now()) {
    if (questionTimerId !== null) clearInterval(questionTimerId);
    const timeoutMs = resolveTimeoutMs(question);
    const deadline = startEpochMs + timeoutMs;
    const timerEl  = el('q-timer');
    if (timerEl) { timerEl.style.display = 'none'; timerEl.className = 'q-timer'; }

    const countdownAudio = new Audio('/sounds/countdown.mp3');
    let countdownStarted = false;

    questionTimerId = setInterval(() => {
        const remaining = Math.ceil((deadline - Date.now()) / 1000);
        if (remaining <= 10 && timerEl) {
            timerEl.style.display = 'inline-block';
            timerEl.textContent   = Math.max(0, remaining) + 's';
            timerEl.classList.toggle('urgent', remaining <= 5);
        }
        if (remaining <= 4 && !countdownStarted) {
            countdownStarted = true;
            countdownAudio.play().catch(() => {});
        }
        if (remaining <= 0) {
            clearInterval(questionTimerId);
            questionTimerId = null;
            handleTimeout(question);
        }
    }, 250);
}

function handleTimeout(question) {
    if (questionAnswered) return;
    setAnswered();
    stopAudio();
    stopVideo();
    revealCorrectAnswer(question);
    showTimeoutFeedback();
    if (online.active) {
        submitOnlineAnswer(false);
    } else {
        updateStreak(false);
        recordAnswer(false);
        el('btn-next')?.classList.add('visible');
    }
}

function revealCorrectAnswer(question) {
    el('reveal-box')?.classList.add('visible');
    el('judge-row')?.classList.remove('visible');
    if (question.type === 'true_false') {
        document.querySelectorAll('.tf-btn').forEach(b => { b.disabled = true; if (b.dataset.value === question.answer) b.classList.add('correct'); });
    } else if (question.type === 'multiple_choice') {
        document.querySelectorAll('.mc-btn').forEach(b => { b.disabled = true; if (b.dataset.value === question.answer) b.classList.add('correct'); });
    } else if (question.type === 'flag_mc') {
        document.querySelectorAll('.flag-option').forEach(o => { o.style.pointerEvents = 'none'; if (o.dataset.label === question.answer) o.classList.add('correct'); });
    } else if (question.type === 'image_reveal') {
        finishReveal(true);
    }
}

function showTimeoutFeedback() {
    const fb = el('feedback');
    if (fb) { fb.textContent = '⏱ Zeit abgelaufen!'; fb.className = 'feedback wrong'; }
}

function shuffle(arr) {
    const copy = [...arr];
    for (let i = copy.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [copy[i], copy[j]] = [copy[j], copy[i]];
    }
    return copy;
}

// --- Setup ---

Object.entries(CATEGORY_LABELS).forEach(([key, label]) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cat-btn active';
    btn.dataset.cat = key;
    btn.textContent = label;
    btn.addEventListener('click', () => btn.classList.toggle('active'));
    el('category-grid').appendChild(btn);
});

document.querySelectorAll('.mode-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const mode = btn.dataset.mode;
        const is2P = mode === '2';
        const isOnline = mode === 'online';
        el('player2-group').style.display = is2P ? '' : 'none';
        el('player-inputs').classList.toggle('single', !is2P);
        el('start-btn').style.display = isOnline ? 'none' : '';
        el('online-actions').style.display = isOnline ? 'flex' : 'none';
        el('join-error').textContent = '';
    });
});

document.querySelectorAll('.round-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.round-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    });
});

el('start-btn').addEventListener('click', async () => {
    state.twoPlayer = document.querySelector('.mode-btn.active')?.dataset.mode === '2';
    state.activeCategories = Array.from(document.querySelectorAll('.cat-btn.active')).map(b => b.dataset.cat);

    if (state.activeCategories.length === 0) return;

    state.players = [el('player1-name').value.trim() || 'Spieler 1'];
    state.scores  = [0];
    state.streaks = [0];
    if (state.twoPlayer) {
        state.players.push(el('player2-name').value.trim() || 'Spieler 2');
        state.scores.push(0);
        state.streaks.push(0);
    }

    state.stats = {
        answerTimes:     state.players.map(() => []),
        categoryCorrect: state.players.map(() => ({})),
        categoryTotal:   state.players.map(() => ({})),
        maxStreak:       state.players.map(() => 0),
    };

    state.questionsPerPlayer = parseInt(document.querySelector('.round-btn.active').dataset.value);
    state.questionIndex = 0;
    state.playerIndex = 0;

    showScreen('loading');
    await loadQuestions();
});

async function loadQuestions() {
    try {
        const resp = await fetch('/api/questions');
        if (!resp.ok) { showScreen('error'); return; }

        const data = await resp.json();
        const apiQuestions = data.questions ?? [];
        const total  = state.questionsPerPlayer * (state.twoPlayer ? 2 : 1);
        const perCat = Math.ceil(total / state.activeCategories.length);

        const pool = state.activeCategories.flatMap(cat => {
            if (cat === 'kopfrechnen')      return buildKopfrechnenPool(perCat);
            if (cat === 'gedaechtnisspiel') return buildGedaechtnisspielPool(perCat);
            return shuffle(apiQuestions.filter(q => q.category === cat)).slice(0, perCat);
        });

        if (pool.length === 0) { showScreen('error'); return; }

        state.questions = shuffle(pool).slice(0, total);

        state.twoPlayer ? showTransition() : showQuestion(state.questions[0]);
    } catch {
        showScreen('error');
    }
}

function buildKopfrechnenPool(count) {
    return Array.from({ length: count }, (_, i) => ({
        id: `kopfrechnen-${i}`,
        category: 'kopfrechnen',
        type: 'kopfrechnen',
        question: 'Kopfrechnen',
        answer: '',
    }));
}

function buildGedaechtnisspielPool(count) {
    return Array.from({ length: count }, (_, i) => ({
        id: `gedaechtnisspiel-${i}`,
        category: 'gedaechtnisspiel',
        type: 'gedaechtnisspiel',
        question: 'Gedächtnisspiel',
        answer: '',
    }));
}

// --- Transition ---

function preloadNextQuestionImage() {
    const next = state.questions[state.questionIndex];
    if (next?.type !== 'image_reveal' || !next.image_path) return;
    const isFullUrl = next.image_path.startsWith('http://') || next.image_path.startsWith('https://');
    const preload = new Image();
    preload.crossOrigin = 'anonymous';
    preload.src = isFullUrl ? next.image_path : '/' + next.image_path;
}

function showTransition() {
    clearTimers();
    preloadNextQuestionImage();

    const i = state.playerIndex;
    el('transition-icon').textContent = PLAYER_ICONS[i];
    el('transition-name').textContent = state.players[i];

    el('transition-scores').innerHTML = state.players.map((name, pi) => `
        <div class="inline-score ${pi === i ? 'current' : ''}">
            <div class="score-name">${esc(name)}</div>
            <div class="score-value">${state.scores[pi]}</div>
        </div>
    `).join('');

    showScreen('transition');
}

el('ready-btn').addEventListener('click', () => {
    showQuestion(state.questions[state.questionIndex]);
});

document.addEventListener('keydown', e => {
    if (e.key !== 'Enter' || e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (el('screen-transition')?.classList.contains('active')) { el('ready-btn')?.click(); return; }
    const btnNext = el('btn-next');
    if (btnNext?.classList.contains('visible')) btnNext.click();
});

// --- Question Header ---

function updateHeader(question) {
    const total = state.questionsPerPlayer * (state.twoPlayer ? 2 : 1);
    el('q-number').textContent = `Frage ${state.questionIndex + 1} / ${total}`;
    el('q-category').textContent = CATEGORY_LABELS[question.category] ?? question.category;

    if (state.twoPlayer) {
        el('header-scores').innerHTML = state.players.map((name, i) => `
            <span class="score-chip ${i === state.playerIndex ? 'current' : ''}">
                ${esc(name)}: ${state.scores[i]}
            </span>
        `).join('');
    } else {
        el('header-scores').innerHTML = `<span class="score-chip current">${state.scores[0]} Punkte</span>`;
    }
}

// --- Question Dispatch ---

function showQuestion(question) {
    questionAnswered = false;
    questionStartedAt = Date.now();
    updateHeader(question);
    renderComboBar(state.streaks[state.playerIndex]);
    el('question-card').innerHTML = buildPlayerLabel() + buildQuestion(question);
    attachHandlers(question);
    attachReportHandler(question);
    showScreen('question');
    if (leafletMap) leafletMap.invalidateSize();
    const noTimer = question.type === 'kopfrechnen' || question.type === 'gedaechtnisspiel';
    if (!online.active && !noTimer) startQuestionTimer(question);
}

function attachReportHandler(question) {
    const btn = el('btn-report');
    if (question.type === 'kopfrechnen' || question.type === 'gedaechtnisspiel') { btn.style.display = 'none'; return; }
    btn.style.display = '';
    btn.textContent = 'Frage entfernen';
    btn.disabled = false;
    btn.classList.remove('btn-report--reported');
    btn.onclick = () => reportQuestion(question.id, btn);
}

async function reportQuestion(id, btn) {
    btn.disabled = true;
    await fetch('/api/report', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
    }).catch(() => {});
    btn.textContent = 'Frage wurde entfernt.';
    btn.classList.add('btn-report--reported');
}

function buildPlayerLabel() {
    if (!state.twoPlayer) return '';
    return `<div class="player-label">${esc(state.players[state.playerIndex])} ist dran</div>`;
}

function buildQuestion(question) {
    const builders = {
        true_false:       () => buildTrueFalse(question),
        song_guess:       () => buildSongGuess(question),
        image_reveal:     () => buildImageReveal(question),
        location:         () => buildLocation(question),
        flag_mc:          () => buildFlagMc(question),
        multiple_choice:  () => buildMultipleChoice(question),
        kopfrechnen:      () => buildKopfrechnen(question),
        gedaechtnisspiel: () => buildGedaechtnisspiel(question),
        film_scene:       () => buildVideoGuess(question),
        youtube_creator:  () => buildVideoGuess(question),
    };

    return (builders[question.type] ?? (() => ''))();
}

function attachHandlers(question) {
    const handlers = {
        true_false:       () => attachTrueFalseHandlers(question),
        song_guess:       () => attachSongGuessHandlers(question),
        image_reveal:     () => attachImageRevealHandlers(question),
        location:         () => attachLocationHandlers(question),
        flag_mc:          () => attachFlagMcHandlers(question),
        multiple_choice:  () => attachMultipleChoiceHandlers(question),
        kopfrechnen:      () => attachKopfrechnenHandlers(question),
        gedaechtnisspiel: () => attachGedaechtnisspielHandlers(question),
        film_scene:       () => attachVideoGuessHandlers(question),
        youtube_creator:  () => attachVideoGuessHandlers(question),
    };

    (handlers[question.type] ?? (() => {}))();
}

// --- True / False ---

function buildTrueFalse(question) {
    return `
        <p class="question-text">${esc(question.question)}</p>
        <div class="tf-buttons">
            <button class="tf-btn" data-value="true">✓ Wahr</button>
            <button class="tf-btn" data-value="false">✗ Falsch</button>
        </div>
        <div class="feedback" id="feedback"></div>
        <button class="btn btn-primary btn-next" id="btn-next">Weiter →</button>
    `;
}

function attachTrueFalseHandlers(question) {
    el('btn-next').addEventListener('click', advance);

    document.querySelectorAll('.tf-btn').forEach(btn => {
        btn.addEventListener('click', () => handleTrueFalseClick(btn, question));
    });
}

function handleTrueFalseClick(clicked, question) {
    if (questionAnswered) return;
    setAnswered();
    document.querySelectorAll('.tf-btn').forEach(b => { b.disabled = true; });

    const correct = clicked.dataset.value === question.answer;

    document.querySelectorAll('.tf-btn').forEach(btn => {
        if (btn.dataset.value === question.answer) btn.classList.add('correct');
        else if (btn === clicked) btn.classList.add('wrong');
    });

    const feedback = el('feedback');
    if (correct) {
        if (!online.active) { state.scores[state.playerIndex]++; updateStreak(true); recordAnswer(true); }
        feedback.textContent = '✓ Richtig! +1 Punkt';
        feedback.className = 'feedback correct';
        playSound('right');
    } else {
        if (!online.active) { updateStreak(false); recordAnswer(false); }
        feedback.textContent = `✗ Falsch! Richtig war: ${question.answer === 'true' ? 'Wahr' : 'Falsch'}`;
        feedback.className = 'feedback wrong';
        playSound('wrong');
    }

    if (online.active) { submitOnlineAnswer(correct); } else { el('btn-next').classList.add('visible'); }
}

// --- Song Guess ---

function buildSongGuess(question) {
    return `
        <p class="question-text">${esc(question.question)}</p>
        <div class="song-player">
            <button class="play-btn" id="play-btn">▶</button>
            <div class="song-progress-wrap">
                <div class="song-progress-bar">
                    <div class="song-progress-fill" id="song-progress"></div>
                </div>
                <span class="song-time" id="song-time">0:00</span>
            </div>
        </div>
        <button class="btn-ghost" id="btn-reveal" style="margin-bottom:14px">Antwort einblenden</button>
        <div class="reveal-box" id="reveal-box">
            <div class="reveal-label">Antwort</div>
            <div class="reveal-answer">${esc(question.answer)}</div>
        </div>
        <div class="judge-row" id="judge-row">
            <button class="btn-judge btn-correct-j" id="btn-correct">✓ Richtig</button>
            <button class="btn-judge btn-wrong-j" id="btn-wrong">✗ Falsch</button>
        </div>
        <button class="btn btn-primary btn-next" id="btn-next">Weiter →</button>
    `;
}

function attachSongGuessHandlers(question) {
    el('btn-next').addEventListener('click', () => { stopAudio(); advance(); });
    el('btn-reveal').addEventListener('click', () => {
        setAnswered();
        el('reveal-box').classList.add('visible');
        el('judge-row').classList.add('visible');
    });
    attachJudgeHandlers();

    stopAudio();
    songAudio = new Audio(question.audio_url);

    const playBtn = el('play-btn');
    const progressEl = el('song-progress');
    const timeEl = el('song-time');

    playBtn.addEventListener('click', () => {
        if (songAudio.paused) {
            songAudio.play();
            playBtn.textContent = '⏸';
        } else {
            songAudio.pause();
            playBtn.textContent = '▶';
        }
    });

    songAudio.addEventListener('timeupdate', () => {
        const pct = songAudio.duration ? (songAudio.currentTime / songAudio.duration) * 100 : 0;
        progressEl.style.width = pct + '%';
        const s = Math.floor(songAudio.currentTime);
        timeEl.textContent = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    });

    songAudio.addEventListener('ended', () => { playBtn.textContent = '▶'; });
}

function stopAudio() {
    if (!songAudio) return;
    songAudio.pause();
    songAudio = null;
}

function stopVideo() {
    if (!videoEl) return;
    videoEl.pause();
    videoEl = null;
}

// --- Video Guess (film_scene / youtube_creator) ---

function buildVideoGuess(question) {
    return `
        <p class="question-text">${esc(question.question)}</p>
        <div class="video-player">
            <video id="video-el" src="${esc(question.video_path)}" preload="metadata" playsinline></video>
        </div>
        <div class="video-controls">
            <button class="play-btn" id="play-btn">▶</button>
            <div class="song-progress-wrap">
                <div class="song-progress-bar">
                    <div class="song-progress-fill" id="video-progress"></div>
                </div>
                <span class="song-time" id="video-time">0:00</span>
            </div>
        </div>
        <button class="btn-ghost" id="btn-reveal" style="margin-bottom:14px">Antwort einblenden</button>
        <div class="reveal-box" id="reveal-box">
            <div class="reveal-label">Antwort</div>
            <div class="reveal-answer">${esc(question.answer)}</div>
        </div>
        <div class="judge-row" id="judge-row">
            <button class="btn-judge btn-correct-j" id="btn-correct">✓ Richtig</button>
            <button class="btn-judge btn-wrong-j" id="btn-wrong">✗ Falsch</button>
        </div>
        <button class="btn btn-primary btn-next" id="btn-next">Weiter →</button>
    `;
}

function attachVideoGuessHandlers(question) {
    el('btn-next').addEventListener('click', () => { stopVideo(); advance(); });
    el('btn-reveal').addEventListener('click', () => {
        setAnswered();
        el('reveal-box').classList.add('visible');
        el('judge-row').classList.add('visible');
    });
    attachJudgeHandlers();

    stopVideo();
    stopAudio();
    videoEl = el('video-el');

    const playBtn    = el('play-btn');
    const progressEl = el('video-progress');
    const timeEl     = el('video-time');

    playBtn.addEventListener('click', () => {
        if (videoEl.paused) {
            videoEl.play();
            playBtn.textContent = '⏸';
        } else {
            videoEl.pause();
            playBtn.textContent = '▶';
        }
    });

    videoEl.addEventListener('timeupdate', () => {
        const pct = videoEl.duration ? (videoEl.currentTime / videoEl.duration) * 100 : 0;
        progressEl.style.width = pct + '%';
        const s = Math.floor(videoEl.currentTime);
        timeEl.textContent = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    });

    videoEl.addEventListener('ended', () => { playBtn.textContent = '▶'; });
}

// --- Image Reveal ---

function buildImageReveal(question) {
    return `
        <p class="question-text">${esc(question.question)}</p>
        <div class="reveal-canvas-wrap">
            <canvas id="reveal-canvas"></canvas>
            <div class="reveal-progress" id="reveal-progress">0%</div>
        </div>
        <div class="answer-row">
            <input class="answer-input" id="answer-input" type="text" placeholder="Was ist zu sehen?" autocomplete="off">
            <button class="btn-ghost" id="btn-check">Prüfen</button>
            <button class="btn-ghost" id="btn-reveal">Sofort aufdecken</button>
        </div>
        <div class="feedback" id="feedback"></div>
        <div class="reveal-box" id="reveal-box">
            <div class="reveal-label">Antwort</div>
            <div class="reveal-answer">${esc(question.answer)}</div>
        </div>
        <div class="judge-row" id="judge-row">
            <button class="btn-judge btn-correct-j" id="btn-correct">✓ Richtig</button>
            <button class="btn-judge btn-wrong-j" id="btn-wrong">✗ Falsch</button>
        </div>
        <button class="btn btn-primary btn-next" id="btn-next">Weiter →</button>
    `;
}

function attachImageRevealHandlers(question) {
    el('btn-next').addEventListener('click', advance);
    attachJudgeHandlers();

    const btnReveal = el('btn-reveal');
    const canvas    = el('reveal-canvas');
    const LOCK_SECS = 10;

    btnReveal.disabled = true;
    btnReveal.textContent = `Sofort aufdecken (${LOCK_SECS}s)`;

    let remaining = LOCK_SECS;
    const unlockTimer = setInterval(() => {
        remaining--;
        if (remaining > 0) {
            btnReveal.textContent = `Sofort aufdecken (${remaining}s)`;
        } else {
            clearInterval(unlockTimer);
            btnReveal.disabled = false;
            btnReveal.textContent = 'Sofort aufdecken';
            canvas.style.cursor = 'pointer';
        }
    }, 1000);
    state.activeTimers.push(unlockTimer);

    canvas.style.cursor = 'default';

    btnReveal.addEventListener('click', () => {
        if (btnReveal.disabled) return;
        setAnswered();
        finishReveal();
    });
    canvas.addEventListener('click', () => {
        if (remaining > 0) return;
        setAnswered();
        finishReveal();
    });

    const input = el('answer-input');

    input.addEventListener('input', () => {
        if (questionAnswered) return;
        if (answerMatchesWord(input.value, question.answer)) {
            judgeImageReveal(true, question);
        }
    });

    const checkAnswer = () => {
        if (questionAnswered) return;
        judgeImageReveal(answerMatchesWord(input.value, question.answer), question);
    };

    el('btn-check').addEventListener('click', checkAnswer);
    input.addEventListener('keydown', e => { if (e.key === 'Enter') checkAnswer(); });

    startProgressiveReveal(question.image_path);
}

function judgeImageReveal(correct, question) {
    setAnswered();
    el('answer-input').disabled = true;
    finishReveal(true);

    const feedback = el('feedback');
    if (correct) {
        if (!online.active) { state.scores[state.playerIndex]++; updateStreak(true); recordAnswer(true); }
        feedback.textContent = '✓ Richtig! +1 Punkt';
        feedback.className = 'feedback correct';
        playSound('right');
    } else {
        if (!online.active) { updateStreak(false); recordAnswer(false); }
        feedback.textContent = `✗ Falsch! Richtig war: ${question.answer}`;
        feedback.className = 'feedback wrong';
        playSound('wrong');
    }

    if (online.active) { submitOnlineAnswer(correct); } else { el('btn-next').classList.add('visible'); }
}

function startProgressiveReveal(imagePath) {
    // Support both local paths and full URLs
    const isFullUrl = imagePath.startsWith('http://') || imagePath.startsWith('https://');
    currentRevealImagePath = isFullUrl ? imagePath : '/' + imagePath;

    const canvas = el('reveal-canvas');
    const ctx = canvas.getContext('2d');
    const img = new Image();

    // Enable CORS for external images (Pixabay, etc.)
    img.crossOrigin = 'anonymous';

    img.onload = () => {
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;

        const COLS = 24;
        const ROWS = 18;
        const tileW = Math.ceil(img.naturalWidth / COLS);
        const tileH = Math.ceil(img.naturalHeight / ROWS);

        const tiles = [];
        for (let r = 0; r < ROWS; r++) {
            for (let c = 0; c < COLS; c++) {
                tiles.push([c * tileW, r * tileH, tileW, tileH]);
            }
        }

        const order = shuffle(tiles);
        let revealed = 0;

        ctx.fillStyle = '#1e293b';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        const intervalMs = (IMAGE_REVEAL_SECONDS * 1000) / order.length;

        const timer = setInterval(() => {
            const progressEl = el('reveal-progress');
            if (!progressEl) { clearInterval(timer); return; }

            if (revealed >= order.length) {
                clearInterval(timer);
                finishReveal();
                return;
            }

            const [x, y, w, h] = order[revealed++];
            ctx.drawImage(img, x, y, w, h, x, y, w, h);

            const pct = Math.round((revealed / order.length) * 100);
            progressEl.textContent = pct + '%';
        }, intervalMs);

        state.activeTimers.push(timer);
    };

    img.onerror = () => {
        console.error('Failed to load image:', imagePath);
        // Still show a reveal canvas with error state
        const canvas = el('reveal-canvas');
        canvas.width = 400;
        canvas.height = 300;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#dc2626';
        ctx.fillRect(0, 0, 400, 300);
        ctx.fillStyle = '#fff';
        ctx.font = '16px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Bild konnte nicht geladen werden', 200, 150);
    };

    img.src = isFullUrl ? imagePath : '/' + imagePath;
}

function finishReveal(autoJudge = false) {
    clearTimers();
    const canvas = el('reveal-canvas');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const img = new Image();
    img.onload = () => ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    img.src = currentRevealImagePath;

    const progressEl = el('reveal-progress');
    if (progressEl) progressEl.textContent = '100%';

    el('reveal-box').classList.add('visible');
    if (!autoJudge) el('judge-row').classList.add('visible');
}

// --- Location ---

let leafletMap = null;
let playerMarker = null;

function buildLocation(question) {
    return `
        <p class="question-text">${esc(question.question)}</p>
        <img class="location-img" src="/${esc(question.image_path)}" alt="Ort">
        <div id="map-container"></div>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px">Klicke auf die Karte, um den Ort zu markieren.</p>
        <button class="btn-ghost" id="btn-reveal" style="margin-bottom:12px">Ort einblenden</button>
        <div class="reveal-box" id="reveal-box">
            <div class="reveal-label">Ort</div>
            <div class="reveal-answer">${esc(question.answer)}</div>
        </div>
        <div class="judge-row" id="judge-row">
            <button class="btn-judge btn-correct-j" id="btn-correct">✓ Richtig</button>
            <button class="btn-judge btn-wrong-j" id="btn-wrong">✗ Falsch</button>
        </div>
        <button class="btn btn-primary btn-next" id="btn-next">Weiter →</button>
    `;
}

function attachLocationHandlers(question) {
    el('btn-next').addEventListener('click', () => { cleanupMap(); advance(); });
    attachJudgeHandlers();

    if (leafletMap) { leafletMap.remove(); leafletMap = null; }
    playerMarker = null;

    leafletMap = L.map('map-container').setView([20, 0], 2);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap',
        maxZoom: 10,
    }).addTo(leafletMap);

    leafletMap.on('click', e => {
        if (playerMarker) playerMarker.remove();
        playerMarker = L.marker(e.latlng, { title: 'Deine Auswahl' }).addTo(leafletMap);
    });

    el('btn-reveal').addEventListener('click', () => {
        setAnswered();
        L.marker([question.latitude, question.longitude], {
            icon: L.divIcon({ className: '', html: '<div style="background:#4ade80;width:14px;height:14px;border-radius:50%;border:2px solid #fff"></div>', iconSize: [14, 14] }),
        }).addTo(leafletMap).bindPopup(question.answer).openPopup();

        leafletMap.setView([question.latitude, question.longitude], 5);
        el('reveal-box').classList.add('visible');
        el('judge-row').classList.add('visible');
    });
}

function cleanupMap() {
    if (!leafletMap) return;
    leafletMap.remove();
    leafletMap = null;
    playerMarker = null;
}

// --- Flag MC ---

function buildFlagMc(question) {
    const opts = (question.options ?? []).map((opt, i) => `
        <div class="flag-option" data-index="${i}" data-label="${esc(opt.label)}">
            <img src="/${esc(opt.image_path)}" alt="Flagge">
        </div>
    `).join('');

    return `
        <p class="question-text">${esc(question.question)}</p>
        <div class="flag-options">${opts}</div>
        <div class="feedback" id="feedback"></div>
        <button class="btn btn-primary btn-next" id="btn-next">Weiter →</button>
    `;
}

function attachFlagMcHandlers(question) {
    el('btn-next').addEventListener('click', advance);

    document.querySelectorAll('.flag-option').forEach(opt => {
        opt.addEventListener('click', () => handleFlagMcClick(opt, question));
    });
}

function handleFlagMcClick(clicked, question) {
    if (questionAnswered) return;
    setAnswered();
    document.querySelectorAll('.flag-option').forEach(o => {
        o.style.pointerEvents = 'none';
        o.style.cursor = 'default';
    });

    const correct = clicked.dataset.label === question.answer;

    document.querySelectorAll('.flag-option').forEach(opt => {
        if (opt.dataset.label === question.answer) opt.classList.add('correct');
        else if (opt === clicked) opt.classList.add('wrong');
    });

    const feedback = el('feedback');
    if (correct) {
        if (!online.active) { state.scores[state.playerIndex]++; updateStreak(true); recordAnswer(true); }
        feedback.textContent = '✓ Richtig! +1 Punkt';
        feedback.className = 'feedback correct';
        playSound('right');
    } else {
        if (!online.active) { updateStreak(false); recordAnswer(false); }
        feedback.textContent = `✗ Falsch! Richtig war: ${question.answer}`;
        feedback.className = 'feedback wrong';
        playSound('wrong');
    }

    if (online.active) { submitOnlineAnswer(correct); } else { el('btn-next').classList.add('visible'); }
}

// --- Multiple Choice ---

function buildMultipleChoice(question) {
    const opts = (question.options ?? []).map(opt => `
        <button class="mc-btn" data-value="${esc(opt)}">${esc(opt)}</button>
    `).join('');

    return `
        <p class="question-text">${esc(question.question)}</p>
        <div class="mc-buttons">${opts}</div>
        <div class="feedback" id="feedback"></div>
        <button class="btn btn-primary btn-next" id="btn-next">Weiter →</button>
    `;
}

function attachMultipleChoiceHandlers(question) {
    el('btn-next').addEventListener('click', advance);

    document.querySelectorAll('.mc-btn').forEach(btn => {
        btn.addEventListener('click', () => handleMcClick(btn, question));
    });
}

function handleMcClick(clicked, question) {
    if (questionAnswered) return;
    setAnswered();
    document.querySelectorAll('.mc-btn').forEach(b => { b.disabled = true; });

    const correct = clicked.dataset.value === question.answer;

    document.querySelectorAll('.mc-btn').forEach(btn => {
        if (btn.dataset.value === question.answer) btn.classList.add('correct');
        else if (btn === clicked) btn.classList.add('wrong');
    });

    const feedback = el('feedback');
    if (correct) {
        if (!online.active) { state.scores[state.playerIndex]++; updateStreak(true); recordAnswer(true); }
        feedback.textContent = '✓ Richtig! +1 Punkt';
        feedback.className = 'feedback correct';
        playSound('right');
    } else {
        if (!online.active) { updateStreak(false); recordAnswer(false); }
        feedback.textContent = `✗ Falsch! Richtig war: ${question.answer}`;
        feedback.className = 'feedback wrong';
        playSound('wrong');
    }

    if (online.active) { submitOnlineAnswer(correct); } else { el('btn-next').classList.add('visible'); }
}

// --- Shared: Judge Buttons ---

function attachJudgeHandlers() {
    const btnCorrect = el('btn-correct');
    const btnWrong = el('btn-wrong');
    const btnNext = el('btn-next');
    const judgeRow = el('judge-row');

    if (!btnCorrect || !btnNext) return;

    btnCorrect.addEventListener('click', () => {
        setAnswered();
        judgeRow.classList.remove('visible');
        playSound('right');
        if (online.active) { submitOnlineAnswer(true); return; }
        state.scores[state.playerIndex]++;
        updateStreak(true);
        recordAnswer(true);
        btnNext.classList.add('visible');
    });

    btnWrong.addEventListener('click', () => {
        setAnswered();
        judgeRow.classList.remove('visible');
        playSound('wrong');
        if (online.active) { submitOnlineAnswer(false); return; }
        updateStreak(false);
        recordAnswer(false);
        btnNext.classList.add('visible');
    });
}

// --- Advance ---

function advance() {
    clearTimers();
    stopAudio();
    stopVideo();
    state.questionIndex++;

    if (state.questionIndex >= state.questions.length) {
        showResults();
        return;
    }

    if (state.twoPlayer) {
        state.playerIndex = (state.playerIndex + 1) % 2;
        showTransition();
    } else {
        showQuestion(state.questions[state.questionIndex]);
    }
}

// --- Stats ---

function buildStatsHtml() {
    if (!state.stats || online.active) return '';

    const items = [];

    const fastestPerPlayer = state.players.map((_, pi) => {
        const times = state.stats.answerTimes[pi];
        return times.length > 0 ? Math.min(...times) : null;
    });
    const validFastest = fastestPerPlayer.flatMap((t, pi) => t !== null ? [{ pi, ms: t }] : []);
    if (validFastest.length > 0) {
        validFastest.sort((a, b) => a.ms - b.ms);
        const { pi, ms } = validFastest[0];
        const value = state.twoPlayer
            ? `${esc(state.players[pi])} — ${(ms / 1000).toFixed(1)}s`
            : `${(ms / 1000).toFixed(1)}s`;
        items.push({ icon: '⚡', label: 'Schnellste Antwort', value });
    }

    const avgParts = state.players.map((name, pi) => {
        const times = state.stats.answerTimes[pi];
        if (times.length === 0) return null;
        const avg = times.reduce((a, b) => a + b, 0) / times.length;
        return state.twoPlayer ? `${esc(name)}: ${(avg / 1000).toFixed(1)}s` : `${(avg / 1000).toFixed(1)}s`;
    }).filter(Boolean);
    if (avgParts.length > 0) {
        items.push({ icon: '⏱', label: 'Ø Antwortzeit', value: avgParts.join(' &nbsp;|&nbsp; ') });
    }

    const bestParts = state.players.map((name, pi) => {
        const totals   = state.stats.categoryTotal[pi];
        const corrects = state.stats.categoryCorrect[pi];
        const cats     = Object.keys(totals);
        if (cats.length === 0) return null;
        let bestCat = null, bestRate = -1;
        for (const cat of cats) {
            const rate = (corrects[cat] || 0) / totals[cat];
            if (rate > bestRate) { bestRate = rate; bestCat = cat; }
        }
        if (!bestCat) return null;
        const pct = Math.round(bestRate * 100);
        const label = CATEGORY_LABELS[bestCat] ?? bestCat;
        return state.twoPlayer ? `${esc(name)}: ${label} (${pct}%)` : `${label} (${pct}%)`;
    }).filter(Boolean);
    if (bestParts.length > 0) {
        items.push({ icon: '🏅', label: 'Beste Kategorie', value: bestParts.join(' &nbsp;|&nbsp; ') });
    }

    const worstParts = state.players.map((name, pi) => {
        const totals   = state.stats.categoryTotal[pi];
        const corrects = state.stats.categoryCorrect[pi];
        const cats     = Object.keys(totals);
        if (cats.length < 2) return null;
        let worstCat = null, worstRate = Infinity;
        for (const cat of cats) {
            const rate = (corrects[cat] || 0) / totals[cat];
            if (rate < worstRate) { worstRate = rate; worstCat = cat; }
        }
        if (!worstCat) return null;
        const pct = Math.round(worstRate * 100);
        const label = CATEGORY_LABELS[worstCat] ?? worstCat;
        return state.twoPlayer ? `${esc(name)}: ${label} (${pct}%)` : `${label} (${pct}%)`;
    }).filter(Boolean);
    if (worstParts.length > 0) {
        items.push({ icon: '📉', label: 'Schwächste Kategorie', value: worstParts.join(' &nbsp;|&nbsp; ') });
    }

    const maxStreaks  = state.stats.maxStreak;
    const overallMax  = Math.max(...maxStreaks);
    if (overallMax >= 2) {
        let streakValue;
        if (state.twoPlayer) {
            const leaders = maxStreaks.reduce((acc, s, pi) => s === overallMax ? [...acc, pi] : acc, []);
            streakValue = leaders.length === 1
                ? `${esc(state.players[leaders[0]])}: ${overallMax}×`
                : `${overallMax}× (beide Spieler)`;
        } else {
            streakValue = `${overallMax}×`;
        }
        items.push({ icon: '🔥', label: 'Längste Kombo-Serie', value: streakValue });
    }

    if (items.length === 0) return '';

    const rows = items.map(({ icon, label, value }) => `
        <div class="stat-row">
            <span class="stat-icon">${icon}</span>
            <span class="stat-label">${label}</span>
            <span class="stat-value">${value}</span>
        </div>
    `).join('');

    return `<div class="fun-stats"><div class="fun-stats-title">Spielstatistiken</div>${rows}</div>`;
}

// --- Results ---

async function showResults() {
    if (state.twoPlayer) {
        const [s0, s1] = state.scores;
        const winner = s0 > s1 ? 0 : s1 > s0 ? 1 : -1;

        let icon, title;
        if (s0 > s1)      { icon = '🏆'; title = `${state.players[0]} gewinnt!`; }
        else if (s1 > s0) { icon = '🏆'; title = `${state.players[1]} gewinnt!`; }
        else              { icon = '🤝'; title = 'Unentschieden!'; }

        el('results-icon').textContent = icon;
        el('results-title').textContent = title;
        el('results-scores').innerHTML = state.players.map((name, i) => `
            <div class="result-row ${i === winner ? 'winner' : ''}">
                <span class="player-name">${esc(name)}</span>
                <span class="player-score">${state.scores[i]}</span>
                ${i === winner ? '<span style="font-size:22px">🏆</span>' : ''}
            </div>
        `).join('');
    } else {
        const total = state.questions.length;
        const score = state.scores[0];
        const pct = Math.round((score / total) * 100);
        el('results-icon').textContent = pct >= 80 ? '🏆' : pct >= 50 ? '👍' : '💪';
        el('results-title').textContent = `${score} von ${total} richtig`;
        el('results-scores').innerHTML = `
            <div class="result-row winner">
                <span class="player-name">${esc(state.players[0])}</span>
                <span class="player-score">${score} / ${total}</span>
            </div>
        `;
    }

    el('results-stats').innerHTML = buildStatsHtml();
    showScreen('results');
    playSound('victory');

    const players = state.players.map((name, i) => ({ name, score: state.scores[i], total: state.questions.length }));
    await renderHighscores(players);
}

// ===================== ONLINE MULTIPLAYER =====================

const online = {
    active: false,
    code: null,
    playerId: null,
    role: null,
    phase: null,
    questionIndex: -1,
    pollTimer: null,
};

function onlinePlayerName() {
    return el('player1-name').value.trim() || 'Spieler';
}

function onlineSettings() {
    return {
        categories: Array.from(document.querySelectorAll('.cat-btn.active')).map(b => b.dataset.cat),
        count:      parseInt(document.querySelector('.round-btn.active')?.dataset.value ?? '10'),
    };
}

el('create-room-btn').addEventListener('click', async () => {
    const { categories, count } = onlineSettings();
    const resp = await fetch('/api/room', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ playerName: onlinePlayerName(), categories, count }),
    });
    const data = await resp.json();
    if (data.error) return;

    online.active   = true;
    online.code     = data.code;
    online.playerId = data.playerId;
    online.role     = 'host';

    el('lobby-code').textContent  = data.code;
    el('lobby-title').textContent = 'Raum erstellen';
    showScreen('lobby');
    startPolling();
});

el('join-room-btn').addEventListener('click', joinRoom);
el('join-code-input').addEventListener('keydown', e => { if (e.key === 'Enter') joinRoom(); });

async function joinRoom() {
    const code = el('join-code-input').value.trim().toUpperCase();
    el('join-error').textContent = '';
    if (code.length !== 4) { el('join-error').textContent = 'Bitte 4-stelligen Code eingeben'; return; }

    const resp = await fetch('/api/room/join', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ code, playerName: onlinePlayerName() }),
    });
    const data = await resp.json();
    if (data.error) { el('join-error').textContent = data.error; return; }

    online.active   = true;
    online.code     = data.code;
    online.playerId = data.playerId;
    online.role     = 'guest';

    el('lobby-code').textContent  = data.code;
    el('lobby-title').textContent = 'Beitreten';
    el('lobby-status').textContent = 'Warte auf Host…';
    showScreen('lobby');
    startPolling();
}

el('lobby-start-btn').addEventListener('click', async () => {
    await fetch(`/api/room/${online.code}/start`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ playerId: online.playerId }),
    });
});

function startPolling() {
    online.pollTimer = setInterval(pollRoom, 1000);
}

function stopPolling() {
    clearInterval(online.pollTimer);
    online.pollTimer = null;
}

el('new-game-btn').addEventListener('click', () => {
    if (!online.active) { location.reload(); return; }
    restartOnlineGame();
});

async function restartOnlineGame() {
    await fetch(`/api/room/${online.code}/restart`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ playerId: online.playerId }),
    });
    startPolling();
}

async function pollRoom() {
    try {
        const resp = await fetch(`/api/room/${online.code}`);
        if (!resp.ok) return;
        handleRoomState(await resp.json());
    } catch {}
}

function handleRoomState(room) {
    const phaseChanged    = room.phase !== online.phase;
    const questionChanged = room.questionIndex !== online.questionIndex;

    online.phase         = room.phase;
    online.questionIndex = room.questionIndex;

    if (room.phase === 'waiting') return;

    if (room.phase === 'ready') {
        if (phaseChanged) showScreen('lobby');
        updateLobbyPlayers(room);
        if (online.role === 'host') {
            el('lobby-status').textContent = `${room.guest.name} ist beigetreten!`;
            el('lobby-start-btn').style.display = '';
        } else {
            el('lobby-status').textContent = 'Warte auf Host…';
        }
        return;
    }

    if (room.phase === 'question' && (phaseChanged || questionChanged)) {
        clearTimers();
        stopAudio();
        showOnlineQuestion(room);
        return;
    }

    if (room.phase === 'reveal' && phaseChanged) {
        showOnlineReveal(room);
        return;
    }

    if (room.phase === 'finished' && phaseChanged) {
        stopPolling();
        showOnlineResults(room);
    }
}

function updateLobbyPlayers(room) {
    const players = [
        { player: room.host,  role: 'Host' },
        { player: room.guest, role: 'Gast' },
    ].filter(({ player }) => player !== null);

    el('lobby-players').innerHTML = players.map(({ player, role }) => `
        <div class="lobby-player">
            <span class="lobby-player-name">${esc(player.name)}</span>
            <span class="lobby-player-role">${role}</span>
        </div>
    `).join('');
}

function showOnlineQuestion(room) {
    questionAnswered = false;
    const question = room.questions[room.questionIndex];
    const total    = room.questions.length;

    el('q-number').textContent   = `Frage ${room.questionIndex + 1} / ${total}`;
    el('q-category').textContent = CATEGORY_LABELS[question.category] ?? question.category;
    updateOnlineScoreHeader(room);

    renderComboBar(room[online.role]?.streak ?? 0);
    el('question-card').innerHTML = buildQuestion(question);
    attachHandlers(question);
    attachReportHandler(question);
    showScreen('question');
    if (question.type !== 'kopfrechnen') {
        startQuestionTimer(question, (room.questionStartedAt ?? (Date.now() / 1000)) * 1000);
    }
}

function updateOnlineScoreHeader(room) {
    const hostCurrent  = online.role === 'host';
    const guestCurrent = online.role === 'guest';
    el('header-scores').innerHTML = `
        <span class="score-chip ${hostCurrent ? 'current' : ''}">${esc(room.host.name)}: ${room.host.score}</span>
        <span class="score-chip ${guestCurrent ? 'current' : ''}">${esc((room.guest ?? room.host).name)}: ${(room.guest ?? room.host).score}</span>
    `;
}

async function submitOnlineAnswer(correct) {
    setAnswered();
    showWaitingOverlay();
    await fetch(`/api/room/${online.code}/answer`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ playerId: online.playerId, correct }),
    });
}

function showWaitingOverlay() {
    const card = el('question-card');
    if (!card || card.querySelector('.waiting-overlay')) return;
    const div = document.createElement('div');
    div.className   = 'waiting-overlay';
    div.textContent = 'Warte auf Gegner…';
    card.appendChild(div);
}

function showOnlineReveal(room) {
    setAnswered();
    document.querySelector('.waiting-overlay')?.remove();
    updateOnlineScoreHeader(room);

    const oppRole   = online.role === 'host' ? 'guest' : 'host';
    const opp       = room[oppRole];
    const oppResult = room.answers[oppRole] ?? null;

    if (!opp || oppResult === null) return;

    const card = el('question-card');
    if (!card || card.querySelector('.opponent-result')) return;

    const div = document.createElement('div');
    div.className = 'opponent-result';
    if (typeof oppResult === 'number') {
        div.innerHTML = `
            <span class="opp-label">${esc(opp.name)}:</span>
            <span class="${oppResult > 0 ? 'opp-correct' : 'opp-wrong'}">${oppResult} gelöst</span>
        `;
    } else {
        div.innerHTML = `
            <span class="opp-label">${esc(opp.name)}:</span>
            <span class="${oppResult ? 'opp-correct' : 'opp-wrong'}">${oppResult ? '✓ Richtig' : '✗ Falsch'}</span>
        `;
    }
    card.appendChild(div);
}

async function showOnlineResults(room) {
    const { host, guest } = room;
    const hostWins  = host.score > guest.score;
    const guestWins = guest.score > host.score;

    let icon, title;
    if (hostWins)       { icon = '🏆'; title = `${host.name} gewinnt!`; }
    else if (guestWins) { icon = '🏆'; title = `${guest.name} gewinnt!`; }
    else                { icon = '🤝'; title = 'Unentschieden!'; }

    el('results-icon').textContent = icon;
    el('results-title').textContent = title;
    el('results-scores').innerHTML = [
        { p: host,  winner: hostWins },
        { p: guest, winner: guestWins },
    ].map(({ p, winner }) => `
        <div class="result-row ${winner ? 'winner' : ''}">
            <span class="player-name">${esc(p.name)}</span>
            <span class="player-score">${p.score}</span>
            ${winner ? '<span style="font-size:22px">🏆</span>' : ''}
        </div>
    `).join('');

    showScreen('results');
    playSound('victory');

    const total = room.questions.length;
    await renderHighscores([
        { name: room.host.name,  score: room.host.score,  total },
        { name: room.guest.name, score: room.guest.score, total },
    ]);
}

// --- Highscores ---

async function renderHighscores(players) {
    const section = el('results-highscores');
    if (!section) return;

    let scores = [];
    try {
        const resp = await fetch('/api/scores');
        if (resp.ok) scores = (await resp.json()).scores ?? [];
    } catch {}

    section.innerHTML = buildScoreTableHtml(scores);

    const qualifying = players.filter(p => p.score > 0 && qualifiesForHighscore(p.score, scores));
    if (qualifying.length === 0) return;

    const form = buildEntryForm(qualifying);
    section.appendChild(form);
    qualifying.forEach((_, i) => {
        el(`hs-save-${i}`)?.addEventListener('click', () => saveScore(i));
    });
}

function qualifiesForHighscore(score, scores) {
    if (scores.length < 10) return true;
    return score >= (scores[scores.length - 1]?.score ?? 0);
}

function buildScoreTableHtml(scores) {
    const medals = ['🥇', '🥈', '🥉'];
    const header = '<h3 class="hs-title">Highscores</h3>';
    if (scores.length === 0) return header + '<p class="hs-empty">Noch keine Einträge.</p>';
    const rows = scores.map((e, i) => `
        <div class="hs-row">
            <span class="hs-rank">${medals[i] ?? (i + 1) + '.'}</span>
            <span class="hs-name">${esc(e.name)}</span>
            <span class="hs-score">${e.score}/${e.total}</span>
            <span class="hs-date">${e.date}</span>
        </div>
    `).join('');
    return header + `<div class="hs-list" id="hs-list">${rows}</div>`;
}

function buildEntryForm(qualifying) {
    const wrap = document.createElement('div');
    wrap.className = 'hs-entry-area';
    wrap.innerHTML = '<p class="hs-qualify-msg">🎉 Highscore erreicht!</p>' +
        qualifying.map((p, i) => `
            <div class="hs-entry-row">
                <input class="hs-name-input" id="hs-input-${i}"
                       type="text" value="${esc(p.name)}"
                       data-score="${p.score}" data-total="${p.total}"
                       maxlength="30" autocomplete="off">
                <button class="btn btn-primary hs-save-btn" id="hs-save-${i}">Eintragen</button>
            </div>
        `).join('');
    return wrap;
}

async function saveScore(idx) {
    const input = el(`hs-input-${idx}`);
    const btn   = el(`hs-save-${idx}`);
    if (!input || !btn || btn.disabled) return;

    const name  = input.value.trim() || 'Anonym';
    const score = parseInt(input.dataset.score, 10);
    const total = parseInt(input.dataset.total, 10);

    btn.disabled    = true;
    btn.textContent = '…';

    try {
        await fetch('/api/scores', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ name, score, total }),
        });
        btn.textContent = '✓ Gespeichert';
        input.disabled  = true;
        await refreshScoreList();
    } catch {
        btn.disabled    = false;
        btn.textContent = 'Eintragen';
    }
}

async function refreshScoreList() {
    const listEl = el('hs-list');
    if (!listEl) return;
    try {
        const resp = await fetch('/api/scores');
        if (!resp.ok) return;
        const scores = (await resp.json()).scores ?? [];
        const medals = ['🥇', '🥈', '🥉'];
        listEl.innerHTML = scores.map((e, i) => `
            <div class="hs-row">
                <span class="hs-rank">${medals[i] ?? (i + 1) + '.'}</span>
                <span class="hs-name">${esc(e.name)}</span>
                <span class="hs-score">${e.score}/${e.total}</span>
                <span class="hs-date">${e.date}</span>
            </div>
        `).join('');
    } catch {}
}

// --- Kopfrechnen ---

function buildKopfrechnen(_question) {
    return `
        <div class="kr-header">
            <div class="kr-timer">
                <div class="kr-timer-track">
                    <div class="kr-timer-fill" id="kr-timer-fill"></div>
                </div>
                <div class="kr-timer-seconds" id="kr-seconds">30</div>
            </div>
            <div class="kr-score-display">
                <div class="kr-score-number" id="kr-solved">0</div>
                <div class="kr-score-label">gelöst</div>
            </div>
        </div>
        <div class="kr-problem-wrap">
            <div class="kr-problem" id="kr-problem">…</div>
        </div>
        <div class="kr-milestone" id="kr-milestone"></div>
        <div class="kr-input-group">
            <input type="number" id="kr-input" class="kr-input" autocomplete="off" inputmode="decimal" placeholder="?">
            <button class="kr-ok-btn" id="kr-ok">OK</button>
        </div>
        <button class="btn btn-primary btn-next" id="btn-next">Weiter →</button>
    `;
}

function attachKopfrechnenHandlers(_question) {
    const DURATION  = 30;
    const BONUS_MS  = 2000;
    const startMs   = Date.now();
    let solved        = 0;
    let bonusMs       = 0;
    let currentProblem = null;

    el('btn-next').addEventListener('click', advance);

    function nextProblem() {
        currentProblem = generateMathProblem(solved);
        el('kr-problem').textContent = currentProblem.display;
        const input = el('kr-input');
        input.value = '';
        input.focus();
    }

    function checkAnswer() {
        if (questionAnswered) return;
        const input = el('kr-input');
        const given = parseInt(input.value, 10);
        if (isNaN(given) || given !== currentProblem.result) return;

        solved++;
        bonusMs += BONUS_MS;

        if (!online.active) {
            state.scores[state.playerIndex]++;
            updateStreak(true);
            renderComboBar(state.streaks[state.playerIndex]);
        }
        el('kr-solved').textContent = solved;
        playSound('right');
        nextProblem();

        const problemEl = el('kr-problem');
        problemEl.classList.remove('kr-flash-correct');
        void problemEl.offsetWidth;
        problemEl.classList.add('kr-flash-correct');

        const fill = el('kr-timer-fill');
        fill.classList.remove('kr-bonus-flash');
        void fill.offsetWidth;
        fill.classList.add('kr-bonus-flash');

        const milestoneText = solved === 10 ? 'Good!' : solved === 15 ? 'Wow!' : solved === 20 ? 'Godlike!' : null;
        if (milestoneText) showKopfrechnenMilestone(milestoneText);
    }

    function handleWrong() {
        if (questionAnswered) return;
        if (!online.active) {
            updateStreak(false);
            renderComboBar(state.streaks[state.playerIndex]);
        }
        playSound('wrong');
        const input = el('kr-input');
        input.classList.remove('kr-flash-wrong');
        void input.offsetWidth;
        input.classList.add('kr-flash-wrong');
        input.value = '';
        input.focus();
    }

    el('kr-input').addEventListener('wheel', e => e.preventDefault(), { passive: false });

    el('kr-ok').addEventListener('click', () => {
        const given = parseInt(el('kr-input').value, 10);
        if (!isNaN(given) && given !== currentProblem.result) handleWrong();
        else checkAnswer();
    });

    el('kr-input').addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        const given = parseInt(el('kr-input').value, 10);
        if (!isNaN(given) && given !== currentProblem.result) handleWrong();
        else checkAnswer();
    });

    el('kr-input').addEventListener('input', () => {
        const given = parseInt(el('kr-input').value, 10);
        if (!isNaN(given) && given === currentProblem.result) checkAnswer();
    });

    questionTimerId = setInterval(() => {
        const remaining = Math.max(0, DURATION - (Date.now() - startMs - bonusMs) / 1000);

        el('kr-seconds').textContent = Math.ceil(remaining);
        el('kr-timer-fill').style.width = `${Math.min(100, (remaining / DURATION) * 100)}%`;

        if (remaining <= 10) el('kr-timer-fill').classList.add('warning');
        if (remaining <= 5)  {
            el('kr-timer-fill').classList.remove('warning');
            el('kr-timer-fill').classList.add('danger');
            el('kr-seconds').classList.add('urgent');
        }

        if (remaining <= 0) {
            clearInterval(questionTimerId);
            questionTimerId = null;
            finishKopfrechnenRound(solved);
        }
    }, 100);

    nextProblem();
}

function showKopfrechnenMilestone(text) {
    const el_ = el('kr-milestone');
    el_.textContent = text;
    el_.classList.remove('kr-milestone--active');
    void el_.offsetWidth;
    el_.classList.add('kr-milestone--active');
}

function finishKopfrechnenRound(solved) {
    setAnswered();
    el('kr-input').disabled = true;
    el('kr-ok').disabled    = true;

    el('kr-problem').textContent = `⏱ ${solved} Aufgabe${solved !== 1 ? 'n' : ''} gelöst!`;
    el('kr-problem').style.fontSize = 'clamp(18px, 5vw, 28px)';

    if (online.active) {
        submitOnlineKopfrechnenAnswer(solved);
    } else {
        el('btn-next').classList.add('visible');
    }
}

async function submitOnlineKopfrechnenAnswer(solved) {
    showWaitingOverlay();
    await fetch(`/api/room/${online.code}/answer`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ playerId: online.playerId, correct: solved > 0, solved }),
    });
}

function randomInt(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

function generateMathProblem(solved) {
    const level = solved < 5 ? 1 : solved < 12 ? 2 : solved < 20 ? 3 : 4;
    let a, b, op;

    if (level === 1) {
        op = Math.random() < 0.5 ? '+' : '-';
        a  = op === '+' ? randomInt(1, 15) : randomInt(2, 20);
        b  = op === '+' ? randomInt(1, 20 - a) : randomInt(1, a - 1);
    } else if (level === 2) {
        const r = Math.random();
        if (r < 0.4)      { op = '+'; a = randomInt(10, 80); b = randomInt(10, 99 - a); }
        else if (r < 0.7) { op = '-'; a = randomInt(20, 99); b = randomInt(10, a - 5); }
        else              { op = '×'; a = randomInt(2, 5);   b = randomInt(2, 5); }
    } else if (level === 3) {
        const r = Math.random();
        if (r < 0.25)     { op = '+'; a = randomInt(20, 90);  b = randomInt(10, 100 - a); }
        else if (r < 0.5) { op = '-'; a = randomInt(30, 100); b = randomInt(10, a - 10); }
        else if (r < 0.8) { op = '×'; a = randomInt(2, 9);   b = randomInt(2, 9); }
        else              { op = '÷'; b = randomInt(2, 9); a = randomInt(2, 9) * b; }
    } else {
        const r = Math.random();
        if (r < 0.2)      { op = '+'; a = randomInt(50, 150); b = randomInt(50, 200 - a); }
        else if (r < 0.4) { op = '-'; a = randomInt(100, 200); b = randomInt(50, a - 20); }
        else if (r < 0.75){ op = '×'; a = randomInt(3, 12);  b = randomInt(3, 12); }
        else              { op = '÷'; b = randomInt(2, 12); a = randomInt(2, 12) * b; }
    }

    const result = op === '+' ? a + b : op === '-' ? a - b : op === '×' ? a * b : a / b;

    return { display: `${a} ${op} ${b} = ?`, result };
}

// --- Gedächtnisspiel ---

function buildGedaechtnisspiel(_question) {
    const tiles = Array.from({ length: 16 }, (_, i) =>
        `<div class="gd-tile" id="gd-tile-${i}" data-index="${i}"></div>`
    ).join('');

    return `
        <p class="gd-status" id="gd-status">Merke dir die Reihenfolge…</p>
        <div class="gd-grid">${tiles}</div>
        <div class="gd-info" id="gd-info"></div>
        <div class="feedback" id="feedback"></div>
        <button class="btn btn-primary btn-next" id="btn-next">Weiter →</button>
    `;
}

function attachGedaechtnisspielHandlers(_question) {
    const SEQ_LENGTH = 5;
    const sequence   = generateGdSequence(SEQ_LENGTH);
    const tileEls    = Array.from({ length: 16 }, (_, i) => el(`gd-tile-${i}`));
    let playerInput  = [];
    let inputEnabled = false;

    el('btn-next').addEventListener('click', advance);

    tileEls.forEach((tile, i) => {
        tile.addEventListener('click', () => {
            if (!inputEnabled || questionAnswered) return;
            handleGdClick(i);
        });
    });

    function handleGdClick(index) {
        const expected = sequence[playerInput.length];
        playerInput.push(index);

        if (index === expected) {
            tileEls[index].classList.add('gd-correct-click');
            state.activeTimers.push(setTimeout(() => tileEls[index].classList.remove('gd-correct-click'), 300));
            el('gd-info').textContent = `${playerInput.length} / ${SEQ_LENGTH}`;

            if (playerInput.length === SEQ_LENGTH) {
                finishGd(true);
            }
        } else {
            tileEls[index].classList.add('gd-wrong-click');
            finishGd(false);
        }
    }

    function finishGd(success) {
        inputEnabled = false;
        setAnswered();

        sequence.forEach(idx => tileEls[idx].classList.add('gd-reveal'));

        const correctCount = playerInput.length - (success ? 0 : 1);
        const fb = el('feedback');

        if (success) {
            if (!online.active) {
                state.scores[state.playerIndex]++;
                updateStreak(true);
                renderComboBar(state.streaks[state.playerIndex]);
            }
            fb.textContent = `✓ Perfekt! Alle ${SEQ_LENGTH} richtig! +1 Punkt`;
            fb.className   = 'feedback correct';
            playSound('right');
        } else {
            if (!online.active) {
                updateStreak(false);
                renderComboBar(state.streaks[state.playerIndex]);
            }
            fb.textContent = `✗ ${correctCount} / ${SEQ_LENGTH} richtig.`;
            fb.className   = 'feedback wrong';
            playSound('wrong');
        }

        el('btn-next').classList.add('visible');
    }

    // Schedule playback using state.activeTimers so clearTimers() stops it cleanly
    let t = 600;
    for (let step = 0; step < sequence.length; step++) {
        const idx = sequence[step];
        state.activeTimers.push(setTimeout(() => tileEls[idx].classList.add('gd-lit'),    t));
        state.activeTimers.push(setTimeout(() => tileEls[idx].classList.remove('gd-lit'), t + 550));
        t += 800;
    }

    state.activeTimers.push(setTimeout(() => {
        inputEnabled = true;
        tileEls.forEach(tile => tile.classList.add('gd-interactive'));
        el('gd-status').textContent = 'Deine Reihenfolge!';
        el('gd-info').textContent   = `0 / ${SEQ_LENGTH}`;
    }, t + 150));
}

function generateGdSequence(length) {
    const seq = [];
    let prev = -1;
    for (let i = 0; i < length; i++) {
        let next;
        do { next = randomInt(0, 15); } while (next === prev);
        seq.push(next);
        prev = next;
    }
    return seq;
}
