import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AssignmentModal, DeploymentModal, ProjectModal, ServerModal, SshModal, TokenModal } from '../components/Modals';
import ThemeToggle from '../components/ThemeToggle';
import { confirmAction, notifyError, notifySuccess } from '../lib/notify';
import { Badge, Card, EmptyState, Icon, StatusBadge, dangerButton, primaryButton, secondaryButton } from '../components/ui';

const SIDEBAR_KEY = 'panel.sidebar.collapsed';
const ONLINE_WINDOW_MS = 5 * 60 * 1000;

const sections = [
    { key: 'summary', label: 'Resumen', icon: 'home' },
    { key: 'deployments', label: 'Programaciones', icon: 'rocket' },
    { key: 'projects', label: 'Proyectos', icon: 'folder' },
    { key: 'servers', label: 'Servidores', icon: 'server' },
];

function runAction(method, url, { data, ...confirmation }) {
    confirmAction(confirmation).then((confirmed) => {
        if (confirmed) {
            router[method](url, data ?? {}, { preserveScroll: true });
        }
    });
}

function generateToken(method, url) {
    router[method](url, {}, { preserveScroll: true });
}

function isOnline(server) {
    return server.last_seen_at !== null && Date.now() - Date.parse(`${server.last_seen_at.replace(' ', 'T')}Z`) < ONLINE_WINDOW_MS;
}

function ServerStatus({ server }) {
    if (!server.is_active) {
        return <Badge tone="danger">Desactivado</Badge>;
    }

    if (server.active_target_id) {
        return <Badge tone="info">Trabajando</Badge>;
    }

    return isOnline(server) ? <Badge tone="success">En línea</Badge> : <Badge>Sin conexión</Badge>;
}

function StatCard({ icon, label, tone, value }) {
    return (
        <div className="flex items-center gap-4 rounded-2xl bg-white/90 p-5 shadow-sm ring-1 ring-green-900/10 dark:bg-[#17251a] dark:ring-white/15">
            <span className={`grid size-12 place-items-center rounded-xl ${tone}`}><Icon className="size-6" name={icon} /></span>
            <div>
                <p className="font-display text-2xl font-extrabold text-neutral-800 dark:text-slate-100">{value}</p>
                <p className="text-sm text-slate-500 dark:text-slate-400">{label}</p>
            </div>
        </div>
    );
}

function UserMenu({ onLogout, processing, user }) {
    const [open, setOpen] = useState(false);
    const container = useRef(null);
    const trigger = useRef(null);
    const initial = Array.from(user.name.trim() || user.email)[0].toLocaleUpperCase();

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onPointerDown = (event) => {
            if (!container.current?.contains(event.target)) {
                setOpen(false);
            }
        };
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
                trigger.current?.focus();
            }
        };

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    return (
        <div className="relative" onBlur={(event) => {
            if (!event.currentTarget.contains(event.relatedTarget)) {
                setOpen(false);
            }
        }} ref={container}>
            <button
                aria-controls="user-menu"
                aria-expanded={open}
                aria-label="Menú de usuario"
                className="grid size-10 shrink-0 place-items-center rounded-full bg-prosof-action text-sm font-bold text-white transition hover:bg-prosof-action-hover"
                onClick={() => setOpen(!open)}
                ref={trigger}
                type="button"
            >
                {initial}
            </button>
            {open && (
                <div id="user-menu" className="absolute right-0 top-full z-50 mt-2 w-64 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-white/15 dark:bg-[#17251a]">
                    <div className="border-b border-slate-200 px-4 py-3 dark:border-white/10">
                        <p className="break-words text-sm font-bold text-slate-900 dark:text-slate-100">{user.name}</p>
                        <p className="mt-1 break-all text-xs text-slate-600 dark:text-slate-300">{user.email}</p>
                    </div>
                    <button
                        className="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-semibold text-red-800 transition hover:bg-red-50 disabled:opacity-60 dark:text-red-300 dark:hover:bg-red-950/60"
                        disabled={processing}
                        onClick={() => {
                            setOpen(false);
                            onLogout();
                        }}
                        type="button"
                    >
                        <Icon className="size-4" name="logout" />Cerrar sesión
                    </button>
                </div>
            )}
        </div>
    );
}

