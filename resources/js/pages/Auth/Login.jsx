import { Head, useForm } from '@inertiajs/react';
import ThemeToggle from '../../components/ThemeToggle';

const inputClassName = 'mt-1 w-full rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-base text-slate-900 dark:text-slate-100 shadow-sm sm:text-sm outline-none transition focus:border-prosof-dark focus:ring-2 focus:ring-prosof-lime/40';

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
        email: '',
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
            <main className="flex min-h-screen items-center justify-center bg-slate-100 dark:bg-slate-800 px-4 py-10">
                <section className="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 p-6 sm:p-8 shadow-sm ring-1 ring-slate-200 dark:ring-slate-700">
                    <div className="mb-6 flex items-start justify-between gap-4">
                        <img alt="Prosof" className="h-auto w-52 min-w-0 max-w-[70%] rounded-sm dark:bg-white dark:ring-4 dark:ring-white" src="/img/prosof.png" />
                        <ThemeToggle />
                    </div>
                    <h1 className="text-2xl font-extrabold tracking-tight text-neutral-800 dark:text-slate-100">Panel de despliegues</h1>
                    <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">Ingresa con tu cuenta para continuar.</p>
                    <form className="mt-8 space-y-5" onSubmit={submit}>
                        <Field error={form.errors.email} label="Correo">
                            <input
                                autoComplete="username"
                                className={inputClassName}
                                onChange={(event) => form.setData('email', event.target.value)}
                                required
                                type="email"
                                value={form.data.email}
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
                            className="w-full rounded-md bg-prosof-lime px-4 py-2.5 font-bold text-prosof-ink transition hover:bg-prosof-green disabled:cursor-not-allowed disabled:opacity-60"
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
