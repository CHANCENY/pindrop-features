document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', function () {
        const target = this.dataset.tab;

        // remove active
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

        // activate current
        this.classList.add('active');
        document.getElementById('tab-' + target).classList.add('active');
    });
});

document.querySelectorAll('.sortable').forEach(sortable => {
    const items = document.querySelectorAll(`${sortable.getAttribute('data-items')}`);
    const sortableObject = new Sortable(sortable,{
        animation: 150,
        onEnd: reSortWeight,
    })
});

function reSortWeight(evt) {
    const weight = document.querySelectorAll('.weight-input');
    weight.forEach((input, index)=>{
        input.value = index + 1;
    })
}

document.addEventListener('DOMContentLoaded', function () {
    const fieldsets = document.querySelectorAll('.fieldset.collapsible, fieldset.collapsible, .field-group.collapsible');

    fieldsets.forEach(fieldset => {
        const toggleBtn = fieldset.querySelector('.fieldset-toggle');
        const content = fieldset.querySelector('.fieldset-content');

        if (!toggleBtn || !content) return;

        // Set initial state
        const isCollapsed = fieldset.classList.contains('collapsed');
        content.style.display = isCollapsed ? 'none' : 'block';
        toggleBtn.setAttribute('aria-expanded', !isCollapsed);

        // Toggle handler
        toggleBtn.addEventListener('click', function () {
            const currentlyCollapsed = fieldset.classList.contains('collapsed');

            if (currentlyCollapsed) {
                fieldset.classList.remove('collapsed');
                content.style.display = 'block';
                toggleBtn.setAttribute('aria-expanded', 'true');
            } else {
                fieldset.classList.add('collapsed');
                content.style.display = 'none';
                toggleBtn.setAttribute('aria-expanded', 'false');
            }
        });
    });
});