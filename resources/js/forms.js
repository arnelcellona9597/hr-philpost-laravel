// Form behaviour shared across pages.
// Warn before leaving a form with unsaved changes.
document.querySelectorAll('form[data-dirty-guard]').forEach((form) => {
  let dirty = false
  form.addEventListener('input', () => (dirty = true))
  form.addEventListener('submit', () => (dirty = false))
  window.addEventListener('beforeunload', (e) => {
    if (dirty) e.preventDefault()
  })
})

// Confirmation prompts for destructive actions.
document.querySelectorAll('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (e) => {
    if (!confirm(form.dataset.confirm)) e.preventDefault()
  })
})

// Type-to-filter for long employee dropdowns.
document.querySelectorAll('select[data-searchable]').forEach((select) => {
  const search = document.createElement('input')
  search.type = 'search'
  search.placeholder = 'Type to filter employees…'
  search.className = 'input mb-2'
  search.setAttribute('aria-label', 'Filter employee list')
  select.before(search)
  const all = [...select.options]
  search.addEventListener('input', () => {
    const q = search.value.toLowerCase()
    for (const o of all) o.hidden = Boolean(q) && o.value !== '' && !o.text.toLowerCase().includes(q)
  })
})

// Resend countdown and auto-submit on the email code step.
const resend = document.getElementById('resend')
if (resend && !resend.disabled) {
  const at = Date.parse(resend.dataset.at)
  const label = resend.textContent
  const tick = () => {
    const s = Math.ceil((at - Date.now()) / 1000)
    if (s > 0) {
      resend.disabled = true
      resend.textContent = `Send a new code (${s}s)`
      setTimeout(tick, 1000)
    } else {
      resend.disabled = false
      resend.textContent = label
    }
  }
  tick()
}
const code = document.getElementById('code')
code?.addEventListener('input', () => {
  code.value = code.value.replace(/\D/g, '').slice(0, 6)
  if (code.value.length === 6) code.form.requestSubmit()
})

// Live leave preview: calls /leave/preview as the dates change.
const preview = document.getElementById('leave-preview')
const previewForm = preview?.closest('form')
if (preview && previewForm) {
  const get = (name) => previewForm.elements.namedItem(name)?.value ?? ''
  let timer
  const update = async () => {
    const employee = get('employee')
    const from = get('inclusive_date_from')
    const to = get('inclusive_date_to')
    if (!employee || !from || !to) return
    const params = new URLSearchParams({ employee, from, to, status: get('status') || 'Approved' })
    if (preview.dataset.exclude) params.set('exclude', preview.dataset.exclude)
    const res = await fetch(`/leave/preview?${params}`)
    const data = await res.json()
    preview.replaceChildren()
    const head = document.createElement('p')
    head.className = data.ok ? 'font-medium text-success' : 'font-medium text-danger'
    head.textContent = data.ok ? `${data.days} working day${data.days === 1 ? '' : 's'} — within the allowance.` : data.error
    preview.append(head)
    for (const y of data.years ?? []) {
      const line = document.createElement('p')
      line.className = 'mt-1 text-fg-2 tabular'
      line.textContent = `${y.year}: allowance ${y.allowance}, already used ${y.usedBefore}, this filing ${y.thisFiling}, remaining after ${y.remaining}`
      preview.append(line)
    }
  }
  previewForm.addEventListener('change', () => {
    clearTimeout(timer)
    timer = setTimeout(update, 150)
  })
  update()
}

// Show the chosen profile picture before saving, and catch files that are too large without a round trip.
const avatarForm = document.querySelector('[data-avatar-form]')
if (avatarForm) {
  const input = avatarForm.querySelector('[data-avatar-input]')
  const save = avatarForm.querySelector('[data-avatar-save]')
  const nameEl = avatarForm.querySelector('[data-avatar-name]')
  const preview = document.querySelector('[data-avatar-preview]')
  const current = document.querySelector('[data-avatar-current]')
  const maxBytes = Number(avatarForm.dataset.maxBytes)
  let url = null
  input.addEventListener('change', () => {
    const file = input.files && input.files[0]
    if (url) URL.revokeObjectURL(url)
    if (!file) return
    if (file.size > maxBytes) {
      nameEl.textContent = `That file is larger than ${maxBytes / 1024 / 1024} MB.`
      save.disabled = true
      input.value = ''
      return
    }
    url = URL.createObjectURL(file)
    preview.src = url
    preview.classList.remove('hidden')
    current.classList.add('hidden')
    nameEl.textContent = file.name
    save.disabled = false
  })
}
