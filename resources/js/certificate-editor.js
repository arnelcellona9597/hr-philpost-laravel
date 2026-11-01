// Drag-and-drop certificate template editor: a palette of blocks, the page (drag to reorder, click to
// select) and a property panel. The layout is saved as JSON and validated on the server.
const data = JSON.parse(document.getElementById('editor-data').textContent)
const BLOCK_TYPES = data.blockTypes
const FIELDS = data.fields
const state = { blocks: data.blocks, selected: data.blocks[0]?.id ?? null, mode: 'sample' }

const form = document.getElementById('tpl-form')
const paper = document.getElementById('ed-paper')
const scaleBox = document.getElementById('ed-scale')
const box = document.getElementById('ed-box')
const inspector = document.getElementById('ed-inspector')
const warning = document.getElementById('ed-warning')
const announce = (message) => (document.getElementById('ed-announce').textContent = message)
const SIZES = { A4: [794, 1123], Letter: [816, 1056], Legal: [816, 1344] }
const ICON = {
  grip: 'M9 5h.01M9 12h.01M9 19h.01M15 5h.01M15 12h.01M15 19h.01',
  up: 'M12 19V5M5 12l7-7 7 7',
  down: 'M12 5v14M19 12l-7 7-7-7',
  copy: 'M9 9h11a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-9a2 2 0 0 1-2-2zM5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1',
  trash: 'M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6',
}
const svg = (d) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${d}"/></svg>`
const labelOf = (block) => BLOCK_TYPES.find((t) => t.type === block.type)?.label ?? block.type
const esc = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])
const newId = () => Math.random().toString(36).slice(2, 10)
const newBlock = (type) => ({ ...structuredClone(data.newBlocks[type]), id: newId() })
// Marks the form as changed for the "unsaved changes" guard.
const touch = () => form.dispatchEvent(new Event('input', { bubbles: true }))
const opt = (name) => form.elements.namedItem(name).value

// ---------- Rendering: the browser twin of App\Support\CertificateRenderer ----------
const PLACEHOLDER = /\{\{\s*([a-zA-Z][a-zA-Z0-9]*)\s*\}\}/g
const sampleValues = () => ({ ...Object.fromEntries(Object.entries(FIELDS).map(([name, field]) => [name, field.sample])), companyName: data.companyName })
const merge = (text, values) => text.replace(PLACEHOLDER, (_, key) => values[key] ?? '')
const rich = (text) =>
  text
    .split(/(\*\*[^*]+\*\*)/g)
    .filter(Boolean)
    .map((part) => (/^\*\*[^*]+\*\*$/.test(part) ? `<strong>${esc(part.slice(2, -2))}</strong>` : esc(part)))
    .join('')
    .replace(/\n/g, '<br>')
const chip = (name) => `<span class="cert-ph">${esc(name)}</span>`
const inline = (text, options) => (options.showPlaceholders ? rich(text).replace(PLACEHOLDER, (_, key) => chip(key)) : rich(merge(text, options.values)))

function renderBlock(block, options) {
  switch (block.type) {
    case 'header': {
      const name = block.companyName.trim() ? inline(block.companyName, options) : esc(data.companyName)
      const logo = block.showLogo && data.logoUrl ? `<img class="cert-logo" src="${esc(data.logoUrl)}" alt="">` : ''
      const lines = block.lines.trim() ? `<div class="cert-lines">${inline(block.lines.trim(), options)}</div>` : ''
      return `<div class="cert-header cert-${block.align}">${logo}<div><div class="cert-company">${name}</div>${lines}</div></div>`
    }
    case 'title':
      return `<div class="cert-title cert-${block.align} cert-t-${block.size}${block.wide ? ' cert-wide' : ''}">${inline(block.text, options)}</div>`
    case 'paragraph':
      return `<p class="cert-p cert-${block.align} cert-s-${block.size}${block.indent ? ' cert-indent' : ''}">${inline(block.text, options) || '&nbsp;'}</p>`
    case 'details': {
      const rows = block.fields
        .map((field) => `<tr><th>${esc(FIELDS[field]?.label ?? field)}</th><td>${options.showPlaceholders ? chip(field) : esc(options.values[field] ?? '')}</td></tr>`)
        .join('')
      return `<table class="cert-details"><tbody>${rows}</tbody></table>`
    }
    case 'signatory': {
      const columns = block.signers
        .map((signer) => `<div class="cert-signer">${signer.label ? `<div class="cert-sig-label">${inline(signer.label, options)}</div>` : ''}<div class="cert-sig-line"></div><div class="cert-sig-name">${inline(signer.name, options) || '&nbsp;'}</div><div class="cert-sig-title">${inline(signer.title, options)}</div></div>`)
        .join('')
      return `<div class="cert-signatory cert-${block.align}">${columns}</div>`
    }
    case 'spacer':
      return `<div style="height:${data.spacers[block.size]}pt"></div>`
    case 'divider':
      return '<hr class="cert-hr">'
    case 'footer':
      return `<p class="cert-footer cert-${block.align}">${inline(block.text, options)}</p>`
    default:
      return ''
  }
}

