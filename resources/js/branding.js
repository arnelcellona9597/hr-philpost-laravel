// Live preview on the Branding & Theme page.
const form = document.getElementById('branding-form')
const hex = /^#[0-9a-fA-F]{6}$/
const luminance = (color) => {
  const value = parseInt(color.slice(1), 16)
  const [r, g, b] = [(value >> 16) & 255, (value >> 8) & 255, value & 255].map((channel) => {
    const scaled = channel / 255
    return scaled <= 0.03928 ? scaled / 12.92 : ((scaled + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
const readableOn = (color) => ((luminance(color) + 0.05) / 0.05 > 1.05 / (luminance(color) + 0.05) ? '#000000' : '#ffffff')
const textFor = (key) => document.querySelector(`[data-hex="${key}"]`)

function paint() {
  document.querySelectorAll('[data-swatch]').forEach((element) => {
    const value = textFor(element.dataset.swatch).value
    if (!hex.test(value)) return
    element.style.background = value
    element.style.color = readableOn(value)
  })
  const primary = textFor('primary').value
  if (hex.test(primary)) document.querySelector('[data-link]').style.color = primary
  document.querySelector('[data-preview-name]').textContent = document.getElementById('company_name').value
}

document.querySelectorAll('[data-color-for]').forEach((picker) => {
  const text = textFor(picker.dataset.colorFor)
  picker.addEventListener('input', () => {
    text.value = picker.value
    paint()
  })
  text.addEventListener('input', () => {
    if (hex.test(text.value)) picker.value = text.value
    paint()
  })
})
document.querySelectorAll('[data-preset]').forEach((button) =>
  button.addEventListener('click', () => {
    for (const [key, value] of Object.entries(JSON.parse(button.dataset.preset))) {
      textFor(key).value = value
      document.querySelector(`[data-color-for="${key}"]`).value = value
    }
    paint()
    form.dispatchEvent(new Event('input'))
  }),
)
document.getElementById('company_name').addEventListener('input', paint)
paint()