function CancelTargetButton({ target }) {
    const form = useForm({});

    return (
        <button
            className="rounded-md border border-red-600 dark:border-red-800 bg-red-50 dark:bg-transparent px-2.5 py-1 text-xs font-semibold text-red-800 dark:text-red-200 transition hover:bg-red-100 dark:hover:bg-red-950/70 disabled:opacity-60"
            disabled={form.processing}
            onClick={async () => {
                const confirmed = await confirmAction({
                    title: '¿Cancelar este trabajo?',
                    text: 'El trabajo pendiente no se ejecutará en el servidor.',
                    confirmText: 'Sí, cancelar',
                    danger: true,
                });
                if (confirmed) {
                    form.post(`/targets/${target.id}/cancel`);
                }
            }}
            type="button"
        >
            Cancelar
        </button>
    );
}

function TargetsList({ targets }) {
    return targets.map((target) => (
        <div className="flex flex-wrap items-center gap-2" key={target.id}>
            <span className="font-medium text-slate-700 dark:text-slate-200">{target.server_name}</span>
            <StatusBadge status={target.status} />
            {target.status === 'pending' && <CancelTargetButton target={target} />}
            {target.result && <span className="basis-full text-xs text-slate-500 dark:text-slate-400">{target.result}</span>}
        </div>
    ));
}

function DeploymentActions({ deployment }) {
    const hasPending = deployment.targets.some((target) => target.status === 'pending');

    return (
        <div className="mt-2 flex flex-wrap gap-2">
            {hasPending && (
                <button
                    className={dangerButton}
                    onClick={() => runAction('post', `/deployments/${deployment.id}/cancel`, {
                        title: '¿Cancelar la programación?',
                        text: 'Se cancelarán todos los trabajos pendientes de esta programación.',
                        confirmText: 'Sí, cancelar',
                        danger: true,
                    })}
                    type="button"
                >
                    Cancelar programación
                </button>
            )}
            {deployment.can_delete && (
                <button
                    className={dangerButton}
                    onClick={() => runAction('delete', `/deployments/${deployment.id}`, {
                        title: '¿Eliminar la programación?',
                        text: 'Se borrará del listado. Esta acción no se puede deshacer.',
                        confirmText: 'Sí, eliminar',
                        danger: true,
                    })}
                    type="button"
                >
                    <Icon className="size-3.5" name="trash" />Eliminar
                </button>
            )}
        </div>
    );
}

