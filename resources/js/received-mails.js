// The "log mail" page: add and remove rows, and paste rows copied from Excel.
const body = document.querySelector('#mail-rows tbody')
const fields = ['tracking_number', 'post_office', 'description', 'remarks']

const rows = () => [...body.querySelectorAll('[data-row]')]

function number() {
  rows().forEach((row, index) => {
    row.querySelector('[data-number]').textContent = index + 1
    row.querySelectorAll('input').forEach((input) => {
      input.name = input.name.replace(/rows\[\d+\]/, `rows[${index}]`)
    })
  })
}

function addRow() {
  const row = rows()[0].cloneNode(true)
  row.querySelectorAll('input').forEach((input) => (input.value = ''))
  body.append(row)
  number()
  return row
}

document.getElementById('add-row').addEventListener('click', () => addRow().querySelector('input').focus())

body.addEventListener('click', (event) => {
  if (!event.target.closest('[data-remove]')) return
  if (rows().length > 1) event.target.closest('[data-row]').remove()
  else rows()[0].querySelectorAll('input').forEach((input) => (input.value = ''))
  number()
})

// Pasting several lines (tab-separated, as Excel copies them) fills one row per line.
body.addEventListener('paste', (event) => {
  const text = event.clipboardData.getData('text')
  if (!/[\n\t]/.test(text.trim())) return
  event.preventDefault()
  const lines = text.replace(/\r/g, '').split('\n').filter((line) => line.trim() !== '')
  let row = event.target.closest('[data-row]')
  lines.forEach((line, index) => {
    if (index > 0) row = row.nextElementSibling ?? addRow()
    line.split('\t').slice(0, fields.length).forEach((cell, column) => {
      row.querySelector(`input[name$="[${fields[column]}]"]`).value = cell.trim()
    })
  })
  document.getElementById('mail-form').dispatchEvent(new Event('input', { bubbles: true }))
})
