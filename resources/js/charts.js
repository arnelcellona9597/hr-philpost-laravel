import {
  ArcElement,
  BarController,
  BarElement,
  CategoryScale,
  Chart,
  DoughnutController,
  Filler,
  LinearScale,
  LineController,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js'

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, Filler, DoughnutController, ArcElement, CategoryScale, LinearScale, Tooltip)

const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim()
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)')
const font = () => getComputedStyle(document.body).fontFamily

/** '#rrggbb' + alpha → rgba() (series tokens are plain hex). */
const alpha = (hex, a) => {
  const n = parseInt(hex.replace('#', ''), 16)
  return Number.isNaN(n) ? hex : `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${a})`
}

// Direct value labels at the bar tip (single-series charts only — never a number on every segment).
const valueLabelPlugin = {
  id: 'hrValueLabels',
  afterDatasetsDraw(chart) {
    const spec = chart.options.plugins.hrSpec
    if (!spec?.valueLabels || spec.series.length !== 1 || (spec.kind ?? 'bar') !== 'bar') return
    const { ctx } = chart
    const meta = chart.getDatasetMeta(0)
    ctx.save()
    ctx.fillStyle = css('--chart-ink')
    ctx.font = `500 12px ${font()}`
    meta.data.forEach((bar, i) => {
      const v = spec.series[0].data[i]
      if (!v) return
      const { x, y } = bar.getProps(['x', 'y'], true)
      if (spec.horizontal) {
        ctx.textAlign = 'left'
        ctx.textBaseline = 'middle'
        ctx.fillText(v.toLocaleString(), x + 6, y)
      } else {
        ctx.textAlign = 'center'
        ctx.textBaseline = 'bottom'
        ctx.fillText(v.toLocaleString(), x, y - 4)
      }
    })
    ctx.restore()
  },
}

const charts = new Map()

