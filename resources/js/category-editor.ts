import { store, update } from '@/actions/App/Http/Controllers/Admin/CategoryController';

type Category = { id: number; name: string; slug: string; description: string | null };
const dialog = document.querySelector<HTMLDialogElement>('#category-editor');
const form = document.querySelector<HTMLFormElement>('#category-editor-form');
if (dialog && form) {
    const field = (name: string) => form.elements.namedItem(name) as HTMLInputElement | HTMLTextAreaElement;
    const heading = dialog.querySelector('#category-editor-title');
    const submit = dialog.querySelector('[data-category-submit]');
    const slugHelp = dialog.querySelector('#category-slug-help');
    const open = (category?: Category) => {
        form.reset();
        field('name').value = category?.name ?? '';
        field('slug').value = category?.slug ?? '';
        field('slug').readOnly = Boolean(category);
        field('description').value = category?.description ?? '';
        field('category_modal_id').value = category ? String(category.id) : '';
        field('_method').value = category ? 'PUT' : 'POST';
        field('slug_mode').value = 'auto';
        form.action = category ? update.url(category.id) : store.url();
        if (heading) heading.textContent = category ? 'Edit category' : 'Create category';
        if (submit) submit.textContent = category ? 'Save changes' : 'Create category';
        if (slugHelp) slugHelp.textContent = category ? 'The existing slug stays fixed to preserve references.' : 'Generated from the name as you type. You can edit it before creating the category.';
        dialog.showModal();
        field('name').focus();
    };
    document.addEventListener('click', (event) => {
        const target = event.target as Element;
        if (target.closest('[data-category-create]')) { event.preventDefault(); open(); }
        const edit = target.closest<HTMLElement>('[data-category-edit]');
        if (edit?.dataset.categoryEdit) { event.preventDefault(); open(JSON.parse(edit.dataset.categoryEdit) as Category); }
        if (target.closest('[data-category-close]')) dialog.close();
    });
    field('name').addEventListener('input', () => {
        if (!field('category_modal_id').value && field('slug_mode').value === 'auto') {
            field('slug').value = field('name').value.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 100);
        }
    });
    field('slug').addEventListener('input', () => { field('slug_mode').value = field('slug').value ? 'custom' : 'auto'; });
    if (dialog.open) { dialog.close(); dialog.showModal(); }
}
