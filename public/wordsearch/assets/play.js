// Interactive selection logic
const gridEl = document.getElementById('grid');
if (gridEl) {
  const h = GRID.length, w = GRID[0].length;
  gridEl.style.setProperty('--w', w);
  let cells = [];
  for (let y=0; y<h; y++) {
    for (let x=0; x<w; x++) {
      const d = document.createElement('div');
      d.className = 'cell';
      d.dataset.x = x; d.dataset.y = y;
      d.textContent = GRID[y][x];
      gridEl.appendChild(d);
      cells.push(d);
    }
  }

  let dragging=false, start=null, end=null;
  let found = new Set();
  const wordEls = Array.from(document.querySelectorAll('.word'));
  const foundCountEl = document.getElementById('foundCount');
  const timerEl = document.getElementById('timer');
  let selections = [];

  function resetSel() {
    cells.forEach(c=>c.classList.remove('sel'));
  }

  function lineCells(x1,y1,x2,y2) {
    const dx = Math.sign(x2-x1), dy = Math.sign(y2-y1);
    let x=x1, y=y1, out=[];
    const len = Math.max(Math.abs(x2-x1), Math.abs(y2-y1))+1;
    for (let i=0;i<len;i++) { out.push([x,y]); x+=dx; y+=dy; }
    return out;
  }

  function strFromCells(arr) {
    return arr.map(([x,y]) => GRID[y][x]).join('');
  }

  function markFound(path, word) {
    path.forEach(([x,y]) => {
      const idx = y*w + x;
      cells[idx].classList.add('found');
    });
    wordEls.find(e => e.dataset.word===word)?.classList.add('found');
    found.add(word);
    foundCountEl.textContent = String(found.size);
    selections.push({word, path});
  }

  function tryMatch(x1,y1,x2,y2) {
    const dx = Math.sign(x2-x1), dy = Math.sign(y2-y1);
    if (dx===0 && dy===0) return;
    // Only straight lines (including diagonals)
    if (!(dx===0 || dy===0 || Math.abs(x2-x1)===Math.abs(y2-y1))) return;
    const path = lineCells(x1,y1,x2,y2);
    let s = strFromCells(path);
    const options = new Set([s, s.split('').reverse().join('')]);
    for (const w of WORDS) {
      if (!found.has(w) && options.has(w)) {
        markFound(path, w);
        break;
      }
    }
  }

  gridEl.addEventListener('mousedown', e => {
    const c = e.target.closest('.cell'); if (!c) return;
    dragging=true; start={x:+c.dataset.x,y:+c.dataset.y};
    resetSel(); c.classList.add('sel');
  });
  gridEl.addEventListener('mouseover', e => {
    if (!dragging) return;
    const c = e.target.closest('.cell'); if (!c) return;
    resetSel();
    end = {x:+c.dataset.x,y:+c.dataset.y};
    if (!start) return;
    const path = lineCells(start.x,start.y,end.x,end.y);
    path.forEach(([x,y]) => {
      cells[y*w+x].classList.add('sel');
    });
  });
  window.addEventListener('mouseup', e => {
    if (!dragging) return;
    dragging=false;
    if (start && end) tryMatch(start.x,start.y,end.x,end.y);
    start=end=null; resetSel();
  });

  // Timer
  let sec=0;
  const tInt = setInterval(() => {
    sec+=1;
    const mm = String(Math.floor(sec/60)).padStart(2,'0');
    const ss = String(sec%60).padStart(2,'0');
    timerEl.textContent = `${mm}:${ss}`;
  }, 1000);

  async function submitResult(done) {
    try {
      const res = await fetch('result.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
          id: RESULT_ID,
          found: found.size,
          selections: selections,
          seconds: sec,
          done: !!done
        })
      });
      return await res.json();
    } catch(e) { console.error(e); }
  }

  document.getElementById('btnFinish').addEventListener('click', async () => {
    await submitResult(true);
    alert('Result recorded. Great job!');
    window.location.href = 'index.php';
  });

  // Periodic autosave
  setInterval(() => submitResult(false), 5000);
}
