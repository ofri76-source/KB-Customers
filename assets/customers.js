document.addEventListener('DOMContentLoaded', function () {
    const formCard = document.querySelector('#dc-customer-form');
    const form = document.querySelector('.dc-form-customer');

    function openForm(reset = false) {
        if (!formCard || !form) return;
        formCard.classList.remove('is-collapsed');
        if (reset) {
            form.reset();
            const hiddenId = form.querySelector('input[name="id"]');
            if (hiddenId) {
                hiddenId.value = '';
            }
        }
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function closeForm() {
        if (!formCard) return;
        formCard.classList.add('is-collapsed');
    }

    document.querySelectorAll('.dc-open-form').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openForm(true);
        });
    });

    document.querySelectorAll('.dc-collapse-form').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeForm();
        });
    });

    // מילוי טופס העריכה העליון
    document.querySelectorAll('.dc-edit-customer').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!form) return;

            openForm();

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
