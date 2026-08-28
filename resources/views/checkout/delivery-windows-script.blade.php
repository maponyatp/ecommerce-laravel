<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('checkout-form');
    if (!form) return;
    const panels = form.querySelectorAll('[data-delivery-window-method]');
    const syncWindows = () => {
        const selected = form.querySelector('[name="shipping_method_id"]:checked')?.value;
        panels.forEach(panel => {
            const active = panel.dataset.deliveryWindowMethod === selected;
            panel.hidden = !active;
            const select = panel.querySelector('select');
            select.disabled = !active;
            select.required = active;
        });
    };
    form.querySelectorAll('[name="shipping_method_id"]').forEach(input => input.addEventListener('change', syncWindows));
    syncWindows();
});
</script>
