// The payroll grid: live row totals, and every amount sent as one JSON field on save, because a large
// period has more inputs than PHP's max_input_vars lets through.
const form = document.getElementById('payroll-grid')
const symbol = form.dataset.symbol
const money = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const centavos = (value) => {
  const amount = Number(String(value).replace(/,/g, '').trim() || 0)
  return Number.isFinite(amount) ? Math.round(amount * 100) : NaN
}
const show = (cents) => (Number.isNaN(cents) ? '—' : `${cents < 0 ? '−' : ''}${symbol}${money.format(Math.abs(cents) / 100)}`)

form.querySelectorAll('tr[data-row]').forEach((row) => {
  row.addEventListener('input', () => {
    let earnings = 0
    let deductions = 0
    row.querySelectorAll('input[data-kind]').forEach((input) => {
      const cents = centavos(input.value)
      input.setAttribute('aria-invalid', Number.isNaN(cents) || cents < 0 ? 'true' : 'false')
      if (input.dataset.kind === 'e') earnings += cents
      else deductions += cents
    })
    row.querySelector('[data-gross]').textContent = show(earnings)
    row.querySelector('[data-ded]').textContent = show(deductions)
    const net = row.querySelector('[data-net]')
    net.textContent = show(earnings - deductions)
    net.classList.toggle('text-danger', earnings - deductions < 0)
  })
})

form.addEventListener('submit', () => {
  const rows = [...form.querySelectorAll('tr[data-row]')].map((row) => ({
    id: Number(row.dataset.row),
    values: Object.fromEntries([...row.querySelectorAll('input[data-item]')].map((input) => [input.dataset.item, input.value])),
  }))
  document.getElementById('grid-input').value = JSON.stringify(rows)
})
