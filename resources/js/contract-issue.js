// The contract issue grid: tick the workers, adjust anyone who differs, and send the whole list as one
// JSON field, because a long list has more inputs than PHP's max_input_vars lets through.
const form = document.getElementById('contract-grid')
const rows = [...form.querySelectorAll('tr[data-row]')]
const all = document.getElementById('select-all')
const count = document.getElementById('selected-count')

const refresh = () => {
  const selected = rows.filter((row) => row.querySelector('[data-selected]').checked).length
  count.textContent = selected
  all.checked = selected === rows.length
}

all.addEventListener('change', () => {
  rows.forEach((row) => { row.querySelector('[data-selected]').checked = all.checked })
  refresh()
})
form.addEventListener('change', (event) => {
  if (event.target.matches('[data-selected]')) refresh()
})

form.addEventListener('submit', () => {
  const list = rows.map((row) => ({
    selected: row.querySelector('[data-selected]').checked,
    employee: Number(row.dataset.employee),
    ...Object.fromEntries([...row.querySelectorAll('[data-field]')].map((input) => [input.dataset.field, input.value])),
  }))
  document.getElementById('grid-input').value = JSON.stringify(list)
})
