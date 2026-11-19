import Swal from 'sweetalert2';

function currentTheme() {
    return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
}

const buttonClasses = {
    confirmButton: 'rounded-lg bg-prosof-lime px-5 py-2.5 text-sm font-bold text-prosof-ink hover:bg-prosof-green',
    cancelButton: 'rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-5 py-2.5 text-sm font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800',
    actions: 'gap-3',
    popup: 'rounded-2xl font-sans',
    title: 'font-display text-neutral-800 dark:text-slate-100',
};

const toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    showCloseButton: true,
    timer: 5000,
    timerProgressBar: true,
    customClass: { popup: 'rounded-xl font-sans', title: 'text-sm text-neutral-800 dark:text-slate-100' },
    didOpen: (element) => {
        element.addEventListener('mouseenter', Swal.stopTimer);
        element.addEventListener('mouseleave', Swal.resumeTimer);
    },
});

export function notifySuccess(message) {
    toast.fire({ theme: currentTheme(), icon: 'success', iconColor: '#79a52b', title: message });
}

export function notifyError(messages) {
    const list = [].concat(messages);

    toast.fire({
        theme: currentTheme(),
        icon: 'error',
        timer: 8000,
        title: list.length > 1 ? 'Revise los datos' : list[0],
        html: list.length > 1 ? `<ul style="text-align:left;margin:0;padding-left:1.1rem;list-style:disc">${list.map((message) => `<li>${escapeHtml(message)}</li>`).join('')}</ul>` : undefined,
    });
}

export async function confirmAction({ title, text, confirmText = 'Confirmar', danger = false }) {
    const { isConfirmed } = await Swal.fire({
        theme: currentTheme(),
        title,
        text,
        icon: danger ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Volver',
        reverseButtons: true,
        buttonsStyling: false,
        focusCancel: danger,
        customClass: {
            ...buttonClasses,
            confirmButton: danger ? 'rounded-lg bg-red-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-red-700' : buttonClasses.confirmButton,
        },
    });

    return isConfirmed;
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
}
