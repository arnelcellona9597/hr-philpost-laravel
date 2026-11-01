// Live preview on the "Issue certificate" form. The server renders it with every value escaped.
const form = document.getElementById('issue-form')
const paper = document.querySelector('[data-preview-paper]')
const scaleBox = document.querySelector('[data-preview-scale]')
const box = document.querySelector('[data-preview-box]')
const status = document.querySelector('[data-preview-status]')
const SIZES = { A4: [794, 1123], Letter: [816, 1056], Legal: [816, 1344] }
const MARGINS = { narrow: 50, normal: 72, wide: 96 }

const fit = () => {
  const w = Number(paper.dataset.w || 794)
  const h = Number(paper.dataset.h || 1123)
  const scale = Math.min(1, (box.clientWidth - 24) / w)
  scaleBox.style.width = `${w * scale}px`
  scaleBox.style.height = `${h * scale}px`
  paper.style.transform = `scale(${scale})`
  paper.style.transformOrigin = 'top left'
}

let timer
let latest = 0
const refresh = () => {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    const data = new FormData(form)
    const query = new URLSearchParams({
      employee: data.get('employee') ?? '',
      template: data.get('template') ?? '',
      purpose: data.get('purpose') ?? '',
      issued_date: data.get('issued_date') ?? '',
    })
    const mine = ++latest
    status.textContent = 'Updating…'
    const response = await fetch(`${form.dataset.previewUrl}?${query}`, { credentials: 'same-origin' })
    if (mine !== latest) return
    if (response.status === 204) {
      paper.innerHTML = ''
      status.textContent = 'Choose an employee and a certificate to see the preview.'
      return
    }
    const body = await response.text()
    if (!response.ok) {
      paper.textContent = ''
      status.textContent = response.status === 400 ? body : 'Preview unavailable.'
      return
    }
    const [w, h] = SIZES[response.headers.get('X-Paper')] ?? SIZES.A4
    paper.dataset.w = String(w)
    paper.dataset.h = String(h)
    paper.style.width = `${w}px`
    paper.style.minHeight = `${h}px`
    paper.style.padding = `${MARGINS[response.headers.get('X-Margin')] ?? 72}pt`
    paper.innerHTML = body
    status.textContent = 'Control number is assigned when issued.'
    fit()
  }, 250)
}

form.addEventListener('input', refresh)
form.addEventListener('change', refresh)
window.addEventListener('resize', fit)
refresh()