function blockTexts(block) {
  if (block.type === 'header') return [block.companyName, block.lines]
  if (block.type === 'signatory') return block.signers.flatMap((s) => [s.label, s.name, s.title])
  return 'text' in block ? [block.text] : []
}
const unknownPlaceholders = () => [...new Set(state.blocks.flatMap(blockTexts).flatMap((text) => [...text.matchAll(PLACEHOLDER)].map((m) => m[1])))].filter((name) => !(name in FIELDS))

function summary(block) {
  const text = 'text' in block ? block.text : block.type === 'signatory' ? block.signers.map((s) => s.name || s.title).join(', ') : ''
  return `${labelOf(block)}${text ? `: ${text.replace(/\*\*/g, '').slice(0, 60)}` : ''}`
}

function renderPage() {
  const [width, height] = SIZES[opt('paper_size')] ?? SIZES.A4
  paper.style.width = `${width}px`
  paper.style.minHeight = `${height}px`
  paper.style.padding = `${data.margins[opt('margin')] ?? 72}pt`
  const options = { values: sampleValues(), showPlaceholders: state.mode === 'placeholders' }
  const font = opt('font')
  const items = state.blocks
    .map((block, index) => {
      let inner = renderBlock(block, options)
      if (block.type === 'spacer') inner = `<div class="ed-empty" style="height:100%">${inner}</div>`
      if (block.type === 'details' && !block.fields.length) inner = '<div class="ed-empty">Details table: choose fields →</div>'
      return `<div class="ed-block" role="listitem" tabindex="0" data-id="${block.id}" aria-label="${esc(`${index + 1}. ${summary(block)}`)}"${block.id === state.selected ? ' aria-current="true"' : ''}>
        <div class="ed-tools">
          <button type="button" data-drag title="Drag to move" aria-label="Drag to move">${svg(ICON.grip)}</button>
          <button type="button" data-act="up" title="Move up" aria-label="Move up">${svg(ICON.up)}</button>
          <button type="button" data-act="down" title="Move down" aria-label="Move down">${svg(ICON.down)}</button>
          <button type="button" data-act="copy" title="Duplicate" aria-label="Duplicate">${svg(ICON.copy)}</button>
          <button type="button" data-act="delete" title="Delete" aria-label="Delete">${svg(ICON.trash)}</button>
        </div>${inner}</div>`
    })
    .join('')
  paper.innerHTML = `<div class="cert cert-${font}">${items || '<div class="ed-empty" style="padding:48px">Drag blocks here from the palette.</div>'}</div>`
  const unknown = unknownPlaceholders()
  warning.textContent = unknown.length ? `Unknown placeholder(s): ${unknown.map((name) => `{{${name}}}`).join(', ')} — they will be refused when saving.` : ''
  warning.classList.toggle('text-danger', unknown.length > 0)
  fit()
}
function fit() {
  const width = parseFloat(paper.style.width) || 794
  const scale = Math.min(1, (box.clientWidth - 24) / width)
  paper.style.transform = `scale(${scale})`
  paper.style.transformOrigin = 'top left'
  scaleBox.style.width = `${width * scale}px`
  scaleBox.style.height = `${paper.offsetHeight * scale}px`
}
let frame = 0
const renderSoon = () => {
  cancelAnimationFrame(frame)
  frame = requestAnimationFrame(renderPage)
}

// ---------- Inspector ----------
const selectedBlock = () => state.blocks.find((block) => block.id === state.selected) ?? null
let lastField = null

const field = (label, control, help = '') => `<div class="mb-3"><label class="label text-xs">${label}${control}</label>${help ? `<p class="field-help">${help}</p>` : ''}</div>`
const select = (key, value, options) => `<select class="input mt-1" data-key="${key}">${options.map(([v, l]) => `<option value="${v}"${v === value ? ' selected' : ''}>${l}</option>`).join('')}</select>`
const input = (key, value, placeholder = '') => `<input class="input mt-1" data-key="${key}" value="${esc(value)}" placeholder="${esc(placeholder)}">`
const area = (key, value, rows = 5) => `<textarea class="input mt-1 font-[inherit]" rows="${rows}" data-key="${key}">${esc(value)}</textarea>`
const check = (key, checked, label) => `<label class="mb-3 flex items-center gap-2 text-sm"><input type="checkbox" class="size-4 accent-[var(--primary)]" data-key="${key}"${checked ? ' checked' : ''}> ${label}</label>`
const ALIGN3 = [['left', 'Left'], ['center', 'Center'], ['right', 'Right']]
const ALIGN4 = [...ALIGN3, ['justify', 'Justified']]
const NOT_IN_DETAILS = ['fullNameUpper', 'honorific', 'pronoun', 'pronounCap', 'possessive', 'separation']

