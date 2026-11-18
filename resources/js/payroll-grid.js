// The COS payroll grid: every computed column recalculated as you type, with the same whole-centavo
// arithmetic and Excel-style rounding as App\Support\CosPay (the server recomputes on save and is
// authoritative). All rows go as one JSON field, because a large period has more inputs than PHP's
// max_input_vars lets through.
const form = document.getElementById('payroll-grid')
const money = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const plain = new Intl.NumberFormat('en-PH', { maximumFractionDigits: 2 })

// Integer division rounded half away from zero, like Excel's ROUND.
const divRound = (numerator, denominator) => {
  const quotient = Math.floor((Math.abs(numerator) * 2 + denominator) / (denominator * 2))
  return numerator < 0 ? -quotient : quotient
}
// "10.5" -> 1050 hundredths; NaN when it is not a number with at most 2 decimals.
const hundredths = (text) => {
  const value = String(text).replace(/,/g, '').trim()
  if (value === '') return 0
  return /^\d{1,9}(\.\d{1,2})?$/.test(value) ? Math.round(Number(value) * 100) : NaN
}
const whole = (text) => {
  const value = String(text).replace(/,/g, '').trim()
  if (value === '') return 0
  return /^\d{1,5}$/.test(value) ? Number(value) : NaN
}
const show = (cents) => (Number.isNaN(cents) ? '—' : `${cents < 0 ? '−' : ''}${money.format(Math.abs(cents) / 100)}`)

const NUMERIC = { days: hundredths, late: whole, ot: hundredths, ot_rest: hundredths, sss: hundredths, hdmf: hundredths, cmpeai: hundredths, absences: hundredths, tardiness: whole }

function compute(row) {
  const read = {}
  row.querySelectorAll('input[data-in]').forEach((input) => {
    const parse = NUMERIC[input.dataset.in]
    if (!parse) return
    const value = parse(input.value)
    input.setAttribute('aria-invalid', Number.isNaN(value) ? 'true' : 'false')
    read[input.dataset.in] = value
  })

  const rate = Number(row.dataset.rate)
  const premium = divRound(rate * Math.round(Number(row.dataset.premium) * 100), 10000)
  const total = rate + premium
  const perMinute = divRound(total, 480)
  const hourly = divRound(total * 125, 800)
  const hourlyRest = divRound(total * 150, 800)

  const grossExact = total * read.days
  const lateAmount = read.late * perMinute
  const overtimeExact = read.ot * hourly + read.ot_rest * hourlyRest
  const afterExact = grossExact - lateAmount * 100 + overtimeExact
  const deductions = read.sss + read.hdmf + read.cmpeai

  return {
    premium,
    total,
    per_minute: perMinute,
    hourly,
    hourly_rest: hourlyRest,
    days: read.days,
    gross: divRound(grossExact, 100),
    late_amount: lateAmount,
    overtime: divRound(overtimeExact, 100),
    after: divRound(afterExact, 100),
    sss: read.sss,
    hdmf: read.hdmf,
    cmpeai: read.cmpeai,
    net: divRound(afterExact - deductions * 100, 100),
    excess: divRound(read.absences * total, 100),
  }
}

function paint(row) {
  const pay = compute(row)
  row.querySelectorAll('[data-out]').forEach((cell) => {
    cell.textContent = show(pay[cell.dataset.out])
  })
  row.querySelector('[data-out="net"]').classList.toggle('text-danger', pay.net < 0)
  return pay
}

function paintTotals() {
  const totals = {}
  form.querySelectorAll('tr[data-row]').forEach((row) => {
    const pay = compute(row)
    Object.entries(pay).forEach(([key, value]) => {
      totals[key] = (totals[key] ?? 0) + value
    })
  })
  form.querySelectorAll('[data-total]').forEach((cell) => {
    const key = cell.dataset.total
    cell.textContent = key === 'days' ? (Number.isNaN(totals.days) ? '—' : plain.format(totals.days / 100)) : show(totals[key] ?? 0)
  })
}

form.querySelectorAll('tr[data-row]').forEach((row) => {
  row.addEventListener('input', () => {
    paint(row)
    paintTotals()
  })
})
paintTotals()

form.addEventListener('submit', () => {
  const rows = [...form.querySelectorAll('tr[data-row]')].map((row) => ({
    id: Number(row.dataset.row),
    values: Object.fromEntries([...row.querySelectorAll('input[data-in]')].map((input) => [input.dataset.in, input.value])),
  }))
  document.getElementById('grid-input').value = JSON.stringify(rows)
})
