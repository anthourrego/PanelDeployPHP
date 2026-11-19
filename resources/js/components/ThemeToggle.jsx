import { useEffect, useState } from 'react';
import { Icon } from './ui';

export default function ThemeToggle() {
    const [dark, setDark] = useState(false);
    const label = dark ? 'Activar modo claro' : 'Activar modo oscuro';

    useEffect(() => {
        setDark(document.documentElement.dataset.theme === 'dark');
    }, []);

    function toggle() {
        const theme = dark ? 'light' : 'dark';
        document.documentElement.dataset.theme = theme;
        setDark(!dark);

        try {
            localStorage.setItem('panel.theme', theme);
        } catch (error) {
            if (error.name !== 'SecurityError' && error.name !== 'QuotaExceededError') throw error;
            console.warn('El navegador no permite guardar la preferencia del tema.', error);
        }
    }

    return (
        <button
            aria-label={label}
            className="grid size-11 shrink-0 place-items-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
            onClick={toggle}
            title={label}
            type="button"
        >
            <Icon name={dark ? 'sun' : 'moon'} />
        </button>
    );
}