function renderInspector() {
  const block = selectedBlock()
  lastField = null
  if (!block) {
    inspector.innerHTML = '<p class="text-sm text-muted">Select a block on the page to edit it, or add one from the palette.</p>'
    return
  }
  let html = `<p class="mb-3 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-muted"><span>${esc(labelOf(block))}</span><span class="font-normal normal-case">${state.blocks.indexOf(block) + 1} of ${state.blocks.length}</span></p>`
  switch (block.type) {
    case 'header':
      html += check('showLogo', block.showLogo, 'Show logo (from Branding)')
      html += field('Company name', input('companyName', block.companyName, data.companyName), 'Leave blank to use the name from Branding.')
      html += field('Address / contact lines', area('lines', block.lines, 3))
      html += field('Layout', select('align', block.align, [['center', 'Centered'], ['left', 'Logo on the left']]))
      break
    case 'title':
      html += field('Title', input('text', block.text))
      html += field('Size', select('size', block.size, [['md', 'Medium'], ['lg', 'Large'], ['xl', 'Extra large']]))
      html += field('Alignment', select('align', block.align, [['center', 'Center'], ['left', 'Left']]))
      html += check('wide', block.wide, 'Wide letter spacing')
      break
    case 'paragraph':
      html += field('Text', area('text', block.text, 7), 'Placeholders like {{fullName}} are filled in when issued. **bold**')
      html += field('Alignment', select('align', block.align, ALIGN4))
      html += field('Text size', select('size', block.size, [['sm', 'Small'], ['md', 'Normal'], ['lg', 'Large']]))
      html += check('indent', block.indent, 'Indent the first line')
      break
    case 'footer':
      html += field('Text', area('text', block.text, 3))
      html += field('Alignment', select('align', block.align, ALIGN3))
      break
    case 'spacer':
      html += field('Height', select('size', block.size, [['sm', 'Small'], ['md', 'Medium'], ['lg', 'Large'], ['xl', 'Extra large']]))
      break
    case 'divider':
      html += '<p class="text-sm text-muted">A thin line across the page.</p>'
      break
    case 'details':
      html += '<p class="mb-2 text-xs text-muted">Rows shown as “Label: value”.</p>'
      html += Object.entries(FIELDS)
        .filter(([name]) => !NOT_IN_DETAILS.includes(name))
        .map(([name, f]) => `<label class="flex items-center gap-2 py-0.5 text-sm"><input type="checkbox" class="size-4 accent-[var(--primary)]" data-field="${name}"${block.fields.includes(name) ? ' checked' : ''}> ${esc(f.label)}</label>`)
        .join('')
      break
    case 'signatory':
      html += field('Alignment', select('align', block.align, ALIGN3))
      block.signers.forEach((signer, index) => {
        html += `<fieldset class="mb-3 rounded-lg border border-line p-3"><legend class="px-1 text-xs font-semibold text-muted">Signatory ${index + 1}</legend>
          ${field('Label (optional)', `<input class="input mt-1" data-signer="${index}" data-skey="label" value="${esc(signer.label)}" placeholder="e.g. Certified by:">`)}
          ${field('Name', `<input class="input mt-1" data-signer="${index}" data-skey="name" value="${esc(signer.name)}" placeholder="Leave blank to sign by hand">`)}
          ${field('Title', `<input class="input mt-1" data-signer="${index}" data-skey="title" value="${esc(signer.title)}">`)}
          ${block.signers.length > 1 ? `<button type="button" class="btn-ghost btn-sm text-danger" data-remove-signer="${index}">Remove</button>` : ''}
        </fieldset>`
      })
      if (block.signers.length < 3) html += '<button type="button" class="btn-secondary btn-sm" data-add-signer>Add signatory</button>'
      break
  }
  inspector.innerHTML = html
}