function DeploymentsTable({ deployments, limit }) {
    const rows = limit ? deployments.slice(0, limit) : deployments;

    if (rows.length === 0) {
        return <EmptyState>Aún no hay programaciones.</EmptyState>;
    }

    return (
        <>
            <ul className="divide-y divide-slate-100 dark:divide-slate-800 md:hidden">
                {rows.map((deployment) => (
                    <li className="space-y-3 px-4 py-4" key={deployment.id}>
                        <div>
                            <p className="font-semibold text-neutral-800 dark:text-slate-100">{deployment.project_name}</p>
                            <p className="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{deployment.ref} · <code className="text-xs" title={deployment.commit}>{deployment.commit.slice(0, 8)}</code></p>
                            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{deployment.scheduled_at} UTC</p>
                            <DeploymentActions deployment={deployment} />
                        </div>
                        <div className="space-y-2 text-sm"><TargetsList targets={deployment.targets} /></div>
                    </li>
                ))}
            </ul>
            <div className="hidden overflow-x-auto md:block">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 dark:bg-slate-950 text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <tr>
                            <th className="px-5 py-3 font-semibold">Proyecto / versión</th>
                            <th className="px-5 py-3 font-semibold">Fecha (UTC)</th>
                            <th className="px-5 py-3 font-semibold">Servidores</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {rows.map((deployment) => (
                            <tr className="align-top hover:bg-slate-50/60 dark:hover:bg-slate-800/60" key={deployment.id}>
                                <td className="px-5 py-4">
                                    <p className="font-semibold text-neutral-800 dark:text-slate-100">{deployment.project_name}</p>
                                    <p className="mt-0.5 text-slate-600 dark:text-slate-300">{deployment.ref} · <code className="text-xs" title={deployment.commit}>{deployment.commit.slice(0, 8)}</code></p>
                                    <DeploymentActions deployment={deployment} />
                                </td>
                                <td className="whitespace-nowrap px-5 py-4 text-slate-600 dark:text-slate-300">{deployment.scheduled_at}</td>
                                <td className="space-y-2 px-5 py-4"><TargetsList targets={deployment.targets} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}

function ServerActions({ server }) {
    const canDelete = !server.has_history && !server.active_target_id;

    return (
        <>
            <button
                className={secondaryButton.replace('px-4 py-2 text-sm', 'px-3 py-1.5 text-xs')}
                disabled={server.active_target_id !== null}
                onClick={() => (server.has_token ? runAction : generateToken)('post', `/servers/${server.id}/token`, {
                    title: '¿Regenerar el token?',
                    text: 'El token anterior dejará de funcionar y deberá actualizarlo en el agente de este servidor.',
                    confirmText: 'Sí, regenerar',
                    danger: true,
                })}
                type="button"
            >
                <Icon className="size-3.5" name="key" />{server.has_token ? 'Regenerar token' : 'Generar token'}
            </button>
            <button
                className={secondaryButton.replace('px-4 py-2 text-sm', 'px-3 py-1.5 text-xs')}
                disabled={server.is_active && server.active_target_id !== null}
                onClick={() => runAction('post', `/servers/${server.id}/active`, {
                    data: { is_active: !server.is_active },
                    title: server.is_active ? '¿Desactivar el servidor?' : '¿Activar el servidor?',
                    text: server.is_active ? 'No recibirá nuevos trabajos; los pendientes quedarán en espera.' : 'Volverá a recibir trabajos programados.',
                    confirmText: server.is_active ? 'Sí, desactivar' : 'Sí, activar',
                    danger: server.is_active,
                })}
                title={server.is_active && server.active_target_id !== null ? 'Tiene un trabajo en ejecución' : undefined}
                type="button"
            >
                <Icon className="size-3.5" name="power" />{server.is_active ? 'Desactivar' : 'Activar'}
            </button>
            {canDelete && (
                <button
                    className={dangerButton}
                    onClick={() => runAction('delete', `/servers/${server.id}`, {
                        title: '¿Eliminar el servidor?',
                        text: `Se eliminará "${server.name}" y sus rutas asignadas. Esta acción no se puede deshacer.`,
                        confirmText: 'Sí, eliminar',
                        danger: true,
                    })}
                    type="button"
                >
                    <Icon className="size-3.5" name="trash" />Eliminar
                </button>
            )}
        </>
    );
}

function ServersList({ servers, showActions = false }) {
    if (servers.length === 0) {
        return <EmptyState>No hay servidores registrados.</EmptyState>;
    }

    return (
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {servers.map((server) => (
                <li className="flex flex-wrap items-center justify-between gap-3 px-5 py-4" key={server.id}>
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300"><Icon name="server" /></span>
                        <div className="min-w-0">
                            <p className="font-semibold text-neutral-800 dark:text-slate-100">{server.name}</p>
                            <p className="text-xs text-slate-500 dark:text-slate-400">ID {server.id} · última conexión: {server.last_seen_at ? `${server.last_seen_at} UTC` : 'nunca'}{server.active_target_id && ` · trabajo activo ${server.active_target_id}`}</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <ServerStatus server={server} />
                        {showActions && <ServerActions server={server} />}
                    </div>
                </li>
            ))}
        </ul>
    );
}

function ProjectsGrid({ onAssign, onSsh, projects }) {
    if (projects.length === 0) {
        return <div className="rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-700"><EmptyState>No hay proyectos registrados.</EmptyState></div>;
    }

    return (
        <div className="grid gap-5 md:grid-cols-2">
            {projects.map((project) => (
                <article className="flex flex-col rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-700" key={project.id}>
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h3 className="font-display text-lg font-bold text-neutral-800 dark:text-slate-100">{project.name}</h3>
                            <p className="text-sm text-slate-500 dark:text-slate-400">{project.owner}/{project.repository}</p>
                        </div>
                        <div className="flex gap-2">
                            {!project.is_active && <Badge tone="danger">Desactivado</Badge>}
                            <Badge tone={project.ssh_url ? 'success' : 'neutral'}>{project.ssh_url ? 'SSH' : 'Sin SSH'}</Badge>
                        </div>
                    </div>
                    <div className="mt-4 flex-1">
                        {project.servers.length > 0 ? (
                            <ul className="space-y-2">
                                {project.servers.map((server) => (
                                    <li className="flex items-start justify-between gap-2 rounded-lg bg-slate-50 dark:bg-slate-950 px-3 py-2 text-sm" key={server.id}>
                                        <div className="min-w-0">
                                            <span className="font-semibold text-slate-700 dark:text-slate-200">{server.name}</span>
                                            <code className="mt-0.5 block break-all text-xs text-slate-500 dark:text-slate-400">{server.path}</code>
                                        </div>
                                        <button
                                            aria-label={`Quitar ruta de ${server.name}`}
                                            className="shrink-0 rounded-md p-1.5 text-slate-400 dark:text-slate-400 transition hover:bg-red-50 dark:hover:bg-red-950/70 hover:text-red-700 dark:hover:text-red-300"
                                            onClick={() => runAction('delete', `/projects/${project.id}/servers/${server.id}`, {
                                                title: '¿Quitar esta ruta?',
                                                text: `El proyecto dejará de poder desplegarse en ${server.name}.`,
                                                confirmText: 'Sí, quitar',
                                                danger: true,
                                            })}
                                            type="button"
                                        >
                                            <Icon className="size-4" name="trash" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : <p className="text-sm text-slate-500 dark:text-slate-400">Sin servidores asignados.</p>}
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 dark:border-slate-800 pt-4">
                        <button className={secondaryButton} onClick={() => onAssign(project.id)} type="button"><Icon className="size-4" name="plus" />Asignar ruta</button>
                        <button className={secondaryButton} onClick={() => onSsh(project)} type="button"><Icon className="size-4" name="link" />Conexión SSH</button>
                        <button
                            className={secondaryButton}
                            onClick={() => runAction('post', `/projects/${project.id}/active`, {
                                data: { is_active: !project.is_active },
                                title: project.is_active ? '¿Desactivar el proyecto?' : '¿Activar el proyecto?',
                                text: project.is_active ? 'No se podrán programar nuevos despliegues hasta activarlo de nuevo.' : 'Podrá programar despliegues de nuevo.',
                                confirmText: project.is_active ? 'Sí, desactivar' : 'Sí, activar',
                                danger: project.is_active,
                            })}
                            type="button"
                        >
                            <Icon className="size-4" name="power" />{project.is_active ? 'Desactivar' : 'Activar'}
                        </button>
                        {!project.has_deployments && (
                            <button
                                className={`${dangerButton} py-2 text-sm`}
                                onClick={() => runAction('delete', `/projects/${project.id}`, {
                                    title: '¿Eliminar el proyecto?',
                                    text: `Se eliminará "${project.name}" y sus rutas. Esta acción no se puede deshacer.`,
                                    confirmText: 'Sí, eliminar',
                                    danger: true,
                                })}
                                type="button"
                            >
                                <Icon className="size-4" name="trash" />Eliminar
                            </button>
                        )}
                    </div>
                </article>
            ))}
        </div>
    );
}

export default function Panel({ deployments, projects, section, sectionUrls, servers, user }) {
    const [modal, setModal] = useState(null);
    const [tokenReveal, setTokenReveal] = useState(null);
    const [menuOpen, setMenuOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(() => typeof window !== 'undefined' && window.localStorage.getItem(SIDEBAR_KEY) === '1');
    const logoutForm = useForm({});

    async function logout() {
        const confirmed = await confirmAction({ title: '¿Cerrar sesión?', text: 'Tendrá que ingresar de nuevo para continuar.', confirmText: 'Salir' });
        if (confirmed) {
            logoutForm.post('/logout');
        }
    }

    useEffect(() => {
        const removeSuccess = router.on('success', (event) => {
            if (event.detail.page.flash?.agent_token) {
                setTokenReveal(event.detail.page.flash.agent_token);
            }
            const message = event.detail.page.props.flash?.success;
            if (message) {
                notifySuccess(message);
            }
        });
        const removeError = router.on('error', (event) => {
            const messages = [...new Set(Object.values(event.detail.errors ?? {}).filter(Boolean))];
            if (messages.length > 0) {
                notifyError(messages);
            }
        });
        const removeException = router.on('exception', () => notifyError('No se pudo completar la acción. Verifique la conexión e intente de nuevo.'));

        return () => {
            removeSuccess();
            removeError();
            removeException();
        };
    }, []);
    const closeModal = () => setModal(null);

    useEffect(() => {
        window.localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
    }, [collapsed]);

    useEffect(() => {
        if (!menuOpen) {
            return undefined;
        }
        const onKey = (event) => event.key === 'Escape' && setMenuOpen(false);
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [menuOpen]);

    const targets = deployments.flatMap((deployment) => deployment.targets);
    const pending = targets.filter((target) => target.status === 'pending').length;
    const problems = targets.filter((target) => ['failed', 'uncertain'].includes(target.status)).length;
    const current = sections.find((item) => item.key === section);

    const headerAction = {
        summary: { label: 'Programar despliegue', modal: { type: 'deployment' } },
        deployments: { label: 'Programar despliegue', modal: { type: 'deployment' } },
        projects: { label: 'Nuevo proyecto', modal: { type: 'project' } },
        servers: { label: 'Nuevo servidor', modal: { type: 'server' } },
    }[section];

    return (
        <>
            <Head title="Panel de despliegues" />
            <div className="panel-backdrop min-h-screen text-slate-900 dark:text-slate-100">
                <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-green-900/10 bg-white/55 px-4 backdrop-blur-[30px] backdrop-saturate-175 sm:gap-5 sm:px-6 dark:border-white/10 dark:bg-[#142118]/80">
                    <button aria-controls="panel-navigation" aria-expanded={menuOpen} aria-label={menuOpen ? 'Cerrar menú' : 'Abrir menú'} className="grid size-10 shrink-0 place-items-center rounded-full bg-white/80 text-slate-700 transition hover:bg-white dark:bg-white/10 dark:text-slate-100 dark:hover:bg-white/20 lg:hidden" onClick={() => setMenuOpen(!menuOpen)} type="button">
                        <Icon name={menuOpen ? 'close' : 'menu'} />
                    </button>
                    <button aria-controls="panel-navigation" aria-expanded={!collapsed} aria-label={collapsed ? 'Expandir menú' : 'Contraer menú'} className="hidden size-10 shrink-0 place-items-center rounded-full bg-white/80 text-slate-700 transition hover:bg-white dark:bg-white/10 dark:text-slate-100 dark:hover:bg-white/20 lg:grid" onClick={() => setCollapsed(!collapsed)} type="button">
                        <Icon name={collapsed ? 'menu' : 'close'} />
                    </button>
                    <img alt="Prosof" className="h-8 w-auto max-w-[45%] object-contain" src="/img/prosof-logo.webp" />
                    <p className="hidden border-l border-green-900/15 pl-5 text-sm text-slate-600 sm:block dark:border-white/15 dark:text-slate-300">Panel de despliegues <span className="font-semibold text-slate-900 dark:text-white">:: Prosof SAS</span></p>
                    <div className="ml-auto flex items-center gap-3">
                        <ThemeToggle />
                        <UserMenu onLogout={logout} processing={logoutForm.processing} user={user} />
                    </div>
                </header>

                {menuOpen && <div aria-hidden="true" className="fixed inset-0 z-40 bg-neutral-900/50 lg:hidden" onClick={() => setMenuOpen(false)} />}

                <div className="lg:flex lg:items-start lg:gap-4 lg:px-4 lg:pb-4">
                <aside id="panel-navigation" className={`fixed bottom-4 left-4 top-4 z-50 flex w-72 max-w-[calc(100vw-2rem)] flex-col rounded-2xl border border-white/80 bg-white/55 shadow-xl backdrop-blur-[30px] backdrop-saturate-175 dark:border-white/15 dark:bg-[#17251a]/95 lg:sticky lg:bottom-auto lg:left-auto lg:top-20 lg:z-20 lg:mt-4 lg:h-[calc(100vh-6rem)] lg:shrink-0 lg:shadow-sm ${menuOpen ? 'visible translate-x-0' : 'invisible -translate-x-[110%] lg:visible lg:translate-x-0'} ${collapsed ? 'lg:w-[72px]' : 'lg:w-[210px]'}`}>
                    <div className="flex items-center justify-between px-4 pt-4 lg:hidden">
                        <span className="font-display text-sm font-bold text-slate-700 dark:text-slate-200">Navegación</span>
                        <button aria-label="Cerrar menú" className="rounded-lg p-2 text-slate-700 transition hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-white/10" onClick={() => setMenuOpen(false)} type="button">
                            <Icon name="close" />
                        </button>
                    </div>
                    <nav aria-label="Navegación principal" className="flex-1 space-y-1 overflow-y-auto px-2 pt-3">
                        {sections.map((item) => (
                            <Link
                                aria-current={section === item.key ? 'page' : undefined}
                                aria-label={item.label}
                                className={`flex w-full items-center gap-3 whitespace-nowrap rounded-xl px-3 py-3 text-sm font-semibold transition ${collapsed ? 'lg:justify-center lg:px-0' : ''} ${section === item.key ? 'bg-prosof-lime/20 text-prosof-ink ring-1 ring-prosof-dark/30 dark:bg-prosof-lime/15 dark:text-prosof-lime dark:ring-prosof-lime/30' : 'text-slate-600 hover:bg-green-900/5 dark:text-slate-300 dark:hover:bg-white/10'}`}
                                href={sectionUrls[item.key]}
                                key={item.key}
                                onClick={() => setMenuOpen(false)}
                                title={collapsed ? item.label : undefined}
                            >
                                <Icon className={`size-5 shrink-0 ${section === item.key ? 'text-prosof-dark dark:text-prosof-lime' : ''}`} name={item.icon} />
                                <span className={collapsed ? 'lg:hidden' : ''}>{item.label}</span>
                            </Link>
                        ))}
                    </nav>
                    <div className="space-y-1 px-2 pb-3">
                        {!collapsed && <div className="hidden rounded-xl bg-slate-900/5 px-3 py-3 text-xs text-slate-600 dark:bg-black/20 dark:text-slate-300 lg:block"><p className="font-semibold">Prosof SAS</p><p className="mt-1">Panel de despliegues</p></div>}
                    </div>
                </aside>

                <div className="min-w-0 flex-1 p-4 lg:px-0 lg:pb-0">
                    <main className="mx-auto max-w-6xl space-y-5 rounded-2xl border border-white/80 bg-white/55 px-4 py-5 shadow-sm backdrop-blur-[30px] backdrop-saturate-175 sm:px-6 dark:border-white/15 dark:bg-[#17251a]/75">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h1 className="font-display text-xl font-extrabold text-neutral-800 dark:text-slate-100">{current.label}</h1>
                                <p className="hidden text-sm text-slate-600 sm:block dark:text-slate-300">¡Soluciones que evolucionan!</p>
                            </div>
                            <button className={`${primaryButton} max-sm:w-full`} onClick={() => setModal(headerAction.modal)} type="button">
                                <Icon className="size-4" name="plus" />{headerAction.label}
                            </button>
                        </div>
                        {section === 'summary' && (
                            <>
                                <section className="panel-hero rounded-2xl border-l-4 border-prosof-lime px-5 py-6 shadow-sm ring-1 ring-green-900/10 sm:px-7 dark:ring-white/10">
                                    <p className="text-xs font-bold uppercase tracking-widest text-prosof-ink dark:text-prosof-lime">Panel de despliegues</p>
                                    <h2 className="mt-2 text-2xl font-extrabold tracking-tight text-neutral-800 dark:text-white">Control de despliegues</h2>
                                    <p className="mt-2 text-sm text-slate-700 dark:text-slate-300">Consulta el estado de tus proyectos, servidores y programaciones en un solo lugar.</p>
                                </section>
                                <div className="flex gap-3 rounded-xl border-l-4 border-amber-500 bg-amber-50 dark:bg-amber-950/60 px-4 py-3 text-sm text-amber-950 dark:text-amber-100">
                                    <Icon className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-300" name="alert" />
                                    <p><strong>Validación pendiente:</strong> el agente requiere HTTPS y pruebas en Windows antes de usarse en producción. Si el commit cambia una ruta protegida, se bloqueará el despliegue.</p>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                    <StatCard icon="folder" label="Proyectos" tone="bg-prosof-lime/25 text-prosof-dark dark:text-prosof-lime" value={projects.length} />
                                    <StatCard icon="server" label="Servidores en línea" tone="bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-300" value={`${servers.filter(isOnline).length}/${servers.length}`} />
                                    <StatCard icon="rocket" label="Trabajos pendientes" tone="bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-300" value={pending} />
                                    <StatCard icon="alert" label="Fallidos o inciertos" tone="bg-red-50 dark:bg-red-950/60 text-red-600 dark:text-red-300" value={problems} />
                                </div>
                                <div className="grid gap-5 xl:grid-cols-3">
                                    <div className="xl:col-span-2">
                                        <Card action={<Link className="text-sm font-semibold text-prosof-dark dark:text-prosof-lime hover:underline" href={sectionUrls.deployments}>Ver todas</Link>} title="Últimas programaciones">
                                            <DeploymentsTable deployments={deployments} limit={5} />
                                        </Card>
                                    </div>
                                    <Card title="Estado de servidores"><ServersList servers={servers} /></Card>
                                </div>
                            </>
                        )}

                        {section === 'deployments' && <Card title={`Programaciones (${deployments.length})`}><DeploymentsTable deployments={deployments} /></Card>}

                        {section === 'projects' && (
                            <ProjectsGrid onAssign={(projectId) => setModal({ type: 'assignment', projectId })} onSsh={(project) => setModal({ type: 'ssh', project })} projects={projects} />
                        )}

                        {section === 'servers' && <Card title={`Servidores (${servers.length})`}><ServersList servers={servers} showActions /></Card>}
                    </main>
                </div>
                </div>
            </div>

            {tokenReveal && <TokenModal onClose={() => setTokenReveal(null)} reveal={tokenReveal} />}
            {modal?.type === 'deployment' && <DeploymentModal onClose={closeModal} projects={projects.filter((project) => project.is_active)} servers={servers.filter((server) => server.is_active)} />}
            {modal?.type === 'project' && <ProjectModal onClose={closeModal} />}
            {modal?.type === 'server' && <ServerModal onClose={closeModal} />}
            {modal?.type === 'assignment' && <AssignmentModal initialProjectId={modal.projectId} onClose={closeModal} projects={projects} servers={servers} />}
            {modal?.type === 'ssh' && <SshModal onClose={closeModal} project={modal.project} />}
        </>
    );
}
