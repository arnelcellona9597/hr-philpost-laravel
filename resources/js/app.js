import './shell';
import './forms';

// Chart.js is only downloaded on pages that have a chart.
if (document.querySelector('canvas[data-chart]')) {
    import('./charts');
}

// The certificate preview and template editor only load on their own pages.
if (document.getElementById('issue-form')) {
    import('./certificate-preview');
}
if (document.getElementById('tpl-form')) {
    import('./certificate-editor');
}
if (document.getElementById('payroll-grid')) {
    import('./payroll-grid');
}