inspector.addEventListener('focusin', (event) => {
  if (event.target.matches('input:not([type=checkbox]), textarea')) lastField = event.target
})
inspector.addEventListener('input', (event) => {
  const target = event.target
  const block = selectedBlock()
  if (!block) return
  if (target.dataset.key) block[target.dataset.key] = target.type === 'checkbox' ? target.checked : target.value
  else if (target.dataset.signer) block.signers[Number(target.dataset.signer)][target.dataset.skey] = target.value
  else if (target.dataset.field) {
    const chosen = new Set([...inspector.querySelectorAll('[data-field]:checked')].map((c) => c.dataset.field))
    block.fields = Object.keys(FIELDS).filter((name) => chosen.has(name))
  }
  touch()
  renderSoon()
})
inspector.addEventListener('click', (event) => {
  const target = event.target.closest('button')
  const block = selectedBlock()
  if (!target || !block || block.type !== 'signatory') return
  if (target.hasAttribute('data-add-signer')) block.signers.push({ label: '', name: '', title: '' })
  else if (target.dataset.removeSigner) block.signers.splice(Number(target.dataset.removeSigner), 1)
  else return
  touch()
  renderInspector()
  renderPage()
})

// Placeholder chips insert at the cursor of the last edited text field.
document.getElementById('ed-chips').addEventListener('click', (event) => {
  const button = event.target.closest('[data-ph]')
  if (!button) return
  const target = lastField ?? inspector.querySelector('textarea, input[data-key="text"]')
  if (!target) {
    announce('Select a text block first.')
    return
  }
  const token = `{{${button.dataset.ph}}}`
  const start = target.selectionStart ?? target.value.length
  const end = target.selectionEnd ?? start
  target.value = target.value.slice(0, start) + token + target.value.slice(end)
  target.focus()
  target.setSelectionRange(start + token.length, start + token.length)
  target.dispatchEvent(new Event('input', { bubbles: true }))
})

// ---------- Selection and block actions ----------
const selectBlock = (id, focus = false) => {
  state.selected = id
  renderPage()
  renderInspector()
  if (focus && id) paper.querySelector(`[data-id="${id}"]`)?.focus()
}
const move = (from, to) => {
  if (to < 0 || to >= state.blocks.length || from === to) return
  const [block] = state.blocks.splice(from, 1)
  state.blocks.splice(to, 0, block)
  touch()
  announce(`${labelOf(block)} moved to position ${to + 1} of ${state.blocks.length}.`)
}
const insert = (type, at) => {
  if (state.blocks.length >= data.maxBlocks) {
    announce(`A template can have at most ${data.maxBlocks} blocks.`)
    return
  }
  const block = newBlock(type)
  state.blocks.splice(at, 0, block)
  touch()
  announce(`${labelOf(block)} added at position ${at + 1}.`)
  selectBlock(block.id, true)
}

paper.addEventListener('click', (event) => {
  const element = event.target.closest('.ed-block')
  if (!element) return
  const index = state.blocks.findIndex((block) => block.id === element.dataset.id)
  const action = event.target.closest('[data-act]')?.dataset.act
  if (action === 'up') move(index, index - 1)
  else if (action === 'down') move(index, index + 1)
  else if (action === 'copy') {
    if (state.blocks.length >= data.maxBlocks) return announce(`A template can have at most ${data.maxBlocks} blocks.`)
    const copy = { ...structuredClone(state.blocks[index]), id: newId() }
    state.blocks.splice(index + 1, 0, copy)
    touch()
    return selectBlock(copy.id, true)
  } else if (action === 'delete') {
    const [gone] = state.blocks.splice(index, 1)
    touch()
    announce(`${labelOf(gone)} deleted.`)
    return selectBlock(state.blocks[Math.min(index, state.blocks.length - 1)]?.id ?? null)
  }
  selectBlock(element.dataset.id, Boolean(action))
})
paper.addEventListener('keydown', (event) => {
  const element = event.target.closest('.ed-block')
  if (!element || event.target !== element) return
  const index = state.blocks.findIndex((block) => block.id === element.dataset.id)
  const handled = () => event.preventDefault()
  if (event.key === 'Enter' || event.key === ' ') {
    handled()
    selectBlock(element.dataset.id, true)
  } else if (event.altKey && event.key === 'ArrowUp') {
    handled()
    move(index, index - 1)
    selectBlock(element.dataset.id, true)
  } else if (event.altKey && event.key === 'ArrowDown') {
    handled()
    move(index, index + 1)
    selectBlock(element.dataset.id, true)
  } else if (event.key === 'ArrowUp') {
    handled()
    element.previousElementSibling?.focus()
  } else if (event.key === 'ArrowDown') {
    handled()
    element.nextElementSibling?.focus()
  } else if (event.key === 'Delete') {
    handled()
    element.querySelector('[data-act="delete"]')?.click()
  }
})

