// Form behaviour shared across pages.
// Browsers let a date field take a six-digit year unless it has a maximum.
document.querySelectorAll('input[type=date]:not([max])').forEach((input) => {
  input.max = '9999-12-31'
})

// Warn before leaving a form with unsaved changes.
document.querySelectorAll('form[data-dirty-guard]').forEach((form) => {
  let dirty = false
  form.addEventListener('input', () => (dirty = true))
  form.addEventListener('submit', () => (dirty = false))
  window.addEventListener('beforeunload', (e) => {
    if (dirty) e.preventDefault()
  })
})

// A second click on a save button must not send the form twice. After the first submit the form ignores
// further submits and its buttons look busy; they come back if the person returns to the page or, for
// downloads that keep the page open, after a few seconds.
document.querySelectorAll('form').forEach((form) => {
  if ((form.method || 'get').toLowerCase() !== 'post') return
  const release = () => {
    delete form.dataset.submitting
    form.querySelectorAll('button:not([type=button]), input[type=submit]').forEach((button) => {
      button.removeAttribute('aria-busy')
      button.classList.remove('pointer-events-none', 'opacity-60')
    })
  }
  form.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return
    if (form.dataset.submitting) {
      event.preventDefault()
      return
    }
    // Later handlers (a confirmation prompt) may still cancel this submit, so look again once they have run.
    setTimeout(() => {
      if (event.defaultPrevented) return
      form.dataset.submitting = '1'
      form.querySelectorAll('button:not([type=button]), input[type=submit]').forEach((button) => {
        button.setAttribute('aria-busy', 'true')
        button.classList.add('pointer-events-none', 'opacity-60')
      })
      setTimeout(release, 8000)
    }, 0)
  })
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) release()
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

