// The remittance grid: live column totals, add and remove people, and the whole list sent as one JSON
// field on save, because a long list has more inputs than PHP's max_input_vars lets through.
const form = document.getElementById('remittance-grid')
const body = form.querySelector('tbody')
const template = document.getElementById('row-template')
const money = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const centavos = (value) => {
  const amount = Number(String(value).replace(/,/g, '').trim() || 0)
  return Number.isFinite(amount) ? Math.round(amount * 100) : NaN
}
const show = (cents) => `₱${money.format(cents / 100)}`
const field = (row, name) => row.querySelector(`[data-field="${name}"]`)

const renumber = () => body.querySelectorAll('tr[data-row]').forEach((row, index) => { row.querySelector('[data-number]').textContent = index + 1 })

const totals = () => {
  form.querySelectorAll('[data-total]').forEach((cell) => {
    const sum = [...body.querySelectorAll('tr[data-row]')].reduce((all, row) => {
      const cents = centavos(field(row, cell.dataset.total)?.value ?? 0)
      return all + (Number.isNaN(cents) ? 0 : cents)
    }, 0)
    cell.textContent = show(sum)
  })
}

body.addEventListener('input', (event) => {
  const input = event.target
  if (['amount', 'employer_share', 'compensation'].includes(input.dataset.field)) {
    const cents = centavos(input.value)
    input.setAttribute('aria-invalid', Number.isNaN(cents) || cents < 0 ? 'true' : 'false')
    totals()
  }
})

body.addEventListener('click', (event) => {
  const button = event.target.closest('[data-remove]')
  if (button) {
    button.closest('tr').remove()
    renumber()
    totals()
    form.dispatchEvent(new Event('input', { bubbles: true }))
  }
})

document.getElementById('add-person')?.addEventListener('click', () => {
  body.append(template.content.cloneNode(true))
  renumber()
  field(body.lastElementChild, 'last_name').focus()
})

form.addEventListener('submit', () => {
  const rows = [...body.querySelectorAll('tr[data-row]')].map((row) => {
    const values = Object.fromEntries([...row.querySelectorAll('[data-field]')].map((input) => [input.dataset.field, input.value]))
    return {
      employee_id: row.dataset.employee || null,
      birth_date: row.dataset.birth || null,
      station: row.dataset.station || null,
      ...values,
    }
  })
  document.getElementById('grid-input').value = JSON.stringify(rows)
})
