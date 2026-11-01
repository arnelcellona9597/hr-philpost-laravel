// The compose page: templates, placeholder chips and the preview.
const templates = JSON.parse(document.getElementById('compose-templates').textContent)
const form = document.getElementById('compose')
const subject = document.getElementById('subject')
const body = document.getElementById('body')
const categories = form.dataset.categories.split(',')

document.getElementById('template').addEventListener('change', (event) => {
  const template = templates[event.target.value]
  if (!template) return
  if ((subject.value || body.value) && !confirm('Replace the current subject and message with this template?')) return
  subject.value = template.subject
  body.value = template.body
  document.getElementById('category').value = categories.includes(template.category) ? template.category : 'General'
})

let lastFocus = body
subject.addEventListener('focus', () => (lastFocus = subject))
body.addEventListener('focus', () => (lastFocus = body))
document.querySelectorAll('[data-insert]').forEach((button) =>
  button.addEventListener('click', () => {
    const target = lastFocus
    const start = target.selectionStart ?? target.value.length
    target.value = target.value.slice(0, start) + button.dataset.insert + target.value.slice(target.selectionEnd ?? start)
    target.focus()
    target.selectionStart = target.selectionEnd = start + button.dataset.insert.length
  }),
)

const employees = document.getElementById('employees')
const selectedCount = document.querySelector('[data-selected-count]')
const updateCount = () => (selectedCount.textContent = employees.selectedOptions.length ? `${employees.selectedOptions.length} selected.` : '')
employees.addEventListener('change', updateCount)
updateCount()

document.getElementById('preview-btn').addEventListener('click', async () => {
  const data = new FormData(form)
  data.delete('attachments[]')
  const response = await fetch(form.dataset.previewUrl, { method: 'POST', body: data, headers: { Accept: 'application/json' } })
  const result = await response.json()
  const box = document.getElementById('preview')
  const summary = document.getElementById('preview-summary')
  const skipped = document.getElementById('preview-skipped')
  const frame = document.getElementById('preview-frame')
  box.hidden = false
  skipped.replaceChildren()
  if (!response.ok) {
    summary.textContent = result.error ?? 'The preview could not be made.'
    summary.className = 'mb-2 text-danger'
    frame.srcdoc = ''
    return
  }
  summary.className = 'mb-2 text-fg-2'
  summary.textContent = `${result.deliverable} of ${result.total} recipient(s) will get this email. Showing the version for ${result.sample.name} <${result.sample.to ?? 'no email'}>.`
  for (const person of result.skipped.slice(0, 10)) {
    const item = document.createElement('li')
    item.textContent = `Skipped: ${person.name} — ${person.reason}`
    skipped.append(item)
  }
  if (result.skipped.length > 10) {
    const item = document.createElement('li')
    item.textContent = `…and ${result.skipped.length - 10} more`
    skipped.append(item)
  }
  frame.srcdoc = result.sample.html
  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
})