// One searchable employee field: type to filter, pick from the list. The original <select> stays in the
// form, hidden, as the value that is submitted, so old input, prefill and the live preview keep working.
document.querySelectorAll('select[data-combobox]').forEach((select) => {
  const options = [...select.options].filter((option) => option.value !== '')
  const wrap = document.createElement('div')
  wrap.className = 'relative'
  const input = document.createElement('input')
  const list = document.createElement('ul')
  const listId = `${select.id || 'combo'}-list`
  input.type = 'text'
  input.className = 'input'
  input.id = select.id
  input.autocomplete = 'off'
  input.placeholder = 'Type a name or employee ID…'
  input.required = select.required
  input.setAttribute('role', 'combobox')
  input.setAttribute('aria-autocomplete', 'list')
  input.setAttribute('aria-expanded', 'false')
  input.setAttribute('aria-controls', listId)
  list.id = listId
  list.setAttribute('role', 'listbox')
  list.hidden = true
  list.className = 'absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-line bg-surface py-1 shadow-elev-2'
  select.removeAttribute('id')
  select.required = false
  select.hidden = true
  select.before(wrap)
  wrap.append(input, list)
  wrap.append(select)

  let matches = []
  let active = -1
  const chosen = () => select.selectedOptions[0]?.value ? select.selectedOptions[0] : null
  const sync = () => {
    input.value = chosen()?.text ?? ''
    input.setCustomValidity('')
  }
  const close = () => {
    list.hidden = true
    input.setAttribute('aria-expanded', 'false')
    input.removeAttribute('aria-activedescendant')
  }
  const highlight = () => {
    ;[...list.children].forEach((item, index) => {
      item.setAttribute('aria-selected', index === active ? 'true' : 'false')
      item.classList.toggle('bg-surface-2', index === active)
      if (index === active) {
        input.setAttribute('aria-activedescendant', item.id)
        item.scrollIntoView({ block: 'nearest' })
      }
    })
  }
  const choose = (option) => {
    select.value = option.value
    sync()
    close()
    select.dispatchEvent(new Event('change', { bubbles: true }))
  }
  const show = () => {
    const query = input.value.trim().toLowerCase()
    matches = options.filter((option) => !query || option.text.toLowerCase().includes(query)).slice(0, 50)
    list.replaceChildren(...matches.map((option, index) => {
      const item = document.createElement('li')
      item.id = `${listId}-${index}`
      item.setAttribute('role', 'option')
      item.className = 'cursor-pointer px-3 py-1.5 text-sm hover:bg-surface-2'
      item.textContent = option.text
      item.addEventListener('mousedown', (event) => {
        event.preventDefault()
        choose(option)
      })
      return item
    }))
    if (!matches.length) {
      const none = document.createElement('li')
      none.className = 'px-3 py-1.5 text-sm text-muted'
      none.textContent = 'No employee matches'
      list.replaceChildren(none)
    }
    active = matches.length ? 0 : -1
    list.hidden = false
    input.setAttribute('aria-expanded', 'true')
    highlight()
  }

  sync()
  input.addEventListener('focus', () => input.select())
  input.addEventListener('input', () => {
    if (chosen()) {
      select.value = ''
      select.dispatchEvent(new Event('change', { bubbles: true }))
    }
    input.setCustomValidity(input.required ? 'Pick an employee from the list.' : '')
    show()
  })
  input.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      if (list.hidden) return show()
      active = (active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % Math.max(matches.length, 1)
      highlight()
    } else if (event.key === 'Enter' && !list.hidden && matches[active]) {
      event.preventDefault()
      choose(matches[active])
    } else if (event.key === 'Escape' && !list.hidden) {
      event.preventDefault()
      close()
    }
  })
  input.addEventListener('blur', () => {
    close()
    if (input.value.trim() === '' && chosen()) sync()
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

// Whole table rows that open a record.
document.querySelectorAll('tr[data-href]').forEach((row) => {
  row.addEventListener('click', (e) => {
    if (e.target.closest('a, button, input, select')) return
    location.href = row.dataset.href
  })
})

// Filters that apply as soon as they change.
document.querySelectorAll('form[data-autosubmit] select, select[data-autosubmit]').forEach((select) => {
  select.addEventListener('change', () => select.form?.requestSubmit())
})

// The rows-per-page choice is remembered in this browser, so every list opens with it next time.
document.querySelectorAll('form[data-page-size] select[name=perPage]').forEach((select) => {
  select.addEventListener('change', () => {
    document.cookie = `hr-per-page=${select.value}; path=/; max-age=31536000; samesite=lax`
  })
})

// A dashboard date range replaces the preset, and a preset replaces the range.
document.querySelectorAll('form[data-autosubmit]').forEach((form) => {
  const dates = [...form.querySelectorAll('[data-range-date]')]
  const period = form.querySelector('select[name=period]')
  if (!dates.length || !period) return
  period.addEventListener('change', () => dates.forEach((input) => { input.value = '' }), { capture: true })
  dates.forEach((input) => input.addEventListener('change', () => {
    // A date is already valid after the first digit of its year, so wait until a real one is typed.
    if (dates.every((date) => date.value >= '1900-01-01')) form.requestSubmit()
  }))
})

// Close a deadline popover with Escape.
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return
  document.querySelectorAll('[data-deadline-banner] details[open]').forEach((details) => {
    details.open = false
    details.querySelector('summary')?.focus()
  })
})

// Inline onboarding requirements: saves immediately and rolls back on error.
const REQUIREMENT_TONES = { Pending: 'badge-warning', Submitted: 'badge-primary', Verified: 'badge-success', 'N/A': 'badge-neutral' }
document.querySelectorAll('select[data-req]').forEach((select) => {
  let previous = select.value
  select.addEventListener('change', async () => {
    const row = select.closest('tr[data-app]')
    select.disabled = true
    try {
      const res = await fetch(`/onboarding/${row.dataset.app}/requirement`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ key: select.dataset.req, value: select.value }),
      })
      const body = await res.json()
      if (!res.ok) throw new Error(body.message ?? 'Could not save')
      previous = select.value
      Object.values(REQUIREMENT_TONES).forEach((tone) => select.classList.remove(tone))
      select.classList.add(REQUIREMENT_TONES[select.value])
      const fds = row.querySelector('[data-fds] span')
      fds.className = body.requirementsStatus === 'Complete' ? 'badge-success' : 'badge-warning'
      fds.textContent = body.requirementsStatus === 'Complete' ? 'Complete' : `${body.requirementsMissing} missing`
    } catch (err) {
      select.value = previous
      alert(err.message)
    } finally {
      select.disabled = false
    }
  })
})
