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
            className="grid size-11 shrink-0 place-items-center rounded-full border border-slate-500 bg-slate-100 text-slate-800 transition hover:bg-slate-200 dark:border-white/15 dark:bg-[#132016] dark:text-slate-100 dark:hover:bg-[#29402c]"
            onClick={toggle}
            title={label}
            type="button"
        >
            <Icon name={dark ? 'sun' : 'moon'} />
        </button>
    );
}
