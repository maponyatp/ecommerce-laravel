@push('styles')
<style>
.payment-workspace{max-width:1100px;margin:0 auto;padding:40px 24px 64px;color:#17212f}
.payment-workspace h1{font-size:clamp(1.7rem,4vw,2.2rem);font-weight:700;margin:0 0 8px}
.payment-workspace h2{font-size:1.1rem;font-weight:650;margin:0 0 20px}
.payment-workspace .payment-intro{color:#526071;line-height:1.6;max-width:740px;margin-bottom:28px}
.payment-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;align-items:start}
.payment-panel{background:#fff;border:1px solid #dce2ea;border-radius:18px;padding:24px;box-shadow:0 4px 18px #17212f06}
.payment-field{margin-bottom:20px}.payment-field label{display:block;font-weight:600;margin-bottom:8px}
.payment-field input[type=text]{width:100%;min-height:46px;border:1px solid #aab5c4;border-radius:10px;padding:10px 12px}
.payment-field small{display:block;color:#526071;line-height:1.5;margin-top:8px}
.payment-workspace :focus-visible{outline:3px solid #2563eb;outline-offset:3px}
.payment-actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:16px}
.payment-actions button,.payment-actions a{display:inline-flex;align-items:center;justify-content:center;border:1px solid #b8c3d1;border-radius:10px;padding:10px 16px;font-weight:600;min-height:44px;text-decoration:none}
.payment-actions .payment-primary{color:#fff;background:#17212f;border-color:#17212f}
.payment-actions .payment-remove{color:#a31527;background:#fff}
.payment-item{padding:20px 0;border-bottom:1px solid #e5e9ef;overflow-wrap:anywhere}.payment-item:first-of-type{padding-top:0}.payment-item:last-child{border-bottom:0;padding-bottom:0}
.payment-item strong{font-size:1rem}.payment-reference{color:#526071;margin-top:8px;font-family:monospace}
.payment-badge{display:inline-block;background:#edf4ff;color:#234985;border-radius:20px;padding:3px 10px;font-size:.8rem;margin-left:8px}
.payment-errors{border:1px solid #f2b9bf;background:#fff3f4;color:#8b1524;border-radius:12px;padding:16px;margin-bottom:24px}
.payment-check{display:flex;gap:10px;align-items:center;margin-bottom:20px}.payment-check input{width:18px;height:18px}
@media(max-width:720px){.payment-grid{grid-template-columns:1fr}.payment-workspace{padding:24px 16px 40px}.payment-panel{padding:20px}}
</style>
@endpush
