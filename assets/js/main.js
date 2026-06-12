// Auto-submit filters on dropdown change
document.querySelectorAll('.filters-bar select').forEach(sel => {
    sel.addEventListener('change', () => sel.closest('form').submit());
});
