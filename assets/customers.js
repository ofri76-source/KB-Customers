document.addEventListener('DOMContentLoaded', function () {
    // בחירת כל הלקוחות
    const selectAll = document.querySelector('.dc-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function (e) {
            document.querySelectorAll('input[name="ids[]"]').forEach(function (cb) {
                cb.checked = e.target.checked;
            });
        });
    }

    // מילוי טופס העריכה העליון
    document.querySelectorAll('.dc-edit-customer').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const form = document.querySelector('.dc-form-customer');
            if (!form) return;

            form.querySelector('input[name="id"]').value = this.dataset.id || '';
            form.querySelector('input[name="customer_name"]').value = this.dataset.name || '';
            form.querySelector('input[name="customer_number"]').value = this.dataset.number || '';

            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
});
