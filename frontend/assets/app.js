(async () => {
  const API_BASE = '/wp-json/sc/v1';
  const token = new URLSearchParams(window.location.search).get('t') || '';
  const consentCard = document.getElementById('consent-card');
  const surveyCard = document.getElementById('survey-card');
  const resultCard = document.getElementById('result-card');
  const startButton = document.getElementById('start-button');
  const consentCheck = document.getElementById('consent-check');
  const startError = document.getElementById('start-error');
  const backWarning = document.getElementById('back-warning');
  const surveyForm = document.getElementById('survey-form');
  const surveyError = document.getElementById('survey-error');
  const nextButton = document.getElementById('next-button');
  const prevButton = document.getElementById('prev-button');
  const stepLabel = document.getElementById('step-label');
  const progressPercent = document.getElementById('progress-percent');
  const progressFill = document.getElementById('progress-fill');
  const surveyTitle = document.getElementById('survey-title');
  const resultSummary = document.getElementById('result-summary');
  const resultMessages = document.getElementById('result-messages');
  const resultError = document.getElementById('result-error');
  const interviewButton = document.getElementById('interview-request-button');
  const pdfButton = document.getElementById('pdf-generate-button');

  const state = {
    token,
    questions: [],
    answers: {},
    status: 'init',
    step: 0,
    chunkSize: 10,
    tokenValid: false,
    readOnly: false,
    result: null,
    frontend: {
      paused: false,
      pause_message: '現在受付を中止しています。',
      base_path: '/st-check-r8',
    },
  };

  function showError(node, text) {
    if (!node) return;
    node.textContent = text;
    node.hidden = !text;
  }

  function updateStartButton() {
    if (!startButton) return;
    if (state.readOnly) {
      startButton.disabled = false;
      startButton.textContent = '結果を見る';
      return;
    }
    startButton.textContent = '回答を開始する';
    startButton.disabled = !state.tokenValid || !consentCheck?.checked;
  }

  function showBackWarning() {
    if (!backWarning) return;
    backWarning.hidden = false;
    backWarning.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  async function loadFrontendConfig() {
    try {
      const response = await fetch('./config.json', { cache: 'no-store' });
      if (!response.ok) {
        return;
      }
      const config = await response.json();
      state.frontend.base_path = typeof config.frontend_base_path === 'string' && config.frontend_base_path
        ? config.frontend_base_path
        : state.frontend.base_path;
      state.frontend.paused = Boolean(config.paused);
      state.frontend.pause_message = typeof config.pause_message === 'string' && config.pause_message.trim()
        ? config.pause_message.trim()
        : state.frontend.pause_message;
    } catch (error) {
      // config.json が無い環境では既定値のまま継続する。
    }
  }

  function renderPausedPage() {
    const message = state.frontend.pause_message || '現在受付を中止しています。';
    const shell = document.querySelector('.app-shell');
    if (shell) {
      shell.replaceChildren();
      const card = document.createElement('section');
      card.className = 'card';
      const heading = document.createElement('h1');
      heading.textContent = '公開停止中';
      const body = document.createElement('p');
      body.textContent = message;
      card.append(heading, body);
      shell.appendChild(card);
    }
  }

  async function request(path, method = 'GET', body = null) {
    const res = await fetch(`${API_BASE}${path}`, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : null,
    });
    return await res.json();
  }

  function currentChunk() {
    const start = state.step * state.chunkSize;
    return state.questions.slice(start, start + state.chunkSize);
  }

  const optionLabels = {
    1: 'そうだ',
    2: 'まあそうだ',
    3: 'ややちがう',
    4: 'ちがう',
  };

  function createOption(questionNo, value, checked) {
    const label = document.createElement('label');
    label.className = 'option-pill';

    const input = document.createElement('input');
    input.type = 'radio';
    input.name = `q-${questionNo}`;
    input.value = String(value);
    if (checked) input.checked = true;

    const number = document.createElement('span');
    number.className = 'option-pill__number';
    number.textContent = String(value);

    const caption = document.createElement('span');
    caption.className = 'option-pill__caption';
    caption.textContent = optionLabels[value] || String(value);

    label.append(input, number, caption);
    return label;
  }

  function createQuestionElement(question) {
    const article = document.createElement('article');
    article.className = 'question';

    const heading = document.createElement('h3');
    heading.textContent = `${question.question_no}. ${question.text}`;

    const group = document.createElement('div');
    group.className = 'option-group';
    group.setAttribute('role', 'radiogroup');
    group.setAttribute('aria-label', String(question.question_no));

    const current = state.answers[question.question_no] || '';
    [1, 2, 3, 4].forEach((value) => {
      group.appendChild(createOption(question.question_no, value, String(current) === String(value)));
    });

    article.append(heading, group);
    return article;
  }

  function renderChunk() {
    const chunk = currentChunk();
    const totalSteps = Math.max(1, Math.ceil(state.questions.length / state.chunkSize));
    const fragment = document.createDocumentFragment();

    chunk.forEach((question) => {
      fragment.appendChild(createQuestionElement(question));
    });

    if (surveyForm) {
      surveyForm.replaceChildren(fragment);
      surveyForm.querySelectorAll('input[type="radio"]').forEach((input) => {
        input.addEventListener('change', (event) => {
          const name = event.target.name;
          const questionNo = Number(name.replace('q-', ''));
          state.answers[questionNo] = Number(event.target.value);
        });
      });
    }

    if (surveyTitle) surveyTitle.textContent = `セクション ${chunk[0]?.section_code || ''}`;
    if (stepLabel) stepLabel.textContent = `${state.step + 1}/${totalSteps}`;
    const percent = Math.round(((state.step + 1) / totalSteps) * 100);
    if (progressPercent) progressPercent.textContent = `${percent}%`;
    if (progressFill) progressFill.style.width = `${percent}%`;
    if (prevButton) prevButton.disabled = state.step === 0;
    if (nextButton) nextButton.textContent = state.step + 1 >= totalSteps ? '送信する' : '次へ';
  }

  function validateCurrentChunk() {
    const chunk = currentChunk();
    for (const question of chunk) {
      const value = state.answers[question.question_no];
      if (![1, 2, 3, 4].includes(Number(value))) {
        return `Q${question.question_no} に回答してください。`;
      }
    }
    return '';
  }

  async function loadSurvey() {
    updateStartButton();
    if (!state.token) {
      state.tokenValid = false;
      showError(startError, 'トークンが見つかりません。');
      updateStartButton();
      return;
    }
    const data = await request(`/survey/${encodeURIComponent(state.token)}`);
    if (!data.ok) {
      state.tokenValid = false;
      showError(startError, data.error?.message || '読み込みに失敗しました。');
      updateStartButton();
      return;
    }
    state.tokenValid = true;
    state.readOnly = data.data.status === 'submitted';
    state.questions = data.data.questions || [];
    consentCard.hidden = false;
    surveyCard.hidden = true;
    if (state.readOnly) {
      if (consentCheck) consentCheck.disabled = true;
      showError(startError, 'このURLは既に回答済みです。回答内容は変更できません。結果画面で確認してください。');
    }
    updateStartButton();
  }

  async function startSurvey() {
    showError(startError, '');
    if (state.readOnly) {
      window.location.href = `./result.html?t=${encodeURIComponent(state.token)}`;
      return;
    }
    if (!state.tokenValid || !consentCheck.checked) {
      updateStartButton();
      return;
    }
    const data = await request(`/survey/${encodeURIComponent(state.token)}/start`, 'POST', {});
    if (!data.ok) {
      showError(startError, data.error?.message || '開始できませんでした。');
      return;
    }
    consentCard.hidden = true;
    surveyCard.hidden = false;
    state.step = 0;
    window.history.pushState({ stresscheckSurvey: true }, '', window.location.href);
    renderChunk();
  }

  async function nextStep() {
    const error = validateCurrentChunk();
    if (error) {
      showError(surveyError, error);
      return;
    }
    showError(surveyError, '');
    const totalSteps = Math.max(1, Math.ceil(state.questions.length / state.chunkSize));
    if (state.step + 1 < totalSteps) {
      state.step += 1;
      renderChunk();
      return;
    }
    const data = await request(`/survey/${encodeURIComponent(state.token)}/submit`, 'POST', {
      answers: state.answers,
      interview_requested: false,
    });
    if (!data.ok) {
      showError(surveyError, data.error?.message || '送信に失敗しました。');
      return;
    }
    window.location.href = `./result.html?t=${encodeURIComponent(state.token)}`;
  }

  function createResultItem(labelText, valueText) {
    const item = document.createElement('div');
    item.className = 'result-item';

    const strong = document.createElement('strong');
    strong.textContent = labelText;

    const value = document.createElement('span');
    value.textContent = valueText;

    item.append(strong, value);
    return item;
  }

  function createMessageElement(message) {
    const wrapper = document.createElement('div');
    wrapper.className = 'message';

    const title = document.createElement('strong');
    title.textContent = message.title;

    const body = document.createElement('p');
    body.textContent = message.body;

    wrapper.append(title, body);
    return wrapper;
  }

  const scoreDefinitions = [
    { key: 'score_b', label: '心身のストレス反応', shortLabel: '心身のストレス反応', max: 116, min: 29, threshold: 77, helper: '疲労感・不安感・抑うつ感などの反応スコア' },
    { key: 'score_ac', label: '仕事の負担・支援不足', shortLabel: '仕事の負担・支援不足', max: 104, min: 26, threshold: 76, helper: '仕事のストレス要因と支援不足を合わせたスコア' },
    { key: 'score_a', label: '仕事のストレス要因', shortLabel: '仕事のストレス要因', max: 68, min: 17, threshold: 44, helper: '仕事量・裁量・対人関係など' },
    { key: 'score_c', label: '支援不足', shortLabel: '支援不足', max: 36, min: 9, threshold: 24, helper: '上司・同僚・家族等からの支援不足' },
  ];

  function lower20Point(min, max) {
    return Math.round(min + ((max - min) * 0.2));
  }

  function stressPercent(value, min, max) {
    const safeValue = Number(value) || 0;
    const range = max - min;
    if (range <= 0) return 0;
    return Math.max(0, Math.min(100, Math.round(((safeValue - min) / range) * 100)));
  }

  function createScoreMeter({ key, label, value, max, min, threshold }) {
    const safeValue = Number(value) || 0;
    const percent = stressPercent(safeValue, min, max);
    const low20 = lower20Point(min, max);
    const band = safeValue >= threshold ? 'alert' : safeValue <= low20 ? 'calm' : 'watch';
    const item = document.createElement('div');
    item.className = `score-meter is-${band}`;
    item.dataset.scoreKey = key;

    const header = document.createElement('div');
    header.className = 'score-meter__header';

    const title = document.createElement('strong');
    title.className = 'score-meter__title';
    title.textContent = label;

    const score = document.createElement('span');
    score.textContent = `ストレス度${percent}%`;

    header.append(title, score);

    const track = document.createElement('div');
    track.className = 'score-meter__track';

    const bar = document.createElement('span');
    bar.className = 'score-meter__bar';
    bar.style.width = `${percent}%`;
    track.appendChild(bar);

    if (threshold) {
      const thresholdPercent = stressPercent(threshold, min, max);
      const thresholdMarker = document.createElement('span');
      thresholdMarker.className = 'score-meter__threshold';
      thresholdMarker.style.left = `${thresholdPercent}%`;
      thresholdMarker.setAttribute('aria-label', `基準値 ${threshold}点`);
      thresholdMarker.title = `基準値 ${threshold}点`;

      const thresholdLabel = document.createElement('span');
      thresholdLabel.className = 'score-meter__threshold-label';
      thresholdLabel.textContent = '基準';

      thresholdMarker.appendChild(thresholdLabel);
      track.appendChild(thresholdMarker);
    }

    item.append(header, track);
    return item;
  }

  function normalizedScore(value, min, max) {
    return stressPercent(value, min, max);
  }

  function calculateOverallScore(result) {
    const normalizedScores = [
      normalizedScore(result.score_b, 29, 116),
      normalizedScore(result.score_ac, 26, 104),
      normalizedScore(result.score_a, 17, 68),
      normalizedScore(result.score_c, 9, 36),
    ];
    return Math.round(normalizedScores.reduce((sum, value) => sum + value, 0) / normalizedScores.length);
  }

  function getOverallScoreBand(score) {
    if (score >= 67) return 'alert';
    if (score <= 20) return 'calm';
    return 'watch';
  }

  function createHighStressJudgment(result) {
    if (result.high_stress_flag) {
      return '高ストレス（基準値超過）';
    }

    const exceeded = scoreDefinitions
      .filter((definition) => Number(result[definition.key]) >= definition.threshold)
      .map((definition) => definition.shortLabel);

    if (exceeded.length > 0) {
      return `要注意（${exceeded.join('、')}が基準値超過）`;
    }

    return '問題なし（基準値超過項目なし）';
  }

  function createOverallScoreDonut(result) {
    const score = calculateOverallScore(result);
    const band = getOverallScoreBand(score);
    const donut = document.createElement('aside');
    donut.className = `overall-score-donut is-${band}`;
    donut.setAttribute('aria-label', `ストレス危険度 ${score}%`);
    donut.style.setProperty('--overall-score', `${score}%`);

    const inner = document.createElement('div');
    inner.className = 'overall-score-donut__inner';

    const label = document.createElement('span');
    label.className = 'overall-score-donut__label';
    label.textContent = 'ストレス危険度';

    const value = document.createElement('strong');
    value.className = 'overall-score-donut__value';
    value.textContent = `${score}%`;

    inner.append(label, value);
    donut.appendChild(inner);
    return donut;
  }

  function formatDateTime(value) {
    if (!value) return '未記録';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value).replace('T', ' ').slice(0, 16).replace(/-/g, '/');
    const pad = (number) => String(number).padStart(2, '0');
    return `${date.getFullYear()}/${pad(date.getMonth() + 1)}/${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function renderResultVisual(result) {
    const wrapper = document.createElement('div');
    wrapper.className = 'result-visual';

    const overview = document.createElement('div');
    overview.className = 'result-overview';

    const hero = document.createElement('section');
    hero.className = `result-hero ${result.high_stress_flag ? 'is-alert' : 'is-ok'}`;

    const badge = document.createElement('span');
    badge.className = 'result-hero__badge';
    badge.textContent = result.high_stress_flag ? '要確認' : '通常範囲';

    const title = document.createElement('h2');
    title.textContent = result.high_stress_flag ? '高ストレスの可能性があります' : '高ストレスには該当しません';

    const body = document.createElement('p');
    body.textContent = result.high_stress_flag
      ? '心身の反応や周囲の支援状況を確認し、必要に応じて勤務先の相談窓口へ相談してください。'
      : '今回の回答では高ストレス判定には該当しません。睡眠・休息・相談先の確認は継続してください。';

    hero.append(badge, title, body);
    overview.append(hero, createOverallScoreDonut(result));

    const meters = document.createElement('section');
    meters.className = 'score-meter-grid';
    meters.setAttribute('aria-label', 'スコア内訳');
    meters.append(...scoreDefinitions.map((definition) => createScoreMeter({ ...definition, value: result[definition.key] })));

    const summary = document.createElement('dl');
    summary.className = 'result-facts';
    summary.append(
      createResultItem('実施日時', formatDateTime(result.submitted_at)),
      createResultItem('高ストレス判定', createHighStressJudgment(result))
    );

    wrapper.append(overview, meters, summary);
    return wrapper;
  }

  function binaryFromDataUrl(dataUrl) {
    const base64 = dataUrl.split(',')[1] || '';
    return atob(base64);
  }

  function makePdfWithJpeg(jpegBinary, width, height) {
    const pageWidth = 595;
    const pageHeight = 842;
    const margin = 28;
    const imageWidth = pageWidth - margin * 2;
    const imageHeight = Math.min(pageHeight - margin * 2, imageWidth * (height / width));
    const objects = [
      '<< /Type /Catalog /Pages 2 0 R >>',
      '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
      `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageWidth} ${pageHeight}] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>`,
      `<< /Type /XObject /Subtype /Image /Width ${width} /Height ${height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpegBinary.length} >>\nstream\n${jpegBinary}\nendstream`,
      `<< /Length ${`q\n${imageWidth} 0 0 ${imageHeight} ${margin} ${pageHeight - margin - imageHeight} cm\n/Im1 Do\nQ`.length} >>\nstream\nq\n${imageWidth} 0 0 ${imageHeight} ${margin} ${pageHeight - margin - imageHeight} cm\n/Im1 Do\nQ\nendstream`,
    ];
    let pdf = '%PDF-1.4\n';
    const offsets = [0];
    objects.forEach((object, index) => {
      offsets.push(pdf.length);
      pdf += `${index + 1} 0 obj\n${object}\nendobj\n`;
    });
    const xref = pdf.length;
    pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
    offsets.slice(1).forEach((offset) => { pdf += `${String(offset).padStart(10, '0')} 00000 n \n`; });
    pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`;
    return pdf;
  }

  function nextPdfPrintCount() {
    const key = `stresscheck:pdf-print-count:${token || 'unknown'}`;
    const current = Number(localStorage.getItem(key)) || 0;
    const next = current + 1;
    localStorage.setItem(key, String(next));
    return next;
  }

  function drawWrappedText(ctx, text, x, y, maxWidth, lineHeight) {
    const chars = Array.from(String(text));
    let line = '';
    const lines = [];
    chars.forEach((char) => {
      const next = line + char;
      if (line && ctx.measureText(next).width > maxWidth) {
        lines.push(line);
        line = char;
      } else {
        line = next;
      }
    });
    if (line) lines.push(line);
    lines.forEach((lineText, index) => ctx.fillText(lineText, x, y + (index * lineHeight)));
    return y + (lines.length * lineHeight);
  }

  function drawPdfScoreCard(ctx, definition, result, x, y, width) {
    const value = Number(result[definition.key]) || 0;
    const percent = stressPercent(value, definition.min, definition.max);
    const thresholdPercent = stressPercent(definition.threshold, definition.min, definition.max);
    const low20 = lower20Point(definition.min, definition.max);
    const band = value >= definition.threshold ? 'alert' : value <= low20 ? 'calm' : 'watch';
    const color = band === 'alert' ? '#ef4444' : band === 'calm' ? '#3b82f6' : '#f59e0b';
    const cardHeight = 124;
    const trackY = y + 72;
    const trackWidth = width - 48;

    ctx.fillStyle = '#ffffff';
    ctx.strokeStyle = '#e5e7eb';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.roundRect(x, y, width, cardHeight, 18);
    ctx.fill();
    ctx.stroke();

    ctx.fillStyle = '#0f172a';
    ctx.font = '700 26px "Noto Sans JP", sans-serif';
    ctx.fillText(definition.label, x + 24, y + 42);
    ctx.fillStyle = band === 'alert' ? '#b91c1c' : band === 'calm' ? '#1d4ed8' : '#b45309';
    ctx.font = '800 24px "Noto Sans JP", sans-serif';
    ctx.textAlign = 'right';
    ctx.fillText(`ストレス度${percent}%`, x + width - 24, y + 42);
    ctx.textAlign = 'left';

    ctx.fillStyle = '#e5e7eb';
    ctx.beginPath();
    ctx.roundRect(x + 24, trackY, trackWidth, 18, 9);
    ctx.fill();
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.roundRect(x + 24, trackY, Math.max(0, Math.round(trackWidth * (percent / 100))), 18, 9);
    ctx.fill();

    const thresholdX = x + 24 + Math.round(trackWidth * (thresholdPercent / 100));
    ctx.strokeStyle = '#0f172a';
    ctx.lineWidth = 4;
    ctx.beginPath();
    ctx.moveTo(thresholdX, trackY - 8);
    ctx.lineTo(thresholdX, trackY + 26);
    ctx.stroke();
    ctx.fillStyle = '#0f172a';
    ctx.font = '800 17px "Noto Sans JP", sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('基準', thresholdX, trackY - 14);
    ctx.textAlign = 'left';

    return {
      line: `${definition.label}　ストレス度${percent}%`,
      height: cardHeight,
    };
  }

  async function generateResultPdf() {
    if (!state.result || !pdfButton) return;
    pdfButton.disabled = true;
    const originalText = pdfButton.textContent;
    pdfButton.textContent = 'PDFを生成中...';
    try {
      const result = state.result;
      const printCount = nextPdfPrintCount();
      const canvas = document.createElement('canvas');
      canvas.width = 1240;
      canvas.height = 1754;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#f8fafc';
      ctx.fillRect(0, 0, canvas.width, canvas.height);

      ctx.fillStyle = '#0f172a';
      ctx.font = '700 54px "Noto Sans JP", sans-serif';
      ctx.fillText('あなたのストレスチェック結果', 72, 98);
      ctx.font = '500 26px "Noto Sans JP", sans-serif';
      ctx.fillStyle = '#475569';
      ctx.fillText(`実施日時：${formatDateTime(result.submitted_at)}`, 72, 150);

      ctx.fillStyle = '#ffffff';
      ctx.strokeStyle = '#e5e7eb';
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.roundRect(72, 190, 740, 210, 24);
      ctx.fill();
      ctx.stroke();
      ctx.fillStyle = result.high_stress_flag ? '#fee2e2' : '#dcfce7';
      ctx.beginPath();
      ctx.roundRect(102, 222, 112, 38, 19);
      ctx.fill();
      ctx.fillStyle = result.high_stress_flag ? '#b91c1c' : '#15803d';
      ctx.font = '800 22px "Noto Sans JP", sans-serif';
      ctx.fillText(result.high_stress_flag ? '要確認' : '通常範囲', 124, 249);
      ctx.font = '700 38px "Noto Sans JP", sans-serif';
      ctx.fillText(result.high_stress_flag ? '高ストレスの可能性があります' : '高ストレスには該当しません', 102, 304);
      ctx.fillStyle = '#334155';
      ctx.font = '400 24px "Noto Sans JP", sans-serif';
      drawWrappedText(ctx, result.high_stress_flag
        ? '心身の反応や周囲の支援状況を確認し、必要に応じて勤務先の相談窓口へ相談してください。'
        : '今回の回答では高ストレス判定には該当しません。睡眠・休息・相談先の確認は継続してください。', 102, 348, 660, 32);

      const overallScore = calculateOverallScore(result);
      ctx.fillStyle = '#ffffff';
      ctx.strokeStyle = '#e5e7eb';
      ctx.beginPath();
      ctx.roundRect(850, 190, 318, 210, 24);
      ctx.fill();
      ctx.stroke();
      ctx.fillStyle = '#64748b';
      ctx.font = '800 25px "Noto Sans JP", sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('ストレス危険度', 1009, 270);
      ctx.fillStyle = '#0f172a';
      ctx.font = '800 64px "Noto Sans JP", sans-serif';
      ctx.fillText(`${overallScore}%`, 1009, 344);
      ctx.textAlign = 'left';

      let y = 450;
      const scoreLines = [];
      scoreDefinitions.forEach((definition, index) => {
        const x = index % 2 === 0 ? 72 : 640;
        const cardY = y + Math.floor(index / 2) * 150;
        const drawn = drawPdfScoreCard(ctx, definition, result, x, cardY, 528);
        scoreLines.push(drawn.line);
      });

      y = 780;
      ctx.fillStyle = '#ffffff';
      ctx.strokeStyle = '#e5e7eb';
      ctx.beginPath();
      ctx.roundRect(72, y, 1096, 160, 20);
      ctx.fill();
      ctx.stroke();
      ctx.fillStyle = '#64748b';
      ctx.font = '700 22px "Noto Sans JP", sans-serif';
      ctx.fillText('実施日時', 108, y + 52);
      ctx.fillText('高ストレス判定', 108, y + 112);
      ctx.fillStyle = '#0f172a';
      ctx.font = '700 26px "Noto Sans JP", sans-serif';
      ctx.fillText(formatDateTime(result.submitted_at), 360, y + 52);
      ctx.fillText(createHighStressJudgment(result), 360, y + 112);

      y = 1000;
      ctx.fillStyle = '#0f172a';
      ctx.font = '700 30px "Noto Sans JP", sans-serif';
      ctx.fillText('セルフケア情報', 72, y);
      ctx.fillStyle = '#334155';
      ctx.font = '400 24px "Noto Sans JP", sans-serif';
      (result.messages || []).forEach((message) => {
        y = drawWrappedText(ctx, `${message.title || ''}：${message.body || ''}`, 72, y + 44, 1060, 34);
      });

      const footerLines = [
        `専門家面談希望：${result.interview_requested ? '依頼済み' : '未依頼'}`,
        `prefix：${result.token_prefix || '未記録'}`,
        `印刷回数：${printCount}回目`,
      ];
      ctx.fillStyle = '#f1f5f9';
      ctx.fillRect(0, 1590, canvas.width, 164);
      ctx.fillStyle = '#334155';
      ctx.font = '700 24px "Noto Sans JP", sans-serif';
      footerLines.forEach((line, index) => ctx.fillText(line, 72, 1638 + (index * 36)));
      ctx.fillStyle = '#64748b';
      ctx.font = '400 20px "Noto Sans JP", sans-serif';
      ctx.fillText('このPDFはブラウザ上で生成されています。URLを第三者に共有しないでください。', 72, 1730);

      window.__lastResultPdfDebug = { scoreLines, footerLines };
      const jpeg = binaryFromDataUrl(canvas.toDataURL('image/jpeg', 0.92));
      const pdf = makePdfWithJpeg(jpeg, canvas.width, canvas.height);
      const bytes = Uint8Array.from(pdf, (char) => char.charCodeAt(0));
      const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
      window.open(url, '_blank');
    } finally {
      pdfButton.disabled = false;
      pdfButton.textContent = originalText;
    }
  }

  async function renderResult() {
    if (!resultCard || !token) return;
    const data = await request(`/result/${encodeURIComponent(token)}`);
    if (!data.ok) {
      showError(resultError, data.error?.message || '結果を取得できませんでした。');
      return;
    }
    const result = data.data;
    state.result = result;

    if (resultSummary) {
      resultSummary.replaceChildren(renderResultVisual(result));
    }

    if (resultMessages) {
      const fragment = document.createDocumentFragment();
      (result.messages || []).forEach((message) => fragment.appendChild(createMessageElement(message)));
      resultMessages.replaceChildren(fragment);
    }

    if (interviewButton) {
      interviewButton.hidden = !result.high_stress_flag;
      interviewButton.textContent = result.interview_requested ? '面談依頼済み' : '専門家面談を希望';
      interviewButton.disabled = Boolean(result.interview_requested);
      interviewButton.onclick = async () => {
        const currentWidth = interviewButton.getBoundingClientRect().width;
        interviewButton.style.minWidth = `${currentWidth}px`;
        const req = await request(`/result/${encodeURIComponent(token)}/interview-request`, 'POST', { interview_requested: true });
        if (req.ok) {
          result.interview_requested = true;
          state.result = result;
          interviewButton.disabled = true;
          interviewButton.textContent = '面談依頼済み';
        }
      };
    }
  }

  if (consentCheck) {
    consentCheck.addEventListener('change', updateStartButton);
  }
  if (startButton) {
    startButton.addEventListener('click', startSurvey);
    updateStartButton();
  }
  if (nextButton) {
    nextButton.addEventListener('click', nextStep);
  }
  if (prevButton) {
    prevButton.addEventListener('click', () => {
      showError(surveyError, '');
      if (state.step > 0) {
        state.step -= 1;
        renderChunk();
      }
    });
  }
  if (pdfButton) {
    pdfButton.addEventListener('click', generateResultPdf);
  }
  window.addEventListener('popstate', () => {
    if (surveyCard && !surveyCard.hidden && !state.readOnly) {
      window.history.pushState({ stresscheckSurvey: true }, '', window.location.href);
      showBackWarning();
    }
  });

  async function boot() {
    await loadFrontendConfig();
    if (state.frontend.paused) {
      renderPausedPage();
      return;
    }

    if (window.location.pathname.endsWith('/result.html')) {
      consentCard?.remove();
      surveyCard?.remove();
      await renderResult();
    } else {
      resultCard?.remove();
      await loadSurvey();
    }
  }

  boot();
})();
