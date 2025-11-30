document.addEventListener('DOMContentLoaded', function () {
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

    // חיפוש אוטומטי בעת הקלדה + כפתור נקה
    const searchForm = document.querySelector('.dc-search-form');
    const searchInput = searchForm ? searchForm.querySelector('input[name="dc_c_search"]') : null;
    let searchTimer;

    if (searchForm && searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                searchForm.submit();
            }, 300);
        });

        const clearBtn = searchForm.querySelector('.dc-search-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                searchInput.value = '';
                window.location.href = this.dataset.target || window.location.href.split('?')[0];
            });
        }
    }
});
