(() => {
  'use strict';
  let withdrawing = null;
  const modal = document.getElementById('communication-withdraw-modal');
  document.querySelectorAll('[data-communication-withdraw]').forEach(withdrawForm => withdrawForm.addEventListener('submit', event => {
    event.preventDefault();withdrawing = withdrawForm;
    document.getElementById('communication-withdraw-name').textContent = withdrawForm.dataset.title;
    document.getElementById('communication-withdraw-impact').textContent = ({announcement:'撤回后，该内容将从用户仪表盘移除。',message:'撤回后，该站内信将从收件人消息列表中移除。',both:'撤回后，该内容将从用户仪表盘和收件人的站内信列表中移除。'})[withdrawForm.dataset.kind];
    document.getElementById('communication-withdraw-confirm').disabled = false;
    document.getElementById('communication-withdraw-open').click();
  }));
  document.getElementById('communication-withdraw-confirm')?.addEventListener('click', event => {
    if (!withdrawing) return;
    event.currentTarget.disabled = true;HTMLFormElement.prototype.submit.call(withdrawing);
  });
  const form = document.getElementById('communication-editor');
  if (!form) return;
  const byId = id => document.getElementById(id);
  const data = JSON.parse(byId('communication-initial').textContent);
  let blocks = data.blocks || [];
  let selected = blocks.length ? 0 : -1;
  let mode = data.editor || 'blocks';
  let dragIndex = null;
  let timer;
  let sequence = 0;
  let lastHtml = '';
  const names = {text:'文本', heading:'标题', alert:'提示框', collapse:'折叠卡片', divider:'分隔线'};
  const canvas = byId('communication-canvas');
  const status = byId('communication-editor-status');
  const preview = byId('communication-preview');
  const properties = byId('communication-properties');
  const source = byId('communication-source');
  const modeSelect = byId('communication-mode');
  const title = byId('block-title');
  const content = byId('block-content');
  const tone = byId('block-tone');
  const expanded = byId('block-expanded');
  async function request(url, payload) {
    const response = await fetch(url, {method:'POST', credentials:'same-origin', headers:{'Accept':'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, body:JSON.stringify(payload)});
    const result = await response.json();
    if (!response.ok) throw new Error(response.status === 419 ? '登录状态已过期，请刷新后继续。' : Object.values(result.errors || {}).flat()[0] || result.message || '请求失败，请重试。');
    return result;
  }
  function payload() {
    return {editor:mode, source:source.value, blocks};
  }
  async function renderNow() {
    const current = ++sequence;
    status.textContent = '正在更新预览…';
    try {
      const result = await request(form.dataset.preview, payload());
      if (current !== sequence) return null;
      lastHtml = result.html;
      preview.innerHTML = lastHtml;
      status.textContent = '预览已更新';
      return lastHtml;
    } catch (error) {if (current === sequence) status.textContent = error.message;return null;}
  }
  function schedulePreview() {clearTimeout(timer);timer = setTimeout(renderNow, 250);}
  function showMode() {
    modeSelect.value = mode;
    byId('communication-visual').hidden = mode !== 'blocks';
    byId('communication-source-panel').hidden = mode !== 'source';
  }
  function showProperties() {
    const block = blocks[selected];
    properties.hidden = !block;
    if (!block) return;
    title.value = block.title || '';
    content.value = block.content || '';
    tone.value = block.tone || 'info';
    expanded.checked = !!block.expanded;
    byId('block-title-group').hidden = !['heading', 'collapse'].includes(block.type);
    byId('block-content-group').hidden = ['heading', 'divider'].includes(block.type);
    byId('block-tone-group').hidden = !['alert', 'collapse'].includes(block.type);
    byId('block-expanded-group').hidden = block.type !== 'collapse';
  }
  function move(from, to) {
    if (to < 0 || to >= blocks.length || from === to) return;
    blocks.splice(to, 0, blocks.splice(from, 1)[0]);
    selected = to;draw();schedulePreview();
  }
  function draw() {
    canvas.replaceChildren();
    if (!blocks.length) {const p = document.createElement('p');p.className = 'text-muted mb-0';p.textContent = '从上方添加组件，或切换源码模式导入已有内容。';canvas.appendChild(p);}
    blocks.forEach((block, index) => {
      const row = document.createElement('div');row.className = 'border rounded p-2 mb-2 ' + (selected === index ? 'border-primary bg-light' : '');row.draggable = true;
      const select = document.createElement('button');select.type = 'button';select.className = 'btn btn-link text-left p-0';
      select.textContent = (index + 1) + '. ' + names[block.type] + (block.title || block.content ? ' · ' + (block.title || block.content).slice(0, 40) : '');
      select.addEventListener('click', () => {selected = index;draw();});row.appendChild(select);
      const tools = document.createElement('div');tools.className = 'mt-2';
      [['上移', () => move(index,index-1)], ['下移', () => move(index,index+1)], ['删除', () => {blocks.splice(index,1);selected = Math.min(index,blocks.length-1);draw();schedulePreview();}]].forEach(([label,action]) => {
        const button = document.createElement('button');button.type = 'button';button.className = 'btn btn-outline-secondary btn-sm mr-1';button.textContent = label;
        button.setAttribute('aria-label', label + names[block.type] + '组件 ' + (index+1));button.addEventListener('click',action);tools.appendChild(button);
      });row.appendChild(tools);
      row.addEventListener('dragstart', event => {dragIndex = index;event.dataTransfer.setData('text/plain',String(index));event.dataTransfer.effectAllowed = 'move';});
      row.addEventListener('dragover', event => {if (dragIndex !== null) event.preventDefault();});
      row.addEventListener('drop', event => {if (dragIndex !== null) {event.preventDefault();move(dragIndex,index);dragIndex = null;}});
      row.addEventListener('dragend', () => {dragIndex = null;});canvas.appendChild(row);
    });
    byId('communication-blocks').value = JSON.stringify(blocks);
    showProperties();
  }
  document.querySelectorAll('[data-add]').forEach(button => button.addEventListener('click', () => {
    if (blocks.length >= 80) {status.textContent = '最多添加 80 个组件。';return;}
    blocks.push({type:button.dataset.add,title:button.dataset.add === 'heading' ? '新标题' : button.dataset.add === 'collapse' ? '折叠卡片标题' : '',content:'',tone:'info',expanded:false});
    selected = blocks.length-1;draw();schedulePreview();
  }));
  [title, content, tone, expanded].forEach(input => input.addEventListener('input', () => {
    if (!blocks[selected]) return;
    Object.assign(blocks[selected], {title:title.value,content:content.value,tone:tone.value,expanded:expanded.checked});
    byId('communication-blocks').value = JSON.stringify(blocks);schedulePreview();
  }));
  document.querySelectorAll('[data-format]').forEach(button => button.addEventListener('click', () => {
    const from = content.selectionStart, to = content.selectionEnd, text = content.value.slice(from,to) || '文字';
    const value = button.dataset.format === 'bold' ? '**'+text+'**' : button.dataset.format === 'italic' ? '*'+text+'*' : '\n- '+text;
    content.setRangeText(value,from,to,'select');content.dispatchEvent(new Event('input'));content.focus();
  }));
  async function importSource() {
    modeSelect.disabled = true;status.textContent = '正在导入组件…';
    try {const result = await request(form.dataset.import, {source:source.value});blocks = result.blocks;selected = blocks.length ? 0 : -1;mode = 'blocks';showMode();draw();await renderNow();}
    catch (error) {status.textContent = error.message;showMode();}
    finally {modeSelect.disabled = false;}
  }
  byId('communication-import').addEventListener('click', importSource);
  modeSelect.addEventListener('change', async () => {
    if (modeSelect.value === mode) return;
    if (modeSelect.value === 'blocks') {await importSource();return;}
    clearTimeout(timer);modeSelect.disabled = true;
    const html = await renderNow();
    if (html !== null) {source.value = html;mode = 'source';showMode();}
    else showMode();
    modeSelect.disabled = false;
  });
  source.addEventListener('input', schedulePreview);
  function audience() {
    const receiver = byId('communication-receiver').value;
    byId('communication-audience').hidden = byId('communication-kind').value === 'announcement';
    byId('communication-uid').disabled = receiver !== 'uid';byId('communication-email').disabled = receiver !== 'email';
  }
  byId('communication-receiver').addEventListener('change', audience);byId('communication-kind').addEventListener('change', audience);
  form.addEventListener('submit', event => {
    if (modeSelect.disabled) {event.preventDefault();status.textContent = '请等待内容转换完成。';return;}
    byId('communication-blocks').value = JSON.stringify(blocks);
    const operation = document.createElement('input');operation.type = 'hidden';operation.name = 'operation';operation.value = event.submitter?.value || 'draft';form.appendChild(operation);
    form.querySelectorAll('button[type="submit"]').forEach(button => {button.disabled = true;});status.textContent = '正在保存…';
  });
  showMode();draw();audience();renderNow();
})();
