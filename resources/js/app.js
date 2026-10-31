import './shell';
import './forms';

// Chart.js is only downloaded on pages that have a chart.
if (document.querySelector('canvas[data-chart]')) {
    import('./charts');
}
