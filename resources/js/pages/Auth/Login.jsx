import { Head, useForm } from '@inertiajs/react';
import ThemeToggle from '../../components/ThemeToggle';

const inputClassName = 'mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base text-slate-900 outline-none transition focus:border-prosof-dark focus:ring-2 focus:ring-prosof-lime/40 dark:border-white/20 dark:bg-[#101b14] dark:text-slate-100';

function Field({ children, error, label }) {
    return (
        <label className="block text-sm font-medium text-slate-700 dark:text-slate-200">
            <span>{label}</span>
            {children}
            {error && <span className="mt-1 block text-sm text-red-700 dark:text-red-300">{error}</span>}
        </label>
    );
}

export default function Login() {
    const form = useForm({
        username: '',
        password: '',
    });

    function submit(event) {
        event.preventDefault();
        form.post('/login', {
            onSuccess: () => form.reset('password'),
        });
    }

    return (
        <>
            <Head title="Ingresar" />
            <main className="panel-backdrop flex min-h-screen items-center justify-center px-4 py-10">
                <section className="login-glass w-full max-w-md rounded-3xl border border-white/80 bg-white p-6 shadow-xl shadow-green-950/10 backdrop-blur-sm sm:p-10 dark:border-white/15 dark:bg-[#17251a]/90 dark:shadow-black/25">
                    <div className="flex justify-end">
                        <ThemeToggle />
                    </div>
                    <div className="flex flex-col items-center text-center">
                        <img alt="Prosof" className="h-auto w-52 max-w-full p-2" src="/img/prosof-logo.webp" />
                        <h1 className="mt-8 text-2xl font-extrabold tracking-tight text-neutral-800 dark:text-slate-100">Panel de despliegues</h1>
                        <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">Ingresa con tu cuenta para continuar.</p>
                    </div>
                    <form className="mt-8 space-y-5" onSubmit={submit}>
                        <Field error={form.errors.username} label="Nombre de usuario">
                            <input
                                autoComplete="username"
                                autoCapitalize="none"
                                className={inputClassName}
                                onChange={(event) => form.setData('username', event.target.value)}
                                required
                                type="text"
                                value={form.data.username}
                            />
                        </Field>
                        <Field error={form.errors.password} label="Contraseña">
                            <input
                                autoComplete="current-password"
                                className={inputClassName}
                                onChange={(event) => form.setData('password', event.target.value)}
                                required
                                type="password"
                                value={form.data.password}
                            />
                        </Field>
                        <button
                            className="w-full rounded-full bg-prosof-action px-4 py-3 font-bold text-white transition hover:bg-prosof-action-hover disabled:cursor-not-allowed disabled:opacity-60"
                            disabled={form.processing}
                            type="submit"
                        >
                            {form.processing ? 'Ingresando…' : 'Ingresar'}
                        </button>
                    </form>
                </section>
            </main>
        </>
    );
}