// ---------- Drag and drop (pointer events: mouse, pen and touch) ----------
let drag = null
let dropAt = null
let startX = 0
let startY = 0
let started = false
let ghost = null
let indicator = null

const dropIndex = (x, y) => {
  const rect = paper.getBoundingClientRect()
  if (x < rect.left - 40 || x > rect.right + 40 || y < rect.top - 40 || y > rect.bottom + 40) return null
  const elements = [...paper.querySelectorAll('.ed-block')]
  for (let index = 0; index < elements.length; index++) {
    const blockRect = elements[index].getBoundingClientRect()
    if (y < blockRect.top + blockRect.height / 2) return index
  }
  return elements.length
}
const showIndicator = (index) => {
  if (index === null) return indicator?.remove()
  if (!indicator) {
    indicator = document.createElement('div')
    indicator.id = 'ed-indicator'
  }
  const elements = [...paper.querySelectorAll('.ed-block')]
  const rect = paper.getBoundingClientRect()
  const scale = rect.width / paper.offsetWidth || 1
  const reference = elements[index] ?? elements[elements.length - 1]
  const top = reference ? (elements[index] ? reference.getBoundingClientRect().top - 4 : reference.getBoundingClientRect().bottom + 2) : rect.top + 24
  indicator.style.top = `${(top - rect.top) / scale}px`
  paper.appendChild(indicator)
}
const endDrag = () => {
  ghost?.remove()
  indicator?.remove()
  ghost = null
  drag = null
  dropAt = null
  started = false
  document.body.classList.remove('ed-dragging')
}
const begin = (event, details, label) => {
  drag = details
  startX = event.clientX
  startY = event.clientY
  event.target.setPointerCapture(event.pointerId)
  ghost = document.createElement('div')
  ghost.id = 'ed-ghost'
  ghost.textContent = label
}
paper.addEventListener('pointerdown', (event) => {
  const handle = event.target.closest('[data-drag]')
  if (!handle) return
  const element = handle.closest('.ed-block')
  event.preventDefault()
  const block = state.blocks.find((b) => b.id === element.dataset.id)
  begin(event, { kind: 'move', id: block.id }, labelOf(block))
})
document.querySelectorAll('[data-palette-type]').forEach((button) => {
  button.addEventListener('pointerdown', (event) => {
    if (event.button !== 0) return
    const type = BLOCK_TYPES.find((t) => t.type === button.dataset.paletteType)
    begin(event, { kind: 'new', type: type.type }, `+ ${type.label}`)
  })
  // Click (or keyboard) adds below the selected block.
  button.addEventListener('click', () => {
    if (started) return
    const selected = state.blocks.findIndex((block) => block.id === state.selected)
    insert(button.dataset.paletteType, selected >= 0 ? selected + 1 : state.blocks.length)
  })
})
document.addEventListener('pointermove', (event) => {
  if (!drag) return
  if (!started && Math.hypot(event.clientX - startX, event.clientY - startY) < 5) return
  if (!started) {
    started = true
    document.body.classList.add('ed-dragging')
    document.body.appendChild(ghost)
  }
  ghost.style.left = `${event.clientX}px`
  ghost.style.top = `${event.clientY}px`
  dropAt = dropIndex(event.clientX, event.clientY)
  showIndicator(dropAt)
})
document.addEventListener('pointerup', () => {
  if (!drag) return
  const details = drag
  const at = dropAt
  const wasDrag = started
  endDrag()
  if (!wasDrag) return
  // Swallow the click that follows a drag from the palette.
  started = true
  setTimeout(() => (started = false), 0)
  if (at === null) return
  if (details.kind === 'new') insert(details.type, at)
  else {
    const from = state.blocks.findIndex((block) => block.id === details.id)
    move(from, at > from ? at - 1 : at)
    selectBlock(details.id, true)
  }
})
document.addEventListener('pointercancel', endDrag)
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && drag) endDrag()
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
    event.preventDefault()
    form.requestSubmit()
  }
})

// ---------- Page options, view mode, save ----------
form.querySelectorAll('[data-page-opt]').forEach((element) => element.addEventListener('change', renderPage))
document.querySelectorAll('.ed-mode').forEach((button) =>
  button.addEventListener('click', () => {
    state.mode = button.dataset.mode
    document.querySelectorAll('.ed-mode').forEach((b) => b.setAttribute('aria-checked', String(b === button)))
    renderPage()
  }),
)
form.addEventListener('submit', () => {
  document.getElementById('blocks-input').value = JSON.stringify(state.blocks)
})
window.addEventListener('resize', fit)
renderPage()
renderInspector()