function render(canvas, animate) {
  const spec = JSON.parse(canvas.dataset.chart)
  const kind = spec.kind ?? 'bar'
  charts.get(canvas)?.destroy()
  const surface = css('--surface')
  const grid = css('--chart-grid')
  const ink = css('--chart-ink')
  const family = font()
  const tooltip = {
    backgroundColor: surface,
    titleColor: css('--fg'),
    bodyColor: css('--fg-2'),
    borderColor: css('--border-strong'),
    borderWidth: 1,
    padding: 10,
    boxPadding: 4,
    cornerRadius: 8,
    usePointStyle: true,
    titleFont: { family, weight: 600 },
    bodyFont: { family },
    callbacks: {
      label: (item) =>
        kind === 'doughnut'
          ? ` ${Number(item.raw).toLocaleString()}${spec.unit ? ` ${spec.unit}` : ''}`
          : ` ${Number(item.raw).toLocaleString()}${spec.unit ? ` ${spec.unit}` : ''} — ${item.dataset.label}`,
    },
  }
  const animation = animate && !reducedMotion.matches ? { duration: 450, easing: 'easeOutQuart' } : false

  let config
  if (kind === 'doughnut') {
    const s = spec.series[0]
    config = {
      type: 'doughnut',
      data: {
        labels: spec.labels,
        datasets: [
          {
            label: s.label,
            data: s.data,
            backgroundColor: spec.labels.map((_, i) => css(`--series-${spec.sliceSlots?.[i] ?? (i % 4) + 1}`)),
            borderColor: surface,
            borderWidth: 2,
            hoverOffset: 4,
            borderRadius: 3,
          },
        ],
      },
      options: { maintainAspectRatio: false, cutout: '74%', animation, plugins: { legend: { display: false }, tooltip } },
    }
  } else if (kind === 'line' || kind === 'area') {
    config = {
      type: 'line',
      data: {
        labels: spec.labels,
        datasets: spec.series.map(
          (s) => {
            const color = css(`--series-${s.slot}`)
            return {
              label: s.label,
              data: s.data,
              borderColor: color,
              borderWidth: 2,
              tension: 0.35,
              cubicInterpolationMode: 'monotone',
              pointRadius: spec.labels.length <= 14 ? 3 : 0,
              pointBackgroundColor: surface,
              pointBorderColor: color,
              pointBorderWidth: 2,
              pointHoverRadius: 5,
              pointHoverBackgroundColor: color,
              pointHoverBorderColor: surface,
              pointHitRadius: 14,
              fill: kind === 'area' ? 'origin' : false,
              backgroundColor: (ctx) => {
                const area = ctx.chart.chartArea
                if (!area) return alpha(color, 0.12)
                const g = ctx.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom)
                g.addColorStop(0, alpha(color, 0.22))
                g.addColorStop(1, alpha(color, 0))
                return g
              },
            }
          },
        ),
      },
      options: {
        maintainAspectRatio: false,
        animation,
        interaction: { mode: 'index', intersect: false },
        scales: {
          y: {
            beginAtZero: true,
            grid: { color: grid, drawTicks: false },
            border: { display: false },
            ticks: { color: ink, precision: 0, padding: 8, font: { size: 11, family } },
          },
          x: {
            grid: { display: false },
            border: { color: css('--chart-axis') },
            ticks: { color: ink, font: { size: 11, family }, maxRotation: 0, autoSkipPadding: 12 },
          },
        },
        plugins: { legend: { display: false }, tooltip },
      },
    }
  } else {
    const last = spec.series.length - 1
    const valueAxis = spec.horizontal ? 'x' : 'y'
    const catAxis = spec.horizontal ? 'y' : 'x'
    config = {
      type: 'bar',
      data: {
        labels: spec.labels,
        datasets: spec.series.map((s, i) => ({
          label: s.label,
          data: s.data,
          backgroundColor: css(`--series-${s.slot}`),
          hoverBackgroundColor: css(`--series-${s.slot}`),
          maxBarThickness: 24,
          // 4px rounded data-end, square at the baseline; stacked segments separated by a 2px surface gap
          borderRadius: spec.stacked ? (i === last ? 4 : 0) : 4,
          borderSkipped: 'start',
          borderWidth: spec.stacked ? 2 : 0,
          borderColor: surface,
          categoryPercentage: 0.7,
          barPercentage: 0.9,
        })),
      },
      options: {
        indexAxis: spec.horizontal ? 'y' : 'x',
        maintainAspectRatio: false,
        animation,
        layout: { padding: spec.valueLabels ? (spec.horizontal ? { right: 40 } : { top: 18 }) : 0 },
        interaction: { mode: 'index', intersect: false, axis: spec.horizontal ? 'y' : 'x' },
        scales: {
          [valueAxis]: {
            stacked: spec.stacked,
            beginAtZero: true,
            grid: { color: grid, drawTicks: false },
            border: { display: false },
            ticks: { color: ink, precision: 0, padding: 6, font: { size: 11, family } },
          },
          [catAxis]: {
            stacked: spec.stacked,
            grid: { display: false },
            border: { color: css('--chart-axis') },
            ticks: {
              color: ink,
              font: { size: 11, family },
              autoSkip: !spec.horizontal,
              // Long category names would push the bars off narrow screens: shorten them there
              // (the full names stay in the tooltip and the table view).
              callback(value) {
                const label = String(this.getLabelForValue(Number(value)))
                const max = canvas.clientWidth < 520 ? 18 : 40
                return label.length > max ? `${label.slice(0, max - 1)}…` : label
              },
            },
          },
        },
        plugins: {
          hrSpec: spec,
          legend: { display: false },
          tooltip,
        },
      },
      plugins: [valueLabelPlugin],
    }
  }
  charts.set(canvas, new Chart(canvas, config))
}

// Draw each chart when it scrolls into view (cheaper first paint), then on theme changes.
const canvases = () => document.querySelectorAll('canvas[data-chart]')
const io =
  'IntersectionObserver' in window
    ? new IntersectionObserver(
        (entries) => {
          for (const e of entries) {
            if (!e.isIntersecting) continue
            io.unobserve(e.target)
            render(e.target, true)
          }
        },
        { rootMargin: '200px' },
      )
    : null
canvases().forEach((c) => (io ? io.observe(c) : render(c, false)))
const rerender = () => charts.forEach((_, canvas) => render(canvas, false))
// Fired by the theme toggle and when a device-following theme changes (layouts/base.blade.php).
window.addEventListener('hr:theme', rerender)
